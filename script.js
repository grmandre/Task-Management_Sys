document.addEventListener('DOMContentLoaded', function () {
    const toggler = document.querySelector('.toggler-btn');
    const sidebar = document.getElementById('sidebar');

    if (toggler && sidebar) {
        toggler.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
        });
    }
});
