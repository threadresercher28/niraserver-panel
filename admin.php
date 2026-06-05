<?php
session_start();error_reporting(E_ALL);ini_set('display_errors',1);
if(!isset($_SESSION['initialized'])){session_regenerate_id(true);$_SESSION['initialized']=true;}
require_once'config.php';
if(!isset($_SESSION['admin'])){header('Location: index.php');exit;}
if(isset($_GET['logout'])){session_destroy();header('Location: index.php');exit;}
try{$pdo=new PDO("mysql:host=".database_server.";dbname=".database_name.";charset=utf8",database_login,database_password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}catch(Exception$e){error_log('DB error: '.$e->getMessage());http_response_code(500);exit('DB connect error');}
$users_table=table_users;$channels_table=table_stream_source;$message=$error='';
if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
$csrf_token=$_SESSION['csrf_token'];
function validate_csrf_token($token){return isset($_SESSION['csrf_token'])&&hash_equals($_SESSION['csrf_token'],$token);}
$blockPattern='/[<>\'\"\;#\*\\\\\/%]|javascript:/i';
if((!empty($_SERVER['HTTP_HOST'])&&preg_match($blockPattern,$_SERVER['HTTP_HOST']))||(!empty($_SERVER['HTTP_USER_AGENT'])&&preg_match($blockPattern,$_SERVER['HTTP_USER_AGENT']))){http_response_code(400);exit('Invalid headers');}

function is_ip_private($ip){
    if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
        $p=explode('.',$ip);
        if(count($p)==4){
            $a=(int)$p[0];$b=(int)$p[1];
            if($a==10||$a==127||($a==172&&$b>=16&&$b<=31)||($a==192&&$b==168)||($a==169&&$b==254))return true;
        }
    }elseif(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)){
        $ip=strtolower($ip);
        if($ip==='::1'||strpos($ip,'fc00:')===0||strpos($ip,'fe80:')===0)return true;
    }
    return false;
}
function is_host_safe($host){
    $ips=[];
    $dns=@dns_get_record($host,DNS_A|DNS_AAAA);
    if($dns){foreach($dns as$r){if(!empty($r['ip']))$ips[]=$r['ip'];if(!empty($r['ipv6']))$ips[]=$r['ipv6'];}}
    else{if(filter_var($host,FILTER_VALIDATE_IP))$ips[]=$host;}
    foreach($ips as$ip)if(is_ip_private($ip))return false;
    return true;
}
function is_url_safe($url){
    if(empty($url))return true;
    $url=trim($url);
    if(!filter_var($url,FILTER_VALIDATE_URL))return false;
    $scheme=strtolower(parse_url($url,PHP_URL_SCHEME));
    if(!in_array($scheme,['http','https']))return false;
    $host=parse_url($url,PHP_URL_HOST);
    if($host===false||$host===null)return false;
    if(preg_match('/[<>\'\"\;#\*\\\\\/%]|javascript:/i',$host))return false;
    if(!is_host_safe($host))return false;
    return true;
}
if($_SERVER['REQUEST_METHOD']=='POST'&&isset($_POST['channel_action'])){
    if(!isset($_POST['csrf_token'])||!validate_csrf_token($_POST['csrf_token']))$error='CSRF error';
    else{
        $action=$_POST['channel_action'];
        try{
            if($action=='save'){
                $cid=trim($_POST['channel_id']??'');$cn=trim($_POST['channel_name']??'');$su=trim($_POST['stream_url']??'');$iu=trim($_POST['icon_url']??'');
                if($cid===''||$cn===''||$su==='')$error='Fill required';
                elseif(!is_url_safe($su)||($iu!==''&&!is_url_safe($iu)))$error='Unsafe URL';
                else{
                    $data=[$cid,$cn,$_POST['channel_class']??'','',$_POST['channel_group']??'',$iu,$su];
                    if(!empty($_POST['id'])){
                        $stmt=$pdo->prepare("UPDATE $channels_table SET channel_id=?,channel_name=?,channel_class=?,channel_group=?,icon_url=?,stream_url=? WHERE channel_id=?");
                        $stmt->execute(array_merge($data,[$_POST['id']]));$message='Channel updated';
                    }else{
                        $stmt=$pdo->prepare("INSERT INTO $channels_table (channel_id,channel_name,channel_class,channel_group,icon_url,stream_url) VALUES (?,?,?,?,?,?)");
                        $stmt->execute($data);$message='Channel added';
                    }
                }
            }elseif($action=='delete'){
                $stmt=$pdo->prepare("DELETE FROM $channels_table WHERE channel_id=?");$stmt->execute([$_POST['id']]);$message='Channel deleted';
            }elseif($action=='mass_add'){
                $added=0;
                foreach(explode("\n",trim($_POST['channels_data'])) as$line){
                    $line=trim($line);if(!$line)continue;
                    $parts=explode('|',$line);if(count($parts)<2)continue;
                    [$cid,$cn,$cc,$cg,$iu,$su]=array_pad($parts,6,'');
                    if(!is_url_safe($su))continue;
                    $stmt=$pdo->prepare("SELECT channel_id FROM $channels_table WHERE channel_id=?");$stmt->execute([$cid]);
                    if($stmt->fetch()){
                        $stmt=$pdo->prepare("UPDATE $channels_table SET channel_name=?,channel_class=?,channel_group=?,icon_url=?,stream_url=? WHERE channel_id=?");
                        $stmt->execute([$cn,$cc,$cg,$iu,$su,$cid]);
                    }else{
                        $stmt=$pdo->prepare("INSERT INTO $channels_table (channel_id,channel_name,channel_class,channel_group,icon_url,stream_url) VALUES (?,?,?,?,?,?)");
                        $stmt->execute([$cid,$cn,$cc,$cg,$iu,$su]);
                    }
                    $added++;
                }
                $message="Channels: $added";
            }
        }catch(Exception$e){$error='Error: '.$e->getMessage();}
    }
}
if($_SERVER['REQUEST_METHOD']=='POST'&&isset($_POST['key_action'])){
    if(!isset($_POST['csrf_token'])||!validate_csrf_token($_POST['csrf_token']))$error='CSRF error';
    else{
        $action=$_POST['key_action'];
        try{
            if($action=='save'){
                if(empty($_POST['access_key']))$error='Key required';
                else{
                    if(!empty($_POST['edit_key'])){
                        $stmt=$pdo->prepare("UPDATE $users_table SET discription=?,status=? WHERE access_key=?");$stmt->execute([$_POST['discription']??'',$_POST['status'],$_POST['edit_key']]);$message='Key updated';
                    }else{
                        $stmt=$pdo->prepare("SELECT access_key FROM $users_table WHERE access_key=?");$stmt->execute([$_POST['access_key']]);
                        if($stmt->fetch())$error='Key exists';
                        else{
                            $stmt=$pdo->prepare("INSERT INTO $users_table (access_key,status,discription) VALUES (?,?,?)");$stmt->execute([$_POST['access_key'],$_POST['status'],$_POST['discription']??'']);$message='Key added';
                        }
                    }
                }
            }elseif($action=='delete'){
                $stmt=$pdo->prepare("DELETE FROM $users_table WHERE access_key=?");$stmt->execute([$_POST['access_key']]);$message='Key deleted';
            }elseif($action=='toggle_status'){
                $stmt=$pdo->prepare("UPDATE $users_table SET status=? WHERE access_key=?");$stmt->execute([$_POST['status'],$_POST['access_key']]);$message=$_POST['status']=='active'?'Activated':'Banned';
            }elseif($action=='mass_add'){
                $added=0;
                foreach(explode("\n",trim($_POST['keys_data'])) as$line){
                    $line=trim($line);if(!$line)continue;
                    [$key,$status,$desc]=array_pad(explode('|',$line),3,'');
                    $status=$status?:'active';if($status=='blocked')$status='banned';
                    $stmt=$pdo->prepare("SELECT access_key FROM $users_table WHERE access_key=?");$stmt->execute([$key]);
                    if(!$stmt->fetch()){
                        $stmt=$pdo->prepare("INSERT INTO $users_table (access_key,status,discription) VALUES (?,?,?)");$stmt->execute([$key,$status,$desc]);$added++;
                    }
                }
                $message="Keys: $added";
            }
        }catch(Exception$e){$error='Error: '.$e->getMessage();}
    }
}
if($_SERVER['REQUEST_METHOD']=='POST'&&isset($_POST['group_action'])&&$_POST['group_action']=='rename'){
    if(!isset($_POST['csrf_token'])||!validate_csrf_token($_POST['csrf_token']))$error='CSRF error';
    else{
        try{$stmt=$pdo->prepare("UPDATE $channels_table SET channel_group=? WHERE channel_group=?");$stmt->execute([$_POST['new_name'],$_POST['old_name']]);$message='Group renamed';}catch(Exception$e){$error='Error: '.$e->getMessage();}
    }
}
$section=$_GET['chnl']??'channels';$channels=[];$totalChannels=0;$keys=[];$totalKeys=0;$groups=[];
$allGroups=$pdo->query("SELECT DISTINCT channel_group FROM $channels_table WHERE channel_group!=''")->fetchAll(PDO::FETCH_COLUMN);
$groups=array_fill_keys($allGroups,[]);
if($section=='channels'){
    $totalStmt=$pdo->query("SELECT COUNT(*) FROM $channels_table");$totalChannels=$totalStmt->fetchColumn();
    $stmt=$pdo->query("SELECT * FROM $channels_table ORDER BY channel_group,channel_name");$channels=$stmt->fetchAll(PDO::FETCH_ASSOC);
}elseif($section=='keys'){
    $totalStmt=$pdo->query("SELECT COUNT(*) FROM $users_table");$totalKeys=$totalStmt->fetchColumn();
    $stmt=$pdo->query("SELECT access_key,status,discription FROM $users_table ORDER BY access_key");$keys=$stmt->fetchAll(PDO::FETCH_ASSOC);
}elseif($section=='groups'){
    $allChannels=$pdo->query("SELECT channel_group FROM $channels_table WHERE channel_group!=''")->fetchAll(PDO::FETCH_ASSOC);
    $groups=[];foreach($allChannels as$c){if(!empty($c['channel_group']))$groups[$c['channel_group']][]=$c;}
}
function safe_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function safe_json_encode($data){$json=json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);return$json===false?'null':$json;}
$edit_group=$_GET['edit_group']??null;
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>IPTV - HACKER PANEL</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Inter',sans-serif;background:#0a0c10;color:#e0f2fe;line-height:1.5}.sidebar{position:fixed;top:0;left:0;width:280px;height:100vh;background:#0f1115;transform:translateX(-100%);transition:transform 0.25s ease;z-index:1100;border-right:1px solid #2a2e36}.sidebar.open{transform:translateX(0)}.logo{padding:20px;font-size:1.4rem;font-weight:700;display:flex;align-items:center;gap:12px;border-bottom:1px solid #2a2e36;margin-bottom:20px}.logo i{color:#00ffaa}.menu{padding:0 16px;flex-grow:1}.menu-item{display:flex;align-items:center;gap:14px;padding:10px 16px;margin:4px 0;border-radius:8px;color:#8ba3b0;text-decoration:none;font-weight:500;transition:0.2s}.menu-item i{width:24px}.menu-item:hover,.menu-item.active{background:#1a1e24;color:#00ffaa}.sidebar-actions{padding:20px;border-top:1px solid #2a2e36}.logout-btn{display:flex;align-items:center;gap:10px;color:#8ba3b0;text-decoration:none;padding:8px;border-radius:8px}.logout-btn:hover{color:#ff3b3b;background:rgba(255,59,59,0.1)}.main-content{width:100%}.header{padding:12px 20px;background:#0c0e12;display:flex;align-items:center;justify-content:space-between;gap:20px;border-bottom:1px solid #2a2e36;position:sticky;top:0;z-index:100}.header-left{display:flex;align-items:center;gap:20px}.header-right{display:flex;align-items:center;gap:12px}.sidebar-toggle{background:#1a1e24;border:none;width:38px;height:38px;border-radius:8px;color:#00ffaa;cursor:pointer}.sidebar-toggle:hover{background:#2a2e36}.header h1{font-size:1.3rem;font-weight:600;color:#00ffaa;margin:0}.header-action-btn{background:#1a1e24;border:1px solid #2a2e36;border-radius:30px;padding:6px 14px;color:#00ffaa;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:0.2s;font-size:0.85rem}.header-action-btn:hover{background:#2a2e36;border-color:#00ffaa}.content{padding:20px;max-width:1400px;margin:0 auto}.search-container{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap}.search-input,.filter-group{background:#1a1e24;border:1px solid #2a2e36;border-radius:30px;padding:8px 16px;color:#e0f2fe;font-family:'JetBrains Mono',monospace}.search-input:focus,.filter-group:focus{outline:none;border-color:#00ffaa}.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}.stat-card{background:#0f1115;border-radius:12px;padding:12px;border:1px solid #2a2e36}.stat-card h3{font-size:0.65rem;text-transform:uppercase;letter-spacing:0.5px;color:#8ba3b0;margin-bottom:4px}.stat-card .value{font-size:1.4rem;font-weight:700;color:#00ffaa}.table-container{background:#0f1115;border-radius:16px;overflow-x:auto;border:1px solid #2a2e36}.data-table{width:100%;border-collapse:collapse;font-size:0.85rem}.data-table th{background:#1a1e24;padding:10px 12px;text-align:left;font-weight:600;color:#00ffaa}.data-table td{padding:10px 12px;border-bottom:1px solid #2a2e36}.data-table tr:hover td{background:#1a1e24}.channel-info{display:flex;align-items:center;gap:10px}.channel-icon{width:32px;height:32px;background:#000;border-radius:8px;display:flex;align-items:center;justify-content:center;overflow:hidden}.channel-icon img{width:100%;height:100%;object-fit:cover}.channel-name{font-weight:600}.status-badge{display:inline-block;padding:2px 8px;border-radius:30px;font-size:0.7rem;font-weight:600}.status-active{background:rgba(0,200,83,0.2);color:#69f0ae}.status-banned{background:rgba(255,59,59,0.2);color:#ff8a80}.actions{display:flex;gap:6px;flex-wrap:wrap}.action-btn{background:#1a1e24;border:none;padding:4px 10px;border-radius:20px;color:#00ffaa;cursor:pointer;font-size:0.7rem;font-weight:600;transition:0.2s;display:inline-flex;align-items:center;gap:4px;text-decoration:none}.action-btn:hover{background:#2a2e36}.action-btn.delete:hover{background:rgba(255,59,59,0.2);color:#ff8a80}.key-cell{font-family:'JetBrains Mono',monospace}.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);align-items:center;justify-content:center;z-index:1200}.modal.active{display:flex}.modal-content{background:#0f1115;width:500px;max-width:90%;max-height:85vh;overflow-y:auto;border-radius:20px;border:1px solid #2a2e36}.modal-header{padding:16px 20px;background:#1a1e24;border-bottom:1px solid #2a2e36;display:flex;justify-content:space-between;align-items:center}.modal-body{padding:20px}.form-group{margin-bottom:16px}.form-group label{display:block;margin-bottom:6px;font-size:0.7rem;text-transform:uppercase;color:#8ba3b0}.form-control{width:100%;padding:8px 12px;background:#1a1e24;border:1px solid #2a2e36;border-radius:20px;color:#e0f2fe;font-family:'JetBrains Mono',monospace}.form-control:focus{outline:none;border-color:#00ffaa}textarea.form-control{min-height:100px}.modal-footer{padding:12px 20px;background:#1a1e24;border-top:1px solid #2a2e36;display:flex;justify-content:flex-end;gap:10px}.btn{padding:6px 14px;border-radius:30px;font-weight:600;cursor:pointer;border:none;font-family:inherit}.btn-primary{background:#1a1e24;color:#00ffaa;border:1px solid #00ffaa}.btn-primary:hover{background:#2a2e36}.btn-secondary{background:#2a2e36;color:#8ba3b0}.message{position:relative;padding:10px 32px 10px 16px;border-radius:30px;margin-bottom:20px;font-weight:500}.message.success{background:rgba(0,200,83,0.1);border:1px solid #00c853;color:#69f0ae}.message.error{background:rgba(255,59,59,0.1);border:1px solid #ff3b3b;color:#ff8a80}.message-close{position:absolute;right:10px;top:50%;transform:translateY(-50%);cursor:pointer;background:none;border:none;color:inherit;font-size:1.2rem;line-height:1;opacity:0.7;font-weight:bold}.message-close:hover{opacity:1}.pagination{display:flex;justify-content:center;gap:10px;margin-top:20px}.pagination a,.pagination span{display:inline-block;padding:6px 12px;background:#1a1e24;border-radius:30px;color:#e0f2fe;text-decoration:none;transition:0.2s}.pagination a:hover{background:#00ffaa;color:#000}.pagination .active{background:#00ffaa;color:#000}@media(max-width:768px){.sidebar{width:260px}.content{padding:12px}.header-right{gap:6px}.header-action-btn{padding:4px 10px;font-size:0.75rem}}</style>
</head>
<body>
<div class="sidebar" id="sidebar">
<div class="logo"><i class="fas fa-skull-crossbones"></i> F7-TV</div>
<div class="menu">
<a href="?chnl=channels" class="menu-item <?=$section=='channels'?'active':''?>"><i class="fas fa-tv"></i> Каналы</a>
<a href="?chnl=keys" class="menu-item <?=$section=='keys'?'active':''?>"><i class="fas fa-key"></i> Ключи доступа</a>
<a href="?chnl=groups" class="menu-item <?=$section=='groups'?'active':''?>"><i class="fas fa-folder"></i> Группы</a>
</div>
<div class="sidebar-actions">
<a href="?logout=1" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Выход</a>
</div>
</div>
<div class="main-content">
<div class="header">
<div class="header-left">
<button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-terminal"></i></button>
<h1><?=$section=='channels'?'Управление каналами':($section=='keys'?'Управление ключами':'Управление группами')?></h1>
</div>
<div class="header-right">
<?php if($section=='channels'):?>
<button type="button" class="header-action-btn" onclick="openChannelModal()"><i class="fas fa-plus"></i> Добавить канал</button>
<button type="button" class="header-action-btn" onclick="openMassChannelModal()"><i class="fas fa-box"></i> Импорт M3U8</button>
<?php elseif($section=='keys'):?>
<button type="button" class="header-action-btn" onclick="openKeyModal()"><i class="fas fa-plus"></i> Добавить ключ</button>
<button type="button" class="header-action-btn" onclick="openMassKeyModal()"><i class="fas fa-box"></i> Массовое добавление</button>
<?php endif;?>
</div>
</div>
<div class="content">
<?php if($message):?>
<div class="message success"><i class="fas fa-check-circle"></i> <?=safe_html($message)?><button class="message-close" onclick="this.parentElement.remove()">&times;</button></div>
<?php endif;?>
<?php if($error):?>
<div class="message error"><i class="fas fa-exclamation-triangle"></i> <?=safe_html($error)?><button class="message-close" onclick="this.parentElement.remove()">&times;</button></div>
<?php endif;?>

