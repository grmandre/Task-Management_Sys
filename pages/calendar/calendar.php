<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include_once 'db/db.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("
    SELECT id, title, priority, status, due_date
    FROM tbl_tasks
    WHERE user_id = ? AND is_deleted = 0 AND due_date IS NOT NULL
    ORDER BY due_date, FIELD(priority,'High','Medium','Low')
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$tasks  = [];
while ($row = $result->fetch_assoc()) { $tasks[] = $row; }
$tasks_json = json_encode($tasks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
?>

<div class="container-fluid py-4">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-0">Calendar</h4>
            <small class="text-muted">View and manage tasks by due date</small>
        </div>
        <a href="main.php?page=tasks_create" class="btn btn-dark btn-sm">
            <i class="lni lni-file-plus-circle me-1"></i> New Task
        </a>
    </div>

    <!-- Toolbar -->
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <div class="btn-group btn-group-sm" role="group" aria-label="Calendar view">
            <button id="VIEW_MONTH" class="btn btn-dark"        onclick="CAL.setView('month')">Month</button>
            <button id="VIEW_WEEK"  class="btn btn-outline-dark" onclick="CAL.setView('week')">Week</button>
            <button id="VIEW_DAY"   class="btn btn-outline-dark" onclick="CAL.setView('day')">Day</button>
        </div>
        <button class="btn btn-sm btn-outline-dark px-3" onclick="CAL.navigate(-1)">&#8249;</button>
        <span id="CAL_LABEL" class="fw-semibold text-center" style="min-width:200px"></span>
        <button class="btn btn-sm btn-outline-dark px-3" onclick="CAL.navigate(1)">&#8250;</button>
        <button class="btn btn-sm btn-outline-secondary"  onclick="CAL.goToday()">Today</button>
    </div>

    <!-- Legend -->
    <div class="d-flex gap-3 mb-3">
        <span class="d-flex align-items-center gap-1 small text-muted">
            <span class="cal-dot dot-pending"></span> Pending
        </span>
        <span class="d-flex align-items-center gap-1 small text-muted">
            <span class="cal-dot dot-progress"></span> In Progress
        </span>
        <span class="d-flex align-items-center gap-1 small text-muted">
            <span class="cal-dot dot-done"></span> Completed
        </span>
    </div>

    <div class="row g-3">
        <!-- Main calendar area -->
        <div id="CAL_GRID_COL" class="col-12 col-lg-8">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-3 p-md-4" id="CAL_BODY"></div>
            </div>
        </div>

        <!-- Side checklist panel (hidden in day view) -->
        <div id="CAL_PANEL_COL" class="col-12 col-lg-4">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-3 p-md-4">
                    <h6 class="fw-bold mb-1" id="CAL_PANEL_TITLE">Select a date</h6>
                    <div id="CAL_PANEL_BODY">
                        <p class="text-muted small mt-2">Click any day to see its tasks.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ── Shared dots ────────────────────────────────────────────── */
.cal-dot          { display:inline-block; width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.dot-done         { background:#198754; }
.dot-pending      { background:#dc3545; }
.dot-progress     { background:#fd7e14; }

/* ── Task check list items (panel + day view) ───────────────── */
.task-check-item  { display:flex; align-items:flex-start; gap:10px; padding:9px 0; border-bottom:1px solid #f0f0f0; }
.task-check-item:last-child { border-bottom:none; }
.task-check-item input[type=checkbox] { margin-top:2px; cursor:pointer; flex-shrink:0; }
.task-title-done  { text-decoration:line-through; color:#adb5bd !important; }

/* ── MONTH VIEW ─────────────────────────────────────────────── */
.cal-month-grid                 { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
.cal-month-grid.cal-header > div{ text-align:center; font-size:.72rem; font-weight:600; color:#6c757d; padding:4px 0; }
.cal-day {
    min-height:62px; border:1px solid #e9ecef; border-radius:8px;
    padding:6px 5px 4px; cursor:pointer;
    transition:background .12s, border-color .12s; user-select:none;
}
.cal-day:hover:not(.other-month) { background:#f8f9fa; border-color:#ced4da; }
.cal-day.today                   { border-color:#212529 !important; }
.cal-day.today .cal-day-num      { color:#212529; font-weight:700; }
.cal-day.selected                { background:#212529 !important; border-color:#212529 !important; }
.cal-day.selected .cal-day-num   { color:#fff; }
.cal-day.other-month             { opacity:.3; cursor:default; pointer-events:none; }
.cal-day-num  { font-size:.78rem; font-weight:600; line-height:1; }
.cal-dots     { display:flex; flex-wrap:wrap; gap:3px; margin-top:5px; }

/* ── WEEK VIEW ──────────────────────────────────────────────── */
.cal-week-grid {
    display:grid; grid-template-columns:repeat(7,1fr);
    gap:4px; overflow-x:auto;
}
.cal-week-col {
    border:1px solid #e9ecef; border-radius:8px; overflow:hidden;
    cursor:pointer; transition:border-color .12s, background .12s;
    min-width:80px;
}
.cal-week-col:hover    { background:#f8f9fa; border-color:#ced4da; }
.cal-week-col.today    { border-color:#212529; }
.cal-week-col.selected { background:#f0f0f0; border-color:#212529; }

.cal-week-hdr {
    text-align:center; padding:8px 4px 6px;
    border-bottom:1px solid #f0f0f0;
    background:#fafafa;
}
.cal-week-dayname { font-size:.7rem; font-weight:600; color:#6c757d; text-transform:uppercase; }
.cal-week-daynum  { font-size:1.1rem; font-weight:700; line-height:1.2; }
.today-num {
    display:inline-flex; align-items:center; justify-content:center;
    width:30px; height:30px; border-radius:50%;
    background:#212529; color:#fff; font-size:1rem;
}

.cal-week-tasks  { padding:6px 5px; min-height:60px; }
.cal-week-empty  { font-size:.72rem; color:#ced4da; }
.cal-task-chip   { display:flex; align-items:center; gap:4px; margin-bottom:4px; overflow:hidden; }
.cal-task-chip .chip-title { font-size:.7rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cal-week-more   { font-size:.68rem; color:#6c757d; margin-top:2px; }

/* ── DAY VIEW ───────────────────────────────────────────────── */
.day-view-title  { font-size:1.1rem; font-weight:700; border-bottom:1px solid #f0f0f0; padding-bottom:10px; margin-bottom:12px; }
</style>

<script>
window.CAL = (function () {
    const TASKS = <?= $tasks_json ?>;

    /* Index tasks by "YYYY-MM-DD" */
    const byDate = {};
    TASKS.forEach(t => {
        if (!byDate[t.due_date]) byDate[t.due_date] = [];
        byDate[t.due_date].push(t);
    });

    const MONTHS_LONG  = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const DAYS_LONG    = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const DAYS_SHORT   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    let viewDate     = new Date(today);
    let currentView  = 'month';
    let selectedDate = null;

    /* ── Utilities ─────────────────────────────────────────────────────────── */
    function pad(n)        { return String(n).padStart(2, '0'); }
    function toKey(dt)     { return `${dt.getFullYear()}-${pad(dt.getMonth()+1)}-${pad(dt.getDate())}`; }
    function todayKey()    { return toKey(today); }
    function addDays(dt,n) { const d = new Date(dt); d.setDate(d.getDate() + n); return d; }
    function weekStart(dt) { const d = new Date(dt); d.setDate(d.getDate() - d.getDay()); return d; }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function dotCls(status) {
        if (status === 'Completed')   return 'dot-done';
        if (status === 'In Progress') return 'dot-progress';
        return 'dot-pending';
    }
    function priBadge(p) {
        if (p === 'High')   return 'bg-danger';
        if (p === 'Medium') return 'bg-warning text-dark';
        return 'bg-secondary';
    }

    /* ── Shared task row HTML ───────────────────────────────────────────────── */
    function taskRowHtml(t) {
        return `<div class="task-check-item" id="row-${t.id}">
            <input type="checkbox" class="form-check-input mt-1"
                   ${t.status === 'Completed' ? 'checked' : ''}
                   onchange="CAL.toggle(${t.id}, this.checked)">
            <div class="flex-grow-1 overflow-hidden">
                <div class="small fw-semibold text-truncate ${t.status === 'Completed' ? 'task-title-done' : ''}"
                     id="ttl-${t.id}" title="${escHtml(t.title)}">
                    ${escHtml(t.title)}
                </div>
                <span class="badge ${priBadge(t.priority)} mt-1" style="font-size:.62rem">
                    ${escHtml(t.priority)}
                </span>
            </div>
        </div>`;
    }

    /* ── Navigation ─────────────────────────────────────────────────────────── */
    function navigate(dir) {
        if (currentView === 'month') {
            viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + dir, 1);
        } else if (currentView === 'week') {
            viewDate = addDays(viewDate, dir * 7);
        } else {
            viewDate = addDays(viewDate, dir);
        }
        render();
    }

    function goToday() {
        viewDate = new Date(today);
        render();
        if (currentView !== 'day') {
            selectedDate = todayKey();
            render();
            showPanel(today);
        }
    }

    /* ── View switcher ──────────────────────────────────────────────────────── */
    function setView(v) {
        currentView = v;
        ['MONTH','WEEK','DAY'].forEach(name => {
            const btn = document.getElementById('VIEW_' + name);
            btn.className = 'btn btn-sm ' + (v === name.toLowerCase() ? 'btn-dark' : 'btn-outline-dark');
        });
        const panelCol = document.getElementById('CAL_PANEL_COL');
        const gridCol  = document.getElementById('CAL_GRID_COL');
        if (v === 'day') {
            panelCol.style.display = 'none';
            gridCol.className = 'col-12';
        } else {
            panelCol.style.display = '';
            gridCol.className = 'col-12 col-lg-8';
        }
        render();
    }

    /* ── Label ──────────────────────────────────────────────────────────────── */
    function updateLabel() {
        let text = '';
        if (currentView === 'month') {
            text = `${MONTHS_LONG[viewDate.getMonth()]} ${viewDate.getFullYear()}`;
        } else if (currentView === 'week') {
            const ws = weekStart(viewDate);
            const we = addDays(ws, 6);
            if (ws.getMonth() === we.getMonth()) {
                text = `${MONTHS_SHORT[ws.getMonth()]} ${ws.getDate()}–${we.getDate()}, ${ws.getFullYear()}`;
            } else {
                text = `${MONTHS_SHORT[ws.getMonth()]} ${ws.getDate()} – ${MONTHS_SHORT[we.getMonth()]} ${we.getDate()}, ${we.getFullYear()}`;
            }
        } else {
            text = `${DAYS_LONG[viewDate.getDay()]}, ${MONTHS_SHORT[viewDate.getMonth()]} ${viewDate.getDate()}, ${viewDate.getFullYear()}`;
        }
        document.getElementById('CAL_LABEL').textContent = text;
    }

    /* ── Main render dispatcher ─────────────────────────────────────────────── */
    function render() {
        updateLabel();
        if      (currentView === 'month') renderMonth();
        else if (currentView === 'week')  renderWeek();
        else                              renderDay();
    }

    /* ── MONTH VIEW ─────────────────────────────────────────────────────────── */
    function renderMonth() {
        const body = document.getElementById('CAL_BODY');
        const y = viewDate.getFullYear(), m = viewDate.getMonth();

        const headerHtml = `<div class="cal-month-grid cal-header mb-1">
            ${DAYS_SHORT.map(d => `<div>${d}</div>`).join('')}
        </div>`;

        const grid       = document.createElement('div');
        grid.className   = 'cal-month-grid';

        const firstDow   = new Date(y, m, 1).getDay();
        const daysInMon  = new Date(y, m + 1, 0).getDate();
        const prevMonLen = new Date(y, m, 0).getDate();

        for (let i = firstDow - 1; i >= 0; i--)
            grid.appendChild(monthCell(new Date(y, m - 1, prevMonLen - i), true));
        for (let d = 1; d <= daysInMon; d++)
            grid.appendChild(monthCell(new Date(y, m, d), false));
        const tail = (firstDow + daysInMon) % 7;
        for (let d = 1; d < (tail === 0 ? 1 : 8 - tail); d++)
            grid.appendChild(monthCell(new Date(y, m + 1, d), true));

        body.innerHTML = headerHtml;
        body.appendChild(grid);
    }

    function monthCell(dt, ghost) {
        const key  = toKey(dt);
        const cell = document.createElement('div');
        cell.className = 'cal-day' + (ghost ? ' other-month' : '');
        if (key === todayKey())   cell.classList.add('today');
        if (key === selectedDate) cell.classList.add('selected');

        const num       = document.createElement('div');
        num.className   = 'cal-day-num';
        num.textContent = dt.getDate();
        cell.appendChild(num);

        const tasks = byDate[key] || [];
        if (tasks.length) {
            const dots = document.createElement('div');
            dots.className = 'cal-dots';
            tasks.slice(0, 6).forEach(t => {
                const dot = document.createElement('span');
                dot.className = 'cal-dot ' + dotCls(t.status);
                dots.appendChild(dot);
            });
            cell.appendChild(dots);
        }

        if (!ghost) {
            cell.addEventListener('click', () => {
                selectedDate = key;
                render();
                showPanel(dt);
            });
        }
        return cell;
    }

    /* ── WEEK VIEW ──────────────────────────────────────────────────────────── */
    function renderWeek() {
        const ws   = weekStart(viewDate);
        const body = document.getElementById('CAL_BODY');
        body.innerHTML = '';

        const grid     = document.createElement('div');
        grid.className = 'cal-week-grid';

        for (let i = 0; i < 7; i++) {
            const dt    = addDays(ws, i);
            const key   = toKey(dt);
            const tasks = byDate[key] || [];

            const col = document.createElement('div');
            col.className = 'cal-week-col'
                + (key === todayKey()   ? ' today'    : '')
                + (key === selectedDate ? ' selected' : '');

            /* Day header */
            const isToday   = key === todayKey();
            const numHtml   = isToday
                ? `<span class="today-num">${dt.getDate()}</span>`
                : `<span>${dt.getDate()}</span>`;
            col.innerHTML = `
                <div class="cal-week-hdr">
                    <div class="cal-week-dayname">${DAYS_SHORT[dt.getDay()]}</div>
                    <div class="cal-week-daynum">${numHtml}</div>
                </div>`;

            /* Task chips */
            const listDiv     = document.createElement('div');
            listDiv.className = 'cal-week-tasks';

            if (!tasks.length) {
                listDiv.innerHTML = '<span class="cal-week-empty">—</span>';
            } else {
                tasks.slice(0, 4).forEach(t => {
                    const chip     = document.createElement('div');
                    chip.className = 'cal-task-chip';
                    chip.innerHTML = `<span class="cal-dot ${dotCls(t.status)}"></span>
                                      <span class="chip-title">${escHtml(t.title)}</span>`;
                    listDiv.appendChild(chip);
                });
                if (tasks.length > 4) {
                    const more     = document.createElement('div');
                    more.className = 'cal-week-more';
                    more.textContent = `+${tasks.length - 4} more`;
                    listDiv.appendChild(more);
                }
            }
            col.appendChild(listDiv);

            col.addEventListener('click', () => {
                selectedDate = key;
                viewDate     = new Date(dt);
                render();
                showPanel(dt);
            });

            grid.appendChild(col);
        }
        body.appendChild(grid);
    }

    /* ── DAY VIEW ───────────────────────────────────────────────────────────── */
    function renderDay() {
        const key   = toKey(viewDate);
        const tasks = byDate[key] || [];
        const body  = document.getElementById('CAL_BODY');
        const label = `${DAYS_LONG[viewDate.getDay()]}, ${MONTHS_LONG[viewDate.getMonth()]} ${viewDate.getDate()}, ${viewDate.getFullYear()}`;

        if (!tasks.length) {
            body.innerHTML = `
                <div class="day-view-title">${label}</div>
                <div class="text-center py-4">
                    <p class="text-muted small mb-3">No tasks due on this day.</p>
                    <a href="main.php?page=tasks_create&due=${key}" class="btn btn-dark btn-sm">
                        <i class="lni lni-file-plus-circle me-1"></i> Add Task
                    </a>
                </div>`;
            return;
        }

        body.innerHTML = `
            <div class="day-view-title">${label}</div>
            <div id="DAY_LIST">${tasks.map(taskRowHtml).join('')}</div>
            <a href="main.php?page=tasks_create&due=${key}" class="btn btn-sm btn-outline-dark mt-3">
                <i class="lni lni-plus"></i> Add Task
            </a>`;
    }

    /* ── Side panel (month + week views) ───────────────────────────────────── */
    function showPanel(dt) {
        const key   = toKey(dt);
        const tasks = byDate[key] || [];
        const label = `${MONTHS_SHORT[dt.getMonth()]} ${dt.getDate()}, ${dt.getFullYear()}`;

        document.getElementById('CAL_PANEL_TITLE').textContent = label;
        const body = document.getElementById('CAL_PANEL_BODY');

        if (!tasks.length) {
            body.innerHTML = `
                <p class="text-muted small mt-2">No tasks due on this day.</p>
                <a href="main.php?page=tasks_create&due=${key}" class="btn btn-sm btn-outline-dark mt-1">
                    <i class="lni lni-plus"></i> Add Task
                </a>`;
            return;
        }
        body.innerHTML = tasks.map(taskRowHtml).join('');
    }

    /* ── Toggle task status ─────────────────────────────────────────────────── */
    function toggle(taskId, done) {
        const newStatus = done ? 'Completed' : 'Pending';

        fetch('handlers/toggle_task_status_handler.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    `task_id=${taskId}&status=${encodeURIComponent(newStatus)}`
        })
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;

            /* Update in-memory task */
            const task = TASKS.find(x => x.id == taskId);
            if (task) task.status = newStatus;

            if (currentView === 'day') {
                /* Only update the title style — avoid full re-render flicker */
                const ttl = document.getElementById('ttl-' + taskId);
                if (ttl) ttl.classList.toggle('task-title-done', done);
            } else {
                /* Re-render calendar grid (updates dots) + refresh panel */
                render();
                if (selectedDate) {
                    const [y, m, d] = selectedDate.split('-').map(Number);
                    showPanel(new Date(y, m - 1, d));
                }
            }
        })
        .catch(() => {});
    }

    /* ── Boot ───────────────────────────────────────────────────────────────── */
    render();

    /* Auto-select today if it has tasks */
    if (byDate[todayKey()]) {
        selectedDate = todayKey();
        render();
        showPanel(today);
    }

    return { navigate, setView, goToday, toggle };
})();
</script>
