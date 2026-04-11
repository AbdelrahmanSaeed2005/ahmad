<?php
require_once 'auth.php'; 
check_login();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نظام ERP المتكامل</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* ===== ERP MAIN LAYOUT - UI ENHANCEMENTS ===== */
        :root {
            /* ERP color system (from colors.xml) - light mode */
            --primary: #4f46e5;
            --primary-light: #6366f1;
            --primary-dark: #4338ca;
            --primary-soft: #e0e7ff;
            --primary-glow: rgba(79, 70, 229, 0.2);
            --secondary: #64748b;
            --success: #10b981;
            --success-light: #d1fae5;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --warning: #f59e0b;
            --warning-light: #fed7aa;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --bg-sidebar: #ffffff;
            --bg-header: #ffffff;
            --bg-hover: #f1f5f9;
            --bg-input: #ffffff;
            --bg-table-header: #f8fafc;
            --text-primary: #0f172a;
            --text-secondary: #334155;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
            --border-medium: #cbd5e1;
            --border-focus: #4f46e5;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.03);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            --bs-body-bg: var(--bg-body);
            --bs-body-color: var(--text-primary);
            --bs-border-color: var(--border-light);
            --bs-card-bg: var(--bg-card);
            --bs-secondary-color: var(--text-muted);
            --bs-tertiary-bg: var(--bg-hover);
            --bs-primary: var(--primary);
            --bs-success: var(--success);
            --bs-danger: var(--danger);
            --bs-warning: var(--warning);
            --bs-info: var(--info);
            
            /* Legacy aliases used across views */
            --gray-50: var(--bg-body);
            --gray-100: var(--bg-hover);
            --gray-200: var(--border-light);
            --gray-300: var(--border-medium);
            --gray-400: #94a3b8;
            --gray-500: var(--text-muted);
            --gray-600: #475569;
            --gray-700: var(--text-secondary);
            --gray-800: #1e293b;
            --gray-900: var(--text-primary);
            
            /* Spacing */
            --space-1: 0.25rem;
            --space-2: 0.5rem;
            --space-3: 0.75rem;
            --space-4: 1rem;
            --space-5: 1.25rem;
            --space-6: 1.5rem;
            --space-8: 2rem;
            --space-10: 2.5rem;
            --space-12: 3rem;
            
            /* Transitions */
            --transition-fast: 0.15s ease;
            --transition-base: 0.2s ease;
            --transition-slow: 0.3s ease;
        }
        
        [data-theme="dark"] {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --primary-dark: #4f46e5;
            --primary-soft: #1e293b;
            --primary-glow: rgba(99, 102, 241, 0.2);
            --secondary: #94a3b8;
            --success: #34d399;
            --success-light: #064e3b;
            --danger: #f87171;
            --danger-light: #7f1d1d;
            --warning: #fbbf24;
            --warning-light: #78350f;
            --info: #60a5fa;
            --info-light: #1e3a8a;
            --bg-body: #0f172a;
            --bg-card: #1e293b;
            --bg-sidebar: #1e293b;
            --bg-header: #1e293b;
            --bg-hover: #2d3a4f;
            --bg-input: #1e293b;
            --bg-table-header: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border-light: #334155;
            --border-medium: #475569;
            --border-focus: #6366f1;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.4);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.5);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.6);
            --gray-600: #94a3b8;
            --gray-800: #cbd5e1;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            transition: background-color var(--transition-base), color var(--transition-base), border-color var(--transition-base);
        }

        body {
            font-family: 'Cairo', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-primary);
            line-height: 1.5;
            overflow-x: hidden;
            transition: background-color var(--transition-base), color var(--transition-base);
        }

        /* ===== SIDEBAR STYLES ===== */
        .sidebar {
            width: 280px;
            height: 100vh;
            position: fixed;
            top: 0;
            right: 0;
            background: var(--bg-sidebar);
            border-left: 1px solid var(--gray-200);
            box-shadow: var(--shadow-lg);
            z-index: 1000;
            overflow-y: auto;
            overflow-x: hidden;
            transition: all var(--transition-base);
        }

        .sidebar-header {
            padding: var(--space-6) var(--space-4);
            border-bottom: 1px solid var(--gray-200);
            background: var(--bg-sidebar);
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: var(--space-3);
        }

        .sidebar-header-text {
            flex: 1;
            min-width: 0;
        }

        .sidebar-inner-toggle {
            flex-shrink: 0;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            border: 1px solid var(--gray-200);
            background: var(--gray-50);
            color: var(--gray-600);
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .sidebar-inner-toggle:hover {
            background: var(--gray-100);
            color: var(--primary);
            border-color: var(--gray-300);
        }

        @media (min-width: 769px) {
            .sidebar-inner-toggle {
                display: inline-flex;
            }
        }

        .btn-sidebar-toggle {
            flex-shrink: 0;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            background: var(--gray-50);
            color: var(--gray-700);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .btn-sidebar-toggle:hover {
            background: var(--gray-100);
            color: var(--primary);
            border-color: var(--primary-light);
        }

        .btn-sidebar-toggle i {
            font-size: 1.35rem;
        }

        @media (min-width: 769px) {
            body.sidebar-collapsed .sidebar {
                transform: translateX(100%);
                pointer-events: none;
                box-shadow: none;
            }

            body.sidebar-collapsed .main-content {
                margin-right: 0 !important;
            }

            body.sidebar-collapsed .sidebar-inner-toggle {
                display: none;
            }
        }

        .sidebar-header h4 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin: 0;
        }

        .sidebar-header small {
            display: block;
            color: var(--gray-500);
            font-size: 0.75rem;
            letter-spacing: 0.5px;
        }

        .sidebar-nav {
            padding: var(--space-4) 0;
        }

        .nav-item {
            list-style: none;
            margin: 2px 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: var(--space-3) var(--space-4);
            color: var(--gray-600);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all var(--transition-fast);
            border-right: 3px solid transparent;
            margin: 0 var(--space-2);
            border-radius: 8px;
        }

        .nav-link i {
            margin-left: var(--space-3);
            font-size: 1.2rem;
            color: var(--gray-400);
            transition: all var(--transition-fast);
            width: 24px;
            text-align: center;
        }

        .nav-link:hover {
            background-color: var(--gray-100);
            color: var(--primary);
            border-right-color: var(--primary);
        }

        .nav-link:hover i {
            color: var(--primary);
        }

        .nav-link.active {
            background: linear-gradient(90deg, rgba(37, 99, 235, 0.1) 0%, rgba(37, 99, 235, 0.02) 100%);
            color: var(--primary);
            border-right-color: var(--primary);
            font-weight: 600;
        }

        .nav-link.active i {
            color: var(--primary);
        }

        .nav-link.logout {
            color: var(--danger);
            margin-top: auto;
        }

        .nav-link.logout i {
            color: var(--danger);
        }

        .nav-link.logout:hover {
            background-color: #fef2f2;
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            margin-right: 280px;
            padding: var(--space-6);
            min-height: 100vh;
            transition: all var(--transition-base);
        }

        /* ===== TOP NAVBAR ===== */
        .navbar-top {
            background: var(--bg-header);
            border-radius: 16px;
            padding: var(--space-4) var(--space-6);
            margin-bottom: var(--space-6);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .navbar-brand {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            text-decoration: none;
            letter-spacing: -0.5px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }

        .user-name {
            font-weight: 600;
            color: var(--gray-700);
        }

        .user-badge {
            background: var(--gray-100);
            color: var(--gray-600);
            padding: var(--space-2) var(--space-4);
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            border: 1px solid var(--gray-200);
        }

        /* ===== TOAST NOTIFICATIONS ===== */
        .toast-container {
            z-index: 9999;
        }

        .toast {
            background: var(--bg-card);
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

        .toast.bg-success {
            background: var(--success) !important;
        }

        .toast.bg-danger {
            background: var(--danger) !important;
        }

        .toast-body {
            padding: var(--space-3) var(--space-4);
            font-weight: 500;
        }

        .btn-close-white {
            filter: brightness(0) invert(1);
        }

        /* ===== RESPONSIVE DESIGN ===== */
        @media (max-width: 1200px) {
            .sidebar {
                width: 260px;
            }
            .main-content {
                margin-right: 260px;
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 80px;
            }
            
            .sidebar-header h4,
            .sidebar-header small,
            .nav-link span {
                display: none;
            }
            
            .nav-link {
                justify-content: center;
                padding: var(--space-3);
            }
            
            .nav-link i {
                margin: 0;
                font-size: 1.4rem;
            }
            
            .main-content {
                margin-right: 80px;
            }
            
            .navbar-brand {
                font-size: 1.2rem;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-right: 0;
                padding: var(--space-4);
            }
            
            .sidebar {
                transform: translateX(100%);
                transition: transform var(--transition-base);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .navbar-top {
                flex-direction: column;
                gap: var(--space-3);
                align-items: flex-start;
            }
            
            .user-info {
                width: 100%;
                justify-content: space-between;
            }
        }

        /* ===== UTILITY CLASSES ===== */
        .card {
            background: var(--bg-card);
            border-radius: 16px;
            border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
            transition: all var(--transition-fast);
        }

        .card:hover {
            box-shadow: var(--shadow-md);
        }

        .card-header {
            background: transparent;
            border-bottom: 1px solid var(--gray-200);
            padding: var(--space-4) var(--space-6);
            font-weight: 600;
        }

        .card-body {
            padding: var(--space-6);
        }

        /* Table Styles */
        .table-responsive {
            border-radius: 12px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            width: 100%;
            background: var(--bg-card);
            border-collapse: separate;
            border-spacing: 0;
            min-width: 600px;
        }

        .table th {
            background: var(--bg-table-header);
            color: var(--gray-600);
            font-weight: 600;
            font-size: 0.9rem;
            padding: var(--space-3) var(--space-4);
            border-bottom: 2px solid var(--gray-200);
            white-space: nowrap;
        }

        .table td {
            padding: var(--space-3) var(--space-4);
            border-bottom: 1px solid var(--gray-200);
            color: var(--gray-700);
        }

        .table tbody tr:hover {
            background-color: var(--gray-50);
        }

        /* Form Styles */
        .form-label {
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: var(--space-2);
            font-size: 0.95rem;
        }

        .form-control {
            border: 2px solid var(--gray-200);
            border-radius: 10px;
            padding: var(--space-3) var(--space-4);
            transition: all var(--transition-fast);
            width: 100%;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-glow);
            outline: none;
        }

        /* Button Styles */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
            padding: var(--space-3) var(--space-6);
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all var(--transition-fast);
            border: none;
            cursor: pointer;
            line-height: 1;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-secondary:hover {
            background: var(--gray-300);
        }

        .btn-sm {
            padding: var(--space-2) var(--space-4);
            font-size: 0.85rem;
        }

        .btn-lg {
            padding: var(--space-4) var(--space-8);
            font-size: 1rem;
        }

        /* Badge Styles */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: var(--space-1) var(--space-3);
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            line-height: 1.5;
        }

        .badge-success {
            background: var(--success-light);
            color: var(--success);
        }

        .badge-danger {
            background: var(--danger-light);
            color: var(--danger);
        }

        .badge-warning {
            background: var(--warning-light);
            color: var(--warning);
        }

        .theme-toggle-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid var(--border-light);
            background: var(--bg-card);
            color: var(--text-secondary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .theme-toggle-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--bg-hover);
        }

        /* Grid System */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: calc(var(--space-3) * -1);
        }

        .col {
            flex: 1 0 0%;
            padding: var(--space-3);
        }

        /* Loading States */
        .loading {
            opacity: 0.7;
            pointer-events: none;
            position: relative;
        }

        .loading::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            border: 2px solid var(--gray-200);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Print Styles */
        @media print {
            .sidebar,
            .navbar-top,
            .btn,
            .btn-sidebar-toggle {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .card {
                border: none;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <?php 
        if ($_SESSION['role_name'] === 'admin') {
            include 'sidebar_admin.php';
        } else {
            include 'sidebar_employee.php';
        }
    ?>

    <main class="main-content">
        <nav class="navbar-top">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <button type="button" class="btn-sidebar-toggle" id="sidebarToggle" data-erp-sidebar-toggle title="إظهار أو إخفاء القائمة" aria-expanded="true" aria-controls="erp-sidebar">
                    <i class="bi bi-layout-sidebar-inset-reverse" aria-hidden="true"></i>
                </button>
                <a href="dashboard.php" class="navbar-brand mb-0">ERP SYSTEM V2</a>
            </div>
            <div class="user-info">
                <button type="button" class="theme-toggle-btn" id="themeToggleBtn" title="تبديل الوضع" aria-label="تبديل الوضع">
                    <i class="bi bi-moon-stars" id="themeToggleIcon"></i>
                </button>
                <span class="user-name">مرحباً: <?= htmlspecialchars($_SESSION['username']) ?></span>
                <span class="user-badge"><?= htmlspecialchars($_SESSION['role_name']) ?></span>
            </div>
        </nav>

        <!-- محتوى الصفحة سيتم إضافته هنا -->