<?php if($section=='channels'):?>
<div class="search-container">
<input type="text" id="searchChannels" class="search-input" placeholder="🔍 Поиск...">
<select id="groupFilter" class="filter-group">
<option value="">Все группы</option>
<?php foreach(array_keys($groups)as$group):?>
<option value="<?=safe_html($group)?>"><?=safe_html($group)?></option>
<?php endforeach;?>
</select>
</div>
<div class="stats-grid">
<div class="stat-card"><h3>Всего каналов</h3><div class="value"><?=$totalChannels?></div></div>
<div class="stat-card"><h3>Активных групп</h3><div class="value"><?=count($groups)?></div></div>
<div class="stat-card"><h3>С иконкой</h3><div class="value"><?=count(array_filter($channels,fn($c)=>!empty($c['icon_url'])))?></div></div>
</div>
<div class="table-container">
<table class="data-table">
<thead><tr><th style="width:40%">Стрим</th><th style="width:20%">ID</th><th style="width:15%">Класс</th><th style="width:15%">Группа</th><th style="width:10%">Действие</th></tr></thead>
<tbody id="channelsTableBody">
<?php if(empty($channels)):?>
<tr><td colspan="5" style="text-align:center;padding:30px">Нет данных</td></tr>
<?php else:foreach($channels as$c):?>
<tr data-group="<?=safe_html($c['channel_group'])?>" data-name="<?=safe_html($c['channel_name'])?>" data-id="<?=safe_html($c['channel_id'])?>">
<td><div class="channel-info"><div class="channel-icon"><?php if(!empty($c['icon_url'])):?><img src="<?=safe_html($c['icon_url'])?>" alt="icon"><?php else:?><i class="fas fa-tv"></i><?php endif;?></div><div class="channel-name"><?=safe_html($c['channel_name'])?></div></div></td>
<td><code><?=safe_html($c['channel_id'])?></code></td>
<td><?=safe_html($c['channel_class']??'-')?></td>
<td><?=$c['channel_group']?safe_html($c['channel_group']):'-'?></td>
<td><div class="actions">
<button class="action-btn" onclick='openChannelModal(<?=safe_json_encode($c)?>)'><i class="fas fa-edit"></i></button>
<form method="POST" style="display:inline" onsubmit="return confirm('Удалить канал?')">
<input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
<input type="hidden" name="channel_action" value="delete">
<input type="hidden" name="id" value="<?=safe_html($c['channel_id'])?>">
<button type="submit" class="action-btn delete"><i class="fas fa-trash-alt"></i></button>
</form>
</div></td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table>
</div>

