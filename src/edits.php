<?php
/**
 * Правки расписания администратором (таблица schedule_edits, миграция v7).
 *
 * Идея: импорт полностью перезаписывает schedule, поэтому ручные правки
 * хранятся отдельно и накладываются на расписание ПРИ ЧТЕНИИ. Правка
 * сопоставляется с уроком источника по стабильному ключу
 * (date + class_name + base_lesson_num + base_time_start), поэтому переживает
 * импорты. Если импорт изменил/убрал урок — правка сохраняется и применяется
 * снова, как только появится совпадающий урок.
 *
 * Типы правок:
 *   edit   — замена полей существующего урока (пустое поле = взять из источника)
 *   add    — добавление нового урока (base_* = 0 / '')
 *   delete — скрытие урока источника
 */

require_once __DIR__ . '/db.php';

/** Максимальные длины строковых полей (защита от мусора в БД). */
const SCHEDULE_EDIT_MAXLEN = [
    'class_name'     => 50,
    'subject'        => 200,
    'teacher'        => 200,
    'room'           => 100,
    'parallel_group' => 100,
];

/** Поля, которые правит администратор (все, кроме ключевых). */
function schedule_edit_fields(): array
{
    return ['lesson_num', 'time_start', 'time_end', 'subject', 'teacher', 'room', 'parallel_group'];
}

/**
 * Полное название дня недели по дате (формат совпадает с parser.php).
 */
function schedule_day_of_week(string $date): string
{
    $map = [
        1 => 'понедельник', 2 => 'вторник', 3 => 'среда', 4 => 'четверг',
        5 => 'пятница', 6 => 'суббота', 7 => 'воскресенье',
    ];
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if ($dt === false || $dt->format('Y-m-d') !== $date) {
        return '';
    }
    return $map[(int)$dt->format('N')] ?? '';
}

/** Ключ урока источника: date|class|lesson_num|time_start|parallel_group. */
function schedule_base_key(array $row): string
{
    return implode("\x1F", [
        (string)($row['date'] ?? ''),
        (string)($row['class_name'] ?? ''),
        (string)($row['lesson_num'] ?? ''),
        (string)($row['time_start'] ?? ''),
        (string)($row['parallel_group'] ?? ''),
    ]);
}

/** Ключ правки по её базовым (неизменяемым) полям. */
function schedule_edit_key(array $edit): string
{
    return implode("\x1F", [
        (string)($edit['date'] ?? ''),
        (string)($edit['class_name'] ?? ''),
        (string)($edit['base_lesson_num'] ?? ''),
        (string)($edit['base_time_start'] ?? ''),
        (string)($edit['base_parallel_group'] ?? ''),
    ]);
}

/** Ключ слота без группы: date|class|lesson_num|time_start. */
function schedule_slot_key(array $row): string
{
    return implode("\x1F", [
        (string)($row['date'] ?? ''),
        (string)($row['class_name'] ?? ''),
        (string)($row['lesson_num'] ?? ''),
        (string)($row['time_start'] ?? ''),
    ]);
}

/** Ключ слота правки (без группы). */
function schedule_edit_slot_key(array $edit): string
{
    return implode("\x1F", [
        (string)($edit['date'] ?? ''),
        (string)($edit['class_name'] ?? ''),
        (string)($edit['base_lesson_num'] ?? ''),
        (string)($edit['base_time_start'] ?? ''),
    ]);
}

/**
 * Совместимы ли группы правки и урока.
 * Совпадают, либо хотя бы одна пустая: правка всего класса ('') применима
 * к любой подгруппе, а правка подгруппы — к уроку, показанному «группой
 * целиком» (источник перестал делить класс на подгруппы).
 */
function schedule_groups_compatible($a, $b): bool
{
    $a = (string)$a;
    $b = (string)$b;
    return $a === $b || $a === '' || $b === '';
}

/**
 * Правки из БД, опционально отфильтрованные по дате/классу.
 * Возвращает ВСЕ типы (edit/add/delete).
 */
