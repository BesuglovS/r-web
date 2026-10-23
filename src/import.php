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

    $ctx = stream_context_create(['http' => [
        'timeout' => 30,
        'ignore_errors' => true, // читать тело ответа даже при 4xx/5xx (например, 429)
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]]);
    $response = @file_get_contents($api_url, false, $ctx);
    if ($response === false) {
        throw new RuntimeException('Не удалось получить информацию о файле с Яндекс-Диска');
    }

    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int)$m[1];
        }
    }

    $data = json_decode($response, true);
    if ($status !== 200) {
        // Яндекс лимитирует частые запросы (429 Too Many Requests) — показываем
        // код и описание, чтобы отличать rate-limit от реальной ошибки
        $detail = $data['message'] ?? ($data['description'] ?? 'нет описания');
        if ($status === 429) {
            throw new RuntimeException("Яндекс.Диск: HTTP 429 (слишком частые запросы, повторите позже): {$detail}");
        }
        throw new RuntimeException("Яндекс.Диск вернул HTTP {$status} при получении ссылки на скачивание: {$detail}");
    }

    if (!$data || empty($data['href'])) {
        throw new RuntimeException('Неверный ответ от Яндекс-Диска: ' . ($response ?: 'пустой ответ'));
    }

    // Защита от SSRF: принимаем только HTTPS-ссылки на загрузчики Яндекса.
    // ВАЖНО: проверяем СУФФИКС хоста — str_contains пропустил бы
    // "evil-yandex.attacker.com". Та же проверка повторяется для каждого
    // редиректа в download_file().
    $href = (string)$data['href'];
    if (!is_allowed_download_host($href)) {
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
        'ignore_errors' => true,
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
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

/**
 * Проверяет, что хост скачивания — загрузчик Яндекса (SSRF-защита).
 * Применяется и к исходной ссылке, и к КАЖДОМУ редиректу:
 * follow_location выключен, редиректы обрабатываются вручную ниже.
 */
function is_allowed_download_host(string $url): bool
{
    if (!str_starts_with($url, 'https://')) {
        return false;
    }
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    $allowed_hosts = ['yandex.net', 'yandex.ru', 'yadi.sk'];
    foreach ($allowed_hosts as $suffix) {
        if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
            return true;
        }
    }
    return false;
}

function download_file(string $url, string $dest, int $max_bytes = 52428800): void
{
    // Ручное следование редиректам: каждый промежуточный URL повторно
    // проходит SSRF-проверку. follow_location=0 нужен, иначе file_get_contents
    // молча ушёл бы по редиректу куда угодно (внутренние адреса и т.п.).
    $max_redirects = 5;
    $current_url = $url;

    for ($redirect = 0; $redirect <= $max_redirects; $redirect++) {
        if (!is_allowed_download_host($current_url)) {
            throw new RuntimeException('Ссылка для скачивания ведёт на недоверенный хост (SSRF-защита)');
        }

        $ctx = stream_context_create(['http' => [
            'timeout' => 120,
            'follow_location' => 0,
            'max_redirects' => 1,
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]]);
        $src = @fopen($current_url, 'rb', false, $ctx);
        if ($src === false) {
            throw new RuntimeException('Не удалось скачать файл');
        }

        $code = 0;
        $location = '';
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int)$m[1];
            } elseif (preg_match('#^Location:\s*(\S+)#i', $h, $m)) {
                $location = trim($m[1]);
            }
        }

        if ($code >= 300 && $code < 400 && $location !== '') {
            fclose($src);
            // Location может быть относительным — разворачиваем относительно текущего URL
            if (str_starts_with($location, '//')) {
                // protocol-relative: //host/path → https://host/path
                $next = 'https:' . $location;
            } elseif (preg_match('#^https?://#i', $location)) {
                $next = $location;
            } else {
                $p = parse_url($current_url);
                $next = 'https://' . ($p['host'] ?? '')
                      . (isset($p['port']) ? ':' . $p['port'] : '')
                      . '/' . ltrim($location, '/');
            }
            $current_url = $next;
            continue;
        }

        if ($code >= 400) {
            fclose($src);
            throw new RuntimeException("Не удалось скачать файл: HTTP $code");
        }

        // Успешный ответ — потоковое копирование
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
        return;
    }

    throw new RuntimeException('Слишком много редиректов при скачивании файла');
}

/**
 * Разбивает список дат на батчи для IN (...): SQLite до версии 3.32 имеет
 * лимит 999 переменных на запрос — большой учебный год на нескольких
 * источниках может его превысить.
 */
