{{--
    Single source of truth for dark mode — included by every layout so they
    can never drift into separate/conflicting implementations.

    Only ever toggles `.dark` on <html>, never on <body>. This app runs
    Turbo Drive (see resources/js/app.js), which replaces <body> wholesale
    on every navigation but never touches <html> — so anything keyed off
    the <html> ancestor (every `dark:` Tailwind variant, including on
    freshly-swapped-in <body> markup) keeps working correctly with zero
    re-sync logic needed after navigation. Each layout's own <body> tag
    carries a static `dark:bg-gray-900` utility class for exactly this
    reason: the moment new body markup arrives, it's already correctly
    dark if <html> says so, without any JS having to run again.
--}}
<script>
    (function() {
        const savedTheme = localStorage.getItem('theme');
        const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        const theme = savedTheme || systemTheme;
        document.documentElement.classList.toggle('dark', theme === 'dark');
    })();

    document.addEventListener('alpine:init', () => {
        Alpine.store('theme', {
            theme: 'light',
            init() {
                const savedTheme = localStorage.getItem('theme');
                const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                this.theme = savedTheme || systemTheme;
                this.updateTheme();
            },
            toggle() {
                this.theme = this.theme === 'light' ? 'dark' : 'light';
                localStorage.setItem('theme', this.theme);
                this.updateTheme();
            },
            updateTheme() {
                document.documentElement.classList.toggle('dark', this.theme === 'dark');
            },
        });
    });
</script>
