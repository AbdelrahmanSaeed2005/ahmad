</main>

<!-- Toast Notifications -->
<div class="toast-container position-fixed bottom-0 start-0 p-3">
    <div id="liveToast" class="toast align-items-center text-white border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="toastMessage"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Theme system (light/dark) - shared across all views
    (function () {
        const THEME_KEY = 'erp_theme';
        const html = document.documentElement;

        function setTheme(theme) {
            const safeTheme = theme === 'dark' ? 'dark' : 'light';
            html.setAttribute('data-theme', safeTheme);
            try { localStorage.setItem(THEME_KEY, safeTheme); } catch (e) {}
            const icon = document.getElementById('themeToggleIcon');
            if (icon) {
                icon.className = safeTheme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
            }
        }

        function getInitialTheme() {
            try {
                const saved = localStorage.getItem(THEME_KEY);
                if (saved === 'dark' || saved === 'light') return saved;
            } catch (e) {}
            return 'light';
        }

        window.toggleTheme = function () {
            const current = html.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            setTheme(current === 'dark' ? 'light' : 'dark');
        };

        document.addEventListener('DOMContentLoaded', function () {
            setTheme(getInitialTheme());
            const btn = document.getElementById('themeToggleBtn');
            if (btn) btn.addEventListener('click', window.toggleTheme);
        });
    })();

    // تمييز الرابط الفعال في السايدبار
    document.addEventListener('DOMContentLoaded', function() {
        const currentUrl = window.location.href;
        document.querySelectorAll('.nav-link').forEach(link => {
            if(link.href === currentUrl) {
                link.classList.add('active');
            }
        });
    });

    // دالة تشغيل التوست
    function showToast(message, type = 'success') {
        const toastEl = document.getElementById('liveToast');
        const toastMessage = document.getElementById('toastMessage');
        toastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning');
        
        if(type === 'success') toastEl.classList.add('bg-success');
        else if(type === 'error') toastEl.classList.add('bg-danger');
        else if(type === 'warning') toastEl.classList.add('bg-warning');
        
        toastMessage.innerText = message;
        const toast = new bootstrap.Toast(toastEl);
        toast.show();
    }

    // التأكد من وجود رسالة في الجلسة لعرضها بعد إعادة التحميل
    <?php if(isset($_SESSION['msg'])): ?>
        showToast("<?= htmlspecialchars($_SESSION['msg']) ?>", "<?= $_SESSION['msg_type'] ?? 'success' ?>");
        <?php unset($_SESSION['msg'], $_SESSION['msg_type']); ?>
    <?php endif; ?>

    // إظهار/إخفاء الشريط الجانبي (سطح المكتب + جوال)
    (function () {
        const sidebar = document.querySelector('.sidebar');
        const toggles = document.querySelectorAll('[data-erp-sidebar-toggle]');
        const mainBtn = document.getElementById('sidebarToggle');
        if (!sidebar || toggles.length === 0) return;

        const isMobile = function () {
            return window.matchMedia('(max-width: 768px)').matches;
        };

        function updateToggleUi() {
            const icon = mainBtn && mainBtn.querySelector('i');
            if (!mainBtn) return;
            if (isMobile()) {
                const open = sidebar.classList.contains('show');
                mainBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (icon) icon.className = open ? 'bi bi-x-lg' : 'bi bi-list';
            } else {
                const collapsed = document.body.classList.contains('sidebar-collapsed');
                mainBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                if (icon) icon.className = collapsed ? 'bi bi-list' : 'bi bi-layout-sidebar-inset-reverse';
            }
        }

        function restoreDesktopState() {
            if (isMobile()) {
                document.body.classList.remove('sidebar-collapsed');
                return;
            }
            try {
                if (localStorage.getItem('erp_sidebar_collapsed') === '1') {
                    document.body.classList.add('sidebar-collapsed');
                } else {
                    document.body.classList.remove('sidebar-collapsed');
                }
            } catch (e) {
                document.body.classList.remove('sidebar-collapsed');
            }
        }

        function onToggle() {
            if (isMobile()) {
                sidebar.classList.toggle('show');
            } else {
                document.body.classList.toggle('sidebar-collapsed');
                try {
                    localStorage.setItem(
                        'erp_sidebar_collapsed',
                        document.body.classList.contains('sidebar-collapsed') ? '1' : '0'
                    );
                } catch (e) {}
            }
            updateToggleUi();
        }

        toggles.forEach(function (el) {
            el.addEventListener('click', onToggle);
        });

        document.addEventListener('DOMContentLoaded', function () {
            restoreDesktopState();
            updateToggleUi();
        });

        window.addEventListener('resize', function () {
            sidebar.classList.remove('show');
            restoreDesktopState();
            updateToggleUi();
        });

        document.addEventListener('click', function (e) {
            if (!isMobile() || !sidebar.classList.contains('show')) return;
            if (sidebar.contains(e.target)) return;
            if (e.target.closest('[data-erp-sidebar-toggle]')) return;
            sidebar.classList.remove('show');
            updateToggleUi();
        });
    })();
</script>
</body>
</html>