<?php elseif($section=='keys'):?>
<div class="search-container"><input type="text" id="searchKeys" class="search-input" placeholder="🔍 Поиск ключей..."></div>
<div class="stats-grid">
<div class="stat-card"><h3>Всего ключей</h3><div class="value"><?=$totalKeys?></div></div>
<div class="stat-card"><h3>Активных</h3><div class="value"><?=count(array_filter($keys,fn($k)=>$k['status']=='active'))?></div></div>
<div class="stat-card"><h3>Забанено</h3><div class="value"><?=count(array_filter($keys,fn($k)=>$k['status']=='banned'))?></div></div>
</div>
<div class="table-container">
<table class="data-table">
<thead><tr><th>Ключ</th><th>Описание</th><th>Статус</th><th>Действие</th></tr></thead>
<tbody id="keysTableBody">
<?php if(empty($keys)):?>
<tr><td colspan="4" style="text-align:center;padding:30px">Нет ключей</td></tr>
<?php else:foreach($keys as$k):?>
<tr data-key="<?=safe_html($k['access_key'])?>" data-desc="<?=safe_html($k['discription']??'')?>">
<td class="key-cell"><span class="key-value"><?=safe_html($k['access_key'])?></span></td>
<td><?=safe_html($k['discription']??'')?></td>
<td><span class="status-badge <?=$k['status']=='active'?'status-active':'status-banned'?>"><?=$k['status']=='active'?'Работает':'Забанен'?></span></td>
<td><div class="actions">
<button class="action-btn" onclick="copyToClipboard('<?=safe_html($k['access_key'])?>')" title="Скопировать ключ"><i class="fas fa-copy"></i></button>
<button class="action-btn" onclick='openKeyModal(<?=safe_json_encode($k)?>)'><i class="fas fa-edit"></i></button>
<?php if($k['status']=='active'):?>
<form method="POST" style="display:inline">
<input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
<input type="hidden" name="key_action" value="toggle_status">
<input type="hidden" name="access_key" value="<?=safe_html($k['access_key'])?>">
<input type="hidden" name="status" value="banned">
<button type="submit" class="action-btn delete" title="Забанить"><i class="fas fa-ban"></i></button>
</form>
<?php else:?>
<form method="POST" style="display:inline">
<input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
<input type="hidden" name="key_action" value="toggle_status">
<input type="hidden" name="access_key" value="<?=safe_html($k['access_key'])?>">
<input type="hidden" name="status" value="active">
<button type="submit" class="action-btn" title="Активировать"><i class="fas fa-check-circle"></i></button>
</form>
<?php endif;?>
<form method="POST" style="display:inline" onsubmit="return confirm('Удалить ключ?')">
<input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
<input type="hidden" name="key_action" value="delete">
<input type="hidden" name="access_key" value="<?=safe_html($k['access_key'])?>">
<button type="submit" class="action-btn delete" title="Удалить"><i class="fas fa-trash-alt"></i></button>
</form>
</div></td>
</tr>
<?php endforeach;endif;?>
</tbody>
</table>
</div>

