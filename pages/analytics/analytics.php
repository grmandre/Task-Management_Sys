<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once 'db/db.php';

$is_admin = ($_SESSION['role'] ?? '') === 'Admin';

// ── Filters ────────────────────────────────────────────────────────────────
// Admins can look at everyone (user=0) or one user; regular users only see themselves.
$RANGES = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];
$days   = array_key_exists((int)($_GET['range'] ?? 30), $RANGES) ? (int)($_GET['range'] ?? 30) : 30;
$target = $is_admin ? intval($_GET['user'] ?? 0) : (int)$_SESSION['user_id'];
$since  = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

$users = $is_admin
    ? $conn->query("SELECT id, username, first_name, last_name FROM vw_user ORDER BY first_name, last_name")->fetch_all(MYSQLI_ASSOC)
    : [];

$scope  = $target ? 'user_id = :uid' : '1 = 1';
$params = $target ? [':uid' => $target, ':since' => $since] : [':since' => $since];

$q = function ($sql, $p) {
    $stmt = $GLOBALS['db']->prepare($sql);
    $stmt->execute($p);
    return $stmt;
};

// ── Task outcomes for tasks created in the range ───────────────────────────
// Every task lands in exactly one bucket: on time / late / still open / overdue / dropped.
$o = $q("
    SELECT COUNT(*) AS total,
        SUM(status = 'Completed') AS completed,
        SUM(status = 'Completed' AND (due_date IS NULL OR completed_at IS NULL OR DATE(completed_at) <= due_date)) AS on_time,
        SUM(status = 'Completed' AND due_date IS NOT NULL AND DATE(completed_at) > due_date) AS late,
        SUM(is_deleted = 0 AND status IN ('Pending','In Progress') AND due_date < CURDATE()) AS overdue,
        SUM(is_deleted = 0 AND status IN ('Pending','In Progress') AND (due_date IS NULL OR due_date >= CURDATE())) AS open,
        SUM(status <> 'Completed' AND (is_deleted = 1 OR status = 'Archived')) AS dropped,
        AVG(CASE WHEN status = 'Completed' AND completed_at IS NOT NULL
                 THEN TIMESTAMPDIFF(MINUTE, created_at, completed_at) END) / 1440 AS avg_days
    FROM tbl_tasks
    WHERE $scope AND created_at >= :since
", $params)->fetch(PDO::FETCH_ASSOC);

$o = array_map(fn($v) => $v === null ? 0 : $v, $o);
$total      = (int)$o['total'];
$completed  = (int)$o['completed'];
$pct        = fn($n, $of) => $of > 0 ? round($n / $of * 100) : null;

$completion_rate = $pct($completed, $total);
$on_time_rate    = $pct((int)$o['on_time'], $completed);
$unfinished_rate = $pct((int)$o['overdue'] + (int)$o['dropped'], $total);
$avg_days        = $completed > 0 ? (float)$o['avg_days'] : null;

// ── Daily series ───────────────────────────────────────────────────────────
$created_by_day = $q("
    SELECT DATE(created_at) AS d, COUNT(*) AS n
    FROM tbl_tasks WHERE $scope AND created_at >= :since GROUP BY d
", $params)->fetchAll(PDO::FETCH_KEY_PAIR);

$completed_by_day = $q("
    SELECT DATE(completed_at) AS d, COUNT(*) AS n
    FROM tbl_tasks WHERE $scope AND status = 'Completed' AND completed_at >= :since GROUP BY d
", $params)->fetchAll(PDO::FETCH_KEY_PAIR);

$usage_rows = $q("
    SELECT DATE(created_at) AS d, COUNT(*) AS actions, SUM(action = 'login') AS logins, COUNT(DISTINCT user_id) AS users
    FROM tbl_activity_logs WHERE $scope AND created_at >= :since GROUP BY d
", $params)->fetchAll(PDO::FETCH_ASSOC);
$usage_by_day = array_column($usage_rows, null, 'd');

// One entry per calendar day, zero-filled
$series = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $series[] = [
        'date'      => $d,
        'label'     => date('M j', strtotime($d)),
        'created'   => (int)($created_by_day[$d] ?? 0),
        'completed' => (int)($completed_by_day[$d] ?? 0),
        'actions'   => (int)($usage_by_day[$d]['actions'] ?? 0),
        'logins'    => (int)($usage_by_day[$d]['logins'] ?? 0),
        'users'     => (int)($usage_by_day[$d]['users'] ?? 0),
    ];
}
$active_days   = count(array_filter($series, fn($s) => $s['actions'] > 0));
$tasks_per_day = $total / $days;

// ── Per-user tendencies (admin, all users) ─────────────────────────────────
$per_user = [];
if ($is_admin && !$target) {
    $per_user = $q("
        SELECT u.id, u.username, u.first_name, u.last_name,
            COUNT(t.id) AS total,
            COALESCE(SUM(t.status = 'Completed'), 0) AS completed,
            COALESCE(SUM(t.status = 'Completed' AND t.due_date IS NOT NULL AND DATE(t.completed_at) > t.due_date), 0) AS late,
            COALESCE(SUM(t.is_deleted = 0 AND t.status IN ('Pending','In Progress') AND t.due_date < CURDATE()), 0) AS overdue,
            COALESCE(SUM(t.status <> 'Completed' AND (t.is_deleted = 1 OR t.status = 'Archived')), 0) AS dropped,
            AVG(CASE WHEN t.status = 'Completed' AND t.completed_at IS NOT NULL
                     THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.completed_at) END) / 1440 AS avg_days,
            (SELECT COUNT(DISTINCT DATE(l.created_at)) FROM tbl_activity_logs l
              WHERE l.user_id = u.id AND l.created_at >= :since2) AS active_days
        FROM vw_user u
        LEFT JOIN tbl_tasks t ON t.user_id = u.id AND t.created_at >= :since
        GROUP BY u.id
        ORDER BY total DESC, u.first_name
    ", [':since' => $since, ':since2' => $since])->fetchAll(PDO::FETCH_ASSOC);
}

// Needs a few tasks before a tendency means anything.
function tendency($total, $completed, $unfinished) {
    if ($total < 3) {
        return ['Not enough data', 'bg-light text-muted border', 'lni-hourglass'];
    }
    $done = $completed / $total * 100;
    $miss = $unfinished / $total * 100;
    if ($done >= 70 && $miss < 20) return ['Tends to finish',     'bg-success',           'lni-check-circle-1'];
    if ($done < 40 || $miss >= 40) return ['Tends not to finish', 'bg-danger',            'lni-xmark-circle'];
    return                                ['Mixed',               'bg-warning text-dark', 'lni-trend-down-1'];
}

$subject_name = 'All users';
foreach ($users as $u) {
    if ((int)$u['id'] === $target) {
        $subject_name = trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['username'];
    }
}
if (!$is_admin) {
    $subject_name = 'You';
}
[$t_label, $t_class, $t_icon] = tendency($total, $completed, (int)$o['overdue'] + (int)$o['dropped']);

$fmt_pct  = fn($v) => $v === null ? '—' : $v . '%';
$fmt_days = fn($v) => $v === null ? '—' : ($v < 1 ? round($v * 24, 1) . ' hrs' : round($v, 1) . ' days');

$OUTCOMES = [
    ['Completed on time',            (int)$o['on_time'], '#0ca30c', 'lni-check-circle-1'],
    ['Completed late',               (int)$o['late'],    '#fab219', 'lni-alarm-1'],
    ['Still open (not yet due)',     (int)$o['open'],    '#9a9a96', 'lni-hourglass'],
    ['Overdue and unfinished',       (int)$o['overdue'], '#d03b3b', 'lni-xmark-circle'],
    ['Dropped (deleted or archived)',(int)$o['dropped'], '#ec835a', 'lni-trash-3'],
];
?>

<div class="container-fluid analytics">

    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-0">Analytics</h4>
            <small class="text-muted">How tasks get done — <?= htmlspecialchars($subject_name) ?>, <?= strtolower($RANGES[$days]) ?></small>
        </div>
        <?php if ($target): // a tendency describes one person, not everyone combined ?>
        <span class="badge <?= $t_class ?> px-3 py-2" title="Tendency">
            <i class="lni <?= $t_icon ?> me-1"></i> <?= $t_label ?>
        </span>
        <?php endif; ?>
    </div>

    <!-- Filters: one row, above every chart -->
    <form method="GET" class="d-flex flex-wrap align-items-center gap-2 mb-4">
        <input type="hidden" name="page" value="analytics">
        <input type="hidden" name="range" value="<?= $days ?>">

        <?php if ($is_admin): ?>
        <select name="user" class="form-select form-select-sm" style="max-width:240px;" onchange="this.form.submit();" aria-label="User">
            <option value="0">All users</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $target ? 'selected' : '' ?>>
                    <?= htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['username']) ?> (<?= htmlspecialchars($u['username']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <div class="btn-group btn-group-sm" role="group" aria-label="Date range">
            <?php foreach ($RANGES as $r => $label): ?>
                <a href="main.php?page=analytics&range=<?= $r ?><?= $target && $is_admin ? '&user=' . $target : '' ?>"
                   class="btn <?= $r === $days ? 'btn-dark' : 'btn-outline-dark' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </form>

    <!-- Stat tiles -->
    <div class="row g-3 mb-4">
        <?php
        $tiles = [
            ['Completion rate',      $fmt_pct($completion_rate), "$completed of $total tasks completed",                               'lni-check-circle-1'],
            ['On-time rate',         $fmt_pct($on_time_rate),    (int)$o['on_time'] . ' finished by their due date',                    'lni-stopwatch'],
            ['Avg. time to finish',  $fmt_days($avg_days),       'From creation to completion',                                        'lni-hourglass'],
            ['Left unfinished',      $fmt_pct($unfinished_rate), ((int)$o['overdue'] + (int)$o['dropped']) . ' overdue or dropped',    'lni-trend-down-1'],
        ];
        foreach ($tiles as [$label, $value, $sub, $icon]): ?>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 text-muted small mb-2">
                        <i class="lni <?= $icon ?>"></i> <?= $label ?>
                    </div>
                    <div class="stat-value fw-bold lh-1"><?= $value ?></div>
                    <div class="text-muted small mt-2"><?= $sub ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-4">

        <!-- Tasks created vs completed per day -->
        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-1">
                        <div>
                            <h6 class="fw-bold mb-0">Tasks made vs. completed per day</h6>
                            <small class="text-muted">
                                <?= number_format($tasks_per_day, 1) ?> tasks made per day on average
                            </small>
                        </div>
                        <div class="d-flex gap-3 small text-muted" aria-hidden="true">
                            <span class="d-flex align-items-center gap-1"><span class="legend-line" style="background:var(--series-1)"></span> Made</span>
                            <span class="d-flex align-items-center gap-1"><span class="legend-line" style="background:var(--series-2)"></span> Completed</span>
                        </div>
                    </div>
                    <div class="chart-box"><canvas id="CHART_TASKS" role="img" aria-label="Line chart of tasks made and completed per day"></canvas></div>
                    <details class="mt-2 small">
                        <summary class="text-muted">View as table</summary>
                        <div class="table-responsive data-table mt-2">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Date</th><th class="text-end">Made</th><th class="text-end">Completed</th></tr></thead>
                                <tbody>
                                <?php foreach (array_reverse($series) as $s): ?>
                                    <tr><td><?= date('D, M j', strtotime($s['date'])) ?></td><td class="text-end"><?= $s['created'] ?></td><td class="text-end"><?= $s['completed'] ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            </div>
        </div>

        <!-- Outcomes: finishing vs not finishing -->
        <div class="col-12 col-xl-4">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-body">
                    <h6 class="fw-bold mb-0">Task outcomes</h6>
                    <small class="text-muted d-block mb-3">What happened to the <?= $total ?> task<?= $total === 1 ? '' : 's' ?> made in this period</small>

                    <?php if ($total === 0): ?>
                        <p class="text-muted small mb-0">No tasks were made in this period.</p>
                    <?php else: foreach ($OUTCOMES as [$label, $n, $color, $icon]):
                        $share = round($n / $total * 100); ?>
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-2 small mb-1">
                                <i class="lni <?= $icon ?>" style="color:<?= $color ?>"></i>
                                <span class="flex-grow-1"><?= $label ?></span>
                                <span class="fw-semibold"><?= $n ?></span>
                                <span class="text-muted" style="min-width:38px;text-align:right;"><?= $share ?>%</span>
                            </div>
                            <div class="meter" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $share ?>" aria-label="<?= $label ?>">
                                <div class="meter-fill" style="width:<?= $share ?>%;background:<?= $color ?>;"></div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- Daily usage -->
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body">
                    <h6 class="fw-bold mb-0">Daily usage</h6>
                    <small class="text-muted d-block mb-1">
                        Actions per day (sign-ins, task and profile changes) — active on <?= $active_days ?> of <?= $days ?> days
                    </small>
                    <div class="chart-box"><canvas id="CHART_USAGE" role="img" aria-label="Bar chart of actions per day"></canvas></div>
                    <details class="mt-2 small">
                        <summary class="text-muted">View as table</summary>
                        <div class="table-responsive data-table mt-2">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Date</th><th class="text-end">Actions</th><th class="text-end">Sign-ins</th><?php if (!$target): ?><th class="text-end">Active users</th><?php endif; ?></tr></thead>
                                <tbody>
                                <?php foreach (array_reverse($series) as $s): ?>
                                    <tr>
                                        <td><?= date('D, M j', strtotime($s['date'])) ?></td>
                                        <td class="text-end"><?= $s['actions'] ?></td>
                                        <td class="text-end"><?= $s['logins'] ?></td>
                                        <?php if (!$target): ?><td class="text-end"><?= $s['users'] ?></td><?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </div>

    <?php if ($per_user): ?>
    <!-- Per-user tendencies -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-0">
            <div class="p-3 p-md-4 pb-3 pb-md-3">
                <h6 class="fw-bold mb-0">User tendencies</h6>
                <small class="text-muted">
                    Based on tasks made <?= strtolower($RANGES[$days]) ?>. A tendency needs at least 3 tasks.
                </small>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">User</th>
                            <th class="text-end">Tasks made</th>
                            <th class="text-end">Completed</th>
                            <th class="text-end">Completed late</th>
                            <th class="text-end">Overdue</th>
                            <th class="text-end">Dropped</th>
                            <th class="text-end">Avg. time to finish</th>
                            <th class="text-end">Active days</th>
                            <th class="pe-4">Tendency</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($per_user as $u):
                        $ut = (int)$u['total'];
                        $uc = (int)$u['completed'];
                        $uu = (int)$u['overdue'] + (int)$u['dropped'];
                        [$label, $class, $icon] = tendency($ut, $uc, $uu);
                        $name = trim($u['first_name'] . ' ' . $u['last_name']) ?: $u['username'];
                    ?>
                        <tr>
                            <td class="ps-4">
                                <a href="main.php?page=analytics&range=<?= $days ?>&user=<?= (int)$u['id'] ?>" class="fw-semibold text-dark">
                                    <?= htmlspecialchars($name) ?>
                                </a>
                                <div class="small text-muted"><?= htmlspecialchars($u['username']) ?></div>
                            </td>
                            <td data-label="Tasks made" class="text-end"><?= $ut ?></td>
                            <td data-label="Completed" class="text-end"><?= $uc ?> <span class="text-muted small">(<?= $fmt_pct($pct($uc, $ut)) ?>)</span></td>
                            <td data-label="Completed late" class="text-end"><?= (int)$u['late'] ?></td>
                            <td data-label="Overdue" class="text-end"><?= (int)$u['overdue'] ?></td>
                            <td data-label="Dropped" class="text-end"><?= (int)$u['dropped'] ?></td>
                            <td data-label="Avg. time to finish" class="text-end"><?= $fmt_days($uc > 0 ? (float)$u['avg_days'] : null) ?></td>
                            <td data-label="Active days" class="text-end"><?= (int)$u['active_days'] ?></td>
                            <td data-label="Tendency" class="pe-4"><span class="badge <?= $class ?>"><i class="lni <?= $icon ?> me-1"></i><?= $label ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<style>
.analytics {
    /* Validated categorical slots 1–2 (blue, orange) on the white card surface */
    --series-1: #2a78d6;
    --series-2: #eb6834;
}
.analytics .stat-value  { font-size: 2rem; }
.analytics .chart-box   { position: relative; height: 260px; }
.analytics .legend-line { display: inline-block; width: 14px; height: 2px; border-radius: 2px; }
.analytics .meter       { height: 6px; background: #f0f0ee; border-radius: 3px; overflow: hidden; }
.analytics .meter-fill  { height: 100%; border-radius: 3px; }
.analytics .data-table  { max-height: 240px; overflow-y: auto; }
.analytics details summary { cursor: pointer; }
@media (max-width: 575.98px) {
    .analytics .stat-value { font-size: 1.5rem; }
    .analytics .chart-box  { height: 200px; }
}
</style>

<script src="assets/plugins/chartjs/chart.min.js"></script>
<script>
(function () {
    const SERIES = <?= json_encode($series, JSON_HEX_TAG) ?>;
    const ALL_USERS = <?= $target ? 'false' : 'true' ?>;
    const css = getComputedStyle(document.querySelector('.analytics'));
    const C1 = css.getPropertyValue('--series-1').trim();
    const C2 = css.getPropertyValue('--series-2').trim();
    const INK_MUTED = '#6c757d', GRID = '#ececea', SURFACE = '#ffffff';

    Chart.defaults.font.family = "'Figtree', sans-serif";
    Chart.defaults.color = INK_MUTED;

    // Vertical crosshair at the hovered day
    const crosshair = {
        id: 'crosshair',
        afterDraw(chart) {
            const active = chart.tooltip && chart.tooltip.getActiveElements();
            if (!active || !active.length || chart.config.type !== 'line') return;
            const x = active[0].element.x, { top, bottom } = chart.chartArea, ctx = chart.ctx;
            ctx.save();
            ctx.beginPath(); ctx.moveTo(x, top); ctx.lineTo(x, bottom);
            ctx.lineWidth = 1; ctx.strokeStyle = '#adb5bd'; ctx.stroke();
            ctx.restore();
        }
    };

    const fullDate = i => new Date(SERIES[i].date + 'T00:00:00')
        .toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });

    const baseOptions = {
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#212529', padding: 10, cornerRadius: 6,
                boxWidth: 8, boxHeight: 8, usePointStyle: true,
                callbacks: { title: items => fullDate(items[0].dataIndex) }
            }
        },
        scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0 } },
            y: { beginAtZero: true, grid: { color: GRID, drawBorder: false }, ticks: { precision: 0, maxTicksLimit: 5 } }
        }
    };

    const line = (label, key, color) => ({
        label, data: SERIES.map(s => s[key]),
        borderColor: color, backgroundColor: color, borderWidth: 2,
        borderJoinStyle: 'round', borderCapStyle: 'round', tension: 0,
        pointRadius: 0, pointHoverRadius: 5, pointHoverBorderWidth: 2, pointHoverBorderColor: SURFACE
    });

    new Chart(document.getElementById('CHART_TASKS'), {
        type: 'line',
        data: {
            labels: SERIES.map(s => s.label),
            datasets: [line('Made', 'created', C1), line('Completed', 'completed', C2)]
        },
        options: baseOptions,
        plugins: [crosshair]
    });

    new Chart(document.getElementById('CHART_USAGE'), {
        type: 'bar',
        data: {
            labels: SERIES.map(s => s.label),
            datasets: [{
                label: 'Actions', data: SERIES.map(s => s.actions),
                backgroundColor: C1, hoverBackgroundColor: C1,
                borderRadius: 4, borderSkipped: 'start', maxBarThickness: 24,
                categoryPercentage: 0.9, barPercentage: 0.9
            }]
        },
        options: {
            ...baseOptions,
            // offset keeps half a band of air at each end so the first/last bars aren't clipped
            scales: { ...baseOptions.scales, x: { ...baseOptions.scales.x, offset: true } },
            plugins: {
                ...baseOptions.plugins,
                tooltip: {
                    ...baseOptions.plugins.tooltip,
                    callbacks: {
                        title: items => fullDate(items[0].dataIndex),
                        label: item => `${item.raw} action${item.raw === 1 ? '' : 's'}`,
                        afterLabel: item => {
                            const s = SERIES[item.dataIndex];
                            let extra = `${s.logins} sign-in${s.logins === 1 ? '' : 's'}`;
                            if (ALL_USERS) extra += ` · ${s.users} active user${s.users === 1 ? '' : 's'}`;
                            return extra;
                        }
                    }
                }
            }
        }
    });
})();
</script>
