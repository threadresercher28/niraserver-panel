<?php
session_start();
require_once 'config.php';
if(!isset($_SESSION['admin'])||$_SESSION['admin']!==true){header('Location: index.php');exit;}
if(isset($_GET['logout'])){session_destroy();header('Location: ../index.php');exit;}
try{$pdo=new PDO("mysql:host=".database_server.";dbname=".database_name.";charset=utf8mb4",database_login,database_password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}catch(Exception $e){exit('Ошибка базы данных');}
$users=table_users;$streams=table_stream_source;$msg=$err='';

try{$pdo->query("SELECT expires FROM $users LIMIT 1");}catch(Exception $e){$pdo->exec("ALTER TABLE $users ADD COLUMN expires DATE NULL");}
try{$pdo->query("SELECT groups_access FROM $users LIMIT 1");}catch(Exception $e){$pdo->exec("ALTER TABLE $users ADD COLUMN groups_access TEXT NULL");}
try{$pdo->query("SELECT type FROM $streams LIMIT 1");}catch(Exception $e){$pdo->exec("ALTER TABLE $streams ADD COLUMN type VARCHAR(20) DEFAULT 'video'");}

if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
$token=$_SESSION['csrf_token'];
function v($t){return isset($_SESSION['csrf_token'])&&hash_equals($_SESSION['csrf_token'],$t);}
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function j($d){return json_encode($d,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?:'null';}
function gen_id($prefix='ch'){return $prefix.'_'.strtolower(bin2hex(random_bytes(4)));}
function gen_key($len=12){return substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'),0,$len);}

// Обработка каналов
if($_SERVER['REQUEST_METHOD']=='POST'&&isset($_POST['channel_action'])){
    if(!isset($_POST['csrf_token'])||!v($_POST['csrf_token']))$err='Ошибка CSRF';
    else{$a=$_POST['channel_action'];
        try{
            if($a=='save'){
                $id=trim($_POST['channel_id']??'');$name=trim($_POST['channel_name']??'');$url=trim($_POST['stream_url']??'');
                if(empty($id))$id=gen_id('ch');
                if(!$name||!$url)$err='Обязательные поля';
                else{
                    $type=in_array($_POST['type']??'',['video','radio'])?$_POST['type']:'video';
                    $d=[$id,$name,$_POST['channel_class']??'',$_POST['channel_group']??'',$_POST['icon_url']??'',$url,$type];
                    if(!empty($_POST['id'])){$s=$pdo->prepare("UPDATE $streams SET channel_id=?,channel_name=?,channel_class=?,channel_group=?,icon_url=?,stream_url=?,type=? WHERE channel_id=?");$s->execute(array_merge($d,[$_POST['id']]));$msg='Обновлено';}
                    else{$s=$pdo->prepare("INSERT INTO $streams (channel_id,channel_name,channel_class,channel_group,icon_url,stream_url,type) VALUES(?,?,?,?,?,?,?)");$s->execute($d);$msg="Добавлено (ID: $id)";}
                }
            }
            elseif($a=='delete'){$s=$pdo->prepare("DELETE FROM $streams WHERE channel_id=?");$s->execute([$_POST['id']]);$msg='Удалено';}
            elseif($a=='mass_add'){
                $c=0;
                foreach(explode("\n",trim($_POST['channels_data']))as $l){
                    $l=trim($l);if(!$l)continue;
                    $p=explode('|',$l);if(count($p)<2)continue;
                    [$i,$n,$cl,$g,$ic,$u,$type]=array_pad($p,7,'');
                    if(empty($i))$i=gen_id('ch');
                    if(!$n||!$u)continue;
                    $type=in_array($type,['video','radio'])?$type:'video';
                    $s=$pdo->prepare("SELECT 1 FROM $streams WHERE channel_id=?");
                    $s->execute([$i]);
                    if($s->fetch()){$s=$pdo->prepare("UPDATE $streams SET channel_name=?,channel_class=?,channel_group=?,icon_url=?,stream_url=?,type=? WHERE channel_id=?");$s->execute([$n,$cl,$g,$ic,$u,$type,$i]);}
                    else{$s=$pdo->prepare("INSERT INTO $streams (channel_id,channel_name,channel_class,channel_group,icon_url,stream_url,type) VALUES(?,?,?,?,?,?,?)");$s->execute([$i,$n,$cl,$g,$ic,$u,$type]);}
                    $c++;
                }
                $msg="Добавлено/Обновлено: $c";
            }
        }catch(Exception $e){$err=$e->getMessage();}}
}

// Обработка ключей
if($_SERVER['REQUEST_METHOD']=='POST'&&isset($_POST['key_action'])){
    if(!isset($_POST['csrf_token'])||!v($_POST['csrf_token']))$err='Ошибка CSRF';
    else{$a=$_POST['key_action'];
        try{
            if($a=='save'){
                $k=trim($_POST['access_key']??'');if(empty($k))$k=gen_key(12);
                $groups_access=isset($_POST['groups_access'])?implode(',',$_POST['groups_access']):'';
                $expires=!empty($_POST['expires'])?$_POST['expires']:null;
                if(!empty($_POST['old_key'])&&$_POST['old_key']!=$k){$s=$pdo->prepare("UPDATE $users SET access_key=?,discription=?,status=?,expires=?,groups_access=? WHERE access_key=?");$s->execute([$k,$_POST['discription']??'',$_POST['status'],$expires,$groups_access,$_POST['old_key']]);$msg='Ключ изменён';}
                elseif(!empty($_POST['edit_key'])){$s=$pdo->prepare("UPDATE $users SET discription=?,status=?,expires=?,groups_access=? WHERE access_key=?");$s->execute([$_POST['discription']??'',$_POST['status'],$expires,$groups_access,$_POST['edit_key']]);$msg='Ключ обновлён';}
                else{$s=$pdo->prepare("SELECT 1 FROM $users WHERE access_key=?");$s->execute([$k]);if($s->fetch())$err='Ключ уже существует';else{$s=$pdo->prepare("INSERT INTO $users(access_key,status,discription,expires,groups_access) VALUES(?,?,?,?,?)");$s->execute([$k,$_POST['status'],$_POST['discription']??'',$expires,$groups_access]);$msg="Ключ добавлен: $k";}}
            }
            elseif($a=='delete'){$s=$pdo->prepare("DELETE FROM $users WHERE access_key=?");$s->execute([$_POST['access_key']]);$msg='Удалено';}
            elseif($a=='toggle_status'){$ns=$_POST['status']=='active'?'active':'banned';$s=$pdo->prepare("UPDATE $users SET status=? WHERE access_key=?");$s->execute([$ns,$_POST['access_key']]);$msg=$ns=='active'?'Активирован':'Заблокирован';}
            elseif($a=='mass_add'){$c=0;foreach(explode("\n",trim($_POST['keys_data']))as $l){$l=trim($l);if(!$l)continue;$p=explode('|',$l);$k=trim($p[0]??'');if(empty($k))$k=gen_key(12);$st=trim($p[1]??'active');$st=($st=='active'||$st=='banned')?$st:'active';$d=trim($p[2]??'');$exp=trim($p[3]??'');$exp=!empty($exp)?$exp:null;$gr=trim($p[4]??'');$s=$pdo->prepare("SELECT 1 FROM $users WHERE access_key=?");$s->execute([$k]);if(!$s->fetch()){$s=$pdo->prepare("INSERT INTO $users(access_key,status,discription,expires,groups_access) VALUES(?,?,?,?,?)");$s->execute([$k,$st,$d,$exp,$gr]);$c++;}}$msg="Добавлено: $c";}
        }catch(Exception $e){$err=$e->getMessage();}}
}

$sec=isset($_GET['chnl'])&&in_array($_GET['chnl'],['channels','radio','keys','about'])?$_GET['chnl']:'channels';
$typeFilter = ($sec=='radio')?'radio':(($sec=='channels')?'video':null);
if($typeFilter){
    $channels=$pdo->prepare("SELECT * FROM $streams WHERE type=? ORDER BY channel_group,channel_name");
    $channels->execute([$typeFilter]);
    $channels=$channels->fetchAll();
}else{
    $channels=$pdo->query("SELECT * FROM $streams ORDER BY channel_group,channel_name")->fetchAll();
}
$totalCh=count($channels);

// Группы только для текущей вкладки
if ($sec == 'channels') {
    $groups = $pdo->query("SELECT DISTINCT channel_group FROM $streams WHERE channel_group!='' AND type='video' ORDER BY channel_group")->fetchAll(PDO::FETCH_COLUMN);
} elseif ($sec == 'radio') {
    $groups = $pdo->query("SELECT DISTINCT channel_group FROM $streams WHERE channel_group!='' AND type='radio' ORDER BY channel_group")->fetchAll(PDO::FETCH_COLUMN);
} else {
    $groups = $pdo->query("SELECT DISTINCT channel_group FROM $streams WHERE channel_group!='' ORDER BY channel_group")->fetchAll(PDO::FETCH_COLUMN);
}

$keys=$pdo->query("SELECT * FROM $users ORDER BY access_key")->fetchAll();
$totalKe=count($keys);
$activeKeys=count(array_filter($keys,fn($k)=>$k['status']=='active'&&(empty($k['expires'])||$k['expires']>date('Y-m-d'))));
$expiredKeys=count(array_filter($keys,fn($k)=>!empty($k['expires'])&&$k['expires']<=date('Y-m-d')));
$bannedKeys=count(array_filter($keys,fn($k)=>$k['status']=='banned'));
?>
<!DOCTYPE html>
<html lang="ru" class="dark-theme"> <!-- всегда тёмная тема -->
<head>
<meta charset="utf-8">
<title>Nira Panel</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/res/css/all.min.css">
<link rel="stylesheet" href="/res/css/plyr.css">
<style>
/* === БАЗОВЫЙ КОМПАКТНЫЙ ДИЗАЙН (ТЁМНАЯ ТЕМА ПО УМОЛЧАНИЮ) === */
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#2a2a2a;color:#d4af37;font-size:13px}
.sidebar{position:fixed;left:0;top:0;width:210px;height:100%;background:#1e1e1e;color:#e2e8f0;display:flex;flex-direction:column;z-index:1000;overflow-y:auto;border-right:1px solid #444}
.sidebar .logo{padding:14px 16px;font-size:1.2rem;font-weight:700;background:#121212;text-align:center;border-bottom:1px solid #444}
.sidebar .logo i{margin-right:8px;color:#d4af37}
.menu{flex:1;padding:12px 8px}
.menu-item{display:flex;align-items:center;gap:10px;padding:8px 14px;color:#b8956e;text-decoration:none;margin:2px 0;border-radius:8px;transition:all .2s;font-weight:500;font-size:.9rem}
.menu-item i{width:20px;font-size:1rem}
.menu-item:hover{background:#3a3a3a;color:#f5d742}
.menu-item.active{background:#d4af37;color:#1e1e1e}
.sidebar-actions{padding:14px 12px;border-top:1px solid #444}
.logout-btn{display:flex;justify-content:center;align-items:center;gap:8px;padding:10px;background:#b22222;border-radius:8px;color:#fff;text-decoration:none;font-weight:600;font-size:.9rem}
.logout-btn:hover{background:#8b1a1a}
.main-content{margin-left:210px;min-height:100vh}
.header{background:rgba(30,30,30,0.85);backdrop-filter:blur(8px);border-bottom:1px solid #444;padding:10px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:100;flex-wrap:wrap;gap:8px}
.header-left{display:flex;align-items:center;gap:14px}
.sidebar-toggle{display:none;background:#1e1e1e;border:none;color:#fff;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:1rem}
.header h1{font-size:1.1rem;font-weight:700;color:#d4af37}
.header h1 i{margin-right:8px;color:#d4af37}
.header-right{display:flex;gap:8px;flex-wrap:wrap}
.header-action-btn{background:#d4af37;border:none;padding:6px 14px;border-radius:6px;color:#1e1e1e;cursor:pointer;font-size:.75rem;font-weight:600;display:inline-flex;align-items:center;gap:6px;transition:all .2s}
.header-action-btn:hover{background:#c49a2c}
.content{padding:16px 20px;background:#2a2a2a}
.search-bar{display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap}
.search-input{flex:1;background:#1e1e1e;border:1px solid #444;border-radius:8px;padding:8px 14px;font-size:.85rem;transition:all .2s;color:#d4af37}
.search-input:focus{outline:none;border-color:#d4af37;box-shadow:0 0 0 3px rgba(212,175,55,0.2)}
.search-input::placeholder{color:#8b7a5a}
.search-input[style*="width:auto"]{width:auto!important;min-width:120px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:20px}
.stat-card{background:#1e1e1e;border-radius:10px;padding:14px 12px;text-align:center;border:1px solid #444;transition:all .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,0.3)}
.stat-card .val{font-size:1.6rem;font-weight:700;color:#d4af37;line-height:1.2}
.stat-card .label{font-size:.65rem;text-transform:uppercase;letter-spacing:0.3px;color:#b8956e;margin-top:4px;font-weight:600}
.table-wrap{background:#1e1e1e;border-radius:10px;border:1px solid #444;overflow-x:auto;margin-bottom:16px}
table{width:100%;border-collapse:collapse;min-width:500px;background:#1e1e1e}
th{text-align:left;padding:10px 14px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;color:#b8956e;border-bottom:2px solid #444;background:#121212}
td{padding:10px 14px;border-bottom:1px solid #2a2a2a;vertical-align:middle;font-size:.85rem;color:#d4af37}
tr:hover{background:#2a2a2a}
.ch-info{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.ch-icon{width:32px;height:32px;background:#3a3a3a;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
.ch-icon img{width:100%;height:100%;object-fit:cover}
.ch-icon i{font-size:1rem;color:#d4af37}
.channel-name{font-weight:600;color:#d4af37}
.channel-id{font-family:monospace;font-size:.65rem;color:#b8956e;margin-top:1px}
.channel-group-badge{display:inline-block;padding:2px 10px;background:#3a3a3a;border-radius:12px;font-size:.65rem;font-weight:600;color:#d4af37}
.status{padding:2px 10px;border-radius:12px;font-size:.65rem;font-weight:600;display:inline-block}
.status-active{background:#2d4a2d;color:#a3e0a3}
.status-banned{background:#4a1a1a;color:#f5a5a5}
.status-expired{background:#4a3a1a;color:#f5d06a}
.groups-badge{display:inline-block;background:#3a3a3a;padding:1px 8px;border-radius:10px;font-size:.6rem;margin:1px;white-space:nowrap;color:#d4af37}
.action-buttons{display:flex;gap:4px;flex-wrap:wrap}
.btn-sm{background:#3a3a3a;border:1px solid #555;padding:4px 10px;border-radius:6px;color:#d4af37;cursor:pointer;font-size:.65rem;font-weight:600;display:inline-flex;align-items:center;gap:4px;transition:all .15s}
.btn-sm:hover{background:#555}
.btn-sm i{font-size:.8rem}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);backdrop-filter:blur(3px);z-index:2000;align-items:center;justify-content:center}
.modal.active{display:flex}
.modal-content{background:#1e1e1e;border-radius:12px;width:90%;max-width:480px;max-height:80vh;overflow-y:auto;border:1px solid #444;box-shadow:0 20px 40px rgba(0,0,0,0.5)}
.modal-header{padding:16px 20px;border-bottom:1px solid #444;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#1e1e1e;border-radius:12px 12px 0 0}
.modal-header h3{font-size:1.1rem;font-weight:700;color:#d4af37}
.modal-body{padding:20px}
.modal-footer{padding:16px 20px;border-top:1px solid #444;display:flex;justify-content:flex-end;gap:10px;position:sticky;bottom:0;background:#1e1e1e;border-radius:0 0 12px 12px}
.form-group{margin-bottom:14px}
.form-group label{display:block;margin-bottom:6px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.3px;color:#b8956e}
.form-control{width:100%;background:#121212;border:1px solid #444;border-radius:8px;padding:8px 12px;font-size:.85rem;transition:border .2s,box-shadow .2s;color:#d4af37}
.form-control:focus{outline:none;border-color:#d4af37;box-shadow:0 0 0 3px rgba(212,175,55,0.2)}
textarea.form-control{resize:vertical;min-height:80px;font-family:monospace}
.checkbox-group{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;max-height:160px;overflow-y:auto;padding:10px;background:#121212;border-radius:8px;border:1px solid #444}
.checkbox-item{display:flex;align-items:center;gap:6px;background:#1e1e1e;padding:6px 12px;border-radius:6px;cursor:pointer;border:1px solid #444;transition:all .15s;font-size:.85rem;color:#d4af37}
.checkbox-item:hover{background:#3a3a3a}
.checkbox-item input{cursor:pointer}
.btn{padding:8px 20px;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer;border:none;transition:all .2s}
.btn-primary{background:#d4af37;color:#1e1e1e}
.btn-primary:hover{background:#c49a2c}
.btn-secondary{background:#3a3a3a;color:#d4af37}
.btn-secondary:hover{background:#555}
hr{margin:16px 0;border:none;border-top:1px solid #444}

/* === ОВЕРЛЕЙ ДЛЯ ВИДЕО (Plyr) === */
.video-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:3000;align-items:center;justify-content:center}
.video-overlay.active{display:flex}
.video-container{width:90%;max-width:900px;background:#0f172a;border-radius:12px;overflow:hidden;border:1px solid #334155}
.video-header{padding:12px 20px;background:#1e293b;border-bottom:1px solid #334155;display:flex;justify-content:space-between;align-items:center}
.video-header h3{color:#f1f5f9;font-size:.9rem;font-weight:600}
.close-player{background:transparent;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer}
.close-player:hover{color:#ef4444}
#player-video{width:100%;background:#000}

/* === АУДИО-ПЛЕЕР ДЛЯ РАДИО === */
.audio-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);z-index:3000;align-items:center;justify-content:center}
.audio-overlay.active{display:flex}
.audio-container{width:90%;max-width:420px;background:#1e1e1e;border-radius:16px;padding:30px 24px 24px;border:1px solid #444;text-align:center;position:relative}
.audio-container .audio-title{color:#d4af37;font-size:1.2rem;font-weight:600;margin-bottom:20px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.audio-container .audio-controls{display:flex;align-items:center;justify-content:center;gap:20px;margin-bottom:16px}
.audio-container .audio-controls button{background:transparent;border:none;color:#d4af37;font-size:2rem;cursor:pointer;padding:8px;transition:color .2s}
.audio-container .audio-controls button:hover{color:#f5d742}
.audio-container .audio-controls button:active{transform:scale(0.9)}
.audio-container .audio-progress{width:100%;height:4px;background:#444;border-radius:2px;cursor:pointer;margin-bottom:12px;position:relative}
.audio-container .audio-progress .progress-fill{height:100%;background:#d4af37;border-radius:2px;width:0%;transition:width .1s}
.audio-container .audio-time{display:flex;justify-content:space-between;color:#b8956e;font-size:.75rem;margin-bottom:8px}
.audio-container .audio-volume{display:flex;align-items:center;gap:10px;justify-content:center;color:#b8956e}
.audio-container .audio-volume input[type="range"]{width:80px;height:3px;background:#444;border-radius:2px;appearance:none;outline:none}
.audio-container .audio-volume input[type="range"]::-webkit-slider-thumb{appearance:none;width:10px;height:10px;background:#d4af37;border-radius:50%;cursor:pointer}
.audio-container .close-audio{position:absolute;top:12px;right:16px;background:transparent;border:none;color:#94a3b8;font-size:1.3rem;cursor:pointer}
.audio-container .close-audio:hover{color:#ef4444}

/* === PLYR ТОЛЬКО ДЛЯ ВИДЕО (ТЁМНАЯ) === */
.plyr{background:#0f172a;color:#d4af37}
.plyr__controls{background:#1e293b;border-top:1px solid #334155}
.plyr__control{color:#94a3b8}
.plyr__control:hover{background:#334155;color:#f1f5f9}
.plyr__time{color:#94a3b8}
.plyr__progress__buffer{background:#334155}
.plyr__progress--played,
.plyr__progress--played .plyr__progress__buffer{background:#d4af37}
.plyr__menu__container{background:#1e293b;border-color:#334155;color:#d4af37}
.plyr__menu__container .plyr__control{color:#d4af37}
.plyr__menu__container .plyr__control:hover{background:#334155}
.plyr__tooltip{background:#334155;color:#d4af37}
.plyr__video-wrapper{background:#000}
.plyr__captions{background:rgba(0,0,0,0.7);color:#fff}

code{background:#3a3a3a;padding:2px 8px;border-radius:4px;font-family:monospace;font-size:.75rem;color:#d4af37}
.auto-badge{font-size:.55rem;background:#d4af37;padding:2px 10px;border-radius:12px;margin-left:8px;color:#1e1e1e;font-weight:600}
.message-success,.message-error{padding:10px 18px;border-radius:8px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;font-weight:500;font-size:.85rem}
.message-success{background:#1a3a1a;border:1px solid #2d4a2d;color:#a3e0a3}
.message-error{background:#3a1a1a;border:1px solid #4a2a2a;color:#f5a5a5}
.empty-state{text-align:center;padding:40px 20px;color:#b8956e}
.empty-state i{font-size:2.5rem;margin-bottom:12px;color:#444}
.empty-state p{font-size:.95rem}
.about-section{background:#1e1e1e;border-radius:10px;padding:20px 24px;border:1px solid #444;margin-top:16px}
.about-header{display:flex;align-items:center;gap:14px;padding-bottom:16px;border-bottom:1px solid #444;margin-bottom:20px}
.about-header i{font-size:1.8rem;color:#d4af37;width:44px;height:44px;background:#2a2a2a;border-radius:10px;display:flex;align-items:center;justify-content:center}
.about-header h3{font-size:1.1rem;font-weight:700;color:#d4af37;margin:0 0 2px}
.about-header p{font-size:.85rem;color:#b8956e}
.about-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:20px}
.about-card{display:flex;align-items:center;gap:12px;padding:12px 16px;background:#121212;border-radius:8px;border:1px solid #444;transition:all .2s}
.about-card:hover{background:#1e1e1e}
.about-card-icon{width:32px;height:32px;background:#2a2a2a;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#d4af37;font-size:.9rem}
.about-card-label{font-size:.55rem;text-transform:uppercase;letter-spacing:0.3px;color:#b8956e;font-weight:600}
.about-card-value{font-size:.95rem;font-weight:700;color:#d4af37;margin-top:1px}
.about-footer{padding-top:12px;border-top:1px solid #444;text-align:center;font-size:.75rem;color:#b8956e}

@media(max-width:768px){
    .sidebar{transform:translateX(-100%);width:210px}
    .sidebar.open{transform:translateX(0)}
    .main-content{margin-left:0}
    .sidebar-toggle{display:block}
    .stats{grid-template-columns:repeat(2,1fr);gap:10px}
    .header{flex-direction:column;align-items:stretch;padding:10px}
    .header-left{justify-content:space-between;width:100%}
    .header-right{width:100%}
    .header-action-btn{flex:1;justify-content:center;font-size:.7rem;padding:6px 10px}
    .search-bar{flex-direction:column}
    .search-input[style*="width:auto"]{width:100%!important}
    .content{padding:12px}
    td{padding:8px 10px;font-size:.8rem}
    .btn-sm{padding:4px 8px;font-size:.6rem}
    .modal-content{width:95%;margin:10px}
    .checkbox-group{max-height:100px}
    .audio-container{width:95%;padding:20px 16px}
}
</style>
</head>
<body>
<div class="sidebar" id="sidebar">
<div class="logo"><i class="fas fa-tv"></i> Nira Panel</div>
<div class="menu">
<a href="?chnl=channels" class="menu-item <?=$sec=='channels'?'active':''?>"><i class="fas fa-video"></i> Каналы</a>
<a href="?chnl=radio" class="menu-item <?=$sec=='radio'?'active':''?>"><i class="fas fa-music"></i> Радио</a>
<a href="?chnl=keys" class="menu-item <?=$sec=='keys'?'active':''?>"><i class="fas fa-key"></i> Ключи</a>
<a href="?chnl=about" class="menu-item <?=$sec=='about'?'active':''?>"><i class="fas fa-info-circle"></i> О системе</a>
</div>
<div class="sidebar-actions">
<a href="?logout=1" class="logout-btn" onclick="return confirm('Выйти?')"><i class="fas fa-sign-out-alt"></i> Выход</a>
</div>
</div>

<div class="main-content">
<div class="header">
<div class="header-left">
<button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
<h1><i class="fas fa-<?=$sec=='channels'?'video':($sec=='radio'?'music':($sec=='keys'?'key':'info-circle'))?>"></i> <?=$sec=='channels'?'Телеканалы':($sec=='radio'?'Радиостанции':($sec=='keys'?'Ключи':'О системе'))?></h1>
</div>
<div class="header-right">
<?php if($sec=='channels'||$sec=='radio'):?>
<button class="header-action-btn" onclick="openChannelModal()"><i class="fas fa-plus"></i> Добавить</button>
<button class="header-action-btn" onclick="openMassChannelModal()"><i class="fas fa-upload"></i> Импорт</button>
<?php elseif($sec=='keys'):?>
<button class="header-action-btn" onclick="openKeyModal()"><i class="fas fa-plus"></i> Добавить</button>
<button class="header-action-btn" onclick="openMassKeyModal()"><i class="fas fa-layer-group"></i> Массово</button>
<?php endif;?>
</div>
</div>
<div class="content">
<?php if($msg):?><div class="message-success"><span><i class="fas fa-check-circle"></i> <?=h($msg)?></span><button onclick="this.parentElement.remove()" style="background:0;border:0;color:inherit;cursor:pointer">&times;</button></div><?php endif;?>
<?php if($err):?><div class="message-error"><span><i class="fas fa-exclamation-triangle"></i> <?=h($err)?></span><button onclick="this.parentElement.remove()" style="background:0;border:0;color:inherit;cursor:pointer">&times;</button></div><?php endif;?>

<?php if($sec=='channels'||$sec=='radio'):?>
<div class="search-bar">
<input type="text" id="searchChannels" class="search-input" placeholder="Поиск">
<select id="groupFilter" class="search-input" style="width:auto;min-width:120px"><option value="">Все группы</option><?php foreach($groups as $g):?><option value="<?=h($g)?>"><?=h($g)?></option><?php endforeach;?></select>
</div>
<div class="stats">
<div class="stat-card"><div class="val"><?=$totalCh?></div><div class="label">Всего</div></div>
<div class="stat-card"><div class="val"><?=count($groups)?></div><div class="label">Групп</div></div>
</div>
<div class="table-wrap"><table><thead><tr><th>Название</th><th>Группа</th><th>Действия</th></tr></thead><tbody id="channelsTableBody"><?php if(empty($channels)):?><tr><td colspan="3"><div class="empty-state"><i class="fas fa-<?=$sec=='radio'?'music':'tv'?>"></i><p>Нет записей. Добавьте первую</p></div></td></tr><?php else: foreach($channels as $c):?><tr data-group="<?=h($c['channel_group'])?>" data-name="<?=h($c['channel_name'])?>" data-id="<?=h($c['channel_id'])?>">
<td><div class="ch-info"><div class="ch-icon"><?php if($c['icon_url']):?><img src="<?=h($c['icon_url'])?>" onerror="this.style.display='none';this.nextElementSibling.style.display='block';"><i class="fas fa-<?=$sec=='radio'?'music':'tv'?>" style="display:none"></i><?php else:?><i class="fas fa-<?=$sec=='radio'?'music':'tv'?>"></i><?php endif;?></div><div><div class="channel-name"><?=h($c['channel_name'])?></div><div class="channel-id"><?=h($c['channel_id'])?></div></div></div></td>
<td><?php if($c['channel_group']):?><span class="channel-group-badge"><i class="fas fa-folder"></i> <?=h($c['channel_group'])?></span><?php else:?>—<?php endif;?></td>
<td><div class="action-buttons">
<button class="btn-sm" onclick="previewChannel('<?=h($c['stream_url'])?>','<?=h($c['channel_name'])?>','<?=$sec=='radio'?'radio':'video'?>')"><i class="fas fa-play"></i></button>
<button class="btn-sm" onclick='openChannelModal(<?=j($c)?>)'><i class="fas fa-edit"></i></button>
<form method="POST" style="display:inline" onsubmit="return confirm('Удалить «<?=h($c['channel_name'])?>»?')">
<input type="hidden" name="csrf_token" value="<?=$token?>">
<input type="hidden" name="channel_action" value="delete">
<input type="hidden" name="id" value="<?=h($c['channel_id'])?>">
<button class="btn-sm"><i class="fas fa-trash"></i></button>
</form>
</div></td>
</tr><?php endforeach; endif;?></tbody></table></div>

<?php elseif($sec=='keys'):?>
<div class="search-bar"><input type="text" id="searchKeys" class="search-input" placeholder="🔍 Поиск по ключу или описанию..."></div>
<div class="stats"><div class="stat-card"><div class="val"><?=$totalKe?></div><div class="label">Всего</div></div><div class="stat-card"><div class="val"><?=$activeKeys?></div><div class="label">Активных</div></div><div class="stat-card"><div class="val"><?=$bannedKeys?></div><div class="label">Заблок.</div></div><div class="stat-card"><div class="val"><?=$expiredKeys?></div><div class="label">Просроч.</div></div></div>
<div class="table-wrap"><table><thead><tr><th>Ключ</th><th>Описание</th><th>Статус</th><th>До</th><th>Доступные группы</th><th>Действия</th></tr></thead><tbody id="keysTableBody"><?php if(empty($keys)):?><tr><td colspan="6"><div class="empty-state"><i class="fas fa-key"></i><p>Нет ключей. Добавьте первый ключ</p></div></td></tr><?php else: foreach($keys as $k):$isExpired=!empty($k['expires'])&&$k['expires']<date('Y-m-d');$statusClass=$k['status']=='active'?($isExpired?'status-expired':'status-active'):'status-banned';$statusText=$k['status']=='active'?($isExpired?'Просрочен':'Активен'):'Заблокирован';$groupsList=explode(',',$k['groups_access']??'');?><tr><td><code><?=h($k['access_key'])?></code></td><td><?=h($k['discription']??'—')?></td><td><span class="status <?=$statusClass?>"><?=$statusText?></span></td><td><?=h($k['expires']??'∞')?></td><td><?php if(empty($groupsList[0])):?>🌍 Все группы<?php else: foreach($groupsList as $g): if($g):?><span class="groups-badge"><?=h($g)?></span><?php endif; endforeach; endif?></td><td><div class="action-buttons"><button class="btn-sm" onclick="copyToClipboard('<?=h($k['access_key'])?>')"><i class="fas fa-copy"></i></button><button class="btn-sm" onclick='openKeyModal(<?=j($k)?>)'><i class="fas fa-edit"></i></button><?php if($k['status']=='active' && !$isExpired):?><form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?=$token?>"><input type="hidden" name="key_action" value="toggle_status"><input type="hidden" name="access_key" value="<?=h($k['access_key'])?>"><input type="hidden" name="status" value="banned"><button class="btn-sm"><i class="fas fa-ban"></i></button></form><?php elseif($k['status']=='banned'):?><form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?=$token?>"><input type="hidden" name="key_action" value="toggle_status"><input type="hidden" name="access_key" value="<?=h($k['access_key'])?>"><input type="hidden" name="status" value="active"><button class="btn-sm"><i class="fas fa-check"></i></button></form><?php endif;?><form method="POST" style="display:inline" onsubmit="return confirm('Удалить ключ?')"><input type="hidden" name="csrf_token" value="<?=$token?>"><input type="hidden" name="key_action" value="delete"><input type="hidden" name="access_key" value="<?=h($k['access_key'])?>"><button class="btn-sm"><i class="fas fa-trash"></i></button></form></div></td></tr><?php endforeach; endif;?></tbody></table></div>

<?php else: // about ?>
<div class="about-section">
<div class="about-header">
<i class="fas fa-info-circle"></i>
<div>
<h3>О системе Nira Panel</h3>
<p>Панель управления для IPTV / радио сервера</p>
</div>
</div>
<div class="about-grid">
<div class="about-card"><div class="about-card-icon"><i class="fas fa-code"></i></div><div class="about-card-info"><span class="about-card-label"></span><span class="about-card-value">V 2.0</span></div></div>
<div class="about-card"><div class="about-card-icon"><i class="fas fa-video"></i></div><div class="about-card-info"><span class="about-card-label"></span><span class="about-card-value"><?=count($pdo->query("SELECT 1 FROM $streams WHERE type='video'")->fetchAll())?></span></div></div>
<div class="about-card"><div class="about-card-icon"><i class="fas fa-music"></i></div><div class="about-card-info"><span class="about-card-label"></span><span class="about-card-value"><?=count($pdo->query("SELECT 1 FROM $streams WHERE type='radio'")->fetchAll())?></span></div></div>
<div class="about-card"><div class="about-card-icon"><i class="fas fa-key"></i></div><div class="about-card-info"><span class="about-card-label"></span><span class="about-card-value"><?=$totalKe?></span></div></div>
<div class="about-card"><div class="about-card-icon"><i class="fab fa-php"></i></div><div class="about-card-info"><span class="about-card-label"></span><span class="about-card-value"><?=phpversion()?></span></div></div>
<div class="about-card"><div class="about-card-icon"><i class="fas fa-database"></i></div><div class="about-card-info"><span class="about-card-label"></span><span class="about-card-value">MySQL</span></div></div>
</div>
<div class="about-footer"><i class="fas fa-copyright"></i> 2026 Nira Panel</div>
</div>
<?php endif;?>
</div>
</div>

<!-- Модалки -->
<div id="channelModal" class="modal"><div class="modal-content"><div class="modal-header"><h3><i class="fas fa-<?=$sec=='radio'?'music':'tv'?>"></i> <span id="channelModalTitle">Добавить</span><span class="auto-badge">ID авто</span></h3><button class="btn btn-secondary" onclick="closeModal('channelModal')">&times;</button></div><form method="POST"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=$token?>"><input type="hidden" name="channel_action" value="save"><input type="hidden" name="id" id="channelId"><div class="form-group"><label>ID</label><input type="text" class="form-control" name="channel_id" id="channel_id" placeholder="Оставьте пустым"></div><div class="form-group"><label>Название *</label><input type="text" class="form-control" name="channel_name" id="channel_name" required></div><div class="form-group"><label>Группа</label><input type="text" class="form-control" name="channel_group" id="channel_group" list="groupsList"><datalist id="groupsList"><?php foreach($groups as $g):?><option value="<?=h($g)?>"><?php endforeach;?></datalist></div><div class="form-group"><label>Иконка URL</label><input type="text" class="form-control" name="icon_url" id="icon_url" placeholder="https://..."></div><div class="form-group"><label>Поток URL *</label><input type="text" class="form-control" name="stream_url" id="stream_url" required></div><div class="form-group"><label>Тип</label><select class="form-control" name="type" id="channel_type"><option value="video">Видео</option><option value="radio">Радио</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('channelModal')">Отмена</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Сохранить</button></div></form></div></div>

<div id="massChannelModal" class="modal"><div class="modal-content"><div class="modal-header"><h3><i class="fas fa-upload"></i> Импорт</h3><button class="btn btn-secondary" onclick="closeModal('massChannelModal')">&times;</button></div><form method="POST" id="massChannelForm"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=$token?>"><input type="hidden" name="channel_action" value="mass_add"><input type="hidden" name="channels_data" id="channels_data"><div class="form-group"><label>M3U файл</label><input type="file" id="m3uFile" class="form-control" accept=".m3u,.m3u8" style="padding:8px"><button type="button" class="btn btn-primary" id="parseM3UBtn" style="margin-top:10px;width:100%"><i class="fas fa-code"></i> Парсить</button></div><hr><div class="form-group"><label>Данные (ID|Название|Класс|Группа|Иконка|URL|тип)</label><textarea class="form-control" id="channels_data_display" rows="6" placeholder="|Первый||Новости|https://...|http://...|video"></textarea><small style="color:#b8956e;display:block;margin-top:6px">ID опционален, тип: video/radio</small></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('massChannelModal')">Отмена</button><button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Импорт</button></div></form></div></div>

<div id="keyModal" class="modal"><div class="modal-content"><div class="modal-header"><h3><i class="fas fa-key"></i> <span id="keyModalTitle">Добавить ключ</span><span class="auto-badge">Авто</span></h3><button class="btn btn-secondary" onclick="closeModal('keyModal')">&times;</button></div><form method="POST"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=$token?>"><input type="hidden" name="key_action" value="save"><input type="hidden" name="edit_key" id="edit_key"><input type="hidden" name="old_key" id="old_key"><div class="form-group"><label>Ключ</label><input type="text" class="form-control" name="access_key" id="access_key" placeholder="Оставьте пустым"></div><div class="form-group"><label>Описание</label><input type="text" class="form-control" name="discription" id="discription" placeholder="Клиент"></div><div class="form-group"><label>Действителен до</label><input type="date" class="form-control" name="expires" id="expires"></div><div class="form-group"><label>Доступные группы</label><div class="checkbox-group" id="groupsCheckbox"><?php foreach($groups as $g):?><label class="checkbox-item"><input type="checkbox" name="groups_access[]" value="<?=h($g)?>"> <?=h($g)?></label><?php endforeach;?><label class="checkbox-item"><input type="checkbox" id="allGroupsCheckbox"> 🌍 Все</label></div><small style="color:#b8956e;display:block;margin-top:6px">Пусто – все группы</small></div><div class="form-group"><label>Статус</label><select class="form-control" name="status" id="key_status"><option value="active">Активен</option><option value="banned">Заблокирован</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('keyModal')">Отмена</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Сохранить</button></div></form></div></div>

<div id="massKeyModal" class="modal"><div class="modal-content"><div class="modal-header"><h3><i class="fas fa-layer-group"></i> Массовое добавление</h3><button class="btn btn-secondary" onclick="closeModal('massKeyModal')">&times;</button></div><form method="POST"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?=$token?>"><input type="hidden" name="key_action" value="mass_add"><div class="form-group"><label>Формат: ключ|статус|описание|дата|группы</label><textarea class="form-control" name="keys_data" rows="8" placeholder="|active|Клиент|2025-12-31|Спорт,Новости"></textarea><small style="color:#b8956e;display:block;margin-top:6px">Ключ опционален, статус active/banned</small></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('massKeyModal')">Отмена</button><button type="submit" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Добавить</button></div></form></div></div>

<!-- === ОВЕРЛЕЙ ДЛЯ ВИДЕО (Plyr) === -->
<div id="videoOverlay" class="video-overlay">
<div class="video-container">
<div class="video-header">
<h3><i class="fas fa-play-circle"></i> <span id="videoTitle">Просмотр</span></h3>
<button class="close-player" onclick="closeVideoPlayer()">&times;</button>
</div>
<video id="player-video" controls playsinline></video>
</div>
</div>

<!-- === ОВЕРЛЕЙ ДЛЯ РАДИО (кастомный аудиоплеер) === -->
<div id="audioOverlay" class="audio-overlay">
<div class="audio-container">
<button class="close-audio" onclick="closeAudioPlayer()">&times;</button>
<div class="audio-title" id="audioTitle">Радио</div>
<div class="audio-controls">
<button id="audioPrev" title="Предыдущий"><i class="fas fa-step-backward"></i></button>
<button id="audioPlayBtn" title="Воспроизвести"><i class="fas fa-play-circle"></i></button>
<button id="audioNext" title="Следующий"><i class="fas fa-step-forward"></i></button>
</div>
<div class="audio-progress" id="audioProgress">
<div class="progress-fill" id="audioProgressFill"></div>
</div>
<div class="audio-time">
<span id="audioCurrentTime">00:00</span>
<span id="audioDuration">00:00</span>
</div>
<div class="audio-volume">
<i class="fas fa-volume-up"></i>
<input type="range" id="audioVolume" min="0" max="1" step="0.01" value="0.8">
</div>
<audio id="audioElement" style="display:none"></audio>
</div>
</div>

<script src="https://cdn.plyr.io/3.7.8/plyr.js"></script>
<script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
<script>
let player = null, hl = null;

// === ВИДЕО ПЛЕЕР (Plyr) ===
function previewVideo(url, title) {
    document.getElementById('videoTitle').innerText = title;
    document.getElementById('videoOverlay').classList.add('active');
    setTimeout(() => {
        let v = document.getElementById('player-video');
        if (player) player.destroy();
        if (hl) hl.destroy();
        v.removeAttribute('src');
        if (url.includes('.m3u8')) {
            if (Hls.isSupported()) {
                hl = new Hls();
                hl.loadSource(url);
                hl.attachMedia(v);
                hl.on(Hls.Events.MANIFEST_PARSED, () => v.play().catch(e => console.log('Автовоспроизведение заблокировано')));
            } else if (v.canPlayType('application/vnd.apple.mpegurl')) {
                v.src = url;
            }
        } else {
            v.src = url;
        }
        player = new Plyr(v, {
            controls: ['play-large','play','progress','current-time','mute','volume','captions','settings','pip','airplay','fullscreen'],
            tooltips: { controls: true, seek: true }
        });
    }, 100);
}
function closeVideoPlayer(){
    if(player) player.destroy();
    if(hl) hl.destroy();
    document.getElementById('videoOverlay').classList.remove('active');
    document.getElementById('player-video').removeAttribute('src');
}

// === АУДИО ПЛЕЕР (кастомный) ===
let audioElement = null;
let audioCurrentUrl = '';
let audioCurrentTitle = '';

function previewAudio(url, title) {
    audioCurrentUrl = url;
    audioCurrentTitle = title;
    document.getElementById('audioTitle').innerText = title;
    document.getElementById('audioOverlay').classList.add('active');
    if (audioElement) {
        audioElement.pause();
        audioElement.src = url;
        audioElement.load();
    } else {
        audioElement = document.getElementById('audioElement');
        audioElement.src = url;
        audioElement.load();
    }
    updateAudioButtons();
    audioElement.addEventListener('timeupdate', updateAudioProgress);
    audioElement.addEventListener('loadedmetadata', updateAudioDuration);
    audioElement.addEventListener('ended', () => { document.getElementById('audioPlayBtn').innerHTML = '<i class="fas fa-play-circle"></i>'; });
    document.getElementById('audioVolume').addEventListener('input', function(e) {
        if (audioElement) audioElement.volume = parseFloat(e.target.value);
    });
        document.getElementById('audioProgress').addEventListener('click', function(e) {
            if (!audioElement) return;
            const rect = this.getBoundingClientRect();
            const percent = (e.clientX - rect.left) / rect.width;
            audioElement.currentTime = percent * audioElement.duration;
        });
        document.getElementById('audioPlayBtn').onclick = toggleAudioPlay;
        document.addEventListener('keydown', audioKeyHandler);
        audioElement.play().catch(() => {});
}

function toggleAudioPlay() {
    if (!audioElement) return;
    if (audioElement.paused) {
        audioElement.play();
        document.getElementById('audioPlayBtn').innerHTML = '<i class="fas fa-pause-circle"></i>';
    } else {
        audioElement.pause();
        document.getElementById('audioPlayBtn').innerHTML = '<i class="fas fa-play-circle"></i>';
    }
}

function updateAudioButtons() {
    if (!audioElement) return;
    const playBtn = document.getElementById('audioPlayBtn');
    if (audioElement.paused) {
        playBtn.innerHTML = '<i class="fas fa-play-circle"></i>';
    } else {
        playBtn.innerHTML = '<i class="fas fa-pause-circle"></i>';
    }
}

function updateAudioProgress() {
    if (!audioElement) return;
    const progress = (audioElement.currentTime / audioElement.duration) * 100;
    document.getElementById('audioProgressFill').style.width = progress + '%';
    document.getElementById('audioCurrentTime').innerText = formatTime(audioElement.currentTime);
}

function updateAudioDuration() {
    if (!audioElement) return;
    document.getElementById('audioDuration').innerText = formatTime(audioElement.duration);
}

function formatTime(seconds) {
    if (isNaN(seconds)) return '00:00';
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
}

function closeAudioPlayer() {
    if (audioElement) {
        audioElement.pause();
        audioElement.src = '';
        audioElement.removeEventListener('timeupdate', updateAudioProgress);
        audioElement.removeEventListener('loadedmetadata', updateAudioDuration);
    }
    document.getElementById('audioOverlay').classList.remove('active');
    document.removeEventListener('keydown', audioKeyHandler);
}

function audioKeyHandler(e) {
    if (e.key === ' ' || e.key === 'Space') {
        e.preventDefault();
        toggleAudioPlay();
    }
    if (e.key === 'Escape') {
        closeAudioPlayer();
    }
}

// === ОБЩАЯ ФУНКЦИЯ ВЫЗОВА ===
function previewChannel(url, title, type = 'video') {
    if (type === 'radio') {
        previewAudio(url, title);
    } else {
        previewVideo(url, title);
    }
}

// === ОСТАЛЬНЫЕ ФУНКЦИИ (модалки, фильтры, импорт и т.д.) ===
function openChannelModal(c){
    let m=document.getElementById('channelModal');
    if(c){
        document.getElementById('channelModalTitle').innerText='Редактировать';
        document.getElementById('channelId').value=c.channel_id;
        document.getElementById('channel_id').value=c.channel_id;
        document.getElementById('channel_name').value=c.channel_name;
        document.getElementById('channel_group').value=c.channel_group||'';
        document.getElementById('icon_url').value=c.icon_url||'';
        document.getElementById('stream_url').value=c.stream_url;
        document.getElementById('channel_type').value=c.type||'video';
    }else{
        document.getElementById('channelModalTitle').innerText='Добавить';
        document.querySelector('#channelModal form')?.reset();
        document.getElementById('channel_type').value='<?=$sec=='radio'?'radio':'video'?>';
    }
    m.classList.add('active');
}
function openMassChannelModal(){document.getElementById('massChannelModal').classList.add('active');}
function openKeyModal(k){
    let m=document.getElementById('keyModal');
    if(k){
        document.getElementById('keyModalTitle').innerText='Редактировать ключ';
        document.getElementById('edit_key').value=k.access_key;
        document.getElementById('old_key').value=k.access_key;
        document.getElementById('access_key').value=k.access_key;
        document.getElementById('discription').value=k.discription||'';
        document.getElementById('expires').value=k.expires||'';
        document.getElementById('key_status').value=k.status;
        let groups=k.groups_access?k.groups_access.split(','):[];
        document.querySelectorAll('input[name="groups_access[]"]').forEach(cb=>{cb.checked=groups.includes(cb.value);});
    }else{
        document.getElementById('keyModalTitle').innerText='Добавить ключ';
        document.querySelector('#keyModal form')?.reset();
        document.getElementById('key_status').value='active';
    }
    m.classList.add('active');
}
function openMassKeyModal(){document.getElementById('massKeyModal').classList.add('active');}
function closeModal(i){document.getElementById(i).classList.remove('active');}

function filterChannels(){
    let s=document.getElementById('searchChannels')?.value.toLowerCase()||'', g=document.getElementById('groupFilter')?.value||'';
    document.querySelectorAll('#channelsTableBody tr').forEach(r=>{
        let n=(r.getAttribute('data-name')||'').toLowerCase(), id=(r.getAttribute('data-id')||'').toLowerCase(), gr=r.getAttribute('data-group')||'';
    let show=!((s&&!n.includes(s)&&!id.includes(s))||(g&&gr!==g));
    r.style.display=show?'':'none';
    });
}
function filterKeys(){
    let s=document.getElementById('searchKeys')?.value.toLowerCase()||'';
    document.querySelectorAll('#keysTableBody tr').forEach(r=>{
        let k=r.querySelector('td:first-child')?.innerText.toLowerCase()||'', d=r.querySelector('td:nth-child(2)')?.innerText.toLowerCase()||'';
    r.style.display=(s&&!k.includes(s)&&!d.includes(s))?'none':'';
    });
}
function copyToClipboard(t){navigator.clipboard.writeText(t).then(()=>alert('✅ Скопировано: '+t)).catch(()=>prompt('Нажмите Ctrl+C:',t));}

function parseM3U(c){
    let l=c.split(/\r?\n/), ch=[];
    for(let i=0;i<l.length;i++){
        let line=l[i].trim();
        if(line.startsWith('#EXTINF')){
            let name='', group='', icon='', ext=line.substring(7);
            let gm=ext.match(/group-title="([^"]*)"/); if(gm) group=gm[1];
            let lm=ext.match(/tvg-logo="([^"]*)"/); if(lm) icon=lm[1];
            let cp=ext.lastIndexOf(','); name=cp!==-1?ext.substring(cp+1).trim():'Unknown';
            i++; while(i<l.length&&l[i].trim()==='') i++;
            if(i<l.length){let url=l[i].trim(); if(url&&!url.startsWith('#')) ch.push({channel_id:'',channel_name:name,channel_class:'',channel_group:group,icon_url:icon,stream_url:url,type:'video'});}
        }
    }
    return ch;
}
document.getElementById('parseM3UBtn')?.addEventListener('click',()=>{
    let f=document.getElementById('m3uFile');
    if(!f.files.length){alert('Выберите M3U файл');return;}
    let r=new FileReader();
    r.onload=e=>{
        let ch=parseM3U(e.target.result);
        if(!ch.length){alert('Не удалось распознать каналы');return;}
        let lines=ch.map(c=>`|${c.channel_name}||${c.channel_group}|${c.icon_url}|${c.stream_url}|video`);
        document.getElementById('channels_data_display').value=lines.join('\n');
        document.getElementById('channels_data').value=lines.join('\n');
        alert(`✅ Распознано: ${ch.length}`);
    };
    r.readAsText(f.files[0],'UTF-8');
});
document.getElementById('massChannelForm')?.addEventListener('submit',function(e){document.getElementById('channels_data').value=document.getElementById('channels_data_display').value;});
document.getElementById('allGroupsCheckbox')?.addEventListener('change',function(e){document.querySelectorAll('input[name="groups_access[]"]').forEach(cb=>cb.checked=e.target.checked);});
document.querySelectorAll('input[name="groups_access[]"]').forEach(cb=>{cb.addEventListener('change',function(){let all=document.getElementById('allGroupsCheckbox'); if(all) all.checked=[...document.querySelectorAll('input[name="groups_access[]"]')].every(c=>c.checked);});});
document.getElementById('searchChannels')?.addEventListener('input',filterChannels);
document.getElementById('groupFilter')?.addEventListener('change',filterChannels);
document.getElementById('searchKeys')?.addEventListener('input',filterKeys);
window.onclick=e=>{if(e.target.classList.contains('modal'))e.target.classList.remove('active');};
document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.querySelectorAll('.modal.active').forEach(m=>m.classList.remove('active')); closeVideoPlayer(); closeAudioPlayer();}});
document.getElementById('sidebarToggle')?.addEventListener('click',()=>document.getElementById('sidebar').classList.toggle('open'));
</script>
</body>
</html>