<?php elseif($section=='groups'):?>
<?php if(isset($_GET['action'])&&$_GET['action']=='edit'&&$edit_group):?>
<div class="table-container" style="padding:20px">
<h2>Редактирование группы</h2>
<form method="POST">
<input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
<input type="hidden" name="group_action" value="rename">
<input type="hidden" name="old_name" value="<?=safe_html($edit_group)?>">
<div class="form-group"><label>Текущее название</label><input type="text" class="form-control" value="<?=safe_html($edit_group)?>" readonly></div>
<div class="form-group"><label>Новое название</label><input type="text" class="form-control" name="new_name" required></div>
<div class="modal-footer"><a href="?chnl=groups" class="btn btn-secondary">Отмена</a><button type="submit" class="btn btn-primary">Переименовать</button></div>
</form>
</div>
<?php else:?>
<div class="search-container"><input type="text" id="searchGroups" class="search-input" placeholder="🔍 Поиск группы..."></div>
<div class="stats-grid">
<div class="stat-card"><h3>Всего групп</h3><div class="value"><?=count($groups)?></div></div>
<div class="stat-card"><h3>Всего каналов</h3><div class="value"><?=$totalChannels?></div></div>
</div>
<div class="table-container">
<table class="data-table">
<thead><tr><th>Группа</th><th>Каналов</th><th>Действия</th></tr></thead>
<tbody id="groupsTableBody">
<?php foreach($groups as$groupName=>$groupChannels):?>
<tr data-group="<?=safe_html($groupName)?>">
<td><strong><?=safe_html($groupName)?></strong></td>
<td><?=count($groupChannels)?></td>
<td><div class="actions">
<a href="?chnl=groups&action=edit&edit_group=<?=urlencode($groupName)?>" class="action-btn"><i class="fas fa-edit"></i> Переименовать</a>
<a href="?chnl=channels&group=<?=urlencode($groupName)?>" class="action-btn"><i class="fas fa-eye"></i> Показать</a>
</div></td>
</tr>
<?php endforeach;?>
</tbody>
</table>
</div>
<?php endif;?>
<?php endif;?>
</div>
</div>

