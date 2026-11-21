// Applies the light, dark, or system theme. The choice is saved in this browser.
(function () {
    function resolve(choice) {
        if (choice === 'dark') return 'dark';
        if (choice === 'system') {
            return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        return 'light';
    }
    window.getThemeChoice = function () {
        return localStorage.getItem('sr_theme') || 'light';
    };
    window.setThemeChoice = function (choice) {
        localStorage.setItem('sr_theme', choice);
        document.documentElement.setAttribute('data-theme', resolve(choice));
    };
    document.documentElement.setAttribute('data-theme', resolve(window.getThemeChoice()));
})();
