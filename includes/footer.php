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

    // Responsive sidebar toggle للهواتف
    function toggleSidebar() {
        document.querySelector('.sidebar').classList.toggle('show');
    }

    // إضافة زر القائمة للهواتف
    if (window.innerWidth <= 768) {
        const navbar = document.querySelector('.navbar-top');
        const menuBtn = document.createElement('button');
        menuBtn.className = 'btn btn-sm btn-secondary';
        menuBtn.innerHTML = '<i class="bi bi-list"></i>';
        menuBtn.onclick = toggleSidebar;
        navbar.insertBefore(menuBtn, navbar.firstChild);
    }
</script>
</body>
</html>