<!-- Модалки -->
<div id="channelModal" class="modal"><div class="modal-content"><div class="modal-header"><h3 id="channelModalTitle">Добавить канал</h3><button type="button" class="btn btn-secondary" onclick="closeModal('channelModal')">&times;</button></div><form method="POST" id="channelForm"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=$csrf_token?>"><input type="hidden" name="channel_action" value="save"><input type="hidden" name="id" id="channelId"><div class="form-group"><label>ID канала *</label><input type="text" class="form-control" name="channel_id" id="channel_id" required></div><div class="form-group"><label>Название *</label><input type="text" class="form-control" name="channel_name" id="channel_name" required></div><div class="form-group"><label>Класс</label><input type="text" class="form-control" name="channel_class" id="channel_class"></div><div class="form-group"><label>Группа</label><input type="text" class="form-control" name="channel_group" id="channel_group"></div><div class="form-group"><label>URL иконки</label><input type="text" class="form-control" name="icon_url" id="icon_url"></div><div class="form-group"><label>URL потока *</label><input type="text" class="form-control" name="stream_url" id="stream_url" required></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('channelModal')">Отмена</button><button type="submit" class="btn btn-primary">Сохранить</button></div></form></div></div>

<div id="massChannelModal" class="modal"><div class="modal-content"><div class="modal-header"><h3>Импорт каналов</h3><button type="button" class="btn btn-secondary" onclick="closeModal('massChannelModal')">&times;</button></div><form method="POST" id="massChannelForm"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=$csrf_token?>"><input type="hidden" name="channel_action" value="mass_add"><input type="hidden" name="channels_data" id="channels_data"><div class="form-group"><label>Ручной ввод (формат: channel_id|channel_name|channel_class|channel_group|icon_url|stream_url)</label><textarea class="form-control" id="manual_data" rows="6" placeholder="channel_1|Первый канал||Группа 1||http://example.com/stream.m3u8"></textarea><button type="button" class="btn btn-primary" id="fillFromManualBtn" style="margin-top:8px"><i class="fas fa-arrow-down"></i> Заполнить из ручного ввода</button></div><hr style="border-color:#2a2e36;margin:15px 0"><div class="form-group"><label>Загрузить M3U8 файл</label><input type="file" id="m3uFile" accept=".m3u,.m3u8,audio/x-mpegurl,application/vnd.apple.mpegurl" class="form-control" style="padding:5px"><button type="button" class="btn btn-primary" id="parseM3UBtn" style="margin-top:10px"><i class="fas fa-upload"></i> Парсить и добавить</button></div><hr style="border-color:#2a2e36;margin:15px 0"><div class="form-group"><label style="display:flex;align-items:center;gap:10px"><input type="checkbox" id="ignoreGroupsCheckbox"> Не учитывать группы</label></div><div class="form-group"><label>Данные для импорта (редактируемые)</label><textarea class="form-control" id="channels_data_display" rows="10" placeholder="Данные появятся здесь после ручного ввода или парсинга файла"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('massChannelModal')">Отмена</button><button type="submit" class="btn btn-primary" id="massAddSubmit">Импортировать</button></div></form></div></div>

