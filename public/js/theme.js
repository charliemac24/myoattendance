/* Light / dark theme. Loaded in <head> so the saved choice (or the device setting) is applied before the page paints. */
(function () {
    var KEY = 'myo-theme';
    var root = document.documentElement;

    function stored() {
        try { return window.localStorage.getItem(KEY); } catch (error) { return null; }
    }

    function systemPrefersDark() {
        return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }

    function current() {
        var saved = stored();
        if (saved === 'dark' || saved === 'light') return saved;
        return systemPrefersDark() ? 'dark' : 'light';
    }

    function syncButton() {
        var button = document.getElementById('theme-toggle');
        if (!button) return;
        var dark = root.getAttribute('data-theme') === 'dark';
        button.setAttribute('aria-pressed', dark ? 'true' : 'false');
        button.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
        button.setAttribute('title', dark ? 'Switch to light mode' : 'Switch to dark mode');
    }

    function apply(theme) {
        root.setAttribute('data-theme', theme);
        syncButton();
    }

    apply(current());

    window.MyoTheme = {
        current: current,
        set: function (theme) {
            try { window.localStorage.setItem(KEY, theme); } catch (error) { /* private mode: the choice lasts for this page only */ }
            apply(theme);
        },
        toggle: function () {
            this.set(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        var button = document.getElementById('theme-toggle');
        if (button) button.addEventListener('click', function () { window.MyoTheme.toggle(); });
        syncButton();
    });

    // Follow the device setting until the person picks a theme themselves.
    if (window.matchMedia) {
        var query = window.matchMedia('(prefers-color-scheme: dark)');
        var onChange = function () { if (!stored()) apply(current()); };
        if (query.addEventListener) query.addEventListener('change', onChange);
    }
    // Keep other open tabs in step.
    window.addEventListener('storage', function (event) { if (event.key === KEY) apply(current()); });
})();
