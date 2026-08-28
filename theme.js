(function () {
    var btn = document.getElementById('themeToggle');
    var body = document.body;

    function apply(light) {
        body.classList.toggle('light', light);
        if (btn) btn.textContent = light ? '☀️' : '🌙';
    }

    var saved = localStorage.getItem('theme');
    apply(saved === 'light');

    if (btn) {
        btn.addEventListener('click', function () {
            var isLight = body.classList.toggle('light');
            localStorage.setItem('theme', isLight ? 'light' : 'dark');
            btn.textContent = isLight ? '☀️' : '🌙';
        });
    }
})();
