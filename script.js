document.addEventListener('DOMContentLoaded', function () {
    const toggler = document.querySelector('.toggler-btn');
    const sidebar = document.getElementById('sidebar');

    if (toggler && sidebar) {
        toggler.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
        });
    }

    REMINDERS.load();
    setInterval(REMINDERS.load, 60 * 1000);
});

// Reminder bell in the navbar: polls for due reminders, pops a toast for new ones.
const REMINDERS = (function () {
    const API = 'handlers/reminders.php';

    // Remember which reminders were already toasted this browser session
    function toasted() {
        try { return JSON.parse(sessionStorage.getItem('toasted_reminders') || '[]'); } catch (e) { return []; }
    }
    function markToasted(ids) {
        try { sessionStorage.setItem('toasted_reminders', JSON.stringify(ids)); } catch (e) { /* storage blocked */ }
    }

    function esc(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    function render(items) {
        const count = document.getElementById('BELL_COUNT');
        const list  = document.getElementById('BELL_LIST');
        if (!count || !list) return;

        count.textContent = items.length > 9 ? '9+' : items.length;
        count.classList.toggle('d-none', items.length === 0);

        list.innerHTML = items.length
            ? items.map(r => `
                <a href="main.php?page=task_view&id=${r.id}" class="dropdown-item d-flex gap-2 py-2 border-bottom text-wrap"
                   onclick="REMINDERS.dismiss(${r.id});">
                    <i class="lni ${r.overdue ? 'lni-alarm-1 text-danger' : 'lni-bell-1'} mt-1"></i>
                    <span class="flex-grow-1">
                        <span class="d-block small fw-semibold">${esc(r.title)}</span>
                        <span class="d-block small ${r.overdue ? 'text-danger' : 'text-muted'}">${esc(r.due)}</span>
                    </span>
                </a>`).join('')
            : '<p class="text-muted small text-center py-3 mb-0">No reminders right now.</p>';
    }

    function toast(items) {
        const seen  = toasted();
        const fresh = items.filter(r => !seen.includes(r.id));
        if (!fresh.length || typeof Swal === 'undefined') return;

        markToasted(seen.concat(fresh.map(r => r.id)));
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'info',
            title: fresh.length === 1 ? 'Reminder: ' + fresh[0].title : `You have ${fresh.length} reminders`,
            text: fresh.length === 1 ? fresh[0].due : 'Open the bell to see them.',
            showConfirmButton: false,
            timer: 7000,
            timerProgressBar: true
        });
    }

    return {
        load() {
            if (!document.getElementById('BELL')) return;
            fetch(API)
                .then(r => r.json())
                .then(res => {
                    if (!res.ok) return;
                    render(res.items);
                    toast(res.items);
                })
                .catch(() => {});
        },
        dismiss(taskId) {
            fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'task_id=' + taskId,
                keepalive: true   // still sent if the click navigates away
            }).then(() => REMINDERS.load()).catch(() => {});
        }
    };
})();