function get_edits(PDO $pdo, ?string $date = null, string $class = ''): array
{
    init_db($pdo);
    $cond = [];
    $params = [];
    if ($date !== null && $date !== '') {
        $cond[] = 'date = :date';
        $params[':date'] = $date;
    }
    if ($class !== '') {
        $cond[] = 'class_name = :class';
        $params[':class'] = $class;
    }
    $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
    $stmt = $pdo->prepare(
        "SELECT * FROM schedule_edits $where ORDER BY date, class_name, lesson_num, base_time_start"
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Строит строку расписания для добавленного урока (change_type = 'add').
 */
function schedule_add_to_row(array $e): array
{
    $group = (string)($e['parallel_group'] ?? '');
    return [
        'date'           => (string)$e['date'],
        'day_of_week'    => schedule_day_of_week((string)$e['date']),
        'class_name'     => (string)$e['class_name'],
        'lesson_num'     => (int)$e['lesson_num'],
        'time_start'     => (string)$e['time_start'],
        'time_end'       => (string)$e['time_end'],
        'subject'        => (string)$e['subject'],
        'teacher'        => (string)$e['teacher'],
        'room'           => (string)$e['room'],
        'parallel_group' => $group !== '' ? $group : null,
        'imported_at'    => null,
        'room_corrected' => false,
        'manual'         => true,
    ];
}

/**
 * Подходит ли добавленный урок под фильтр запроса.
 * $filter: ['date' => ?string, 'class' => ?string, 'teacher' => ?string]
 */
function schedule_add_matches(array $e, array $filter): bool
{
    $date = (string)($filter['date'] ?? '');
    $class = (string)($filter['class'] ?? '');
    $teacher = (string)($filter['teacher'] ?? '');
    if ($date !== '' && (string)$e['date'] !== $date) return false;
    if ($class !== '' && (string)$e['class_name'] !== $class) return false;
    if ($teacher !== '' && (string)$e['teacher'] !== $teacher) return false;
    return true;
}

/**
 * Накладывает правки на базовые уроки расписания.
 *
 * @param array $base_rows Уроки из schedule (результат SQL-фильтра).
 * @param array $edits     Правки (из get_edits()).
 * @param array $filter    ['date'=>?, 'class'=>?, 'teacher'=>?] — для добавленных.
 * @return array Эффективный список уроков, отсортированный как в API.
 */
function apply_edits(array $base_rows, array $edits, array $filter = []): array
{
    $exact = [];
    $by_slot = [];
    $adds = [];
    foreach ($edits as $e) {
        $type = (string)($e['change_type'] ?? 'edit');
        if ($type === 'add') {
            if (schedule_add_matches($e, $filter)) {
                $adds[] = $e;
            }
            continue;
        }
        $exact[schedule_edit_key($e)] = $e;
        $by_slot[schedule_edit_slot_key($e)][(string)($e['base_parallel_group'] ?? '')] = $e;
    }

    $out = [];
    foreach ($base_rows as $row) {
        $base_room = (string)($row['room'] ?? '');
        $base_group = (string)($row['parallel_group'] ?? '');
        $row['room_corrected'] = false;
        $e = $exact[schedule_base_key($row)] ?? null;
        if ($e === null) {
            $cands = $by_slot[schedule_slot_key($row)] ?? [];
            if (isset($cands[''])) {
                // Правка всего класса — применима к любой подгруппе
                $e = $cands[''];
            } elseif ($base_group === '' && !empty($cands)) {
                // Урок показан «группой целиком», а правка сделана для подгруппы —
                // берём детерминированно (первую по названию группы)
                ksort($cands);
                $e = reset($cands);
            }
        }
        if ($e !== null) {
            if ((string)$e['change_type'] === 'delete') {
                continue;
            }
            foreach (schedule_edit_fields() as $f) {
                $v = $e[$f] ?? '';
                if ($v !== null && $v !== '') {
                    $row[$f] = ($f === 'lesson_num') ? (int)$v : (string)$v;
                }
            }
            $row['room_corrected'] = ((string)($e['room'] ?? '') !== '' && (string)$e['room'] !== $base_room);
        }
        $out[] = $row;
    }

    foreach ($adds as $e) {
        $out[] = schedule_add_to_row($e);
    }

    usort($out, function ($a, $b) {
        return [$a['date'], $a['time_start'], $a['lesson_num'], $a['class_name']]
            <=> [$b['date'], $b['time_start'], $b['lesson_num'], $b['class_name']];
    });
    return $out;
}

/**
 * Список правок с пометкой «применена/осиротела» для админ-страницы.
 * Совпадение — по слоту с совместимой группой (см. schedule_groups_compatible):
 * правка подгруппы считается применённой и к уроку «группой целиком», и наоборот.
 */
function list_edits_with_status(PDO $pdo, int $limit = 300): array
{
    init_db($pdo);
    // GROUP BY e.id: одна правка может совпасть с несколькими уроками (весь класс
    // + подгруппы) — берём по одной строке-представителю, без дублей в списке.
    $stmt = $pdo->prepare(
        "SELECT e.*,
                MIN(s.id) AS base_id,
                MIN(s.time_start) AS cur_time_start, MIN(s.time_end) AS cur_time_end,
                MIN(s.subject) AS cur_subject, MIN(s.teacher) AS cur_teacher, MIN(s.room) AS cur_room
         FROM schedule_edits e
         LEFT JOIN schedule s
             ON s.date = e.date
            AND s.class_name = e.class_name
            AND s.lesson_num = e.base_lesson_num
            AND s.time_start = e.base_time_start
            AND (COALESCE(s.parallel_group, '') = e.base_parallel_group
                 OR COALESCE(s.parallel_group, '') = ''
                 OR e.base_parallel_group = '')
         GROUP BY e.id
         ORDER BY e.date DESC, e.time_start, e.class_name
         LIMIT ?"
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $type = (string)$r['change_type'];
        $r['applied'] = ($type === 'add') ? true : ($r['base_id'] !== null);
    }
    unset($r);
    return $rows;
}

/** Нормализует время "H:MM" → "HH:MM" или возвращает null при ошибке. */
function schedule_normalize_time(string $t): ?string
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($t), $m)) {
        return null;
    }
    $h = (int)$m[1];
    $min = (int)$m[2];
    if ($h > 23 || $min > 59) {
        return null;
    }
    return sprintf('%02d:%02d', $h, $min);
}

