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
 * GET ?action=history              — история изменений
 * GET ?action=import_log           — история импортов (требует авторизации)
 * POST ?action=import              — импорт (требует авторизации)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/src/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $pdo = get_db();
    if ($pdo === null) {
        switch ($action) {
            case 'classes': echo json_encode(['classes' => []]); break;
            case 'teachers': echo json_encode(['teachers' => []]); break;
            case 'weeks': echo json_encode(['weeks' => []]); break;
            case 'dates': echo json_encode(['dates' => []]); break;
            case 'schedule': echo json_encode(['schedule' => []]); break;
            default: echo json_encode(['error' => 'БД недоступна']); break;
        }
        exit;
    }
    init_db($pdo);

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
                $monday = clone $dt;
                $monday->modify('monday this week');
                $sunday = clone $monday;
                $sunday->modify('sunday this week');

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

            $conditions = [];
            $params = [];

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
                           subject, teacher, room, parallel_group
                    FROM schedule $where
                    ORDER BY date, lesson_num, class_name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['schedule' => $rows]);
            break;

        case 'dates':
            $week = $_GET['week'] ?? null;

            if ($week) {
                $week_dt = new DateTime($week);
                $sunday = clone $week_dt;
                $sunday->modify('sunday this week');
                $stmt = $pdo->prepare("SELECT DISTINCT date, day_of_week FROM schedule WHERE date >= ? AND date <= ? ORDER BY date");
                $stmt->execute([$week_dt->format('Y-m-d'), $sunday->format('Y-m-d')]);
            } else {
                $stmt = $pdo->query("SELECT DISTINCT date, day_of_week FROM schedule ORDER BY date");
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['dates' => $rows]);
            break;

        case 'history':
            $rows = $pdo->query(
                "SELECT version, changed_at,
                        GROUP_CONCAT(change_type) as types,
                        COUNT(*) as cnt
                 FROM schedule_history
                 GROUP BY version, changed_at
                 ORDER BY version DESC
                 LIMIT 20"
            )->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $row) {
                $details = $pdo->prepare(
                    "SELECT change_type, date, class_name, lesson_num, subject, teacher, room, old_room
                     FROM schedule_history WHERE version = ?
                     ORDER BY date, lesson_num, class_name"
                );
                $details->execute([$row['version']]);
                $result[] = [
                    'version'    => (int)$row['version'],
                    'changed_at' => $row['changed_at'],
                    'count'      => (int)$row['cnt'],
                    'details'    => $details->fetchAll(PDO::FETCH_ASSOC),
                ];
            }
            echo json_encode(['history' => $result]);
            break;

        case 'import_log':
            session_start();
            if (empty($_SESSION['admin_logged_in'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Unauthorized']);
                break;
            }
            $rows = $pdo->query("SELECT * FROM import_log ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['log' => $rows]);
            break;

        case 'import':
            session_start();
            if (empty($_SESSION['admin_logged_in'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Unauthorized']);
                break;
            }

            $url = $_POST['url'] ?? '';
            if (empty($url)) {
                http_response_code(400);
                echo json_encode(['error' => 'URL не указан']);
                break;
            }

            require_once __DIR__ . '/src/import.php';
            $result = do_import($url);
            echo json_encode($result);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Неизвестное действие: ' . $action]);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
