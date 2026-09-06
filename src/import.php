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

    // Защита от SSRF: принимаем только HTTPS-ссылки на загрузчик Яндекса
    $href = (string)$data['href'];
    $host = strtolower((string)(parse_url($href, PHP_URL_HOST) ?: ''));
    if (!str_starts_with($href, 'https://') || !str_contains($host, 'yandex')) {
        throw new RuntimeException('Яндекс-Диск вернул неожиданную ссылку для скачивания');
    }

    return $href;
}

/**
 * Лёгкий запрос метаданных публичного файла (без скачивания, без OAuth).
 * Возвращает ['md5', 'modified', 'size', 'name'].
 * Бросает RuntimeException с HTTP-кодом в сообщении (в т.ч. 429 Too Many Requests).
 */
function get_public_meta(string $public_url): array
{
    $api_url = 'https://cloud-api.yandex.net/v1/disk/public/resources?public_key='
             . urlencode($public_url);

    $ctx = stream_context_create(['http' => [
        'timeout' => 30,
        'ignore_errors' => true, // читать тело ответа даже при 4xx/5xx
    ]]);
    $response = @file_get_contents($api_url, false, $ctx);

    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int)$m[1];
        }
    }

    if ($response === false && $status === 0) {
        throw new RuntimeException('Не удалось связаться с Яндекс.Диском');
    }

    $data = json_decode((string)$response, true);

    if ($status !== 200) {
        $detail = $data['message'] ?? ($data['description'] ?? 'нет описания');
        throw new RuntimeException("Яндекс.Диск вернул HTTP {$status}: {$detail}");
    }

    if (!is_array($data) || ($data['type'] ?? '') !== 'file') {
        throw new RuntimeException('Ссылка ведёт не на файл: метаданные md5/size недоступны');
    }

    return [
        'md5'      => (string)($data['md5'] ?? ''),
        'modified' => (string)($data['modified'] ?? ''),
        'size'     => (int)($data['size'] ?? 0),
        'name'     => (string)($data['name'] ?? ''),
    ];
}

