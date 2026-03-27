<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once 'config.php';

if (!isset($_SESSION['admin'])) {
    header('Location: index.php');
    exit;
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

try {
    if (!isset($db) || !is_array($db)) throw new Exception('Ошибка конфигурации базы данных');
    $pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    error_log('Database connection error: ' . $e->getMessage());
    http_response_code(500);
    exit('Ошибка подключения к базе данных');
}

$users_table = TABLE_USERS;
$channels_table = TABLE_STREAM_SOURCE;

$message = $error = '';
$csrf_token = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

// --- Обработка действий ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['channel_action'])) {
    $action = $_POST['channel_action'];
    try {
        if ($action == 'save') {
            if (empty($_POST['channel_id']) || empty($_POST['channel_name']) || empty($_POST['stream_url'])) {
                $error = 'Заполните обязательные поля';
            } else {
                $data = [$_POST['channel_id'], $_POST['channel_name'], $_POST['channel_class'], $_POST['channel_group'], $_POST['icon_url'], $_POST['stream_url']];
                if (!empty($_POST['id'])) {
                    $pdo->prepare("UPDATE $channels_table SET channel_id=?, channel_name=?, channel_class=?, channel_group=?, icon_url=?, stream_url=? WHERE channel_id=?")->execute(array_merge($data, [$_POST['id']]));
                    $message = 'Канал обновлён';
                } else {
                    $pdo->prepare("INSERT INTO $channels_table (channel_id, channel_name, channel_class, channel_group, icon_url, stream_url) VALUES (?,?,?,?,?,?)")->execute($data);
                    $message = 'Канал добавлен';
                }
            }
        } elseif ($action == 'delete') {
            $pdo->prepare("DELETE FROM $channels_table WHERE channel_id=?")->execute([$_POST['id']]);
            $message = 'Канал удалён';
        } elseif ($action == 'mass_add') {
            $added = 0;
            foreach (explode("\n", trim($_POST['channels_data'])) as $line) {
                $line = trim($line);
                if (!$line) continue;
                $parts = explode('|', $line);
                if (count($parts) < 2) continue;
                [$channel_id, $channel_name, $channel_class, $channel_group, $icon_url, $stream_url] = array_pad($parts, 6, '');
                $stmt = $pdo->prepare("SELECT channel_id FROM $channels_table WHERE channel_id=?");
                $stmt->execute([$channel_id]);
                if ($stmt->fetch()) {
                    $pdo->prepare("UPDATE $channels_table SET channel_name=?, channel_class=?, channel_group=?, icon_url=?, stream_url=? WHERE channel_id=?")->execute([$channel_name, $channel_class, $channel_group, $icon_url, $stream_url, $channel_id]);
                } else {
                    $pdo->prepare("INSERT INTO $channels_table (channel_id, channel_name, channel_class, channel_group, icon_url, stream_url) VALUES (?,?,?,?,?,?)")->execute([$channel_id, $channel_name, $channel_class, $channel_group, $icon_url, $stream_url]);
                }
                $added++;
            }
            $message = "Добавлено/обновлено каналов: $added";
        }
    } catch (Exception $e) {
        $error = 'Ошибка: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['key_action'])) {
    $action = $_POST['key_action'];
    try {
        if ($action == 'save') {
            if (empty($_POST['access_key'])) {
                $error = 'Ключ доступа обязателен';
            } else {
                if (!empty($_POST['edit_key'])) {
                    $pdo->prepare("UPDATE $users_table SET discription=?, status=? WHERE access_key=?")->execute([$_POST['discription'] ?? '', $_POST['status'], $_POST['edit_key']]);
                    $message = 'Ключ обновлён';
                } else {
                    $stmt = $pdo->prepare("SELECT access_key FROM $users_table WHERE access_key=?");
                    $stmt->execute([$_POST['access_key']]);
                    if ($stmt->fetch()) {
                        $error = 'Ключ уже существует';
                    } else {
                        $pdo->prepare("INSERT INTO $users_table (access_key, status, discription) VALUES (?,?,?)")->execute([$_POST['access_key'], $_POST['status'], $_POST['discription'] ?? '']);
                        $message = 'Ключ добавлен';
                    }
                }
            }
        } elseif ($action == 'delete') {
            $pdo->prepare("DELETE FROM $users_table WHERE access_key=?")->execute([$_POST['access_key']]);
            $message = 'Ключ удалён';
        } elseif ($action == 'toggle_status') {
            // здесь статус уже приходит как 'banned' или 'active'
            $pdo->prepare("UPDATE $users_table SET status=? WHERE access_key=?")->execute([$_POST['status'], $_POST['access_key']]);
            $message = $_POST['status'] == 'active' ? 'Ключ активирован' : 'Ключ заблокирован';
        } elseif ($action == 'mass_add') {
            $added = 0;
            foreach (explode("\n", trim($_POST['keys_data'])) as $line) {
                $line = trim($line);
                if (!$line) continue;
                [$access_key, $status, $description] = array_pad(explode('|', $line), 3, '');
                $status = $status ?: 'active';
                // если пришёл 'blocked', меняем на 'banned' для обратной совместимости
                if ($status == 'blocked') $status = 'banned';
                $stmt = $pdo->prepare("SELECT access_key FROM $users_table WHERE access_key=?");
                $stmt->execute([$access_key]);
                if (!$stmt->fetch()) {
                    $pdo->prepare("INSERT INTO $users_table (access_key, status, discription) VALUES (?,?,?)")->execute([$access_key, $status, $description]);
                    $added++;
                }
            }
            $message = "Добавлено ключей: $added";
        }
    } catch (Exception $e) {
        $error = 'Ошибка: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['group_action']) && $_POST['group_action'] == 'rename') {
    try {
        $pdo->prepare("UPDATE $channels_table SET channel_group=? WHERE channel_group=?")->execute([$_POST['new_name'], $_POST['old_name']]);
        $message = 'Группа переименована';
    } catch (Exception $e) {
        $error = 'Ошибка: ' . $e->getMessage();
    }
}

// --- Пагинация и фильтры ---
$section = $_GET['chnl'] ?? 'channels';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

$channels = [];
$totalChannels = 0;
$keys = [];
$totalKeys = 0;
$groups = [];

$allGroups = $pdo->query("SELECT DISTINCT channel_group FROM $channels_table WHERE channel_group != ''")->fetchAll(PDO::FETCH_COLUMN);
foreach ($allGroups as $group) {
    $groups[$group] = [];
}

if ($section == 'channels') {
    $totalStmt = $pdo->query("SELECT COUNT(*) FROM $channels_table");
    $totalChannels = $totalStmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT * FROM $channels_table ORDER BY channel_group, channel_name LIMIT :offset, :perPage");
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($section == 'keys') {
    $totalStmt = $pdo->query("SELECT COUNT(*) FROM $users_table");
    $totalKeys = $totalStmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT access_key, status, discription FROM $users_table ORDER BY access_key LIMIT :offset, :perPage");
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($section == 'groups') {
    $allChannels = $pdo->query("SELECT channel_group FROM $channels_table WHERE channel_group != ''")->fetchAll(PDO::FETCH_ASSOC);
    $groups = [];
    foreach ($allChannels as $c) {
        if (!empty($c['channel_group'])) $groups[$c['channel_group']][] = $c;
    }
}

function safe_html($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$edit_group = $_GET['edit_group'] ?? null;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>IPTV - HACKER PANEL</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* стили без изменений, оставляем как есть */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}
body {
    font-family: 'Inter', sans-serif;
    background: #0a0c10;
    color: #e0f2fe;
    line-height: 1.5;
}
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 280px;
    height: 100vh;
    background: #0f1115;
    transform: translateX(-100%);
    transition: transform 0.25s ease;
    z-index: 1100;
    border-right: 1px solid #2a2e36;
}
.sidebar.open {
    transform: translateX(0);
}
.logo {
    padding: 20px;
    font-size: 1.4rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid #2a2e36;
    margin-bottom: 20px;
}
.logo i {
    color: #00ffaa;
}
.menu {
    padding: 0 16px;
    flex-grow: 1;
}
.menu-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 16px;
    margin: 4px 0;
    border-radius: 8px;
    color: #8ba3b0;
    text-decoration: none;
    font-weight: 500;
    transition: 0.2s;
}
.menu-item i {
    width: 24px;
}
.menu-item:hover, .menu-item.active {
    background: #1a1e24;
    color: #00ffaa;
}
.sidebar-actions {
    padding: 20px;
    border-top: 1px solid #2a2e36;
}
.sidebar-btn {
    width: 100%;
    padding: 8px;
    background: #1a1e24;
    border: 1px solid #2a2e36;
    border-radius: 8px;
    color: #00ffaa;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-bottom: 12px;
    transition: 0.2s;
}
.sidebar-btn:hover {
    background: #2a2e36;
}
.logout-btn {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #8ba3b0;
    text-decoration: none;
    padding: 8px;
    border-radius: 8px;
}
.logout-btn:hover {
    color: #ff3b3b;
    background: rgba(255,59,59,0.1);
}
.main-content {
    width: 100%;
}
.header {
    padding: 12px 20px;
    background: #0c0e12;
    display: flex;
    align-items: center;
    gap: 20px;
    border-bottom: 1px solid #2a2e36;
    position: sticky;
    top: 0;
    z-index: 100;
}
.sidebar-toggle {
    background: #1a1e24;
    border: none;
    width: 38px;
    height: 38px;
    border-radius: 8px;
    color: #00ffaa;
    cursor: pointer;
}
.sidebar-toggle:hover {
    background: #2a2e36;
}
.header h1 {
    font-size: 1.3rem;
    font-weight: 600;
    color: #00ffaa;
}
.content {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
}
.search-container {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.search-input, .filter-group {
    background: #1a1e24;
    border: 1px solid #2a2e36;
    border-radius: 30px;
    padding: 8px 16px;
    color: #e0f2fe;
    font-family: 'JetBrains Mono', monospace;
}
.search-input:focus, .filter-group:focus {
    outline: none;
    border-color: #00ffaa;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.stat-card {
    background: #0f1115;
    border-radius: 12px;
    padding: 12px;
    border: 1px solid #2a2e36;
}
.stat-card h3 {
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #8ba3b0;
    margin-bottom: 4px;
}
.stat-card .value {
    font-size: 1.4rem;
    font-weight: 700;
    color: #00ffaa;
}
.table-container {
    background: #0f1115;
    border-radius: 16px;
    overflow-x: auto;
    border: 1px solid #2a2e36;
}
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
}
.data-table th {
    background: #1a1e24;
    padding: 10px 12px;
    text-align: left;
    font-weight: 600;
    color: #00ffaa;
}
.data-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #2a2e36;
}
.data-table tr:hover td {
    background: #1a1e24;
}
.channel-info {
    display: flex;
    align-items: center;
    gap: 10px;
}
.channel-icon {
    width: 32px;
    height: 32px;
    background: #000;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.channel-icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.channel-name {
    font-weight: 600;
}
.status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 30px;
    font-size: 0.7rem;
    font-weight: 600;
}
.status-active {
    background: rgba(0, 200, 83, 0.2);
    color: #69f0ae;
}
.status-banned {
    background: rgba(255, 59, 59, 0.2);
    color: #ff8a80;
}
.actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.action-btn {
    background: #1a1e24;
    border: none;
    padding: 4px 10px;
    border-radius: 20px;
    color: #00ffaa;
    cursor: pointer;
    font-size: 0.7rem;
    font-weight: 600;
    transition: 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-decoration: none;
}
.action-btn:hover {
    background: #2a2e36;
}
.action-btn.delete:hover {
    background: rgba(255,59,59,0.2);
    color: #ff8a80;
}
.key-cell {
    font-family: 'JetBrains Mono', monospace;
}
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    align-items: center;
    justify-content: center;
    z-index: 1200;
}
.modal.active {
    display: flex;
}
.modal-content {
    background: #0f1115;
    width: 500px;
    max-width: 90%;
    max-height: 85vh;
    overflow-y: auto;
    border-radius: 20px;
    border: 1px solid #2a2e36;
}
.modal-header {
    padding: 16px 20px;
    background: #1a1e24;
    border-bottom: 1px solid #2a2e36;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-body {
    padding: 20px;
}
.form-group {
    margin-bottom: 16px;
}
.form-group label {
    display: block;
    margin-bottom: 6px;
    font-size: 0.7rem;
    text-transform: uppercase;
    color: #8ba3b0;
}
.form-control {
    width: 100%;
    padding: 8px 12px;
    background: #1a1e24;
    border: 1px solid #2a2e36;
    border-radius: 20px;
    color: #e0f2fe;
    font-family: 'JetBrains Mono', monospace;
}
.form-control:focus {
    outline: none;
    border-color: #00ffaa;
}
textarea.form-control {
    min-height: 100px;
}
.modal-footer {
    padding: 12px 20px;
    background: #1a1e24;
    border-top: 1px solid #2a2e36;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
.btn {
    padding: 6px 14px;
    border-radius: 30px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    font-family: inherit;
}
.btn-primary {
    background: #1a1e24;
    color: #00ffaa;
    border: 1px solid #00ffaa;
}
.btn-primary:hover {
    background: #2a2e36;
}
.btn-secondary {
    background: #2a2e36;
    color: #8ba3b0;
}
.message {
    position: relative;
    padding: 10px 32px 10px 16px;
    border-radius: 30px;
    margin-bottom: 20px;
    font-weight: 500;
}
.message.success {
    background: rgba(0,200,83,0.1);
    border: 1px solid #00c853;
    color: #69f0ae;
}
.message.error {
    background: rgba(255,59,59,0.1);
    border: 1px solid #ff3b3b;
    color: #ff8a80;
}
.message-close {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    background: none;
    border: none;
    color: inherit;
    font-size: 1.2rem;
    line-height: 1;
    opacity: 0.7;
    font-weight: bold;
}
.message-close:hover {
    opacity: 1;
}
.pagination {
    display: flex;
    justify-content: center;
    gap: 10px;
    margin-top: 20px;
}
.pagination a, .pagination span {
    display: inline-block;
    padding: 6px 12px;
    background: #1a1e24;
    border-radius: 30px;
    color: #e0f2fe;
    text-decoration: none;
    transition: 0.2s;
}
.pagination a:hover {
    background: #00ffaa;
    color: #000;
}
.pagination .active {
    background: #00ffaa;
    color: #000;
}
@media (max-width: 768px) {
    .sidebar { width: 260px; }
    .content { padding: 12px; }
}
</style>
</head>
<body>
<div class="sidebar" id="sidebar">
    <div class="logo"><i class="fas fa-skull-crossbones"></i> F7-TV</div>
    <div class="menu">
        <a href="?chnl=channels" class="menu-item <?= $section=='channels'?'active':'' ?>"><i class="fas fa-tv"></i> Каналы</a>
        <a href="?chnl=keys" class="menu-item <?= $section=='keys'?'active':'' ?>"><i class="fas fa-key"></i> Ключи доступа</a>
        <a href="?chnl=groups" class="menu-item <?= $section=='groups'?'active':'' ?>"><i class="fas fa-folder"></i> Группы</a>
    </div>
    <div class="sidebar-actions">
        <?php if ($section == 'channels'): ?>
            <button type="button" class="sidebar-btn" onclick="openChannelModal()"><i class="fas fa-plus"></i> Добавить канал</button>
            <button type="button" class="sidebar-btn" onclick="openMassChannelModal()"><i class="fas fa-box"></i> Импорт M3U8</button>
        <?php elseif ($section == 'keys'): ?>
            <button type="button" class="sidebar-btn" onclick="openKeyModal()"><i class="fas fa-plus"></i> Добавить ключ</button>
            <button type="button" class="sidebar-btn" onclick="openMassKeyModal()"><i class="fas fa-box"></i> Массовое добавление</button>
        <?php endif; ?>
        <a href="?logout=1" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Выход</a>
    </div>
</div>
<div class="main-content">
    <div class="header">
        <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-terminal"></i></button>
        <h1><?= $section=='channels'?'Управление каналами':($section=='keys'?'Управление ключами':'Управление группами') ?></h1>
    </div>
    <div class="content">
        <?php if ($message): ?>
            <div class="message success">
                <i class="fas fa-check-circle"></i> <?= safe_html($message) ?>
                <button class="message-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error">
                <i class="fas fa-exclamation-triangle"></i> <?= safe_html($error) ?>
                <button class="message-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($section == 'channels'): ?>
            <div class="search-container">
                <input type="text" id="searchChannels" class="search-input" placeholder="🔍 Поиск...">
                <select id="groupFilter" class="filter-group">
                    <option value="">Все группы</option>
                    <?php foreach (array_keys($groups) as $group): ?>
                        <option value="<?= safe_html($group) ?>"><?= safe_html($group) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="stats-grid">
                <div class="stat-card"><h3>Всего каналов</h3><div class="value"><?= $totalChannels ?></div></div>