function date_batches(array $dates, int $size = 500): array
{
    $dates = array_values(array_unique(array_filter($dates)));
    if (empty($dates)) {
        return [];
    }
    return array_chunk($dates, $size);
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
 * Ключ полной строки урока: lesson_key() + кабинет. Нужен только для
 * схлопывания буквальных дублей источника (одна и та же запись повторена в
 * XLSX несколько раз). В lesson_key кабинета нет намеренно (смена кабинета
 * отслеживается отдельно), поэтому одинаковые уроки в разных кабинетах —
 * не дубли и должны сохраниться.
 */
function lesson_full_key(array $l): string
{
    return lesson_key($l) . '|' . trim((string)($l['room'] ?? ''));
}

/**
 * Схлопывает буквальные дубли строк расписания (совпадают все поля, включая
 * кабинет и параллельную группу), оставляя первую запись. Дубли в исходном
 * XLSX иначе вставляются в schedule/schedule_archive столько раз, сколько
 * строк в файле, а diff их не видит (ключ один) и никогда не удаляет.
 */
function dedupe_lessons(array $lessons): array
{
    $unique = [];
    foreach ($lessons as $l) {
        $unique[lesson_full_key($l)] = $l;
    }
    return array_values($unique);
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
        // импорта (cron + ручной) не вычислили одинаковую версию истории.
        // ВАЖНО: PDO не отслеживает транзакцию, открытую через exec('BEGIN') —
        // inTransaction() вернёт false, а $pdo->commit() бросит
        // «There is no active transaction». Поэтому COMMIT/ROLLBACK тоже
        // выполняем на уровне SQLite через exec().
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $changes = ['added' => 0, 'removed' => 0, 'room_changed' => 0];
            $history_rows = [];
            $new_lessons = [];
            $has_changes = false;

            if (!empty($date_list)) {
                // Get current lessons for affected dates (батчами — лимит переменных SQLite)
                $current = [];
                foreach (date_batches($date_list) as $batch) {
                    $placeholders = implode(',', array_fill(0, count($batch), '?'));
                    $cur_stmt = $pdo->prepare(
                        "SELECT * FROM schedule WHERE date IN ($placeholders) ORDER BY date, lesson_num, class_name"
                    );
                    $cur_stmt->execute($batch);
                    foreach ($cur_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $current[] = $row;
                    }
                }

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

                // Буквальные дубли строк источника схлопываем ДО сравнения и
                // вставки: иначе они попадают в schedule по числу копий в XLSX,
                // а diff (ключ без кабинета) их не замечает.
                $new_lessons = dedupe_lessons($new_lessons);

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

                // Delete & re-insert only if something actually changed —
                // и только затронутые пары (дата, класс): у неизменённых уроков
                // imported_at сохраняется, чтобы «Изменено» в интерфейсе отражало
                // время изменения именно показываемого расписания (класс/дата)
                $has_changes = !empty($history_rows);
                $changed_pairs = [];
                foreach ($history_rows as $hr) {
                    $changed_pairs[$hr['date'] . "\x1F" . $hr['class_name']] = true;
                }
                if ($has_changes) {
                    $del_pair = $pdo->prepare("DELETE FROM schedule WHERE date = :date AND class_name = :class_name");
                    foreach (array_keys($changed_pairs) as $pair) {
                        $pair_parts = explode("\x1F", $pair);
                        $del_pair->execute([':date' => $pair_parts[0], ':class_name' => $pair_parts[1]]);
                    }
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
                    // Уроки, чья пара (дата, класс) не менялась, не трогаем —
                    // их imported_at остаётся прежним (показывается честное время)
                    if (!isset($changed_pairs[$l['date'] . "\x1F" . $l['class_name']])) continue;
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

            // БД-архив: полная замена уроков этой недели (источник статистики
            // педагогов). Пишется в той же транзакции, поэтому откат импорта
            // откатывает и архив. XLSX-файл в каталоге архива — резерв.
            if (!empty($new_lessons)) {
                $src_id = 0;
                try {
                    $q = $pdo->prepare("SELECT id FROM schedule_sources WHERE url = ?");
                    $q->execute([$public_url]);
                    $src_id = (int)($q->fetchColumn() ?: 0);
                } catch (Exception $ignored) {
                    // источника нет — неделя идентифицируется диапазоном дат
                }
                archive_lessons($pdo, $src_id, archive_week_key($src_id, $date_list), $new_lessons, $imported_at);
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

            $pdo->exec('COMMIT');
        } catch (Exception $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Exception $ignored) {
                // транзакция уже закрыта самим SQLite (авто-rollback при ошибке)
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

    $dup = $pdo->prepare("SELECT id FROM schedule_sources WHERE url = :url");
    $dup->execute([':url' => $url]);
    if ($dup->fetchColumn() !== false) {
        throw new RuntimeException('Источник с такой ссылкой уже добавлен');
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

    $src = null;
    foreach (get_sources($pdo) as $s) {
        if ((int)$s['id'] === $id) {
            $src = $s;
            break;
        }
    }
    if ($src === null) {
        throw new RuntimeException('Источник не найден');
    }

    // Файлы с Яндекс.Диска удаляются, поэтому перед удалением источника
    // обязательно сохраняем его последнюю версию в архив. Если скачать не
    // удалось и архива ещё нет — источник НЕ удаляем (иначе потеряем данные).
    try {
        archive_source_file($src, $pdo);
    } catch (Exception $e) {
        throw new RuntimeException(
            'Нельзя удалить источник: не удалось сохранить файл в архив (' . $e->getMessage() . '). '
            . 'Проверьте ссылку или сначала импортируйте источник.'
        );
    }

    // Строки schedule_archive и файл архива не трогаем: это резервная копия,
    // по ней неделя удалённого источника остаётся доступной.
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

    // last_dates сбрасываем: даты принадлежали старому файлу-источнику
    $dup = $pdo->prepare("SELECT id FROM schedule_sources WHERE url = :url AND id != :id");
    $dup->execute([':url' => $url, ':id' => $id]);
    if ($dup->fetchColumn() !== false) {
        throw new RuntimeException('Другой источник уже использует эту ссылку');
    }

    $stmt = $pdo->prepare("UPDATE schedule_sources SET url = :url, label = :label, last_dates = NULL WHERE id = :id");
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

    $src = null;
    foreach (get_sources($pdo) as $s) {
        if ((int)$s['id'] === $id) {
            $src = $s;
            break;
        }
    }
    if ($src === null) {
        throw new RuntimeException('Источник не найден');
    }

    $is_active = (int)$src['is_active'] === 1;

    // Перед выключением активного источника обязательно сохраняем его файл
    // в архив: после выключения обновления файл может исчезнуть с
    // Яндекс.Диска. При ошибке скачивания источник не выключается.
    if ($is_active) {
        archive_source_file($src, $pdo);
    }

    $stmt = $pdo->prepare("UPDATE schedule_sources SET is_active = NOT is_active WHERE id = ?");
    $stmt->execute([$id]);

    return ['status' => 'ok', 'toggled' => $stmt->rowCount(), 'now_active' => !$is_active];
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

/**
 * Запоминает даты, покрытые последним успешным полным импортом источника (JSON).
 * Нужно do_check_all(): unchanged-источник подтверждает свои даты без импорта,
 * и они должны участвовать в cleanup_stale_dates().
 */
function update_source_dates(PDO $pdo, int $id, array $dates): void
{
    try {
        $stmt = $pdo->prepare("UPDATE schedule_sources SET last_dates = ? WHERE id = ?");
        $stmt->execute([json_encode(array_values(array_unique(array_filter($dates)))), $id]);
    } catch (Exception $e) {
        // не должно ломать импорт
    }
}

/**
 * Каталог архивного хранения исходных XLSX. Приоритет:
 *  1) переменная окружения SCHEDULE_ARCHIVE_DIR (или из .env);
 *  2) <корень сайта>/archive/ (прод: на уровень выше public/, вне docroot);
 *  3) <проект>/archive/ (локальная разработка).
 */
function get_archive_dir(): string
{
    $env = getenv('SCHEDULE_ARCHIVE_DIR');
    if ($env === false || $env === '') {
        $env = load_env_value('SCHEDULE_ARCHIVE_DIR') ?? '';
    }
    if ($env !== '') {
        $dir = rtrim($env, "/\\");
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    // Прод: каталог рядом с docroot (создаёт deploy.ps1) — вне доступа из веба
    $site = dirname(__DIR__, 2) . '/archive';
    if (is_dir($site)) {
        return $site;
    }

    // Fallback для локальной разработки
    $fallback = dirname(__DIR__) . '/archive';
    @mkdir($fallback, 0775, true);
    return $fallback;
}

/**
 * Ключ уже сохранённого архива источника (префикс "<id>_" в имени файла).
 * У одного источника (одной недели) в архиве может быть только один файл.
 */
function archive_exists(string $dir, int $id): ?string
{
    $found = glob($dir . '/' . $id . '_*.xlsx');
    return !empty($found) ? $found[0] : null;
}

/**
 * Скачивает XLSX источника в каталог архивного хранения.
 * Имя файла: "<id>_<описание>_<даты>.xlsx".
 *
 * Для одного источника (одной недели) хранится РОВНО ОДИН архив — самый
 * последний: если файл на Диске изменился, старый архив заменяется новым
 * (прежние файлы этого id удаляются). Неизменённый файл не перекачивается
 * (сверка md5 через лёгкий запрос метаданных).
 *
 * Бросает RuntimeException при неудачном скачивании (архив обязателен перед
 * выключением источника).
 */
function archive_source_file(array $source, ?PDO $existing_pdo = null): array
{
    $pdo = $existing_pdo ?? get_db();
    if ($pdo === null) {
        throw new RuntimeException('БД недоступна');
    }
    init_db($pdo);

    $id = (int)$source['id'];
    $url = (string)$source['url'];
    $label = trim((string)($source['label'] ?? ''));

    $dir = get_archive_dir();
    if (!is_dir($dir)) {
        throw new RuntimeException('Не удалось создать каталог архива: ' . $dir);
    }

    $existing = archive_exists($dir, $id);

    if (!is_valid_yandex_url($url)) {
        throw new RuntimeException('Поддерживаются только ссылки на Яндекс.Диск (disk.yandex.ru, yadi.sk)');
    }

    // Если архив уже есть — качаем заново только при изменении файла на Диске.
    // Лёгкая проверка метаданных экономит трафик; при недоступности метаданных
    // оставляем имеющийся архив (в т.ч. чтобы не блокировать выключение источника).
    if ($existing !== null) {
        try {
            $meta = get_public_meta($url);
            $existing_md5 = (string)@md5_file($existing);
            if ($meta['md5'] !== '' && $existing_md5 !== '' && $meta['md5'] === $existing_md5) {
                return ['status' => 'skipped', 'path' => $existing];
            }
        } catch (Exception $e) {
            return ['status' => 'skipped', 'path' => $existing];
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'archive_');
    if ($tmp === false) {
        throw new RuntimeException('Не удалось создать временный файл');
    }

    try {
        $download_url = get_yandex_download_url($url);
        download_file($download_url, $tmp);

        // Даты для имени берём из содержимого файла; при ошибке разбора —
        // из last_dates источника. Архивация важнее красивых метаданных,
        // поэтому провал парсинга не прерывает сохранение.
        $dates = [];
        try {
            foreach (parse_xlsx($tmp) as $lesson) {
                $d = parse_sheet_date((string)$lesson['sheet_name'])['date'];
                if ($d !== '') {
                    $dates[$d] = true;
                }
            }
        } catch (Exception $e) {
            // ignore — имя соберём из last_dates или 'unknown'
        }
        if (empty($dates)) {
            $stored = json_decode((string)($source['last_dates'] ?? '[]'), true);
            if (is_array($stored)) {
                foreach ($stored as $d) {
                    if ((string)$d !== '') {
                        $dates[(string)$d] = true;
                    }
                }
            }
        }

        $dates = array_keys($dates);
        sort($dates);
        $date_part = empty($dates)
            ? 'unknown'
            : ($dates[0] . (count($dates) > 1 ? '_' . $dates[count($dates) - 1] : ''));

        $safe_label = trim((string)preg_replace('/[^\p{L}\p{N}]+/u', '-', $label), '-');
        if ($safe_label === '') {
            $safe_label = 'schedule';
        }

        $target = $dir . '/' . $id . '_' . $safe_label . '_' . $date_part . '.xlsx';

        // Сохраняем новую версию, НЕ удаляя старую заранее: если запись не
        // удалась, прежний архив остаётся на месте. rename на Windows не
        // перезаписывает существующую цель — тогда используем copy (перезапись).
        if (!@rename($tmp, $target)) {
            if (!@copy($tmp, $target)) {
                throw new RuntimeException('Не удалось сохранить архив: ' . $target);
            }
            @unlink($tmp);
        }

        // Только после успешной записи новой версии удаляем прежние файлы
        // этого источника с другими именами — остаётся ровно один, свежий
        foreach (glob($dir . '/' . $id . '_*.xlsx') ?: [] as $old) {
            if ($old !== $target) {
                @unlink($old);
            }
        }

        return ['status' => 'ok', 'path' => $target];
    } finally {
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
    }
}

/**
 * Скачивает в архив все источники (включая выключенные). У каждого источника
 * хранится один последний архив; неизменённые файлы не перекачиваются.
 */
function archive_all_sources(): array
{
    $pdo = get_db();
    if ($pdo === null) {
        throw new RuntimeException('БД недоступна');
    }
    init_db($pdo);

    $sources = get_sources($pdo);
    $saved = 0;
    $skipped = 0;
    $errors = [];

    foreach ($sources as $src) {
        try {
            $result = archive_source_file($src, $pdo);
            if (($result['status'] ?? '') === 'skipped') {
                $skipped++;
            } else {
                $saved++;
            }
        } catch (Exception $e) {
            $errors[] = [
                'id'    => (int)$src['id'],
                'label' => (string)($src['label'] ?? ''),
                'error' => $e->getMessage(),
            ];
        }
    }

    return [
        'total'   => count($sources),
        'saved'   => $saved,
        'skipped' => $skipped,
        'errors'  => $errors,
    ];
}

/**
 * Автоархивация после успешного импорта: сохраняет последнюю версию XLSX
 * в каталог архива (файл на Диске могут удалить в любой момент).
 *
 * Ошибку не пробрасывает: импорт уже состоялся, данные в БД корректны,
 * поэтому сбой архивации только фиксируется в журнале проверок (check_log)
 * и возвращается строкой. Возвращает null, если архив сохранён/актуален.
 */
function try_archive_source(PDO $pdo, array $source): ?string
{
    $id = (int)($source['id'] ?? 0);
    $url = (string)($source['url'] ?? '');
    try {
        archive_source_file($source, $pdo);
        return null;
    } catch (Exception $e) {
        $msg = $e->getMessage();
        log_check($pdo, $id, $url, 'archive_error', '', '', $msg);
        return $msg;
    }
}

/**
 * Ключ архивной недели: по источнику ('s<id>') либо по диапазону дат для
 * ad-hoc импорта без источника ('d_<первая>_<последняя>').
 */
function archive_week_key(int $source_id, array $dates): string
{
    if ($source_id > 0) {
        return 's' . $source_id;
    }
    $dates = array_values(array_filter(array_unique($dates)));
    sort($dates);
    $first = $dates[0] ?? 'unknown';
    $last = $dates[count($dates) - 1] ?? 'unknown';
    return 'd_' . $first . '_' . $last;
}

/**
 * Полностью заменяет БД-архив одной недели (week_key) уроками источника.
 * $lessons — строки с полями date, day_of_week, class_name, lesson_num,
 * time_start, time_end, subject, teacher, room, parallel_group.
 * Возвращает количество записанных уроков.
 */
function archive_lessons(PDO $pdo, int $source_id, string $week_key, array $lessons, string $archived_at): int
{
    $pdo->prepare("DELETE FROM schedule_archive WHERE week_key = ?")->execute([$week_key]);
    if (empty($lessons)) {
        return 0;
    }

    // Буквальные дубли строк источника (в т.ч. после бэкфилла из XLSX) не
    // пишем в архив: иначе статистика педагогов считает урок несколько раз.
    $lessons = dedupe_lessons($lessons);

    $stmt = $pdo->prepare("
        INSERT INTO schedule_archive
            (source_id, week_key, date, day_of_week, class_name, lesson_num,
             time_start, time_end, subject, teacher, room, parallel_group, archived_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $n = 0;
    foreach ($lessons as $l) {
        $stmt->execute([
            $source_id,
            $week_key,
            (string)$l['date'],
            (string)$l['day_of_week'],
            (string)$l['class_name'],
            (int)$l['lesson_num'],
            (string)$l['time_start'],
            (string)$l['time_end'],
            (string)$l['subject'],
            (string)$l['teacher'],
            (string)($l['room'] ?? ''),
            (string)($l['parallel_group'] ?? ''),
            $archived_at,
        ]);
        $n++;
    }
    return $n;
}

/**
 * Первичное наполнение schedule_archive из файлов архива (XLSX) — для недель,
 * импортированных до появления БД-архива. Файл резерва остаётся на месте.
 */
function backfill_archive_from_files(?PDO $existing_pdo = null): array
{
    $pdo = $existing_pdo ?? get_db();
    if ($pdo === null) {
        throw new RuntimeException('БД недоступна');
    }
    init_db($pdo);

    $dir = get_archive_dir();
    $files = glob($dir . '/*.xlsx') ?: [];
    usort($files, static function (string $a, string $b): int {
        $pa = preg_match('/^(\d+)_/', basename($a), $ma) ? (int)$ma[1] : PHP_INT_MAX;
        $pb = preg_match('/^(\d+)_/', basename($b), $mb) ? (int)$mb[1] : PHP_INT_MAX;
        return $pa <=> $pb ?: strcmp(basename($a), basename($b));
    });

    $weeks = 0;
    $lessons_count = 0;
    $errors = [];
    $archived_at = gmdate('Y-m-d H:i:s');

    foreach ($files as $file) {
        $base = basename($file);
        $source_id = preg_match('/^(\d+)_/', $base, $m) ? (int)$m[1] : 0;

        try {
            $parsed = parse_xlsx($file);
        } catch (Exception $e) {
            $errors[] = ['file' => $base, 'error' => $e->getMessage()];
            continue;
        }

        $dates = [];
        $rows = [];
        foreach ($parsed as $l) {
            $di = parse_sheet_date((string)($l['sheet_name'] ?? ''));
            if (($di['date'] ?? '') === '') {
                continue;
            }
            $dates[$di['date']] = true;
            $rows[] = [
                'date'           => $di['date'],
                'day_of_week'    => $di['day_of_week'],
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

        if (empty($rows)) {
            continue;
        }

        $week_key = archive_week_key($source_id, array_keys($dates));
        archive_lessons($pdo, $source_id, $week_key, $rows, $archived_at);
        $weeks++;
        $lessons_count += count($rows);
    }

    return [
        'files'   => count($files),
        'weeks'   => $weeks,
        'lessons' => $lessons_count,
        'errors'  => $errors,
    ];
}

/**
 * Возвращает в живую таблицу schedule уроки прошедших недель из БД-архива
 * (schedule_archive). Нужно после того, как старые недели были вычищены
 * автоочисткой: файлы на Яндекс.Диске удаляются, а архив остаётся.
 *
 * Восстанавливаются только даты, которых сейчас НЕТ в schedule и которые
 * принадлежат неактивным/удалённым источникам (или ad-hoc импорту,
 * source_id = 0). Даты активных источников не трогаем — ими управляет импорт.
 *
 * Дубликаты слотов (одна дата могла попасть в несколько недель архива)
 * схлопываются по ключу date|class|lesson_num|time_start|group — остаётся
 * самая свежая запись по archived_at. История изменения не пишется: это
 * восстановление резерва, а не изменение расписания.
 *
 * $dry_run = true — только посчитать, ничего не менять (ROLLBACK).
 */
function restore_schedule_from_archive(PDO $pdo, bool $dry_run = false): array
{
    init_db($pdo);

    $rows = $pdo->query(
        "SELECT a.id, a.source_id, a.date, a.day_of_week, a.class_name, a.lesson_num,
                a.time_start, a.time_end, a.subject, a.teacher, a.room,
                a.parallel_group, a.archived_at
         FROM schedule_archive a
         WHERE a.date NOT IN (SELECT date FROM schedule)
           AND (a.source_id = 0
                OR a.source_id NOT IN (SELECT id FROM schedule_sources WHERE is_active = 1))
         ORDER BY a.date, a.class_name, a.time_start, a.lesson_num, a.id"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Время последнего импорта источника — честное «Изменено» для его недели.
    // archived_at у недель, залитых бэкфиллом из файлов, равен времени бэкфилла.
    $source_imported = [];
    foreach ($pdo->query("SELECT id, last_imported_at FROM schedule_sources") as $s) {
        $t = (string)($s['last_imported_at'] ?? '');
        if ($t !== '') {
            $source_imported[(int)$s['id']] = $t;
        }
    }

    // Схлопываем дубликаты слотов: свежая запись (archived_at, затем id) важнее
    $by_date = [];
    foreach ($rows as $r) {
        $key = implode("\x1F", [
            $r['date'], $r['class_name'], $r['lesson_num'],
            $r['time_start'], (string)($r['parallel_group'] ?? ''),
        ]);
        if (!isset($by_date[$r['date']][$key])) {
            $by_date[$r['date']][$key] = $r;
            continue;
        }
        $prev = $by_date[$r['date']][$key];
        if ((string)$r['archived_at'] > (string)$prev['archived_at']
            || ((string)$r['archived_at'] === (string)$prev['archived_at'] && (int)$r['id'] > (int)$prev['id'])) {
            $by_date[$r['date']][$key] = $r;
        }
    }

    $weekday_short = [1 => 'ПН', 2 => 'ВТ', 3 => 'СР', 4 => 'ЧТ', 5 => 'ПТ', 6 => 'СБ', 7 => 'ВС'];

    $ins = $pdo->prepare(
        "INSERT INTO schedule
            (sheet_name, date, day_of_week, class_name, lesson_num, time_start,
             time_end, subject, teacher, room, parallel_group, imported_at)
         VALUES (:sheet_name, :date, :day_of_week, :class_name, :lesson_num, :time_start,
                 :time_end, :subject, :teacher, :room, :parallel_group, :imported_at)"
    );

    $restored_dates = [];
    $restored_lessons = 0;
    $now = gmdate('Y-m-d H:i:s');

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        foreach ($by_date as $date => $lessons) {
            $dt = DateTime::createFromFormat('Y-m-d', (string)$date);
            $short = ($dt && $dt->format('Y-m-d') === $date)
                ? ($weekday_short[(int)$dt->format('N')] ?? '')
                : '';
            $sheet_name = trim($short . ' ' . ($dt ? $dt->format('d.m') : ''));

            foreach ($lessons as $l) {
                // «Изменено» = время последнего импорта недели (источника);
                // иначе — время записи в архив, иначе — сейчас
                $sid = (int)($l['source_id'] ?? 0);
                if ($sid > 0 && isset($source_imported[$sid])) {
                    $imported_at = $source_imported[$sid];
                } else {
                    $imported_at = (string)($l['archived_at'] ?? '') !== '' ? (string)$l['archived_at'] : $now;
                }

                $ins->execute([
                    ':sheet_name'     => $sheet_name,
                    ':date'           => $l['date'],
                    ':day_of_week'    => $l['day_of_week'],
                    ':class_name'     => $l['class_name'],
                    ':lesson_num'     => (int)$l['lesson_num'],
                    ':time_start'     => $l['time_start'],
                    ':time_end'       => $l['time_end'],
                    ':subject'        => $l['subject'],
                    ':teacher'        => $l['teacher'],
                    ':room'           => (string)($l['room'] ?? ''),
                    ':parallel_group' => $l['parallel_group'],
                    ':imported_at'    => $imported_at,
                ]);
                $restored_lessons++;
            }
            $restored_dates[] = (string)$date;
        }

        $pdo->exec($dry_run ? 'ROLLBACK' : 'COMMIT');
    } catch (Exception $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Exception $ignored) {
            // транзакция уже закрыта самим SQLite (авто-rollback при ошибке)
        }
        throw $e;
    }

    return [
        'dry_run'        => $dry_run,
        'dates'          => $restored_dates,
        'lessons'        => $restored_lessons,
    ];
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
    $archive_errors = []; // ошибки автоархивации (файл не сохранён, импорт не ломаем)
    $ok_dates = []; // даты, покрытые успешно импортированными источниками

    foreach ($active as $source) {
        $id = (int)$source['id'];
        $url = $source['url'];
        try {
            $result = do_import($url, $pdo);
            update_source_status($id, $result['status'] ?? 'ok', '', $pdo);
            update_source_dates($pdo, $id, $result['dates'] ?? []);
            // Резервная копия: файл на Диске может быть удалён в любой момент,
            // поэтому архив обновляем сразу после каждого импорта.
            $archive_error = try_archive_source($pdo, $source);
            if ($archive_error !== null) {
                $archive_errors[$id] = $archive_error;
            }
            $ok_dates = array_merge($ok_dates, $result['dates'] ?? []);
            $results[] = ['id' => $id, 'url' => $url, 'result' => $result];
        } catch (Exception $e) {
            update_source_status($id, 'error', $e->getMessage(), $pdo);
            $errors[] = ['id' => $id, 'url' => $url, 'error' => $e->getMessage()];
            $results[] = ['id' => $id, 'url' => $url, 'error' => $e->getMessage()];
        }
    }

    // Ошибки ВСЕХ источников (не только последнего): иначе при успешном
    // последнем источнике ошибка первого остаётся без уведомления.

    // Автоочистка устаревших недель: только если ВСЕ активные источники
    // импортировались без ошибок (иначе список дат неполный)
    $cleanup = ['removed_dates' => [], 'lessons' => 0];
    if (!empty($active) && empty($errors)) {
        try {
            $cleanup = cleanup_stale_dates($pdo, collect_keep_dates($pdo, $ok_dates));
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
        'last_error' => $errors ? $errors[count($errors) - 1] : null,
        'archive_errors' => $archive_errors,
        'cleanup' => $cleanup,
    ];
}

/**
 * Импорт только последнего (самого нового по id) активного источника.
 * Автоочистку дат не выполняет: она требует полного импорта всех источников.
 */
function do_import_last(): array
{
    $pdo = get_db();
    if ($pdo === null) {
        throw new RuntimeException('БД недоступна');
    }
    init_db($pdo);

    $sources = get_sources($pdo);
    $active = array_values(array_filter($sources, fn($s) => (int)$s['is_active'] === 1));

    if (empty($active)) {
        throw new RuntimeException('Нет активных источников');
    }

    $source = $active[count($active) - 1];
    $id = (int)$source['id'];
    $url = $source['url'];

    try {
        $result = do_import($url, $pdo);
        update_source_status($id, $result['status'] ?? 'ok', '', $pdo);
        update_source_dates($pdo, $id, $result['dates'] ?? []);
        // Резервная копия последней версии (файл с Диска могут удалить)
        $archive_error = try_archive_source($pdo, $source);

        return [
            'total'   => 1,
            'success' => 1,
            'errors'  => 0,
            'results' => [['id' => $id, 'url' => $url, 'result' => $result, 'archive_error' => $archive_error]],
            'error_details' => [],
            'last_error' => null,
            'archive_errors' => $archive_error !== null ? [$id => $archive_error] : [],
            'cleanup' => ['removed_dates' => [], 'lessons' => 0],
        ];
    } catch (Exception $e) {
        update_source_status($id, 'error', $e->getMessage(), $pdo);
        $errors = [['id' => $id, 'url' => $url, 'error' => $e->getMessage()]];

        return [
            'total'   => 1,
            'success' => 0,
            'errors'  => 1,
            'results' => [['id' => $id, 'url' => $url, 'error' => $e->getMessage()]],
            'error_details' => $errors,
            'last_error' => $errors[0],
            'cleanup' => ['removed_dates' => [], 'lessons' => 0],
        ];
    }
}

/**
 * Полный список дат, которые нельзя удалять из schedule.
 *
 * Кроме дат активных источников ($active_dates — результаты импортов и
 * last_dates unchanged-источников) сохраняем недели выключенных и удалённых
 * источников: файлы на Яндекс.Диске удаляются, поэтому закончившаяся неделя
 * должна оставаться на сайте навсегда (резерв — schedule_archive/XLSX-архив).
 *
 * Источники дат:
 *   1) $active_dates;
 *   2) last_dates всех выключенных источников (страховка для недель,
 *      заархивированных до появления schedule_archive);
 *   3) schedule_archive для всего, что не является активным источником:
 *      выключенные (is_active = 0), удалённые (нет строки) и ad-hoc импорт
 *      по ссылке без источника (source_id = 0).
 */
function collect_keep_dates(PDO $pdo, array $active_dates): array
{
    $keep = array_values(array_unique(array_filter($active_dates)));

    try {
        $inactive = $pdo->query(
            "SELECT last_dates FROM schedule_sources WHERE is_active = 0"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($inactive as $json) {
            $dates = json_decode((string)$json, true);
            if (is_array($dates)) {
                foreach ($dates as $d) {
                    $d = (string)$d;
                    if ($d !== '') {
                        $keep[] = $d;
                    }
                }
            }
        }

        $archived = $pdo->query(
            "SELECT DISTINCT date FROM schedule_archive
             WHERE source_id = 0
                OR source_id NOT IN (SELECT id FROM schedule_sources WHERE is_active = 1)"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($archived as $d) {
            $keep[] = (string)$d;
        }
    } catch (Exception $e) {
        // Ошибка чтения — лучше сохранить больше дат, чем снести лишние,
        // поэтому возвращаем хотя бы даты активных источников.
    }

    return array_values(array_unique(array_filter($keep)));
}

/**
 * Удаляет из schedule даты, которых нет среди $keep_dates
 * (даты, исчезнувшие из ФАЙЛОВ активных источников).
 * Недели выключенных/удалённых источников сохраняются — см. collect_keep_dates().
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

    // Временная таблица вместо NOT IN (...): keep_dates может превышать лимит
    // переменных SQLite. TEMP-таблица живёт в рамках соединения — безопасно.
    $pdo->exec("CREATE TEMP TABLE IF NOT EXISTS _keep_dates (date TEXT PRIMARY KEY)");
    $pdo->exec("DELETE FROM _keep_dates");
    $ins_keep = $pdo->prepare("INSERT OR IGNORE INTO _keep_dates (date) VALUES (?)");
    foreach ($keep as $d) {
        $ins_keep->execute([$d]);
    }

    $stale = $pdo->query(
        "SELECT DISTINCT date FROM schedule WHERE date NOT IN (SELECT date FROM _keep_dates)"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (empty($stale)) {
        return ['removed_dates' => [], 'lessons' => 0];
    }

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $version = get_next_version($pdo);
        $removed_at = gmdate('Y-m-d H:i:s'); // UTC

        // Выборка удаляемых уроков батчами (лимит переменных SQLite)
        $rows = [];
        foreach (date_batches($stale) as $batch) {
            $ph = implode(',', array_fill(0, count($batch), '?'));
            $rows_stmt = $pdo->prepare("SELECT * FROM schedule WHERE date IN ($ph)");
            $rows_stmt->execute($batch);
            foreach ($rows_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = $row;
            }
        }

        $hist = $pdo->prepare("
            INSERT INTO schedule_history
                (version, change_type, date, day_of_week, class_name, lesson_num,
                 time_start, time_end, subject, teacher, room, old_room, parallel_group, changed_at)
            VALUES
                (:version, 'removed', :date, :day_of_week, :class_name, :lesson_num,
                 :time_start, :time_end, :subject, :teacher, :room, '', :parallel_group, :changed_at)
        ");

        $lessons = 0;
        foreach ($rows as $row) {
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

        foreach (date_batches($stale) as $batch) {
            $ph = implode(',', array_fill(0, count($batch), '?'));
            $del = $pdo->prepare("DELETE FROM schedule WHERE date IN ($ph)");
            $del->execute($batch);
        }

        $pdo->exec('COMMIT');
        return ['removed_dates' => $stale, 'lessons' => $lessons, 'version' => $version];
    } catch (Exception $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Exception $ignored) {
            // транзакция уже закрыта самим SQLite (авто-rollback при ошибке)
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
    $archive_errors = []; // ошибки автоархивации (импорт при этом успешен)
    $covered = 0;        // источники, чьи даты подтверждены (импорт ИЛИ unchanged)
    $ok_dates = [];      // даты, покрытые успешно импортированными источниками

    foreach ($active as $source) {
        $id = (int)$source['id'];
        $url = $source['url'];

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
                update_source_dates($pdo, $id, $result['dates'] ?? []);
                update_source_check($pdo, $id, $meta);
                log_check($pdo, $id, $url, 'changed', $old_md5, $meta['md5'], $reason . ' -> ' . ($result['status'] ?? 'ok'));

                // Резервная копия: файл могут удалить с Диска сразу после
                // изменения, поэтому архивируем при каждом полном импорте
                // (сам archive_source_file() пропустит скачивание по md5).
                $archive_error = try_archive_source($pdo, $source);
                if ($archive_error !== null) {
                    $archive_errors[$id] = $archive_error;
                }

                $covered++;
                $ok_dates = array_merge($ok_dates, $result['dates'] ?? []);

                $results[] = [
                    'id' => $id,
                    'url' => $url,
                    'check' => 'changed',
                    'reason' => $reason,
                    'result' => $result,
                    'archive_error' => $archive_error,
                ];
            } else {
                update_source_check($pdo, $id, $meta);
                log_check($pdo, $id, $url, 'unchanged', $old_md5, $meta['md5']);

                // Источник не изменился → последним импортом покрыты даты,
                // сохранённые в last_dates. Учитываем их в автоочистке, иначе
                // cleanup почти никогда не запускался бы (нужно, чтобы ВСЕ
                // источники прошли полный импорт в одном цикле).
                $source_dates = json_decode((string)($source['last_dates'] ?? '[]'), true);
                if (is_array($source_dates)) {
                    $ok_dates = array_merge($ok_dates, $source_dates);
                }
                $covered++;

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

    // Ошибки ВСЕХ источников (не только последнего): иначе при успешном
    // последнем источнике ошибка первого остаётся без уведомления.

    // Автоочистка устаревших дат: только когда даты ВСЕХ активных источников
    // подтверждены (полный импорт ИЛИ unchanged с известными last_dates) —
    // иначе список дат неполный и мы рискуем снести чужие даты.
    // collect_keep_dates() добавляет к датам активных источников недели
    // выключенных и удалённых источников — они остаются видимыми на сайте.
    $cleanup = ['removed_dates' => [], 'lessons' => 0];
    if (!empty($active) && $covered === count($active)) {
        try {
            $cleanup = cleanup_stale_dates($pdo, collect_keep_dates($pdo, $ok_dates));
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
        'last_error' => $errors ? $errors[count($errors) - 1] : null,
        'archive_errors' => $archive_errors,
        'cleanup' => $cleanup,
    ];
}
