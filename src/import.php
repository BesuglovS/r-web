<?php
/**
 * Импорт расписания с Яндекс-Диска.
 * Сравнивает новые данные с текущими, записывает изменения в history.
 */

require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/db.php';

function get_yandex_download_url(string $public_url): string
{
    $api_url = 'https://cloud-api.yandex.net/v1/disk/public/resources/download?public_key='
             . urlencode($public_url);

    $ctx = stream_context_create(['http' => ['timeout' => 30]]);
    $response = @file_get_contents($api_url, false, $ctx);
    if ($response === false) {
        throw new RuntimeException('Не удалось получить информацию о файле с Яндекс-Диска');
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['href'])) {
        throw new RuntimeException('Неверный ответ от Яндекс-Диска: ' . ($response ?: 'пустой ответ'));
    }

    return $data['href'];
}

function download_file(string $url, string $dest): void
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 120,
            'follow_location' => true,
        ],
    ]);

    $content = @file_get_contents($url, false, $ctx);
    if ($content === false) {
        throw new RuntimeException('Не удалось скачать файл');
    }

    if (file_put_contents($dest, $content) === false) {
        throw new RuntimeException('Не удалось записать файл: ' . $dest);
    }
}

/**
 * Ключ урока для сравнения (всё кроме room).
 */
function lesson_key(array $l): string
{
    return implode('|', [
        $l['date'], $l['class_name'], $l['lesson_num'],
        $l['time_start'], $l['time_end'],
        $l['subject'], $l['teacher'], $l['parallel_group'] ?? '',
    ]);
}

