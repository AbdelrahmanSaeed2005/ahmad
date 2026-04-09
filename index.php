<?php
require_once 'includes/db_connect.php';

$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.username = ? AND u.is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['role_name'] = $user['role_name'];

        log_action($pdo, $user['id'], 'login_success');
        
        if ($user['role_name'] === 'admin') {
            header("Location: views/admin_dashboard.php");
        } else {
            header("Location: views/employee_dashboard.php");
        }
        exit();
    } else {
        $error = "بيانات الدخول غير صحيحة";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Ark4n</title>
    <!-- Fonts & Bootstrap RTL -->
    <link name="aa" rel="icon" type="image/png" href="Picture4.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        .aa{
            width: 100%;       /* تخلي الصورة تاخد عرض الدائرة كله */
            height: 100%;      /* تخلي الصورة تاخد طول الدائرة كله */
            object-fit: cover; /* دي أهم خاصية: بتخلي الصورة تملا المساحة وتعمل قص (Crop) بسيط للأطراف بدل ما تتمط */
            border-radius: 50%; /* عشان الصورة نفسها تتقص بشكل دائري */
            }
        
        body {
            font-family: 'Cairo', sans-serif;
            background: linear-gradient(135deg, #0a0a1a 0%, #02040c 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 1rem;
        }

        /* حاوية المحتوى */
        .login-wrapper {
            width: 100%;
            max-width: 500px;
        }

        @keyframes fadeSlideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* بطاقة تسجيل الدخول */
        .ark4n-card {
            background: rgba(8, 16, 30, 0.9);
            backdrop-filter: blur(14px);
            border-radius: 56px;
            padding: 2.8rem 2.2rem;
            border: 1px solid rgba(100, 80, 255, 0.4);
            box-shadow: 0 30px 50px rgba(0, 0, 0, 0.5), 0 0 30px rgba(120, 80, 255, 0.15);
            transition: transform 0.3s ease, border-color 0.3s;
            width: 100%;
            animation: fadeSlideUp 0.6s ease-out;
        }

        .ark4n-card:hover {
            border-color: rgba(160, 100, 255, 0.6);
            box-shadow: 0 35px 60px rgba(0, 0, 0, 0.6), 0 0 35px rgba(140, 90, 255, 0.2);
        }

        /* رأس الصفحة */
        .logo-area {
            text-align: center;
            margin-bottom: 2rem;
        }

        .icon-glow {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, #0f0f2a, #03031a);
            width: 90px;
            height: 90px;
            border-radius: 35px;
            margin-bottom: 1.2rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255,255,255,0.05);
            border: 1px solid rgba(130, 80, 255, 0.5);
        }

        .ark4n-icon {
            font-size: 3.8rem;
            font-weight: 900;
            background: linear-gradient(135deg, #c084fc, #5e9eff, #b77cff);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            text-shadow: 0 0 15px rgba(140, 80, 255, 0.5);
            letter-spacing: 2px;
        }

        .ark4n-name {
            font-size: 3.3rem;
            font-weight: 800;
            font-family: 'Cairo', 'Segoe UI', system-ui;
            background: linear-gradient(125deg, #ffffff, #b77cff, #5e9eff, #c084fc);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            text-shadow: 0 0 20px rgba(130, 80, 255, 0.4);
            letter-spacing: -0.5px;
            margin-bottom: 0.35rem;
            position: relative;
            display: inline-block;
        }

        .ark4n-name::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 10%;
            width: 80%;
            height: 2.5px;
            background: linear-gradient(90deg, transparent, #b77cff, #5e9eff, #c084fc, transparent);
            border-radius: 4px;
        }

        .sub-line {
            color: #c9b6ff;
            font-size: 0.85rem;
            letter-spacing: 1.2px;
            opacity: 0.9;
            margin-top: 16px;
            font-weight: 500;
        }

        /* الحقول */
        .form-floating-custom {
            margin-bottom: 1.6rem;
        }

        .input-dark {
            width: 100%;
            background: rgba(3, 10, 25, 0.8);
            border: 1px solid rgba(120, 90, 220, 0.5);
            border-radius: 40px;
            padding: 0.9rem 1.5rem;
            font-size: 1rem;
            color: #f0f0ff;
            font-family: 'Cairo', monospace;
            transition: all 0.25s ease;
            outline: none;
        }

        .input-dark:focus {
            border-color: #b77cff;
            box-shadow: 0 0 14px rgba(150, 80, 255, 0.3);
            background: rgba(10, 20, 40, 0.95);
            color: white;
        }

        .input-dark::placeholder {
            color: #9a8cbb;
            font-weight: 400;
        }

        .form-label-custom {
            display: block;
            margin-bottom: 0.5rem;
            margin-right: 0.75rem;
            color: #d9ccff;
            font-weight: 600;
            font-size: 0.9rem;
            letter-spacing: 0.4px;
        }

        /* زر الدخول */
        .btn-cyber {
            background: linear-gradient(105deg, #6d4aff, #3b82f6);
            border: none;
            border-radius: 44px;
            padding: 0.95rem 1.5rem;
            font-weight: 700;
            font-size: 1.1rem;
            width: 100%;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: 0.25s;
            margin-top: 1.2rem;
            box-shadow: 0 6px 18px rgba(100, 70, 255, 0.3);
            cursor: pointer;
        }

        .btn-cyber:hover {
            transform: translateY(-2px);
            background: linear-gradient(105deg, #7d5cff, #4f8eff);
            box-shadow: 0 10px 28px rgba(110, 80, 255, 0.5);
        }

        .alert-neon {
            background: rgba(200, 70, 120, 0.15);
            backdrop-filter: blur(8px);
            border-right: 4px solid #ff66aa;
            border-radius: 24px;
            color: #ffc0e0;
            padding: 0.8rem 1rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
            text-align: center;
        }

        /* استجابة للهواتف */
        @media (max-width: 550px) {
            .ark4n-card {
                padding: 1.8rem 1.3rem;
                border-radius: 40px;
            }
            .ark4n-name {
                font-size: 2.2rem;
            }
            .icon-glow {
                width: 72px;
                height: 72px;
            }
            .ark4n-icon {
                font-size: 3rem;
            }
        }

        @media (max-width: 380px) {
            .ark4n-card {
                padding: 1.5rem 1.2rem;
            }
            .ark4n-name {
                font-size: 1.9rem;
            }
            .btn-cyber {
                padding: 0.75rem 1rem;
                font-size: 0.95rem;
            }
        }
    </style>
</head>
<body>

    <div class="login-wrapper">
        <div class="ark4n-card">
            <div class="logo-area">
                <div class="icon-glow">
                    <div class="ark4n-icon">⌘</div>
                </div>
                <div>
                    <span class="ark4n-name">Ark4n</span>
                </div>
            </div>

            <!-- عرض رسالة الخطأ من PHP -->
            <?php if($error): ?>
                <div class="alert-neon">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- نموذج تسجيل الدخول -->
            <form method="POST" action="">
                <div class="form-floating-custom">
                    <label class="form-label-custom"><i class="fas fa-user-astronaut"></i> اسم المستخدم</label>
                    <input type="text" name="username" class="input-dark" placeholder="@" autocomplete="username" required>
                </div>
                <div class="form-floating-custom">
                    <label class="form-label-custom"><i class="fas fa-key"></i> كلمة المرور</label>
                    <input type="password" name="password" class="input-dark" placeholder="••••••••" autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn-cyber">
                    <span>دخول إلى النظام</span>
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                        <polyline points="10 17 15 12 10 7"/>
                        <line x1="15" y1="12" x2="3" y2="12"/>
                    </svg>
                </button>
            </form>
            <div class="sub-line" style="text-align: center; margin-top: 1.5rem; font-size: 0.7rem;">
                Ark4n | اركان للحلول البرمجيه❤️
        </div>
        </div>
    </div>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</body>
</html>