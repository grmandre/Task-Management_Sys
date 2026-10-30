<?php
// Shared task logic: reminders, repeat rules and recurring-task generation.
// Requires db/db.php to be included first ($GLOBALS['db']).

// minutes before due => label
const REMINDER_OPTIONS = [
    0    => 'At due time',
    15   => '15 minutes before',
    30   => '30 minutes before',
    60   => '1 hour before',
    180  => '3 hours before',
    1440 => '1 day before',
];

const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

// Tasks without a due time count as due at 9:00 AM for reminders.
const DEFAULT_REMIND_TIME = '09:00:00';

// How far back the generator catches up if the user hasn't opened the app for a while.
const MAX_CATCHUP_DAYS = 30;

// A task without a due time is due by the end of its due day.
const END_OF_DAY = '23:59:59';

// Deadline as a timestamp, or null when the task has no due date.
function task_deadline($task) {
    return $task['due_date'] ? strtotime($task['due_date'] . ' ' . ($task['due_time'] ?: END_OF_DAY)) : null;
}

// Same deadline in SQL; $t is an optional table alias such as 't.'
function sql_deadline($t = '') {
    return "TIMESTAMP({$t}due_date, COALESCE({$t}due_time, '" . END_OF_DAY . "'))";
}

// Seconds a completed task was finished past its deadline (0 = on time), or null if it doesn't apply.
function seconds_late($task) {
    $deadline = task_deadline($task);
    if ($task['status'] !== 'Completed' || !$deadline || empty($task['completed_at'])) {
        return null;
    }
    return max(0, strtotime($task['completed_at']) - $deadline);
}

// 5400 => "1 hr 30 min late", 3 days => "3 days late"
function describe_late($seconds) {
    $mins = intdiv($seconds, 60);
    if ($mins < 60)   return max(1, $mins) . ' min late';
    if ($mins < 1440) return intdiv($mins, 60) . ' hr' . ($mins % 60 ? ' ' . $mins % 60 . ' min' : '') . ' late';
    $days = intdiv($mins, 1440);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' late';
}

function compute_remind_at($due_date, $due_time, $remind_before) {
    if (!$due_date || $remind_before === null || $remind_before === '') {
        return null;
    }
    $due = strtotime($due_date . ' ' . ($due_time ?: DEFAULT_REMIND_TIME));
    return date('Y-m-d H:i:s', $due - (int)$remind_before * 60);
}

// Does $date (Y-m-d) fall on the repeat rule?
// weekly:  repeat_days = ISO weekdays "1,3,5" (Mon=1 … Sun=7)
// monthly: repeat_days = days of month "1,15,31" — a day past the month's end lands on its last day
function repeat_matches($type, $days_csv, $date) {
    $ts   = strtotime($date);
    $days = array_map('intval', array_filter(explode(',', (string)$days_csv), 'strlen'));

    switch ($type) {
        case 'daily':
            return true;
        case 'weekly':
            return in_array((int)date('N', $ts), $days, true);
        case 'monthly':
            $dom = (int)date('j', $ts);
            $last = (int)date('t', $ts);
            foreach ($days as $d) {
                if (min($d, $last) === $dom) {
                    return true;
                }
            }
            return false;
    }
    return false;
}

// Human-readable rule, e.g. "Every Mon, Wed, Fri" / "Monthly on the 1st, 15th"
function describe_repeat($type, $days_csv, $until = null) {
    $days = array_map('intval', array_filter(explode(',', (string)$days_csv), 'strlen'));
    $text = match ($type) {
        'daily'   => 'Every day',
        'weekly'  => 'Every ' . implode(', ', array_map(fn($d) => WEEKDAYS[$d] ?? '?', $days)),
        'monthly' => 'Monthly on the ' . implode(', ', array_map('ordinal', $days)),
        default   => '',
    };
    if ($text && $until) {
        $text .= ' until ' . date('M j, Y', strtotime($until));
    }
    return $text;
}

function ordinal($n) {
    $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    return $n . $suffix;
}

