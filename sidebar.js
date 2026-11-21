// Resizable sidebar: drag its right edge to make it thinner or wider. Double-click the edge to reset.
(function () {
    var MIN = 72, MAX = 360, DEFAULT = 250, NARROW_AT = 160, KEY = 'sr_sidebar_w';

    function apply(w) {
        w = Math.max(MIN, Math.min(MAX, Math.round(w)));
        document.documentElement.style.setProperty('--sidebar-w', w + 'px');
        document.documentElement.classList.toggle('sidebar-narrow', w < NARROW_AT);
        return w;
    }

    var saved = parseInt(localStorage.getItem(KEY), 10);
    apply(isNaN(saved) ? DEFAULT : saved);

    document.addEventListener('DOMContentLoaded', function () {
        var sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;

        var handle = document.createElement('div');
        handle.className = 'resize-handle';
        handle.title = 'Drag to resize. Double-click to reset.';
        sidebar.appendChild(handle);

        var dragging = false;
        handle.addEventListener('mousedown', function (e) {
            dragging = true;
            document.body.classList.add('resizing');
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (dragging) apply(e.clientX);
        });
        document.addEventListener('mouseup', function () {
            if (!dragging) return;
            dragging = false;
            document.body.classList.remove('resizing');
            var w = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--sidebar-w'), 10);
            localStorage.setItem(KEY, String(w));
        });
        handle.addEventListener('dblclick', function () {
            apply(DEFAULT);
            localStorage.setItem(KEY, String(DEFAULT));
        });
    });
})();
