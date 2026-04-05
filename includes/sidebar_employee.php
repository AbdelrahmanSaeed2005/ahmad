<?php
// includes/sidebar_employee.php
// Sidebar للموظف مع تحسين UI/UX
?>
<div class="sidebar">
    <div class="sidebar-header">
        <h4>قائمة الموظف</h4>
        <small>Employee Panel</small>
    </div>
    
    <ul class="sidebar-nav nav flex-column">
        <li class="nav-item">
            <a class="nav-link" href="employee_dashboard.php">
                <i class="bi bi-eye"></i>
                <span>نظرة عامة</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="pos.php">
                <i class="bi bi-cash-stack"></i>
                <span>نقطة بيع</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="returns.php">
                <i class="bi bi-arrow-return-left"></i>
                <span>مرتجع</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="invoices.php">
                <i class="bi bi-journal-text"></i>
                <span>سجل مبيعاتي</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="customers.php">
                <i class="bi bi-people"></i>
                <span>دليل عملاء</span>
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