<script>
    (() => {
        try {
            const theme = localStorage.getItem('theme') || 'system';
            const dark = theme === 'dark'
                || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

            document.documentElement.classList.toggle('dark', dark);
        } catch (_) {
            document.documentElement.classList.toggle(
                'dark',
                window.matchMedia('(prefers-color-scheme: dark)').matches,
            );
        }
    })();
</script>
