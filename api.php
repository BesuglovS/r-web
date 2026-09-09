<?php
/**
 * JSON API для расписания.
 *
 * GET ?action=classes              — список классов
 * GET ?action=teachers             — список учителей
 * GET ?action=weeks                — список недель
 * GET ?action=dates&week=YYYY-MM-DD — даты за неделю
 * GET ?action=schedule&class=X&date=Y — расписание класса на дату
 * GET ?action=schedule&teacher=X&date=Y — расписание учителя на дату
 * GET ?action=free_rooms&building=1..3&date=Y&time=H:MM — аудитории корпуса
 *   с отметкой «свободно/занято» на дату и момент времени + periods —
 *   интервалы уроков дня и lesson_starts — времена начала уроков 1–10
 *   (клиент строит из них выпадающий список и сдвигает авто-время
 *   с перемены на следующий урок)
 * GET ?action=history              — история изменений
 * GET ?action=import_log           — журнал импортов (требует авторизации)
 * GET ?action=check_log            — журнал проверок источников (требует авторизации)
 * GET ?action=sources              — список источников расписаний
 * GET ?action=last_import          — последний импорт (дата + статус)
 * POST ?action=import              — импорт (требует авторизации)
 * POST ?action=source_add          — добавить источник (требует авторизации)
 * POST ?action=source_delete       — удалить источник (требует авторизации)
 * POST ?action=source_toggle       — вкл/выкл источник (требует авторизации)
 * POST ?action=source_edit         — изменить источник (требует авторизации)
 * POST ?action=import_all          — импорт по всем активным (требует авторизации)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/src/db.php';
// auth.php подключается ради ensure_session()/api_rate_limit() — сессия для
// публичных эндпоинтов НЕ стартует (см. api_require_admin)
require_once __DIR__ . '/src/auth.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Rate-limit публичных GET-эндпоинтов (справочники, расписание, история).
// Админ-эндпоинты защищены сессией и не лимитируются.
$admin_actions = ['import_log', 'check_log', 'sources', 'import', 'import_all',
                  'source_add', 'source_delete', 'source_toggle', 'source_edit'];
if (!in_array($action, $admin_actions, true)) {
    api_rate_limit();
}

/**
 * Единая точка авторизации для админ-эндпоинтов API.
 * Для POST дополнительно требует CSRF-токен (поле csrf_token или заголовок X-CSRF-Token).
 */
function api_require_admin(bool $is_post): void
{
    // Сессия нужна только здесь — публичные эндпоинты её не создают
    ensure_session();
    // Метод фиксируем для всех админ-эндпоинтов: GET-списки — только GET,
    // POST-действия — только POST
    $expected = $is_post ? 'POST' : 'GET';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $expected) {
        http_response_code(405);
        header('Allow: ' . $expected);
        echo json_encode(['error' => 'Метод не поддерживается, используйте ' . $expected]);
        exit;
    }
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    if ($is_post) {
        $token = $_POST['csrf_token']
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($token) || $token === ''
            || empty($_SESSION['csrf_token'])
            || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            echo json_encode(['error' => 'CSRF token invalid']);
            exit;
        }
    }
}