function do_import(string $public_url): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'schedule_') . '.xlsx';

    try {
        $download_url = get_yandex_download_url($public_url);
        download_file($download_url, $tmp);
        $lessons = parse_xlsx($tmp);

        $pdo = get_db();
        if ($pdo === null) {
            throw new RuntimeException('БД недоступна');
        }
        init_db($pdo);

        $imported_at = date('Y-m-d H:i:s');

        // Collect dates from new lessons
        $new_dates = [];
        foreach ($lessons as $l) {
            $di = parse_sheet_date($l['sheet_name']);
            if ($di['date']) {
                $new_dates[$di['date']] = true;
                $l['_date'] = $di['date'];
                $l['_day_of_week'] = $di['day_of_week'];
            }
        }
        $date_list = array_keys($new_dates);

        $pdo->beginTransaction();
        try {
            $changes = ['added' => 0, 'removed' => 0, 'room_changed' => 0];

            if (!empty($date_list)) {
                // Get current lessons for affected dates
                $placeholders = implode(',', array_fill(0, count($date_list), '?'));
                $cur_stmt = $pdo->prepare(
                    "SELECT * FROM schedule WHERE date IN ($placeholders) ORDER BY date, lesson_num, class_name"
                );
                $cur_stmt->execute($date_list);
                $current = $cur_stmt->fetchAll(PDO::FETCH_ASSOC);

                // Build lookup by key
                $current_by_key = [];
                foreach ($current as $row) {
                    $current_by_key[lesson_key($row)] = $row;
                }

                // Build new lessons with metadata
                $new_lessons = [];
                foreach ($lessons as $l) {
                    $new_lessons[] = [
                        'sheet_name'     => $l['sheet_name'],
                        'date'           => $l['_date'],
                        'day_of_week'    => $l['_day_of_week'],
                        'class_name'     => $l['class_name'],
                        'lesson_num'     => $l['lesson_num'],
                        'time_start'     => $l['time_start'],
                        'time_end'       => $l['time_end'],
                        'subject'        => $l['subject'],
                        'teacher'        => $l['teacher'],
                        'room'           => $l['room'],
                        'parallel_group' => $l['parallel_group'],
                    ];
                }

                $new_by_key = [];
                foreach ($new_lessons as $l) {
                    $new_by_key[lesson_key($l)] = $l;
                }

                $version = get_next_version($pdo);

                // Detect changes
                $history_rows = [];

                // 1. Added: in new but not in current
                foreach ($new_by_key as $k => $l) {
                    if (!isset($current_by_key[$k])) {
                        $history_rows[] = [
                            'change_type'   => 'added',
                            'date'          => $l['date'],
                            'day_of_week'   => $l['day_of_week'],
                            'class_name'    => $l['class_name'],
                            'lesson_num'    => $l['lesson_num'],
                            'time_start'    => $l['time_start'],
                            'time_end'      => $l['time_end'],
                            'subject'       => $l['subject'],
                            'teacher'       => $l['teacher'],
                            'room'          => $l['room'],
                            'old_room'      => '',
                            'parallel_group'=> $l['parallel_group'],
                        ];
                        $changes['added']++;
                    }
                }

                // 2. Removed: in current but not in new
                foreach ($current_by_key as $k => $l) {
                    if (!isset($new_by_key[$k])) {
                        $history_rows[] = [
                            'change_type'   => 'removed',
                            'date'          => $l['date'],
                            'day_of_week'   => $l['day_of_week'],
                            'class_name'    => $l['class_name'],
                            'lesson_num'    => $l['lesson_num'],
                            'time_start'    => $l['time_start'],
                            'time_end'      => $l['time_end'],
                            'subject'       => $l['subject'],
                            'teacher'       => $l['teacher'],
                            'room'          => $l['room'],
                            'old_room'      => '',
                            'parallel_group'=> $l['parallel_group'],
                        ];
                        $changes['removed']++;
                    }
                }

                // 3. Room changed: same lesson key but different room
                foreach ($new_by_key as $k => $l) {
                    if (isset($current_by_key[$k])) {
                        $old_room = $current_by_key[$k]['room'] ?? '';
                        $new_room = $l['room'] ?? '';
                        if ($old_room !== $new_room) {
                            $history_rows[] = [
                                'change_type'   => 'room_changed',
                                'date'          => $l['date'],
                                'day_of_week'   => $l['day_of_week'],
                                'class_name'    => $l['class_name'],
                                'lesson_num'    => $l['lesson_num'],
                                'time_start'    => $l['time_start'],
                                'time_end'      => $l['time_end'],
                                'subject'       => $l['subject'],
                                'teacher'       => $l['teacher'],
                                'room'          => $new_room,
                                'old_room'      => $old_room,
                                'parallel_group'=> $l['parallel_group'],
                            ];
                            $changes['room_changed']++;
                        }
                    }
                }

                // Save history if there are changes
                if (!empty($history_rows)) {
                    $hist_stmt = $pdo->prepare("
                        INSERT INTO schedule_history
                            (version, change_type, date, day_of_week, class_name, lesson_num,
                             time_start, time_end, subject, teacher, room, old_room, parallel_group, changed_at)
                        VALUES
                            (:version, :change_type, :date, :day_of_week, :class_name, :lesson_num,
                             :time_start, :time_end, :subject, :teacher, :room, :old_room, :parallel_group, :changed_at)
                    ");
                    foreach ($history_rows as $hr) {
                        $hist_stmt->execute(array_merge($hr, [
                            ':version'    => $version,
                            ':changed_at' => $imported_at,
                        ]));
                    }
                }

                // Delete current for affected dates
                $del_stmt = $pdo->prepare("DELETE FROM schedule WHERE date IN ($placeholders)");
                $del_stmt->execute($date_list);
            }

            // Insert new lessons into schedule
            $ins_stmt = $pdo->prepare("
                INSERT INTO schedule
                    (sheet_name, date, day_of_week, class_name, lesson_num,
                     time_start, time_end, subject, teacher, room, parallel_group, imported_at)
                VALUES
                    (:sheet_name, :date, :day_of_week, :class_name, :lesson_num,
                     :time_start, :time_end, :subject, :teacher, :room, :parallel_group, :imported_at)
            ");

            foreach ($new_lessons as $l) {
                $ins_stmt->execute([
                    ':sheet_name'     => $l['sheet_name'],
                    ':date'           => $l['date'],
                    ':day_of_week'    => $l['day_of_week'],
                    ':class_name'     => $l['class_name'],
                    ':lesson_num'     => $l['lesson_num'],
                    ':time_start'     => $l['time_start'],
                    ':time_end'       => $l['time_end'],
                    ':subject'        => $l['subject'],
                    ':teacher'        => $l['teacher'],
                    ':room'           => $l['room'],
                    ':parallel_group' => $l['parallel_group'],
                    ':imported_at'    => $imported_at,
                ]);
            }

            // Log import
            $log_stmt = $pdo->prepare("
                INSERT INTO import_log (url, filename, imported_at, lessons_count, status)
                VALUES (:url, :filename, :imported_at, :count, 'ok')
            ");
            $filename = basename($tmp);
            $log_stmt->execute([
                ':url'          => $public_url,
                ':filename'     => $filename,
                ':imported_at'  => $imported_at,
                ':count'        => count($lessons),
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'status'  => 'ok',
            'lessons' => count($lessons),
            'date'    => $imported_at,
            'changes' => $changes,
        ];

    } finally {
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
    }
}