// Validate a repeat rule from user input. Returns [type, days_csv] or an error string.
function normalize_repeat($type, $days_csv) {
    if (!in_array($type, ['none', 'daily', 'weekly', 'monthly'], true)) {
        return 'Please choose a valid repeat option.';
    }
    if ($type === 'none' || $type === 'daily') {
        return [$type, null];
    }

    $max  = $type === 'weekly' ? 7 : 31;
    $days = array_unique(array_map('intval', array_filter(explode(',', (string)$days_csv), 'strlen')));
    $days = array_values(array_filter($days, fn($d) => $d >= 1 && $d <= $max));
    sort($days);

    if (!$days) {
        return $type === 'weekly'
            ? 'Pick at least one day of the week for a weekly task.'
            : 'Pick at least one day of the month for a monthly task.';
    }
    return [$type, implode(',', $days)];
}

// Create the occurrences of every recurring task owned by $user_id that are due
// between the last run and today. Each occurrence is a normal task linked by parent_id.
function generate_recurring_tasks($user_id) {
    $db    = $GLOBALS['db'];
    $today = date('Y-m-d');

    $roots = $db->prepare("
        SELECT * FROM tbl_tasks
        WHERE user_id = ? AND parent_id IS NULL AND is_deleted = 0
          AND repeat_type <> 'none'
          AND (last_generated IS NULL OR last_generated < ?)
    ");
    $roots->execute([$user_id, $today]);

    foreach ($roots->fetchAll(PDO::FETCH_ASSOC) as $root) {
        // Claim this series for today first, so two requests at once can't both generate it.
        $claim = $db->prepare('UPDATE tbl_tasks SET last_generated = ? WHERE id = ? AND last_generated <=> ?');
        $claim->execute([$today, $root['id'], $root['last_generated']]);
        if ($claim->rowCount() !== 1) {
            continue;
        }

        $series_start = $root['last_generated']
            ?: ($root['due_date'] ?: ($root['start_date'] ?: substr($root['created_at'], 0, 10)));
        $from  = max(date('Y-m-d', strtotime($series_start . ' +1 day')),
                     date('Y-m-d', strtotime('-' . MAX_CATCHUP_DAYS . ' days')));
        $until = ($root['repeat_until'] && $root['repeat_until'] < $today) ? $root['repeat_until'] : $today;

        for ($d = $from; $d <= $until; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if (repeat_matches($root['repeat_type'], $root['repeat_days'], $d)) {
                create_occurrence($root, $d);
            }
        }
    }
}

// ── Daily check-ins ────────────────────────────────────────────────────────
// A repeating series is one task per scheduled day: the first task itself covers its own
// day, and each copy (parent_id = first task) covers its due_date. Ticking a day completes
// that day's task, creating the copy first if the generator never made it.

// The day the first task of a series covers
function series_day($root) {
    return $root['due_date'] ?: ($root['start_date'] ?: substr($root['created_at'], 0, 10));
}

// The last day check-ins can go up to: today, or the repeat end if that is earlier
function series_last_day($root) {
    $today = date('Y-m-d');
    return ($root['repeat_until'] && $root['repeat_until'] < $today) ? $root['repeat_until'] : $today;
}

// The day a task of a repeating series stands for, or null for an ordinary task.
function task_day($task) {
    if ($task['parent_id']) {
        return $task['due_date'];
    }
    return $task['repeat_type'] !== 'none' ? series_day($task) : null;
}

// Once its day is over, a day of a repeating series is a record: its status, checklist,
// notes and dates can't change any more. Only today's day can be marked complete.
const LOCKED_DAY_MESSAGE = "This day has passed. Past days of a repeating task are kept as a record and can't be changed.";

function day_locked($task) {
    $day = task_day($task);
    return $day !== null && $day < date('Y-m-d');
}

function is_scheduled($root, $date) {
    return $date === series_day($root)
        || ($date > series_day($root) && repeat_matches($root['repeat_type'], $root['repeat_days'], $date));
}

// Day-by-day state of a series from its first day to series_last_day():
//   done    – that day's task is Completed
//   open    – today, not done yet (doesn't break a streak)
//   missed  – a past scheduled day that wasn't completed
//   skipped – that day's copy was deleted (not counted at all)
// Streaks count consecutive scheduled days, so Mon/Wed/Fri walks keep a streak over Tue and Thu.
function series_checkins($root) {
    $stmt = $GLOBALS['db']->prepare('
        SELECT id, due_date, status, is_deleted FROM tbl_tasks WHERE parent_id = ? AND due_date IS NOT NULL
    ');
    $stmt->execute([$root['id']]);
    $by_date = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $by_date[$t['due_date']] = $t;
    }
    $by_date[series_day($root)] = $root;

    $today = date('Y-m-d');
    $out   = ['days' => [], 'scheduled' => 0, 'done' => 0, 'streak' => 0, 'best' => 0];
    $run   = 0;

    for ($d = series_day($root), $last = series_last_day($root); $d <= $last; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if (!is_scheduled($root, $d)) {
            continue;
        }
        $t = $by_date[$d] ?? null;
        if ($t && $t['is_deleted']) {
            $state = 'skipped';
        } elseif ($t && $t['status'] === 'Completed') {
            $state = 'done';
        } else {
            $state = $d === $today ? 'open' : 'missed';
        }
        $out['days'][$d] = $state;

        if ($state === 'done' || $state === 'missed') {
            $out['scheduled']++;
        }
        if ($state === 'done') {
            $out['done']++;
            $out['best'] = max($out['best'], ++$run);
        } elseif ($state === 'missed') {
            $run = 0;
        }
    }
    $out['streak'] = $run;
    return $out;
}

// Tick (or untick) one day of a series. Returns the id of that day's task.
// Callers only allow today (see day_locked); a past day passed in counts as done by its due time.
function set_checkin($root, $date, $done) {
    $db = $GLOBALS['db'];

    if ($date === series_day($root)) {
        $task_id = (int)$root['id'];
    } else {
        create_occurrence($root, $date);
        $stmt = $db->prepare('SELECT id FROM tbl_tasks WHERE parent_id = ? AND due_date = ? ORDER BY is_deleted, id LIMIT 1');
        $stmt->execute([$root['id'], $date]);
        $task_id = (int)$stmt->fetchColumn();
    }

    $completed_at = null;
    if ($done) {
        $completed_at = $date === date('Y-m-d')
            ? date('Y-m-d H:i:s')
            : $date . ' ' . ($root['due_time'] ?: END_OF_DAY);
    }
    // Ticking a skipped (deleted) day brings its copy back
    $stmt = $db->prepare('UPDATE tbl_tasks SET status = ?, completed_at = ?, is_deleted = IF(?, 0, is_deleted) WHERE id = ?');
    $stmt->execute([$done ? 'Completed' : 'Pending', $completed_at, $done ? 1 : 0, $task_id]);

    log_activity($root['user_id'], $done ? 'task_status_completed' : 'task_status_pending', $task_id);
    return $task_id;
}

function create_occurrence($root, $date) {
    $db = $GLOBALS['db'];

    $exists = $db->prepare('SELECT 1 FROM tbl_tasks WHERE parent_id = ? AND due_date = ?');
    $exists->execute([$root['id'], $date]);
    if ($exists->fetchColumn()) {
        return;
    }

    $stmt = $db->prepare('
        INSERT INTO tbl_tasks (user_id, parent_id, title, description, priority, start_date, due_date,
                               due_time, remind_before, remind_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $root['user_id'], $root['id'], $root['title'], $root['description'], $root['priority'],
        $date, $date, $root['due_time'], $root['remind_before'],
        compute_remind_at($date, $root['due_time'], $root['remind_before']),
    ]);
    $new_id = $db->lastInsertId();

    // Fresh, unchecked copy of the series checklist
    $copy = $db->prepare('
        INSERT INTO tbl_task_checklist (task_id, title, position)
        SELECT ?, title, position FROM tbl_task_checklist WHERE task_id = ?
    ');
    $copy->execute([$new_id, $root['id']]);
}
