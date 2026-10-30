<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once 'db/db.php';
include_once 'includes/tasks.php';

$USER_ID = (int)$_SESSION['user_id'];
$TASK_ID = intval($_GET['id'] ?? 0);

$stmt = $GLOBALS['db']->prepare('SELECT * FROM tbl_tasks WHERE id = ? AND user_id = ? AND is_deleted = 0');
$stmt->execute([$TASK_ID, $USER_ID]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if ($task) {
    $stmt = $GLOBALS['db']->prepare('SELECT id, title, is_done FROM tbl_task_checklist WHERE task_id = ? ORDER BY position, id');
    $stmt->execute([$TASK_ID]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $GLOBALS['db']->prepare('SELECT id, note, created_at FROM tbl_task_notes WHERE task_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$TASK_ID]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Repeat info comes from the series' first task
    $series = $task;
    if ($task['parent_id']) {
        $stmt = $GLOBALS['db']->prepare('SELECT * FROM tbl_tasks WHERE id = ? AND user_id = ?');
        $stmt->execute([$task['parent_id'], $USER_ID]);
        $series = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $series_active = $series && $series['repeat_type'] !== 'none' && !$series['is_deleted'];

    // Next date a copy will be added (looks up to ~2 months ahead)
    $next_occurrence = null;
    if ($series_active) {
        $from = max(date('Y-m-d', strtotime('+1 day')),
                    $series['last_generated'] ? date('Y-m-d', strtotime($series['last_generated'] . ' +1 day')) : '');
        for ($i = 0, $d = $from; $i < 62; $i++, $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if ($series['repeat_until'] && $d > $series['repeat_until']) break;
            if (repeat_matches($series['repeat_type'], $series['repeat_days'], $d)) { $next_occurrence = $d; break; }
        }
    }

    // Daily check-ins: one month at a time (?month=YYYY-MM), from the series' first month to now
    $checkins = null;
    if ($series_active) {
        $checkins    = series_checkins($series);
        $first_month = substr(series_day($series), 0, 7);
        $last_month  = substr(max(series_day($series), series_last_day($series)), 0, 7);
        $cal_month   = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $_GET['month'] ?? '') ? $_GET['month'] : $last_month;
        $cal_month   = min(max($cal_month, $first_month), $last_month);
        $cal_start   = $cal_month . '-01';
        $cal_days    = (int)date('t', strtotime($cal_start));
        $cal_offset  = (int)date('N', strtotime($cal_start)) - 1;   // blank cells before the 1st (weeks start Monday)
        $prev_month  = date('Y-m', strtotime($cal_start . ' -1 month'));
        $next_month  = date('Y-m', strtotime($cal_start . ' +1 month'));
        $done_today  = ($checkins['days'][date('Y-m-d')] ?? null) === 'done';
        $today_on    = isset($checkins['days'][date('Y-m-d')]);
    }
    $is_series_page = $checkins && !$task['parent_id'];
    // A past day of a repeating task is read-only. On the series page only day one's ticks are locked;
    // the series settings and its checklist (copied to future days) can still change.
    $locked      = day_locked($task);
    $locked_copy = $locked && $task['parent_id'];

    $done_count = count(array_filter($items, fn($i) => $i['is_done']));
    $is_closed  = in_array($task['status'], ['Completed', 'Archived'], true);
    $due_ts     = task_deadline($task);
    $overdue    = $due_ts && $due_ts < time() && !$is_closed;
    $late_by    = seconds_late($task);   // null = no due date / not completed, 0 = on time

    $priorityClass = match($task['priority']) {
        'High' => 'bg-danger', 'Medium' => 'bg-warning text-dark', default => 'bg-secondary',
    };
    $statusClass = match($task['status']) {
        'Completed' => 'bg-success', 'In Progress' => 'bg-warning text-dark', 'Archived' => 'bg-secondary', default => 'bg-dark',
    };
    $fmt_dt = fn($ts) => date('M j, Y g:i A', strtotime($ts));
}
?>

<div class="container-fluid">

<?php if (!$task): ?>

    <div class="row justify-content-center py-4">
        <div class="col-12 col-lg-6">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4 p-md-5 text-center">
                    <i class="lni lni-search-1 fs-1 text-muted"></i>
                    <h6 class="fw-bold mt-3">Task not found</h6>
                    <p class="text-muted small">It may have been deleted, or it belongs to another user.</p>
                    <a href="main.php?page=task" class="btn btn-dark btn-sm px-4">Back to Tasks</a>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>

    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div class="overflow-hidden">
            <small class="text-muted"><a href="main.php?page=task" class="text-muted">Tasks</a> / Task details</small>
            <h4 class="fw-bold mb-2 text-break"><?= htmlspecialchars($task['title']) ?></h4>
            <div class="d-flex flex-wrap gap-1">
                <span class="badge <?= $priorityClass ?>"><?= $task['priority'] ?> priority</span>
                <?php if (!$is_series_page && !($locked_copy && !$is_closed)): // series page: check-ins show it; missed day: "Missed" says it ?>
                <span class="badge <?= $statusClass ?>"><?= $task['status'] ?></span>
                <?php endif; ?>
                <?php if ($locked_copy && !$is_closed): ?>
                    <span class="badge bg-danger"><i class="lni lni-xmark-circle me-1"></i>Missed</span>
                <?php elseif ($overdue): ?>
                    <span class="badge bg-danger"><i class="lni lni-alarm-1 me-1"></i>Overdue</span>
                <?php elseif ($late_by): ?>
                    <span class="badge bg-warning text-dark"><i class="lni lni-alarm-1 me-1"></i>Completed late</span>
                <?php endif; ?>
                <?php if ($series_active): ?>
                    <span class="badge bg-light text-dark border"><i class="lni lni-refresh-circle-1-clockwise me-1"></i>Repeats</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <?php if ($checkins && !$task['parent_id']): // the series page checks in today instead of completing day one ?>
                <?php if ($today_on): ?>
                    <button class="btn btn-sm <?= $done_today ? 'btn-outline-dark' : 'btn-dark' ?>"
                            onclick="CHECKIN(<?= $TASK_ID ?>, '<?= date('Y-m-d') ?>', <?= $done_today ? 'false' : 'true' ?>);">
                        <i class="lni <?= $done_today ? 'lni-refresh-circle-1-clockwise' : 'lni-check-circle-1' ?> me-1"></i>
                        <?= $done_today ? 'Undo today' : 'Done today' ?>
                    </button>
                <?php endif; ?>
            <?php elseif (!$locked_copy): ?>
            <?php if ($task['status'] === 'Pending'): ?>
                <button class="btn btn-sm btn-outline-dark" onclick="SET_STATUS(<?= $TASK_ID ?>, 'In Progress');">
                    <i class="lni lni-hourglass me-1"></i> Start
                </button>
            <?php endif; ?>
            <?php if (!$is_closed): ?>
                <button class="btn btn-sm btn-dark" onclick="SET_STATUS(<?= $TASK_ID ?>, 'Completed');">
                    <i class="lni lni-check-circle-1 me-1"></i> Mark complete
                </button>
            <?php else: ?>
                <button class="btn btn-sm btn-outline-dark" onclick="SET_STATUS(<?= $TASK_ID ?>, 'Pending');">
                    <i class="lni lni-refresh-circle-1-clockwise me-1"></i> Reopen
                </button>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (!$locked_copy): ?>
            <a href="main.php?page=tasks_edit&id=<?= $TASK_ID ?>" class="btn btn-sm btn-outline-dark">
                <i class="lni lni-pencil-1 me-1"></i> Edit
            </a>
            <?php endif; ?>
            <a href="main.php?page=tasks_create&from=<?= $TASK_ID ?>" class="btn btn-sm btn-outline-dark">
                <i class="lni lni-clipboard me-1"></i> Duplicate
            </a>
            <button class="btn btn-sm btn-outline-dark"
                    onclick='SAVE_AS_TEMPLATE(<?= $TASK_ID ?>, <?= htmlspecialchars(json_encode($task['title']), ENT_QUOTES) ?>);'>
                <i class="lni lni-layers-1 me-1"></i> Save as template
            </button>
            <?php if (!$locked_copy): ?>
            <button class="btn btn-sm btn-outline-danger"
                    onclick='DELETE_TASK(<?= $TASK_ID ?>, <?= htmlspecialchars(json_encode($task['title']), ENT_QUOTES) ?>, <?= !$task['parent_id'] && $series_active ? 'true' : 'false' ?>, "main.php?page=task");'>
                <i class="lni lni-trash-3 me-1"></i> Delete
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($locked_copy): ?>
    <div class="alert alert-light border d-flex align-items-center gap-2 small mb-3" role="note">
        <i class="lni lni-locked-2"></i>
        <span>This day has passed, so it's kept as a record and can't be changed. Only today can be marked complete.</span>
    </div>
    <?php endif; ?>

    <div class="row g-3">

        <div class="col-12 col-lg-8">

            <?php if ($checkins):
                $rate = $checkins['scheduled'] ? round($checkins['done'] / $checkins['scheduled'] * 100) : null;
                $STATE_LABELS = [
                    'done'    => ['Done',     'lni-check-circle-1'],
                    'missed'  => ['Missed',   'lni-xmark-circle'],
                    'open'    => ['Not yet',  'lni-hourglass'],
                    'skipped' => ['Skipped',  'lni-trash-3'],
                ];
            ?>
            <!-- Daily check-ins -->
            <div class="card shadow-sm border-0 rounded-3 mb-3 checkins">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                        <div>
                            <h6 class="fw-bold mb-0">Check-ins</h6>
                            <small class="text-muted">Tick today once you've done it. Past days are locked.</small>
                        </div>
                        <?php if ($task['parent_id']): ?>
                            <a href="main.php?page=task_view&id=<?= (int)$series['id'] ?>" class="small text-dark">Open series</a>
                        <?php endif; ?>
                    </div>

                    <div class="row g-2 mb-3 text-center">
                        <div class="col-4">
                            <div class="rounded-3 bg-light py-2">
                                <div class="fw-bold fs-5 lh-1" id="CHECKIN_STREAK"><?= $checkins['streak'] ?></div>
                                <small class="text-muted">Current streak</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="rounded-3 bg-light py-2">
                                <div class="fw-bold fs-5 lh-1" id="CHECKIN_BEST"><?= $checkins['best'] ?></div>
                                <small class="text-muted">Best streak</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="rounded-3 bg-light py-2">
                                <div class="fw-bold fs-5 lh-1" id="CHECKIN_RATE"><?= $rate === null ? '—' : $rate . '%' ?></div>
                                <small class="text-muted" id="CHECKIN_COUNT"><?= $checkins['done'] ?> of <?= $checkins['scheduled'] ?> days</small>
                            </div>
                        </div>
                    </div>

                    <!-- Month calendar -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <?php $month_link = fn($m) => 'main.php?page=task_view&id=' . $TASK_ID . '&month=' . $m; ?>
                        <a href="<?= $month_link($prev_month) ?>"
                           class="btn btn-sm btn-light <?= $cal_month <= $first_month ? 'disabled' : '' ?>" aria-label="Previous month">
                            <i class="lni lni-arrow-left"></i>
                        </a>
                        <span class="fw-semibold small"><?= date('F Y', strtotime($cal_start)) ?></span>
                        <a href="<?= $month_link($next_month) ?>"
                           class="btn btn-sm btn-light <?= $cal_month >= $last_month ? 'disabled' : '' ?>" aria-label="Next month">
                            <i class="lni lni-arrow-right"></i>
                        </a>
                    </div>

                    <div class="checkin-grid" role="grid" aria-label="Check-ins for <?= date('F Y', strtotime($cal_start)) ?>">
                        <?php foreach (WEEKDAYS as $wd): ?>
                            <div class="checkin-head"><?= $wd ?></div>
                        <?php endforeach; ?>
                        <?php for ($i = 0; $i < $cal_offset; $i++): ?><div></div><?php endfor; ?>

                        <?php for ($day = 1; $day <= $cal_days; $day++):
                            $date  = sprintf('%s-%02d', $cal_month, $day);
                            $state = $checkins['days'][$date] ?? null;
                            $long  = date('l, M j', strtotime($date));
                        ?>
                            <?php if ($state && $date === date('Y-m-d')): [$state_label, $state_icon] = $STATE_LABELS[$state]; // only today can be ticked ?>
                                <button type="button" class="checkin-day is-<?= $state ?> is-today"
                                        data-date="<?= $date ?>"
                                        title="Today — <?= $state_label ?>"
                                        aria-label="Today, <?= $long ?>, <?= $state_label ?>"
                                        aria-pressed="<?= $state === 'done' ? 'true' : 'false' ?>"
                                        onclick="CHECKIN(<?= $TASK_ID ?>, '<?= $date ?>', <?= $state === 'done' ? 'false' : 'true' ?>);">
                                    <span class="checkin-num"><?= $day ?></span>
                                    <i class="lni <?= $state_icon ?>"></i>
                                </button>
                            <?php elseif ($state): [$state_label, $state_icon] = $STATE_LABELS[$state]; ?>
                                <div class="checkin-day is-<?= $state ?>" data-date="<?= $date ?>"
                                     title="<?= $long ?> — <?= $state_label ?> (locked)" aria-label="<?= $long ?>, <?= $state_label ?>">
                                    <span class="checkin-num"><?= $day ?></span>
                                    <i class="lni <?= $state_icon ?>"></i>
                                </div>
                            <?php else: ?>
                                <div class="checkin-day is-off <?= $date === date('Y-m-d') ? 'is-today' : '' ?>" title="<?= $long ?> — not scheduled">
                                    <span class="checkin-num"><?= $day ?></span>
                                </div>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>

                    <div class="d-flex flex-wrap gap-3 mt-3 small text-muted">
                        <?php foreach (['done', 'missed', 'open'] as $s): [$l, $ic] = $STATE_LABELS[$s]; ?>
                            <span class="d-flex align-items-center gap-1"><span class="checkin-key is-<?= $s ?>"><i class="lni <?= $ic ?>"></i></span><?= $l ?></span>
                        <?php endforeach; ?>
                        <span class="d-flex align-items-center gap-1"><span class="checkin-key is-off"></span>Not scheduled</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($task['description']): ?>
            <div class="card shadow-sm border-0 rounded-3 mb-3">
                <div class="card-body">
                    <h6 class="fw-bold mb-2">Description</h6>
                    <p class="mb-0 small" style="white-space:pre-line;"><?= htmlspecialchars($task['description']) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Checklist -->
            <div class="card shadow-sm border-0 rounded-3 mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0">Checklist</h6>
                        <small class="text-muted" id="CHECKLIST_COUNT"><?= $done_count ?> of <?= count($items) ?> done</small>
                    </div>
                    <div class="progress mb-3" style="height:6px;">
                        <div class="progress-bar bg-dark" id="CHECKLIST_BAR"
                             style="width:<?= $items ? round($done_count / count($items) * 100) : 0 ?>%"></div>
                    </div>

                    <div id="CHECKLIST_ITEMS">
                        <?php foreach ($items as $item): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="ITEM_<?= (int)$item['id'] ?>"
                                       <?= $item['is_done'] ? 'checked' : '' ?>
                                       <?= $locked ? 'disabled' : '' ?>
                                       onchange="TOGGLE_ITEM(<?= (int)$item['id'] ?>, this.checked);">
                                <label class="form-check-label small <?= $item['is_done'] ? 'text-decoration-line-through text-muted' : '' ?>"
                                       for="ITEM_<?= (int)$item['id'] ?>"><?= htmlspecialchars($item['title']) ?></label>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$items): ?>
                            <p class="text-muted small mb-2" id="CHECKLIST_EMPTY">No checklist items yet.</p>
                        <?php endif; ?>
                    </div>

                    <?php if ($locked && !$locked_copy): ?>
                        <p class="text-muted small mb-2">The first day has passed, so its ticks are locked. Items you add here are copied to future days.</p>
                    <?php endif; ?>
                    <?php if (!$locked_copy): ?>
                    <div class="input-group input-group-sm mt-2">
                        <input type="text" id="NEW_ITEM" class="form-control" maxlength="255" placeholder="Add an item"
                               onkeydown="if (event.key === 'Enter') { event.preventDefault(); ADD_ITEM(<?= $TASK_ID ?>); }">
                        <button class="btn btn-outline-dark" onclick="ADD_ITEM(<?= $TASK_ID ?>);"><i class="lni lni-plus"></i> Add</button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Progress notes -->
            <div class="card shadow-sm border-0 rounded-3 mb-3">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Progress notes</h6>

                    <?php if (!$locked_copy): ?>
                    <div class="mb-3">
                        <textarea id="NEW_NOTE" class="form-control form-control-sm" rows="2" maxlength="2000"
                                  placeholder="What happened? e.g. &quot;Called the supplier, waiting on a quote&quot;"></textarea>
                        <div class="d-flex justify-content-end mt-2">
                            <button class="btn btn-sm btn-dark" onclick="ADD_NOTE(<?= $TASK_ID ?>);">
                                <i class="lni lni-comment-1-text me-1"></i> Add note
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!$notes): ?>
                        <p class="text-muted small mb-0">No notes yet.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0 note-list">
                        <?php foreach ($notes as $n): ?>
                            <li class="d-flex gap-3 py-2 border-top">
                                <div class="rounded-3 bg-dark text-white d-flex align-items-center justify-content-center flex-shrink-0"
                                     style="width:30px;height:30px;">
                                    <i class="lni lni-comment-1-text"></i>
                                </div>
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="small text-break" style="white-space:pre-line;"><?= htmlspecialchars($n['note']) ?></div>
                                    <small class="text-muted"><?= $fmt_dt($n['created_at']) ?></small>
                                </div>
                                <?php if (!$locked_copy): ?>
                                <button class="btn btn-sm btn-link text-danger p-0 align-self-start" title="Delete note"
                                        onclick="DELETE_NOTE(<?= (int)$n['id'] ?>);">
                                    <i class="lni lni-trash-3"></i>
                                </button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Details -->
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Details</h6>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted fw-normal">Start date</dt>
                        <dd class="col-7"><?= $task['start_date'] ? date('M j, Y', strtotime($task['start_date'])) : '—' ?></dd>

                        <dt class="col-5 text-muted fw-normal">Due</dt>
                        <dd class="col-7 <?= $overdue ? 'text-danger fw-semibold' : '' ?>">
                            <?php if ($task['due_date']): ?>
                                <?= date('M j, Y', strtotime($task['due_date'])) ?>
                                <?= $task['due_time'] ? '<br>' . date('g:i A', strtotime($task['due_time'])) : '' ?>
                            <?php else: ?>—<?php endif; ?>
                        </dd>

                        <dt class="col-5 text-muted fw-normal">Reminder</dt>
                        <dd class="col-7">
                            <?php if ($task['remind_at']): ?>
                                <?= REMINDER_OPTIONS[(int)$task['remind_before']] ?? '' ?>
                                <br><span class="text-muted"><?= $fmt_dt($task['remind_at']) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </dd>

                        <dt class="col-5 text-muted fw-normal">Repeats</dt>
                        <dd class="col-7">
                            <?php if ($series_active): ?>
                                <?= htmlspecialchars(describe_repeat($series['repeat_type'], $series['repeat_days'], $series['repeat_until'])) ?>
                                <?php if ($next_occurrence): ?>
                                    <br><span class="text-muted">Next: <?= date('D, M j', strtotime($next_occurrence)) ?></span>
                                <?php endif; ?>
                                <?php if ($task['parent_id']): ?>
                                    <br><a href="main.php?page=task_view&id=<?= (int)$series['id'] ?>" class="text-dark">View series</a>
                                <?php endif; ?>
                            <?php else: ?>Does not repeat<?php endif; ?>
                        </dd>

                        <dt class="col-5 text-muted fw-normal">Created</dt>
                        <dd class="col-7"><?= $fmt_dt($task['created_at']) ?></dd>

                        <?php if ($task['completed_at'] && !$is_series_page): ?>
                        <dt class="col-5 text-muted fw-normal">Completed</dt>
                        <dd class="col-7 mb-0">
                            <?= $fmt_dt($task['completed_at']) ?>
                            <?php if ($late_by): ?>
                                <br><span class="text-danger fw-semibold"><i class="lni lni-alarm-1 me-1"></i><?= describe_late($late_by) ?></span>
                            <?php elseif ($late_by === 0): ?>
                                <br><span class="text-success"><i class="lni lni-check-circle-1 me-1"></i>On time</span>
                            <?php endif; ?>
                        </dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>

    </div>

<?php endif; ?>

</div>

<style>
/* Check-in calendar: status colours always come with an icon and a label (tooltip + legend) */
.checkins .checkin-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
.checkins .checkin-head { font-size: .7rem; color: #6c757d; text-align: center; padding-bottom: 2px; }
.checkins .checkin-day {
    aspect-ratio: 1 / 1; max-height: 56px; min-height: 36px; width: 100%;
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1px;
    border: 1px solid transparent; border-radius: 8px; background: none; padding: 0; font-size: .8rem;
}
.checkins .checkin-num   { line-height: 1; }
.checkins .checkin-day i { font-size: .8rem; }
.checkins button.checkin-day { cursor: pointer; transition: transform .1s; }
.checkins button.checkin-day:hover { transform: scale(1.06); }
.checkins button.checkin-day:focus-visible { outline: 2px solid #212529; outline-offset: 1px; }
.checkins .is-done    { background: #e4f4e4; border-color: #0ca30c; color: #0a6e0a; }
.checkins .is-missed  { background: #fbeaea; border-color: #f2c4c4; color: #b02a2a; }
.checkins .is-open    { background: #fff; border: 1px dashed #212529; color: #212529; }
.checkins .is-skipped { background: #f6f6f4; color: #9a9a96; }
.checkins .is-off     { color: #c4c4c0; }
.checkins .is-today   { box-shadow: 0 0 0 2px #212529; }
.checkins .checkin-key {
    width: 18px; height: 18px; border-radius: 5px; border: 1px solid #dee2e6;
    display: inline-flex; align-items: center; justify-content: center; font-size: .65rem;
}
</style>

<script src="<?= asset('pages/create/script.js') ?>"></script>
<script src="<?= asset('pages/task/script.js') ?>"></script>
