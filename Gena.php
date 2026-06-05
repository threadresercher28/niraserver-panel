<?php
require_once __DIR__.'/config.php';
$bp='/[<>\'\"\;#\*\\\\\/%]|javascript:/i';
if((!empty($_SERVER['HTTP_HOST'])&&preg_match($bp,$_SERVER['HTTP_HOST']))||(!empty($_SERVER['HTTP_USER_AGENT'])&&preg_match($bp,$_SERVER['HTTP_USER_AGENT']))){http_response_code(400);exit;}
header('X-Content-Type-Options: nosniff');header('Content-Type: text/plain; charset=utf-8');
if(empty($_GET['key'])){http_response_code(404);exit;}
$key=preg_replace('/[^\w\-\.@]/','',trim($_GET['key']));
if(strlen($key)<1||strlen($key)>128){http_response_code(400);exit('Invalid key');}
if(!defined('database_server')||!defined('database_login')||!defined('database_password')||!defined('database_name')){http_response_code(500);exit;}
$ms=@new mysqli(database_server,database_login,database_password,database_name);
if($ms->connect_errno){http_response_code(500);exit;}
$ms->set_charset('utf8');
$tu=defined('table_users')?table_users:'Service_users';
$st=$ms->prepare("SELECT `status` FROM `{$tu}` WHERE `access_key`=? LIMIT 1");
if(!$st){http_response_code(500);$ms->close();exit;}
$st->bind_param('s',$key);$st->execute();$r=$st->get_result();
if(!$r||$r->num_rows===0){http_response_code(404);$st->close();$ms->close();exit;}
$ust=$r->fetch_assoc()['status'];$r->free();$st->close();
if($ust==='banned'){
    echo "#EXTINF:-1, INFO\nhttps://kirya-coder.yzz.me/zg/ban.png\n";
    if(defined('error_client_banned')&&!empty(error_client_banned))echo htmlspecialchars(error_client_banned,ENT_QUOTES,'UTF-8')."\n";
    $ms->close();exit;
}
$tb=defined('table_stream_source')?table_stream_source:'AnyStream';
if(!in_array($tb,['AnyStream','StreamSource','Channels'])){http_response_code(500);$ms->close();exit;}
$maxCh=defined('MAX_CHANNELS')?(int)MAX_CHANNELS:5000;
$st=$ms->prepare("SELECT * FROM `{$tb}` LIMIT ?");
if(!$st){http_response_code(500);$ms->close();exit;}
$st->bind_param('i',$maxCh);$st->execute();$r=$st->get_result();
if(!$r){http_response_code(500);$ms->close();exit;}

// SSRF-защита: запрет приватных и loopback IP
function is_ip_private($ip){
    if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
        $parts=explode('.',$ip);
        $l=array_map('intval',$parts);
        if($l[0]==10||$l[0]==127||($l[0]==172&&$l[1]>=16&&$l[1]<=31)||($l[0]==192&&$l[1]==168)||$l[0]==169&&$l[1]==254)return true;
        return false;
    }elseif(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)){
        if($ip==='::1'||stripos($ip,'fc00:')===0||stripos($ip,'fe80:')===0)return true;
        return false;
    }
    return false;
}

function is_host_safe($host){
    $ips=[];$dns=@dns_get_record($host,DNS_A|DNS_AAAA);
    if($dns){
        foreach($dns as$rr){if(!empty($rr['ip']))$ips[]=$rr['ip'];if(!empty($rr['ipv6']))$ips[]=$rr['ipv6'];}
    }else{
        if(filter_var($host,FILTER_VALIDATE_IP))$ips[]=$host;
    }
    foreach($ips as$ip)if(is_ip_private($ip))return false;
    return true;
}

function is_url_safe($url){
    if(empty($url))return false;
    $url=trim($url);
    if(!filter_var($url,FILTER_VALIDATE_URL))return false;
    $s=strtolower(parse_url($url,PHP_URL_SCHEME));
    if(!in_array($s,['http','https']))return false;
    $h=parse_url($url,PHP_URL_HOST);
    if($h===false||$h===null)return false;
    if(preg_match('/[<>\'\"\;#\*\\\\\/%]|javascript:/i',$h))return false;
    if(!is_host_safe($h))return false;
    return true;
}

function detect_stream_url(array $row){
    $cand=['stream_url','url','src','link','play','stream','m3u8','rtmp'];
    foreach($cand as$c)if(isset($row[$c])&&trim($row[$c])!=='')return trim($row[$c]);
    foreach($row as$v){if(!is_string($v))continue;$s=trim($v);if($s==='')continue;if(preg_match('#^https?://#i',$s)||preg_match('#^[\w\-\./]+/(?:.*\.(?:m3u8|ts|mp4|flv))#i',$s))return $s;}
    return null;
}

function escAttr($v){
    if($v===null||$v==='')return'';
    $v=str_replace(['"',"\n","\r"],['&quot;','',''],$v);
    return htmlspecialchars($v,ENT_QUOTES,'UTF-8');
}

echo "#EXTM3U\n";
if(isset($epg_Master)&&!empty($epg_Master)){
    $epgs=is_array($epg_Master)?$epg_Master:[$epg_Master];
    foreach($epgs as$eu){$se=filter_var($eu,FILTER_SANITIZE_URL);if($se)echo"#EXTM3U url-tvg=\"{$se}\"\n";}
}
$cnt=0;
while($row=$r->fetch_assoc()){
    $url=detect_stream_url($row);
    if(!$url||!is_url_safe($url))continue;
    $title=escAttr($row['channel_name']??'Channel');
    $tid=escAttr($row['channel_id']??'');
    $tname=escAttr($row['channel_class']??'');
    $tlogo='';
    if(!empty($row['icon_url'])){
        $rl=trim($row['icon_url']);
        if(is_url_safe($rl))$tlogo=filter_var($rl,FILTER_SANITIZE_URL);
    }
    $grp=escAttr($row['channel_group']??'');
    echo "#EXTINF:-1 tvg-id=\"{$tid}\" tvg-name=\"{$tname}\" tvg-logo=\"{$tlogo}\" group-title=\"{$grp}\",{$title}\n";
    echo "{$url}\n";
    $cnt++;
}
$r->free();$ms->close();
exit;