try {
    $pdo = get_db();
    if ($pdo === null) {
        switch ($action) {
            case 'classes': echo json_encode(['classes' => []]); break;
            case 'teachers': echo json_encode(['teachers' => []]); break;
            case 'weeks': echo json_encode(['weeks' => []]); break;
            case 'dates': echo json_encode(['dates' => []]); break;
            case 'schedule': echo json_encode(['schedule' => []]); break;
            case 'sources': echo json_encode(['sources' => []]); break;
            case 'last_import': echo json_encode(['imported_at' => null, 'status' => null]); break;
            default: echo json_encode(['error' => 'БД недоступна']); break;
        }
        exit;
    }
    init_db($pdo);

    // HTTP-кэширование публичных GET-ответов: справочники (классы/учителя/
    // недели/даты) меняются раз в день, расписание — раз в несколько минут.
    // Публичные админ-эндпоинты и POST — всегда без кэша.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $cache_ttl = [
            'classes'     => 300,
            'teachers'    => 300,
            'weeks'       => 300,
            'dates'       => 300,
            'schedule'    => 60,
            'free_rooms'  => 30,
            'history'     => 60,
            'last_import' => 30,
        ];
        if (isset($cache_ttl[$action])) {
            header('Cache-Control: public, max-age=' . $cache_ttl[$action]);
        } else {
            header('Cache-Control: no-store');
        }
    } else {
        header('Cache-Control: no-store');
    }

    switch ($action) {
        case 'classes':
            $rows = $pdo->query("SELECT DISTINCT class_name FROM schedule")->fetchAll(PDO::FETCH_COLUMN);
            usort($rows, function($a, $b) {
                preg_match('/^(\d+)/', $a, $ma);
                preg_match('/^(\d+)/', $b, $mb);
                $na = isset($ma[1]) ? (int)$ma[1] : 0;
                $nb = isset($mb[1]) ? (int)$mb[1] : 0;
                return $na !== $nb ? $na - $nb : strcmp($a, $b);
            });
            echo json_encode(['classes' => $rows]);
            break;

        case 'teachers':
            $rows = $pdo->query("SELECT DISTINCT teacher FROM schedule WHERE teacher != '' ORDER BY teacher")->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['teachers' => $rows]);
            break;

        case 'weeks':
            $rows = $pdo->query("SELECT DISTINCT date FROM schedule ORDER BY date")->fetchAll(PDO::FETCH_COLUMN);
            $weeks = [];
            foreach ($rows as $dateStr) {
                $dt = new DateTime($dateStr);
                // Понедельник недели вручную: 'monday this week' в PHP для
                // воскресенья даёт понедельник следующей недели
                $offset = ((int)$dt->format('N')) - 1;
                $monday = (clone $dt)->modify("-{$offset} days");
                $sunday = (clone $monday)->modify('+6 days');

                $key = $monday->format('Y-m-d');
                if (!isset($weeks[$key])) {
                    $month_names = [
                        1=>'янв',2=>'фев',3=>'мар',4=>'апр',5=>'мая',6=>'июн',
                        7=>'июл',8=>'авг',9=>'сен',10=>'окт',11=>'ноя',12=>'дек'
                    ];
                    $m1 = $month_names[(int)$monday->format('n')];
                    $m2 = $month_names[(int)$sunday->format('n')];
                    $label = $monday->format('j') . '–' . $sunday->format('j');
                    if ($monday->format('n') !== $sunday->format('n')) {
                        $label .= ' ' . $m1 . '–' . $m2;
                    } else {
                        $label .= ' ' . $m1;
                    }
                    $weeks[$key] = [
                        'week_start' => $key,
                        'week_end'   => $sunday->format('Y-m-d'),
                        'label'      => $label,
                    ];
                }
            }
            echo json_encode(['weeks' => array_values($weeks)]);
            break;

        case 'schedule':
            $class = $_GET['class'] ?? null;
            $teacher = $_GET['teacher'] ?? null;
            $date = $_GET['date'] ?? null;
            // Защита от array-параметров (?class[]=x) — иначе PDO бросит исключение
            if (!is_string($class)) $class = null;
            if (!is_string($teacher)) $teacher = null;
            if (!is_string($date)) $date = null;

            // Требуем хотя бы один фильтр — иначе выгрузится вся таблица
            if (!$class && !$teacher && !$date) {
                http_response_code(400);
                echo json_encode(['error' => 'Укажите class, teacher или date']);
                break;
            }

            $conditions = [];
            $params = [];

            if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                http_response_code(400);
                echo json_encode(['error' => 'Неверный формат даты (ожидается YYYY-MM-DD)']);
                break;
            }
            if ($class) {
                $conditions[] = 'class_name = :class';
                $params[':class'] = $class;
            }
            if ($teacher) {
                $conditions[] = 'teacher = :teacher';
                $params[':teacher'] = $teacher;
            }
            if ($date) {
                $conditions[] = 'date = :date';
                $params[':date'] = $date;
            }

            $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

            $sql = "SELECT date, day_of_week, class_name, lesson_num, time_start, time_end,
                           subject, teacher, room, parallel_group, imported_at
                    FROM schedule $where
                    ORDER BY date, time_start, lesson_num, class_name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Время последнего изменения именно этого расписания (класс/учитель + дата).
            // Источник истины — schedule_history.changed_at: imported_at строк перезатирается
            // при каждом импорте файла целиком и не отражает «когда показываемое расписание
            // реально изменилось». Поэтому берём максимум changed_at по истории, ограниченный
            // той же датой и прошлым классом/учителем. Фолбек — максимум imported_at показанных строк
            $view_imported = null;
            if ($date) {
                $h_conds = ['date = :date'];
                $h_params = [':date' => $date];
                if ($class) { $h_conds[] = 'class_name = :class'; $h_params[':class'] = $class;}
                if ($teacher) { $h_conds[] = 'teacher = :teacher'; $h_params[':teacher'] = $teacher;}
                $h_sql = "SELECT MAX(changed_at) FROM schedule_history WHERE " . implode(' AND ', $h_conds);
                $h_stmt = $pdo->prepare($h_sql);
                $h_stmt->execute($h_params);
                $hist_max = $h_stmt->fetchColumn();
                if ($hist_max) $view_imported = $hist_max;
            }
            if ($view_imported === null) {
                foreach ($rows as $r) {
                    if ($r['imported_at'] !== null && ($view_imported === null || $r['imported_at'] > $view_imported)) {
                        $view_imported = $r['imported_at'];
                    }
                }
            }

            echo json_encode([
                'schedule'    => $rows,
                // Время хранится в UTC — отдаём в самарском
                'imported_at' => $view_imported !== null ? utc_to_samara($view_imported) : null,
            ]);
            break;

        case 'free_rooms':
            // Свободные аудитории корпуса на дату и момент времени.
            $fb = $_GET['building'] ?? null;
            $fdate = $_GET['date'] ?? null;
            $ftime = $_GET['time'] ?? null;
            // is_string(): ?building[]=x уронил бы PDO, как уже защищено в 'schedule'
            if (!is_string($fb)) $fb = '';
            if (!is_string($fdate)) $fdate = '';
            if (!is_string($ftime)) $ftime = '';

            if (!preg_match('/^[123]$/', $fb)) {
                http_response_code(400);
                echo json_encode(['error' => 'Укажите building: 1, 2 или 3']);
                break;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fdate)
                || !checkdate((int)substr($fdate, 5, 2), (int)substr($fdate, 8, 2), (int)substr($fdate, 0, 4))) {
                http_response_code(400);
                echo json_encode(['error' => 'Неверный формат даты (ожидается YYYY-MM-DD)']);
                break;
            }
            if (!preg_match('/^(\d{1,2}):(\d{2})$/', $ftime, $ftm)
                || (int)$ftm[1] > 23 || (int)$ftm[2] > 59) {
                http_response_code(400);
                echo json_encode(['error' => 'Неверный формат времени (ожидается HH:MM)']);
                break;
            }
            // Нормализация в HH:MM (в БД время хранится с нулевым паддингом)
            $ftime = sprintf('%02d:%02d', (int)$ftm[1], (int)$ftm[2]);

            // Активные аудитории корпуса
            $rstmt = $pdo->prepare("SELECT name FROM rooms WHERE building = ? AND is_active = 1");
            $rstmt->execute([(int)$fb]);
            $room_names = $rstmt->fetchAll(PDO::FETCH_COLUMN);

            // Занятость на момент времени. Сравнение строк «HH:MM» (в БД время
            // с нулевым паддингом); substr('0'||time_start,-5) подстраховка от
            // незападенных '9:00' в будущих импортах.
            $occupied = [];
            if ($room_names) {
                $fph = implode(',', array_fill(0, count($room_names), '?'));
                $fstmt = $pdo->prepare(
                    "SELECT room, lesson_num, time_start, time_end, subject, teacher, class_name, parallel_group
                     FROM schedule
                     WHERE date = ? AND room IN ($fph)
                       AND substr('0'||time_start,-5) <= ?
                       AND substr('0'||time_end,-5) > ?"
                );
                $fstmt->execute(array_merge([$fdate], $room_names, [$ftime, $ftime]));
                foreach ($fstmt->fetchAll(PDO::FETCH_ASSOC) as $frow) {
                    // Параллельные группы могут делить аудиторию — достаточно одного слота
                    if (!isset($occupied[$frow['room']])) {
                        $occupied[$frow['room']] = $frow;
                    }
                }
            }

            // Интервалы уроков дня — клиент сдвигает авто-время с перемены
            // на начало следующего урока
            $pstmt = $pdo->prepare(
                "SELECT DISTINCT time_start, time_end FROM schedule
                 WHERE date = ? ORDER BY time_start, time_end"
            );
            $pstmt->execute([$fdate]);
            $periods = $pstmt->fetchAll(PDO::FETCH_ASSOC);

            // Времена начала уроков дня (по номерам уроков) — клиент строит
            // из них выпадающий список выбора времени
            $lstmt = $pdo->prepare(
                "SELECT lesson_num, MIN(time_start) AS time_start
                 FROM schedule WHERE date = ?
                 GROUP BY lesson_num ORDER BY lesson_num LIMIT 10"
            );
            $lstmt->execute([$fdate]);
            $lesson_starts = $lstmt->fetchAll(PDO::FETCH_ASSOC);

            $frooms = [];
            foreach ($room_names as $fname) {
                if (isset($occupied[$fname])) {
                    $focc = $occupied[$fname];
                    $frooms[] = [
                        'name'           => $fname,
                        'free'           => false,
                        'lesson_num'     => (int)$focc['lesson_num'],
                        'time_start'     => $focc['time_start'],
                        'time_end'       => $focc['time_end'],
                        'subject'        => $focc['subject'],
                        'teacher'        => $focc['teacher'],
                        'class_name'     => $focc['class_name'],
                        'parallel_group' => $focc['parallel_group'],
                    ];
                } else {
                    $frooms[] = ['name' => $fname, 'free' => true, 'lesson_num' => null,
                                 'time_start' => null, 'time_end' => null, 'subject' => null,
                                 'teacher' => null, 'class_name' => null, 'parallel_group' => null];
                }
            }
            // Естественная сортировка: Ч-2 раньше Ч-10, корпуса по этажам
            usort($frooms, function ($a, $b) { return strnatcmp($a['name'], $b['name']); });
            $free_count = count(array_filter($frooms, function ($r) { return $r['free']; }));

            echo json_encode([
                'building'      => (int)$fb,
                'date'          => $fdate,
                'time'          => $ftime,
                'periods'       => $periods,
                'lesson_starts' => $lesson_starts,
                'free_count'    => $free_count,
                'rooms'         => $frooms,
            ]);
            break;

        case 'dates':
            $week = $_GET['week'] ?? null;

            if ($week) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Неверный формат недели (ожидается YYYY-MM-DD)']);
                    break;
                }
                $week_dt = new DateTime($week);
                // +6 дней вместо 'sunday this week': у PHP «sunday this week»
                // для воскресенья возвращает воскресенье СЛЕДУЮЩЕЙ недели
                $sunday = clone $week_dt;
                $sunday->modify('+6 days');
                $stmt = $pdo->prepare("SELECT DISTINCT date, day_of_week FROM schedule WHERE date >= ? AND date <= ? ORDER BY date");
                $stmt->execute([$week_dt->format('Y-m-d'), $sunday->format('Y-m-d')]);
            } else {
                $stmt = $pdo->query("SELECT DISTINCT date, day_of_week FROM schedule ORDER BY date");
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['dates' => $rows]);
            break;

        case 'history':
            // С фильтром по классу/учителю/дате — плоский список изменений.
            // is_string(): ?class[]=x превратил бы значение в массив и уронил
            // PDO в исключение (HTTP 500), как это уже защищено в 'schedule'.
            $h_class = isset($_GET['class']) && is_string($_GET['class']) ? $_GET['class'] : '';
            $h_teacher = isset($_GET['teacher']) && is_string($_GET['teacher']) ? $_GET['teacher'] : '';
            $h_date = isset($_GET['date']) && is_string($_GET['date']) ? $_GET['date'] : '';
            if ($h_class !== '' || $h_teacher !== '' || $h_date !== '') {
                $cond = [];
                $h_params = [];
                if ($h_class !== '') { $cond[] = 'class_name = :class'; $h_params[':class'] = $h_class; }
                if ($h_teacher !== '') { $cond[] = 'teacher = :teacher'; $h_params[':teacher'] = $h_teacher; }
                if ($h_date !== '') { $cond[] = 'date = :date'; $h_params[':date'] = $h_date; }
                $h_stmt = $pdo->prepare(
                    "SELECT change_type, date, class_name, lesson_num, time_start, time_end,
                            subject, teacher, room, old_room, changed_at
                     FROM schedule_history
                     WHERE " . implode(' AND ', $cond) . "
                     ORDER BY changed_at DESC, time_start ASC, class_name ASC, subject ASC
                     LIMIT 100"
                );
                $h_stmt->execute($h_params);
                $h_changes = $h_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($h_changes as &$c) {
                    $c['changed_at'] = utc_to_samara($c['changed_at']);
                }
                unset($c);
                echo json_encode(['changes' => $h_changes]);
                break;
            }

            // Без фильтра — последние 20 версий
            $rows = $pdo->query(
                "SELECT version, MAX(changed_at) as changed_at, COUNT(*) as cnt
                 FROM schedule_history
                 GROUP BY version
                 ORDER BY version DESC
                 LIMIT 20"
            )->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            if ($rows) {
                // Все детали одним запросом (вместо N+1)
                $versions = array_column($rows, 'version');
                $placeholders = implode(',', array_fill(0, count($versions), '?'));
                $det_stmt = $pdo->prepare(
                    "SELECT version, change_type, date, class_name, lesson_num, subject, teacher, room, old_room
                     FROM schedule_history WHERE version IN ($placeholders)
                     ORDER BY date, lesson_num, class_name"
                );
                $det_stmt->execute($versions);

                $details_by_version = [];
                foreach ($det_stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                    $details_by_version[$d['version']][] = $d;
                }

                foreach ($rows as $row) {
                    $result[] = [
                        'version'    => (int)$row['version'],
                        // Время хранится в UTC — отдаём в самарском
                        'changed_at' => utc_to_samara($row['changed_at']),
                        'count'      => (int)$row['cnt'],
                        'details'    => $details_by_version[$row['version']] ?? [],
                    ];
                }
            }
            echo json_encode(['history' => $result]);
            break;

        case 'import_log':
            api_require_admin(false);
            $rows = $pdo->query("SELECT * FROM import_log ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            // Время хранится в UTC — отдаём в самарском
            foreach ($rows as &$r) {
                $r['imported_at'] = utc_to_samara($r['imported_at']);
            }
            unset($r);
            echo json_encode(['log' => $rows]);
            break;

        case 'check_log':
            api_require_admin(false);
            $rows = $pdo->query("SELECT * FROM check_log ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
            // Время хранится в UTC — отдаём в самарском
            foreach ($rows as &$r) {
                $r['checked_at'] = utc_to_samara($r['checked_at']);
            }
            unset($r);
            echo json_encode(['checks' => $rows]);
            break;

        case 'import':
            api_require_admin(true);

            $url = $_POST['url'] ?? '';
            if (empty($url)) {
                http_response_code(400);
                echo json_encode(['error' => 'URL не указан']);
                break;
            }

            require_once __DIR__ . '/src/import.php';
            $result = do_import($url, $pdo);
            echo json_encode($result);
            break;

        case 'sources':
            api_require_admin(false);
            require_once __DIR__ . '/src/import.php';
            $sources = get_sources();
            echo json_encode(['sources' => $sources]);
            break;

        case 'last_import':
            $row = $pdo->query("SELECT imported_at, status FROM import_log ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $chk = $pdo->query("SELECT MAX(last_checked_at) c FROM schedule_sources WHERE is_active = 1")->fetch(PDO::FETCH_ASSOC);
            echo json_encode([
                // Время хранится в UTC — отдаём в самарском
                'imported_at' => isset($row['imported_at']) ? utc_to_samara($row['imported_at']) : null,
                'status'      => $row['status'] ?? null,
                // Время последней проверки метаданных (то, что показывается на сайте)
                'checked_at'  => !empty($chk['c']) ? utc_to_samara($chk['c']) : null,
            ]);
            break;

        case 'source_add':
            api_require_admin(true);

            $url = $_POST['url'] ?? '';
            $label = $_POST['label'] ?? '';
            if (empty($url)) {
                http_response_code(400);
                echo json_encode(['error' => 'URL не может быть пустым']);
                break;
            }

            require_once __DIR__ . '/src/import.php';
            $result = add_source($url, $label);
            echo json_encode($result);
            break;

        case 'source_delete':
            api_require_admin(true);

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Неверный ID']);
                break;
            }

            require_once __DIR__ . '/src/import.php';
            $result = delete_source($id);
            echo json_encode($result);
            break;

        case 'source_toggle':
            api_require_admin(true);

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Неверный ID']);
                break;
            }

            require_once __DIR__ . '/src/import.php';
            $result = toggle_source($id);
            echo json_encode($result);
            break;

        case 'source_edit':
            api_require_admin(true);

            $id = (int)($_POST['id'] ?? 0);
            $url = trim($_POST['url'] ?? '');
            $label = trim($_POST['label'] ?? '');
            if ($id <= 0 || $url === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Неверные данные']);
                break;
            }

            require_once __DIR__ . '/src/import.php';
            $result = edit_source($id, $url, $label);
            echo json_encode($result);
            break;

        case 'import_all':
            api_require_admin(true);

            require_once __DIR__ . '/src/import.php';
            $result = do_import_all();
            echo json_encode($result);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Неизвестное действие']);
            break;
    }

} catch (Throwable $e) {
    // Детали ошибки — в лог сервера, наружу — только обобщённое сообщение
    // (сообщения исключений могут содержать пути и внутренности)
    error_log('[api] ' . $action . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Внутренняя ошибка сервера']);
}
