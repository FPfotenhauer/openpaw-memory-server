(function () {
    const storageKey = 'openpaw-theme';
    const root = document.documentElement;

    function applyTheme(theme) {
        const nextTheme = theme === 'light' ? 'light' : 'dark';
        root.dataset.theme = nextTheme;
        document.querySelectorAll('.op-theme-toggle').forEach((toggle) => {
            toggle.setAttribute('aria-pressed', nextTheme === 'light' ? 'true' : 'false');
        });
    }

    let savedTheme = 'dark';
    try {
        savedTheme = window.localStorage.getItem(storageKey) || 'dark';
    } catch (error) {
        savedTheme = 'dark';
    }
    applyTheme(savedTheme);

    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('.op-theme-toggle');
        if (!toggle) {
            return;
        }
        const nextTheme = root.dataset.theme === 'light' ? 'dark' : 'light';
        applyTheme(nextTheme);
        try {
            window.localStorage.setItem(storageKey, nextTheme);
        } catch (error) {
            return;
        }
    });
})();