function download_file(string $url, string $dest, int $max_bytes = 52428800): void
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 120,
            'follow_location' => true,
        ],
    ]);

    // Потоковое скачивание: не держим весь файл в памяти, ограничиваем размер.
    // Превышение лимита — явная ошибка (а не молчаливая обрезка файла).
    $src = @fopen($url, 'rb', false, $ctx);
    if ($src === false) {
        throw new RuntimeException('Не удалось скачать файл');
    }

    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $code = (int)$m[1];
        }
    }
    if ($code >= 400) {
        fclose($src);
        throw new RuntimeException("Не удалось скачать файл: HTTP $code");
    }

    $dst = @fopen($dest, 'wb');
    if ($dst === false) {
        fclose($src);
        throw new RuntimeException('Не удалось записать файл: ' . $dest);
    }

    try {
        $written = 0;
        while (!feof($src)) {
            $chunk = fread($src, 65536);
            if ($chunk === false) {
                throw new RuntimeException('Ошибка чтения при скачивании файла');
            }
            $written += strlen($chunk);
            if ($written > $max_bytes) {
                throw new RuntimeException('Файл слишком большой (лимит ' . round($max_bytes / 1048576) . ' МБ)');
            }
            if (fwrite($dst, $chunk) === false) {
                throw new RuntimeException('Ошибка записи при скачивании файла');
            }
        }
    } finally {
        fclose($src);
        fclose($dst);
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

/**
 * Проверяет, что ссылка ведёт на Яндекс.Диск (защита от произвольных URL).
 */
function is_valid_yandex_url(string $public_url): bool
{
    $host = strtolower((string)(parse_url($public_url, PHP_URL_HOST) ?: ''));
    $allowed = ['yandex.ru', 'yadi.sk', 'disk.yandex.ru', 'disk.360.yandex.ru', '360.yandex.ru'];
    foreach ($allowed as $a) {
        if ($host === $a || str_ends_with($host, '.' . $a)) {
            return true;
        }
    }
    return false;
}

function do_import(string $public_url, ?PDO $existing_pdo = null): array
{
    if (!is_valid_yandex_url($public_url)) {
        throw new RuntimeException('Поддерживаются только ссылки на Яндекс.Диск (disk.yandex.ru, yadi.sk)');
    }

    // tempnam уже создаёт файл — используем его имя как есть (без утечки временных файлов)
    $tmp = tempnam(sys_get_temp_dir(), 'schedule_');
    if ($tmp === false) {
        throw new RuntimeException('Не удалось создать временный файл');
    }

    try {
        $download_url = get_yandex_download_url($public_url);
        download_file($download_url, $tmp);
        $lessons = parse_xlsx($tmp);

        if (empty($lessons)) {
            throw new RuntimeException('В файле не найдено ни одного урока — проверьте формат XLSX');
        }

        $pdo = $existing_pdo ?? get_db();
        if ($pdo === null) {
            throw new RuntimeException('БД недоступна');
        }
        init_db($pdo);

        $imported_at = gmdate('Y-m-d H:i:s'); // UTC

        // Collect dates from new lessons
        $new_dates = [];
        foreach ($lessons as &$l) {
            $di = parse_sheet_date($l['sheet_name']);
            if ($di['date']) {
                $new_dates[$di['date']] = true;
                $l['_date'] = $di['date'];
                $l['_day_of_week'] = $di['day_of_week'];
            }
        }
        unset($l);
        $date_list = array_keys($new_dates);

        // Анти-залипание: если уроки распарсились, но ни один лист не дал дату,
        // молча завершать импорт нельзя — иначе запишется md5 и импорт
        // никогда не повторится (файл ведь не изменится). Явная ошибка.
        if (empty($date_list) && !empty($lessons)) {
            throw new RuntimeException(
                'Файл прочитан (' . count($lessons) . ' уроков), но ни один лист не распознан по дате. '
                . 'Проверьте названия листов (ожидается формат «ПН 02.09»)'
            );
        }

        // BEGIN IMMEDIATE: берём блокировку записи сразу, чтобы два параллельных
        // импорта (cron + ручной) не вычислили одинаковую версию истории
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $changes = ['added' => 0, 'removed' => 0, 'room_changed' => 0];
            $history_rows = [];
            $new_lessons = [];
            $has_changes = false;

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
                    if (empty($l['_date'])) continue;
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

                // Delete & re-insert only if something actually changed
                $has_changes = !empty($history_rows);
                if ($has_changes) {
                    $del_stmt = $pdo->prepare("DELETE FROM schedule WHERE date IN ($placeholders)");
                    $del_stmt->execute($date_list);
                }
            }

            // Insert new lessons into schedule (only when changes were detected)
            $ins_stmt = $pdo->prepare("
                INSERT INTO schedule
                    (sheet_name, date, day_of_week, class_name, lesson_num,
                     time_start, time_end, subject, teacher, room, parallel_group, imported_at)
                VALUES
                    (:sheet_name, :date, :day_of_week, :class_name, :lesson_num,
                     :time_start, :time_end, :subject, :teacher, :room, :parallel_group, :imported_at)
            ");

            if ($has_changes) {
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
            }

            // Log import (переводим в статус no_changes, если данные не изменились)
            $log_stmt = $pdo->prepare("
                INSERT INTO import_log (url, filename, imported_at, lessons_count, status)
                VALUES (:url, :filename, :imported_at, :count, :status)
            ");
            $filename = basename(parse_url($public_url, PHP_URL_PATH) ?? '') ?: basename($tmp);
            $log_stmt->execute([
                ':url'          => $public_url,
                ':filename'     => $filename,
                ':imported_at'  => $imported_at,
                ':count'        => count($lessons),
                ':status'       => $has_changes ? 'ok' : 'no_changes',
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Чистим старые записи истории и журнала
        prune_old_data($pdo);

        return [
            'status'  => $has_changes ? 'ok' : 'no_changes',
            'lessons' => count($lessons),
            // Возвращаем время уже в самарском часовом поясе (для отображения)
            'date'    => utc_to_samara($imported_at),
            'changes' => $changes,
            // Даты, которые этот источник покрывает (для автоочистки устаревших недель)
            'dates'   => $date_list,
        ];

    } catch (Exception $e) {
        $pdo = get_db();
        if ($pdo !== null) {
            try {
                init_db($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO import_log (url, imported_at, status, error_message)
                    VALUES (:url, :imported_at, 'error', :error)
                ");
                $stmt->execute([
                    ':url'         => $public_url,
                    ':imported_at' => gmdate('Y-m-d H:i:s'), // UTC
                    ':error'       => $e->getMessage(),
                ]);
            } catch (Exception $logEx) {
                // ignore logging errors
            }
        }
        throw $e;
    } finally {
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
    }
}

function get_sources(?PDO $existing_pdo = null): array
{
    $pdo = $existing_pdo ?? get_db();
    if ($pdo === null) {
        return [];
    }
    init_db($pdo);
    $stmt = $pdo->query("SELECT * FROM schedule_sources ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function add_source(string $url, string $label): array
{
    $pdo = get_db();
    if ($pdo === null) {
        throw new RuntimeException('БД недоступна');
    }
    init_db($pdo);

    $url = trim($url);
    if ($url === '') {
        throw new RuntimeException('URL не может быть пустым');
    }
    if (!is_valid_yandex_url($url)) {
        throw new RuntimeException('Поддерживаются только ссылки на Яндекс.Диск (disk.yandex.ru, yadi.sk)');
    }

    $stmt = $pdo->prepare("INSERT INTO schedule_sources (url, label) VALUES (:url, :label)");
    $stmt->execute([':url' => $url, ':label' => trim($label)]);

    return ['status' => 'ok', 'id' => $pdo->lastInsertId()];
}

function delete_source(int $id): array
{
    $pdo = get_db();
    if ($pdo === null) {
        throw new RuntimeException('БД недоступна');
    }
    init_db($pdo);

    $stmt = $pdo->prepare("DELETE FROM schedule_sources WHERE id = ?");
    $stmt->execute([$id]);

    return ['status' => 'ok', 'deleted' => $stmt->rowCount()];
}

function edit_source(int $id, string $url, string $label): array
{
    $pdo = get_db();
    if ($pdo === null) {
        throw new RuntimeException('БД недоступна');
    }
    init_db($pdo);

    $url = trim($url);
    if ($url === '') {
        throw new RuntimeException('URL не может быть пустым');
    }

    $stmt = $pdo->prepare("UPDATE schedule_sources SET url = :url, label = :label WHERE id = :id");
    $stmt->execute([':url' => $url, ':label' => trim($label), ':id' => $id]);

    return ['status' => 'ok', 'updated' => $stmt->rowCount()];
}

function toggle_source(int $id): array
{
    $pdo = get_db();
    if ($pdo === null) {
        throw new RuntimeException('БД недоступна');
    }
    init_db($pdo);

    $stmt = $pdo->prepare("UPDATE schedule_sources SET is_active = NOT is_active WHERE id = ?");
    $stmt->execute([$id]);

    return ['status' => 'ok', 'toggled' => $stmt->rowCount()];
}

function update_source_status(int $id, string $status, string $error = '', ?PDO $existing_pdo = null): void
{
    $pdo = $existing_pdo ?? get_db();
    if ($pdo === null) {
        return;
    }
    init_db($pdo);

    $stmt = $pdo->prepare("
        UPDATE schedule_sources
        SET last_imported_at = ?, last_import_status = ?, last_error = ?
        WHERE id = ?
    ");
    $stmt->execute([gmdate('Y-m-d H:i:s'), $status, $error, $id]); // UTC
}

function do_import_all(): array
{
    $pdo = get_db();
    if ($pdo === null) {
        throw new RuntimeException('БД недоступна');
    }
    init_db($pdo);

    $sources = get_sources($pdo);
    $active = array_values(array_filter($sources, fn($s) => (int)$s['is_active'] === 1));

    $results = [];
    $errors = [];
    $last_id = null;
    $ok_dates = []; // даты, покрытые успешно импортированными источниками

    foreach ($active as $source) {
        $id = (int)$source['id'];
        $url = $source['url'];
        $last_id = $id;
        try {
            $result = do_import($url, $pdo);
            update_source_status($id, $result['status'] ?? 'ok', '', $pdo);
            $ok_dates = array_merge($ok_dates, $result['dates'] ?? []);
            $results[] = ['id' => $id, 'url' => $url, 'result' => $result];
        } catch (Exception $e) {
            update_source_status($id, 'error', $e->getMessage(), $pdo);
            $errors[] = ['id' => $id, 'url' => $url, 'error' => $e->getMessage()];
            $results[] = ['id' => $id, 'url' => $url, 'error' => $e->getMessage()];
        }
    }

    // Ошибка у последнего обработанного (самого свежего) источника
    $last_error = null;
    foreach ($errors as $err) {
        if ((int)$err['id'] === $last_id) {
            $last_error = $err;
        }
    }

    // Автоочистка устаревших недель: только если ВСЕ активные источники
    // импортировались без ошибок (иначе список дат неполный)
    $cleanup = ['removed_dates' => [], 'lessons' => 0];
    if (!empty($active) && empty($errors)) {
        try {
            $cleanup = cleanup_stale_dates($pdo, $ok_dates);
        } catch (Exception $e) {
            $cleanup = ['removed_dates' => [], 'lessons' => 0, 'error' => $e->getMessage()];
        }
    }

    return [
        'total'   => count($active),
        'success' => count($active) - count($errors),
        'errors'  => count($errors),
        'results' => $results,
        'error_details' => $errors,
        'last_error' => $last_error,
        'cleanup' => $cleanup,
    ];
}

/**
 * Удаляет из schedule даты, которых нет среди $keep_dates
 * (недели, исчезнувшие из всех активных источников).
 * Удалённые уроки фиксируются в schedule_history как 'removed' одной версией.
 * Вызывается только когда ВСЕ активные источники прошли полный импорт
 * в рамках одного цикла — иначе список дат неполный.
 */
function cleanup_stale_dates(PDO $pdo, array $keep_dates): array
{
    $keep = array_values(array_filter(array_unique($keep_dates)));
    if (empty($keep)) {
        return ['removed_dates' => [], 'lessons' => 0];
    }

    $placeholders = implode(',', array_fill(0, count($keep), '?'));
    $stmt = $pdo->prepare("SELECT DISTINCT date FROM schedule WHERE date NOT IN ($placeholders)");
    $stmt->execute($keep);
    $stale = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($stale)) {
        return ['removed_dates' => [], 'lessons' => 0];
    }

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $version = get_next_version($pdo);
        $removed_at = gmdate('Y-m-d H:i:s'); // UTC

        $ph = implode(',', array_fill(0, count($stale), '?'));
        $rows_stmt = $pdo->prepare("SELECT * FROM schedule WHERE date IN ($ph)");
        $rows_stmt->execute($stale);

        $hist = $pdo->prepare("
            INSERT INTO schedule_history
                (version, change_type, date, day_of_week, class_name, lesson_num,
                 time_start, time_end, subject, teacher, room, old_room, parallel_group, changed_at)
            VALUES
                (:version, 'removed', :date, :day_of_week, :class_name, :lesson_num,
                 :time_start, :time_end, :subject, :teacher, :room, '', :parallel_group, :changed_at)
        ");

        $lessons = 0;
        foreach ($rows_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hist->execute([
                ':version'       => $version,
                ':date'          => $row['date'],
                ':day_of_week'   => $row['day_of_week'],
                ':class_name'    => $row['class_name'],
                ':lesson_num'    => $row['lesson_num'],
                ':time_start'    => $row['time_start'],
                ':time_end'      => $row['time_end'],
                ':subject'       => $row['subject'],
                ':teacher'       => $row['teacher'],
                ':room'          => $row['room'],
                ':parallel_group'=> $row['parallel_group'],
                ':changed_at'    => $removed_at,
            ]);
            $lessons++;
        }

        $del = $pdo->prepare("DELETE FROM schedule WHERE date IN ($ph)");
        $del->execute($stale);

        $pdo->commit();
        return ['removed_dates' => $stale, 'lessons' => $lessons, 'version' => $version];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Запись в журнал проверок (check_log). Время — UTC.
 */
function log_check(PDO $pdo, int $source_id, string $url, string $result, string $old_md5 = '', string $new_md5 = '', string $message = ''): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO check_log (source_id, url, checked_at, result, old_md5, new_md5, message)
            VALUES (:source_id, :url, :checked_at, :result, :old_md5, :new_md5, :message)
        ");
        $stmt->execute([
            ':source_id'  => $source_id ?: null,
            ':url'        => $url,
            ':checked_at' => gmdate('Y-m-d H:i:s'),
            ':result'     => $result,
            ':old_md5'    => $old_md5,
            ':new_md5'    => $new_md5,
            ':message'    => $message,
        ]);
    } catch (Exception $e) {
        // журнал проверок не должен ломать основной цикл
    }
}

/**
 * Обновляет после проверки: время последней проверки, эталонные метаданные файла, ошибку.
 * $meta = null — метаданные не получены (обновляем только время проверки и ошибку).
 */
function update_source_check(PDO $pdo, int $id, ?array $meta = null, string $error = ''): void
{
    try {
        if ($meta !== null) {
            $stmt = $pdo->prepare("
                UPDATE schedule_sources
                SET last_checked_at = ?, last_md5 = ?, last_modified = ?, last_size = ?, last_error = ?
                WHERE id = ?
            ");
            $stmt->execute([
                gmdate('Y-m-d H:i:s'), // UTC
                $meta['md5'],
                $meta['modified'],
                $meta['size'],
                $error,
                $id,
            ]);
        } else {
            $stmt = $pdo->prepare("UPDATE schedule_sources SET last_checked_at = ?, last_error = ? WHERE id = ?");
            $stmt->execute([gmdate('Y-m-d H:i:s'), $error, $id]); // UTC
        }
    } catch (Exception $e) {
        // ignore
    }
}

/**
 * Лёгкая проверка всех активных источников: запрашивает только метаданные файла
 * (md5/modified/size) и запускает полный импорт лишь при изменении файла.
 * Страховка: раз в $force_full_interval_hours — принудительный полный импорт.
 */
function do_check_all(int $force_full_interval_hours = 6, ?PDO $existing_pdo = null): array
{
    $pdo = $existing_pdo ?? get_db();
    if ($pdo === null) {
        throw new RuntimeException('БД недоступна');
    }
    init_db($pdo);

    $sources = get_sources($pdo);
    $active = array_values(array_filter($sources, fn($s) => (int)$s['is_active'] === 1));

    $results = [];
    $errors = [];
    $last_id = null;
    $full_imports = 0;   // сколько источников прошли полный импорт в этом цикле
    $ok_dates = [];      // даты, покрытые успешно импортированными источниками

    foreach ($active as $source) {
        $id = (int)$source['id'];
        $url = $source['url'];
        $last_id = $id;

        // Страховочный полный импорт: прошло больше N часов с последнего
        $last_imported = (string)($source['last_imported_at'] ?? '');
        $last_imported_ts = $last_imported !== '' ? (strtotime($last_imported . ' UTC') ?: 0) : 0;
        $force_full = $last_imported_ts === 0
                   || (time() - $last_imported_ts) >= $force_full_interval_hours * 3600;

        try {
            $meta = get_public_meta($url);
            $old_md5 = (string)($source['last_md5'] ?? '');

            // Изменение: нет эталона (первый запуск), отличается md5,
            // либо md5 недоступен и отличаются modified/size
            $changed = false;
            if ($old_md5 === '') {
                $changed = true;
            } elseif ($meta['md5'] !== '' && $meta['md5'] !== $old_md5) {
                $changed = true;
            } elseif ($meta['md5'] === '') {
                $changed = ($source['last_modified'] ?? '') !== $meta['modified']
                        || (int)($source['last_size'] ?? -1) !== $meta['size'];
            }

            if ($force_full || $changed) {
                $reason = $force_full && $changed ? 'forced+changed'
                        : ($force_full ? 'forced' : 'changed');

                $result = do_import($url, $pdo);
                update_source_status($id, $result['status'] ?? 'ok', '', $pdo);
                update_source_check($pdo, $id, $meta);
                log_check($pdo, $id, $url, 'changed', $old_md5, $meta['md5'], $reason . ' -> ' . ($result['status'] ?? 'ok'));

                $full_imports++;
                $ok_dates = array_merge($ok_dates, $result['dates'] ?? []);

                $results[] = ['id' => $id, 'url' => $url, 'check' => 'changed', 'reason' => $reason, 'result' => $result];
            } else {
                update_source_check($pdo, $id, $meta);
                log_check($pdo, $id, $url, 'unchanged', $old_md5, $meta['md5']);

                $results[] = ['id' => $id, 'url' => $url, 'check' => 'unchanged'];
            }
        } catch (Exception $e) {
            $msg = $e->getMessage();
            update_source_check($pdo, $id, null, $msg);
            log_check($pdo, $id, $url, 'error', '', '', $msg);
            $errors[] = ['id' => $id, 'url' => $url, 'error' => $msg];
            $results[] = ['id' => $id, 'url' => $url, 'check' => 'error', 'error' => $msg];
        }
    }

    // Ошибка у последнего обработанного (самого свежего) источника
    $last_error = null;
    foreach ($errors as $err) {
        if ((int)$err['id'] === $last_id) {
            $last_error = $err;
        }
    }

    // Автоочистка устаревших недель: только когда ВСЕ активные источники
    // прошли полный импорт (иначе список дат неполный — рискуем снести чужие даты)
    $cleanup = ['removed_dates' => [], 'lessons' => 0];
    if (!empty($active) && $full_imports === count($active)) {
        try {
            $cleanup = cleanup_stale_dates($pdo, $ok_dates);
        } catch (Exception $e) {
            $cleanup = ['removed_dates' => [], 'lessons' => 0, 'error' => $e->getMessage()];
        }
    }

    // Чистим логи и историю старше 10 дней — в том числе в циклах «без изменений»,
    // когда do_import() не вызывается вовсе
    try {
        prune_old_data($pdo, 10);
    } catch (Exception $e) {
        // очистка не должна ломать проверку
    }

    return [
        'total'   => count($active),
        'success' => count($active) - count($errors),
        'errors'  => count($errors),
        'results' => $results,
        'error_details' => $errors,
        'last_error' => $last_error,
        'cleanup' => $cleanup,
    ];
}
