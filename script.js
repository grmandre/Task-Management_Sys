document.addEventListener('DOMContentLoaded', function () {
    const toggler  = document.querySelector('.toggler-btn');
    const sidebar  = document.getElementById('sidebar');
    const backdrop = document.getElementById('SIDEBAR_BACKDROP');
    // Same breakpoint as the drawer styles in styles.css
    const phone    = window.matchMedia('(max-width: 767.98px)');

    // Phones: the sidebar slides in over the page. Wider screens: it collapses to icons.
    function setDrawer(open) {
        sidebar.classList.toggle('mobile-open', open);
        if (backdrop) backdrop.classList.toggle('show', open);
        document.body.classList.toggle('sidebar-open', open);
        toggler.setAttribute('aria-expanded', open);
    }

    if (toggler && sidebar) {
        toggler.addEventListener('click', function () {
            if (phone.matches) {
                sidebar.classList.remove('collapsed');   // the drawer always shows full labels
                setDrawer(!sidebar.classList.contains('mobile-open'));
            } else {
                sidebar.classList.toggle('collapsed');
            }
        });
        if (backdrop) backdrop.addEventListener('click', () => setDrawer(false));
        document.addEventListener('keydown', e => { if (e.key === 'Escape') setDrawer(false); });
        // Rotating / resizing past the breakpoint shouldn't leave the drawer open
        phone.addEventListener('change', e => { if (!e.matches) setDrawer(false); });
    }

    REMINDERS.load();
    setInterval(REMINDERS.load, 60 * 1000);
});

// Eye button next to a password field: shows / hides what was typed
function TOGGLE_PASSWORD(btn) {
    const input = btn.parentElement.querySelector('input');
    const show  = input.type === 'password';
    input.type  = show ? 'text' : 'password';
    btn.classList.toggle('active', show);
    btn.setAttribute('aria-pressed', show);
    btn.title = show ? 'Hide password' : 'Show password';
    btn.setAttribute('aria-label', btn.title);
}

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

