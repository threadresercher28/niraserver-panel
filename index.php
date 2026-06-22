<?php
session_start();
require_once 'config.php';

// Проверяем, если уже авторизован - сразу в админку
if (isset($_SESSION['admin']) && $_SESSION['admin'] === true) {
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
        $_SESSION['admin_created'] = time();
        // Регенерация ID сессии для безопасности
        session_regenerate_id(true);
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
<title>Nira Panel - Вход</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/res/css/all.min.css">
<link rel="stylesheet" href="/res/css/login.css">
</head>
<body>
<div class="login-container">
<div class="login-box">
<h2>Nira Panel v2</h2>
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
