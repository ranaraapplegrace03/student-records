// Feedback toasts: slide in from the top center, stay for 2 seconds, then disappear
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-toast]').forEach(function (el) {
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { el.classList.add('show'); });
        });
        setTimeout(function () {
            el.classList.remove('show');
            setTimeout(function () { el.remove(); }, 400);
        }, 2000);
    });
});
