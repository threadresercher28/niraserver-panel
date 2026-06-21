<?php
session_start();
require_once __DIR__ . '/config.php';

if (isset($_SESSION['admin'])) {
    header('Location: admin.php');
    exit;
}

$error = '';

// Проверяем, что константа panel_password определена
if (!defined('panel_password')) {
    $error = 'Ошибка конфигурации: отсутствует panel_password';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_key'])) {
    $key = trim($_POST['access_key']);
    if ($key !== '' && $key === panel_password) {
        $_SESSION['admin'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $error = 'Неверный ключ доступа';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow, nosnippet, noarchive, noimageindex">
<title>IPTV - Вход</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}
body {
    font-family: 'Inter', sans-serif;
    background: #0a0c10;
    color: #e0f2fe;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}
body::before {
    content: "";
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: radial-gradient(circle at 20% 30%, #0f1217, #020304);
    pointer-events: none;
    z-index: -1;
}
.login-container {
    width: 100%;
    max-width: 420px;
    padding: 20px;
}
.login-box {
    background: #0f1115;
    border: 1px solid #2a2e36;
    border-radius: 24px;
    padding: 40px 32px;
    backdrop-filter: blur(4px);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    transition: border-color 0.2s;
}
.login-box:hover {
    border-color: #00ffaa;
}
.login-box h2 {
    margin: 0 0 30px;
    font-size: 1.6rem;
    font-weight: 600;
    text-align: center;
    color: #00ffaa;
    letter-spacing: -0.5px;
    text-shadow: 0 0 5px rgba(0, 255, 170, 0.3);
}
.input-group {
    margin-bottom: 24px;
    text-align: left;
}
.input-group label {
    display: block;
    margin-bottom: 8px;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #8ba3b0;
    font-weight: 500;
}
.password-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}
.login-input {
    width: 100%;
    padding: 12px 40px 12px 16px;
    background: #1a1e24;
    border: 1px solid #2a2e36;
    border-radius: 40px;
    color: #e0f2fe;
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.9rem;
    transition: all 0.2s;
}
.login-input:focus {
    outline: none;
    border-color: #00ffaa;
    box-shadow: 0 0 8px rgba(0, 255, 170, 0.3);
}
.toggle-password {
    position: absolute;
    right: 12px;
    background: transparent;
    border: none;
    cursor: pointer;
    color: #8ba3b0;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    transition: color 0.2s;
}
.toggle-password:hover {
    color: #00ffaa;
}
.login-btn {
    width: 100%;
    padding: 12px;
    background: #1a1e24;
    border: 1px solid #2a2e36;
    border-radius: 40px;
    color: #00ffaa;
    font-weight: 600;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: all 0.2s;
    font-family: 'Inter', sans-serif;
}
.login-btn:hover {
    background: #2a2e36;
    border-color: #00ffaa;
    box-shadow: 0 0 8px rgba(0, 255, 170, 0.3);
}
.error {
    margin-top: 20px;
    padding: 10px 16px;
    background: rgba(255, 59, 59, 0.1);
    border: 1px solid #ff3b3b;
    border-radius: 40px;
    color: #ff8a80;
    font-size: 0.8rem;
    text-align: center;
}
@media (max-width: 480px) {
    .login-box {
        padding: 30px 24px;
    }
    .login-box h2 {
        font-size: 1.4rem;
    }
}
</style>
</head>
<body>
<div class="login-container">
    <div class="login-box">
        <h2>IPTV Панель</h2>
        <form method="post">
            <div class="input-group">
                <label>Ключ доступа</label>
                <div class="password-wrapper">
                    <input type="password" name="access_key" id="access_key" class="login-input" placeholder="Введите ключ" required autofocus>
                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility()">
                        <i class="fas fa-eye" id="toggleIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="login-btn">
                <i class="fas fa-sign-in-alt"></i> Войти
            </button>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
function togglePasswordVisibility() {
    const passwordInput = document.getElementById('access_key');
    const toggleIcon = document.getElementById('toggleIcon');
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.classList.remove('fa-eye');
        toggleIcon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        toggleIcon.classList.remove('fa-eye-slash');
        toggleIcon.classList.add('fa-eye');
    }
}
</script>
</body>
</html>
