<?php
session_start();
require_once 'config.php';

if (isset($_SESSION['admin'])) {
    header('Location: admin.php');
    exit;
}

$error = '';

try {
    $pdo = new PDO(
        "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_key'])) {
        $stmt = $pdo->prepare("SELECT 1 FROM " . TABLE_USERS . " WHERE access_key=? AND status='active' LIMIT 1");
        $stmt->execute([$_POST['access_key']]);
        if ($stmt->fetchColumn()) {
            $_SESSION['admin'] = true;
            header('Location: admin.php');
            exit;
        } else {
            $error = 'Неверный ключ доступа';
        }
    }
} catch (Exception $e) {
    $error = 'Ошибка подключения к базе данных';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>IPTV - Вход</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    background: #f5f5f5;
    color: #333;
    font-family: 'Share Tech Mono', monospace;
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100vh;
}
.login-container {
    width: 100%;
    max-width: 400px;
    padding: 20px;
}
.login-box {
    background: #ffffff;
    padding: 40px 30px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
.login-box h2 {
    margin: 0 0 25px;
    color: #333;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 3px;
}
.input-group {
    margin-bottom: 20px;
    text-align: left;
}
.input-group label {
    display: block;
    margin-bottom: 8px;
    color: #666;
    font-size: 13px;
    letter-spacing: 1px;
}
.password-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}
.login-input {
    width: 100%;
    padding: 12px 15px;
    background: #f0f0f0;
    color: #333;
    font-family: inherit;
    border: none;
    padding-right: 40px; /* место для иконки */
}
.login-input:focus {
    outline: none;
    background: #e0e0e0;
}
.toggle-password {
    position: absolute;
    right: 10px;
    background: transparent;
    border: none;
    cursor: pointer;
    color: #999;
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
}
.toggle-password:hover {
    color: #333;
}
.login-btn {
    width: 100%;
    padding: 14px;
    background: #cccccc;
    color: #333;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-weight: 500;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 2px;
    border: none;
    transition: background 0.2s;
}
.login-btn:hover {
    background: #bbbbbb;
}
.error {
    color: #d32f2f;
    margin-top: 15px;
    font-size: 12px;
    padding: 8px;
    background: rgba(211,47,47,0.1);
    text-align: center;
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
<input type="password" name="access_key" id="access_key" class="login-input" placeholder="Введите ключ" required>
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