<div id="keyModal" class="modal"><div class="modal-content"><div class="modal-header"><h3 id="keyModalTitle">Добавить ключ</h3><button type="button" class="btn btn-secondary" onclick="closeModal('keyModal')">&times;</button></div><form method="POST" id="keyForm"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=$csrf_token?>"><input type="hidden" name="key_action" value="save"><input type="hidden" name="edit_key" id="edit_key"><div class="form-group"><label>Ключ доступа *</label><input type="text" class="form-control" name="access_key" id="access_key" required></div><div class="form-group"><label>Описание</label><input type="text" class="form-control" name="discription" id="discription"></div><div class="form-group"><label>Статус</label><select class="form-control" name="status" id="key_status"><option value="active">Активен</option><option value="banned">Забанен</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('keyModal')">Отмена</button><button type="submit" class="btn btn-primary">Сохранить</button></div></form></div></div>

<div id="massKeyModal" class="modal"><div class="modal-content"><div class="modal-header"><h3>Массовое добавление ключей</h3><button type="button" class="btn btn-secondary" onclick="closeModal('massKeyModal')">&times;</button></div><form method="POST"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=$csrf_token?>"><input type="hidden" name="key_action" value="mass_add"><div class="form-group"><label>Формат:</label><code style="display:block;background:#1a1e24;padding:8px">access_key|status|description</code><br><small>Статус: active или banned (по умолчанию active)</small></div><div class="form-group"><label>Данные (один ключ на строку)</label><textarea class="form-control" name="keys_data" rows="10" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('massKeyModal')">Отмена</button><button type="submit" class="btn btn-primary">Добавить</button></div></form></div></div>

