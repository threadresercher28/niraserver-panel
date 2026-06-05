<?php
$bp='/[<>\'\"\;#\*\\\\\/%]|javascript:/i';
if((!empty($_SERVER['HTTP_HOST'])&&preg_match($bp,$_SERVER['HTTP_HOST']))||(!empty($_SERVER['HTTP_USER_AGENT'])&&preg_match($bp,$_SERVER['HTTP_USER_AGENT']))){http_response_code(400);exit;}
header('X-Content-Type-Options: nosniff');
$l=trim($_GET['l']??'');
if(!$l||!filter_var($l,FILTER_VALIDATE_URL)){http_response_code(400);exit;}
$s=strtolower(parse_url($l,PHP_URL_SCHEME));
if(!in_array($s,['http','https'])){http_response_code(400);exit;}
$h=parse_url($l,PHP_URL_HOST);
if($h===false||$h===null||preg_match($bp,$h)){http_response_code(400);exit;}
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
if(!is_host_safe($h)){http_response_code(403);exit;}
$ctx=stream_context_create(['http'=>['timeout'=>10,'ignore_errors'=>true,'user_agent'=>'M3UProxy/1.0']]);
$pl=@file_get_contents($l,false,$ctx);
if($pl===false||strlen($pl)>2097152){http_response_code(502);exit;} // лимит 2МБ
$purl=parse_url($l);
$base=$purl['scheme'].'://'.$purl['host'].dirname($purl['path']);
$self="http://lkc-usercontent.x10.mx/wp-admin/Nira.php";
$pl=preg_replace_callback('/^(?!#)(.+)$/m',function($m)use($base,$self){
    $line=trim($m[1]);
    if($line==='')return'';
    if(preg_match('/^https?:\/\//i',$line))$full=$line;
    elseif(str_starts_with($line,'/')){
        $pb=parse_url($base);
        $full=$pb['scheme'].'://'.$pb['host'].$line;
    }else{$full=$base.'/'.$line;}
    return $self.'?url='.urlencode($full);
},$pl);
header("Content-Type: application/vnd.apple.mpegurl");
echo $pl;