/**
 * Валидирует и сохраняет правку (upsert для edit/delete, insert для add).
 * Бросает InvalidArgumentException с сообщением для клиента.
 * Возвращает ['status' => 'ok', 'id' => int].
 */
function save_edit(PDO $pdo, array $in): array
{
    init_db($pdo);

    $date = trim((string)($in['date'] ?? ''));
    $class = trim((string)($in['class_name'] ?? ''));
    $base_lesson = (int)($in['base_lesson_num'] ?? 0);
    $base_time = trim((string)($in['base_time_start'] ?? ''));
    $base_group = trim((string)($in['base_parallel_group'] ?? ''));
    $type = (string)($in['change_type'] ?? 'edit');

    if (!in_array($type, ['edit', 'add', 'delete'], true)) {
        throw new InvalidArgumentException('Неверный тип правки');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
        throw new InvalidArgumentException('Неверная дата');
    }
    if ($class === '' || mb_strlen($class) > SCHEDULE_EDIT_MAXLEN['class_name']) {
        throw new InvalidArgumentException('Неверный класс');
    }
    if (mb_strlen($base_group) > SCHEDULE_EDIT_MAXLEN['parallel_group']) {
        throw new InvalidArgumentException('Слишком длинная параллельная группа');
    }

    // Строковые поля: обрезаем и проверяем длины
    $text = [];
    foreach (['subject', 'teacher', 'room', 'parallel_group'] as $f) {
        $text[$f] = trim((string)($in[$f] ?? ''));
        if (mb_strlen($text[$f]) > SCHEDULE_EDIT_MAXLEN[$f]) {
            throw new InvalidArgumentException('Слишком длинное значение: ' . $f);
        }
    }

    $lesson_num = (int)($in['lesson_num'] ?? 0);
    if ($type === 'delete') {
        $lesson_num = $base_lesson;
    } elseif ($lesson_num < 1 || $lesson_num > 20) {
        throw new InvalidArgumentException('Номер урока должен быть от 1 до 20');
    }

    if ($type === 'delete') {
        // Для скрытия урока время неважно — наследуется из источника
        $time_start = '';
        $time_end = '';
    } else {
        $time_start = schedule_normalize_time((string)($in['time_start'] ?? ''));
        $time_end = schedule_normalize_time((string)($in['time_end'] ?? ''));
        if ($type === 'edit' && $time_start === null && $time_end === null) {
            // Оба пустые — наследуем время источника
            $time_start = '';
            $time_end = '';
        } else {
            if ($time_start === null || $time_end === null) {
                throw new InvalidArgumentException('Неверный формат времени (ожидается HH:MM)');
            }
            if (strcmp($time_start, $time_end) >= 0) {
                throw new InvalidArgumentException('Время начала должно быть раньше окончания');
            }
        }
    }

    if ($type === 'add') {
        if ($base_lesson !== 0 || $base_time !== '' || $base_group !== '') {
            // Для add ключ не нужен — игнорируем переданные базовые поля
            $base_lesson = 0;
            $base_time = '';
            $base_group = '';
        }
        if ($text['subject'] === '') {
            throw new InvalidArgumentException('Укажите предмет');
        }
    } else {
        // edit/delete сопоставляются с источником по базовому ключу
        if ($base_lesson < 1 || $base_time === '' || schedule_normalize_time($base_time) === null) {
            throw new InvalidArgumentException('Не найден исходный урок для правки');
        }
        $base_time = schedule_normalize_time($base_time);
    }

    $created_at = gmdate('Y-m-d H:i:s'); // UTC

    if ($type === 'add') {
        $edit_id = (int)($in['id'] ?? 0);
        $values = [
            $lesson_num, $time_start, $time_end,
            $text['subject'], $text['teacher'], $text['room'], $text['parallel_group'], $created_at,
        ];
        if ($edit_id > 0) {
            // Обновление ранее добавленного урока
            $upd = $pdo->prepare(
                "UPDATE schedule_edits
                 SET lesson_num = ?, time_start = ?, time_end = ?,
                     subject = ?, teacher = ?, room = ?, parallel_group = ?, created_at = ?
                 WHERE id = ? AND change_type = 'add'"
            );
            $upd->execute(array_merge($values, [$edit_id]));
            return ['status' => 'ok', 'id' => $edit_id];
        }
        $stmt = $pdo->prepare(
            "INSERT INTO schedule_edits
                (date, class_name, base_lesson_num, base_time_start, change_type,
                 lesson_num, time_start, time_end, subject, teacher, room, parallel_group, created_at)
             VALUES (?, ?, 0, '', 'add', ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute(array_merge([$date, $class], $values));
        return ['status' => 'ok', 'id' => (int)$pdo->lastInsertId()];
    }

    // edit/delete: upsert по (date, class, base_lesson_num, base_time_start, base_parallel_group)
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $sel = $pdo->prepare(
            "SELECT id FROM schedule_edits
             WHERE date = ? AND class_name = ? AND base_lesson_num = ? AND base_time_start = ?
               AND base_parallel_group = ? AND change_type IN ('edit', 'delete')"
        );
        $sel->execute([$date, $class, $base_lesson, $base_time, $base_group]);
        $existing = $sel->fetchColumn();

        if ($existing !== false) {
            $upd = $pdo->prepare(
                "UPDATE schedule_edits
                 SET change_type = ?, lesson_num = ?, time_start = ?, time_end = ?,
                     subject = ?, teacher = ?, room = ?, parallel_group = ?, created_at = ?
                 WHERE id = ?"
            );
            $upd->execute([
                $type, $lesson_num, $time_start, $time_end,
                $text['subject'], $text['teacher'], $text['room'], $text['parallel_group'],
                $created_at, (int)$existing,
            ]);
            $id = (int)$existing;
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO schedule_edits
                    (date, class_name, base_lesson_num, base_time_start, base_parallel_group, change_type,
                     lesson_num, time_start, time_end, subject, teacher, room, parallel_group, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([
                $date, $class, $base_lesson, $base_time, $base_group, $type,
                $lesson_num, $time_start, $time_end,
                $text['subject'], $text['teacher'], $text['room'], $text['parallel_group'], $created_at,
            ]);
            $id = (int)$pdo->lastInsertId();
        }
        $pdo->exec('COMMIT');
    } catch (Exception $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Exception $ignored) {}
        throw $e;
    }

    return ['status' => 'ok', 'id' => $id];
}

/** Удаляет правку по id. Возвращает число удалённых строк. */
function delete_edit(PDO $pdo, int $id): int
{
    init_db($pdo);
    if ($id <= 0) {
        throw new InvalidArgumentException('Неверный ID правки');
    }
    $stmt = $pdo->prepare("DELETE FROM schedule_edits WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount();
}