<script>
const sidebar=document.getElementById('sidebar'),toggleBtn=document.getElementById('sidebarToggle');toggleBtn.addEventListener('click',()=>sidebar.classList.toggle('open'));document.addEventListener('click',e=>{if(!sidebar.contains(e.target)&&!toggleBtn.contains(e.target)&&sidebar.classList.contains('open'))sidebar.classList.remove('open')});
function openChannelModal(channel=null){let m=document.getElementById('channelModal');if(channel){document.getElementById('channelModalTitle').innerText='Редактировать канал';document.getElementById('channelId').value=channel.channel_id;document.getElementById('channel_id').value=channel.channel_id;document.getElementById('channel_name').value=channel.channel_name;document.getElementById('channel_class').value=channel.channel_class||'';document.getElementById('channel_group').value=channel.channel_group||'';document.getElementById('icon_url').value=channel.icon_url||'';document.getElementById('stream_url').value=channel.stream_url}else{document.getElementById('channelModalTitle').innerText='Добавить канал';document.getElementById('channelForm').reset();document.getElementById('channelId').value=''}m.classList.add('active')}
function openMassChannelModal(){document.getElementById('massChannelModal').classList.add('active')}
function openKeyModal(key=null){let m=document.getElementById('keyModal');if(key){document.getElementById('keyModalTitle').innerText='Редактировать ключ';document.getElementById('edit_key').value=key.access_key;document.getElementById('access_key').value=key.access_key;document.getElementById('access_key').readOnly=true;document.getElementById('discription').value=key.discription||'';document.getElementById('key_status').value=key.status}else{document.getElementById('keyModalTitle').innerText='Добавить ключ';document.getElementById('keyForm').reset();document.getElementById('edit_key').value='';document.getElementById('access_key').readOnly=false}m.classList.add('active')}
function openMassKeyModal(){document.getElementById('massKeyModal').classList.add('active')}
function closeModal(id){document.getElementById(id).classList.remove('active')}
function filterChannels(){let s=document.getElementById('searchChannels')?.value.toLowerCase()||'',g=document.getElementById('groupFilter')?.value||'';document.querySelectorAll('#channelsTableBody tr').forEach(r=>{let n=r.getAttribute('data-name')?.toLowerCase()||'',i=r.getAttribute('data-id')?.toLowerCase()||'',gr=r.getAttribute('data-group')||'';r.style.display=(s&&!n.includes(s)&&!i.includes(s))||(g&&gr!==g)?'none':''})}
function filterKeys(){let s=document.getElementById('searchKeys')?.value.toLowerCase()||'';document.querySelectorAll('#keysTableBody tr').forEach(r=>{let k=r.getAttribute('data-key')?.toLowerCase()||'',d=r.getAttribute('data-desc')?.toLowerCase()||'';r.style.display=(s&&!k.includes(s)&&!d.includes(s))?'none':''})}
function filterGroups(){let s=document.getElementById('searchGroups')?.value.toLowerCase()||'';document.querySelectorAll('#groupsTableBody tr').forEach(r=>{let g=r.getAttribute('data-group')?.toLowerCase()||'';r.style.display=(s&&!g.includes(s))?'none':''})}
function copyToClipboard(t){navigator.clipboard.writeText(t).then(()=>alert('Скопировано: '+t)).catch(()=>alert('Ошибка копирования'))}
function parseM3U(content){let lines=content.split(/\r?\n/),channels=[],i=0;while(i<lines.length){let line=lines[i].trim();if(line.startsWith('#EXTINF')){let channelName='',channelGroup='',iconUrl='',extinf=line.substring(7),groupMatch=extinf.match(/group-title="([^"]*)"/);if(groupMatch)channelGroup=groupMatch[1];let logoMatch=extinf.match(/tvg-logo="([^"]*)"/);if(logoMatch)iconUrl=logoMatch[1];let commaPos=extinf.lastIndexOf(',');channelName=commaPos!==-1?extinf.substring(commaPos+1).trim():'Unknown';i++;while(i<lines.length&&lines[i].trim()==='')i++;if(i<lines.length){let url=lines[i].trim();while(url.startsWith('#')&&i<lines.length){i++;url=lines[i]?.trim()||''}if(url&&!url.startsWith('#')){let id=channelName.toLowerCase().replace(/[^a-z0-9]/g,'_').replace(/_+/g,'_').replace(/^_|_$/g,'');if(!id)id='channel_'+(channels.length+1);channels.push({channel_id:id,channel_name:channelName,channel_class:'',channel_group:channelGroup,icon_url:iconUrl,stream_url:url})}}}i++}return channels}
document.getElementById('fillFromManualBtn')?.addEventListener('click',()=>{let manual=document.getElementById('manual_data').value;if(manual.trim()){document.getElementById('channels_data_display').value=manual;updateHiddenData();alert('Данные скопированы в поле для импорта.')}else alert('Введите данные')});
document.getElementById('parseM3UBtn')?.addEventListener('click',()=>{let fileInput=document.getElementById('m3uFile');if(!fileInput.files.length){alert('Выберите файл');return}let file=fileInput.files[0],reader=new FileReader();reader.onload=function(e){let channels=parseM3U(e.target.result);if(channels.length===0){alert('Не удалось распознать каналы');return}let lines=channels.map(ch=>`${ch.channel_id}|${ch.channel_name}|${ch.channel_class}|${ch.channel_group}|${ch.icon_url}|${ch.stream_url}`),current=document.getElementById('channels_data_display').value,newData=current.trim()?current+'\n'+lines.join('\n'):lines.join('\n');document.getElementById('channels_data_display').value=newData;updateHiddenData();alert(`Распознано ${channels.length} каналов`)};reader.onerror=()=>alert('Ошибка чтения');reader.readAsText(file,'UTF-8')});
function updateHiddenData(){document.getElementById('channels_data').value=document.getElementById('channels_data_display').value}
document.getElementById('massChannelForm')?.addEventListener('submit',function(e){let ignoreGroups=document.getElementById('ignoreGroupsCheckbox').checked,data=document.getElementById('channels_data_display').value;if(ignoreGroups&&data.trim()){let lines=data.split(/\r?\n/),newLines=[];for(let line of lines){line=line.trim();if(!line)continue;let parts=line.split('|');if(parts.length>=4){parts[3]='';newLines.push(parts.join('|'))}else newLines.push(line)}data=newLines.join('\n');document.getElementById('channels_data_display').value=data;updateHiddenData()}updateHiddenData()});
document.getElementById('channels_data_display')?.addEventListener('input',updateHiddenData);
document.getElementById('searchChannels')?.addEventListener('input',filterChannels);
document.getElementById('groupFilter')?.addEventListener('change',filterChannels);
document.getElementById('searchKeys')?.addEventListener('input',filterKeys);
document.getElementById('searchGroups')?.addEventListener('input',filterGroups);
let urlGroup=new URLSearchParams(window.location.search).get('group');if(urlGroup&&document.getElementById('groupFilter')){document.getElementById('groupFilter').value=urlGroup;filterChannels()}
window.onclick=e=>{if(e.target.classList.contains('modal'))e.target.classList.remove('active')}
</script>
</body>
</html>
