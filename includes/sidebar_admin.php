<?php
// includes/sidebar_admin.php
// Sidebar للمدير مع تحسين UI/UX
?>
<div class="sidebar" id="erp-sidebar">
    <div class="sidebar-header">
        <div class="sidebar-header-text">
            <h4>لوحة الإدارة</h4>
            <small>Administration Panel</small>
        </div>
        <button type="button" class="sidebar-inner-toggle" data-erp-sidebar-toggle title="إخفاء القائمة" aria-label="إخفاء القائمة">
            <i class="bi bi-chevron-double-right"></i>
        </button>
    </div>
    
    <ul class="sidebar-nav nav flex-column">
        <li class="nav-item">
            <a class="nav-link" href="admin_dashboard.php">
                <i class="bi bi-grid-1x2-fill"></i>
                <span>لوحة التحكم</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="pos.php">
                <i class="bi bi-cart4"></i>
                <span>نقطة بيع</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="products.php">
                <i class="bi bi-box-seam"></i>
                <span>إدارة الأصناف</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="categories.php">
                <i class="bi bi-tags"></i>
                <span>فئات منتجات</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="invoices.php">
                <i class="bi bi-receipt"></i>
                <span>سجل فواتير</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="returns.php">
                <i class="bi bi-arrow-return-left"></i>
                <span>مرتجع مبيعات</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="purchases.php">
                <i class="bi bi-bag-plus"></i>
                <span>فاتورة مشتريات</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="suppliers.php">
                <i class="bi bi-truck"></i>
                <span>الموردين</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="customers.php">
                <i class="bi bi-people"></i>
                <span>العملاء والديون</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="expenses.php">
                <i class="bi bi-wallet2"></i>
                <span>إدارة المصاريف</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="reports.php">
                <i class="bi bi-graph-up-arrow"></i>
                <span>تقارير الأرباح</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="withdrawals.php">
                <i class="bi bi-cash-coin"></i>
                <span>سحب الأرباح</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="users.php">
                <i class="bi bi-person-gear"></i>
                <span>المستخدمين</span>
            </a>
        </li>
        <li class="nav-item mt-auto">
            <a class="nav-link logout" href="../logout.php">
                <i class="bi bi-box-arrow-right"></i>
                <span>تسجيل خروج</span>
            </a>
        </li>
    </ul>
</div>