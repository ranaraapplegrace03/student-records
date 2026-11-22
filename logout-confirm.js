// Asks "Are you sure you want to log out?" before any Log out link takes effect
(function () {
    function buildModal() {
        var m = document.createElement('div');
        m.className = 'modal';
        m.id = 'logoutModal';
        m.hidden = true;
        m.innerHTML =
            '<div class="modal-box logout-box">' +
              '<div class="logout-icon">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
                  '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>' +
                '</svg>' +
              '</div>' +
              '<h3>Log out?</h3>' +
              '<p>Are you sure you want to log out of the Student Records System?</p>' +
              '<div class="modal-actions">' +
                '<button type="button" class="btn-ghost" data-cancel>Cancel</button>' +
                '<a href="logout.php" class="btn-primary logout-confirm">Yes, log out</a>' +
              '</div>' +
            '</div>';
        document.body.appendChild(m);
        return m;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var modal = buildModal();

        document.querySelectorAll('a[href="logout.php"]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                modal.hidden = false;
                modal.querySelector('.logout-confirm').focus();
            });
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal || e.target.closest('[data-cancel]')) modal.hidden = true;
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') modal.hidden = true;
        });
    });
})();
