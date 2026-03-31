<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '256M');
set_time_limit(30);
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
if (!function_exists('curl_init')) { http_response_code(500); exit("cURL not available"); }
$blacklist = ['malware-domain.com','bad-site.org','evil.local','1.2.3.4','5.6.7.8','192.168.1.0/24','10.0.0.0/8'];
$allowed_ext = ["mpd","ts","m3u","m3u8","png","mpeg","mkv","mp4","xmpd"];
function sanitize_sql($i){return is_array($i)?array_map('sanitize_sql',$i):addslashes(preg_replace('/[\'\"\;\*\-\-\#\%\\\\]/','',$i));}
function sanitize_xss($i){if(is_array($i))return array_map('sanitize_xss',$i);$i=preg_replace('/<script\b[^>]*>(.*?)<\/script>/is','',$i);$i=preg_replace('/javascript:/i','blocked:',$i);$i=preg_replace('/on\w+\s*=/i','data-blocked=',$i);return htmlspecialchars(strip_tags($i,'<p><br><strong><em><u><h1><h2><h3><h4><h5><h6>'),ENT_QUOTES|ENT_HTML5,'UTF-8');}
function sanitize_crlf($i){if(is_array($i))return array_map('sanitize_crlf',$i);return preg_replace('/[\r\n]|%0[ad]/i','',$i);}
function sanitize_path($i){return preg_replace('/\.\.([\/\\\\])?/','',$i);}
if(empty($_GET['url'])){http_response_code(400);exit("Missing URL");}
$url=sanitize_crlf(sanitize_xss($_GET['url']));
if(!filter_var($url,FILTER_VALIDATE_URL)){http_response_code(400);exit("Invalid URL");}
$scheme=strtolower(parse_url($url,PHP_URL_SCHEME));
if(!in_array($scheme,['http','https'])){http_response_code(403);exit("Only HTTP/HTTPS allowed");}
$path=sanitize_path(parse_url($url,PHP_URL_PATH));
$ext=sanitize_sql(strtolower(pathinfo($path,PATHINFO_EXTENSION)));
if($ext && !in_array($ext,$allowed_ext)){http_response_code(403);exit("Extension not allowed");}
function ip_in_range_v4($ip,$c){list($s,$m)=explode('/',$c,2);$i=ip2long($ip);$n=ip2long($s);if($i===false||$n===false)return false;$m=(int)$m;if($m<0||$m>32)return false;if($m==0)return true;$mask=-1<<(32-$m);return($i&$mask)==($n&$mask);}
function ip_in_range_v6($ip,$c){list($s,$m)=explode('/',$c,2);$i=inet_pton($ip);$n=inet_pton($s);if($i===false||$n===false)return false;$m=(int)$m;if($m<0||$m>128)return false;$b=intdiv($m,8);$bits=$m%8;for($j=0;$j<$b;$j++){if($i[$j]!==$n[$j])return false;}if($bits>0){$mb=chr((0xFF<<(8-$bits))&0xFF);if(($i[$b]&$mb)!==($n[$b]&$mb))return false;}return true;}
function is_private_ip($ip){$r=['10.0.0.0/8','172.16.0.0/12','192.168.0.0/16','127.0.0.0/8','169.254.0.0/16','::1/128','fc00::/7','fe80::/10'];foreach($r as$range){if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){if(ip_in_range_v4($ip,$range))return true;}elseif(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)){if(ip_in_range_v6($ip,$range))return true;}}return false;}
function is_blacklisted($host,$ip=null){global$blacklist;if($ip&&filter_var($ip,FILTER_VALIDATE_IP)){foreach($blacklist as$item){if(strpos($item,'/')!==false){if(ip_in_range_v4($ip,$item)||ip_in_range_v6($ip,$item))return true;}elseif($ip===$item)return true;}}if($host){foreach($blacklist as$item){if(strpos($item,'/')===false&&stripos($host,$item)!==false)return true;}}return false;}
function resolve_host($host){$ips=[];$r=dns_get_record($host,DNS_A+DNS_AAAA);if($r!==false){foreach($r as$rr){if(!empty($rr['ip']))$ips[]=$rr['ip'];if(!empty($rr['ipv6']))$ips[]=$rr['ipv6'];}}return$ips;}
$host=sanitize_crlf(parse_url($url,PHP_URL_HOST));
$ips=resolve_host($host);
foreach($ips as$ip){if(is_private_ip($ip)||is_blacklisted($host,$ip)){http_response_code(403);exit("Blocked host");}}
$allowed_headers=['accept','accept-language','cache-control','pragma','user-agent','referer','origin'];
$headers=[];
foreach($_SERVER as$k=>$v){if(strpos($k,'HTTP_')===0){$n=strtolower(str_replace('_','-',substr($k,5)));$v=sanitize_crlf(sanitize_xss(sanitize_sql($v)));in_array($n,$allowed_headers)&&$headers[]="$n: $v";}}
$headers[]='User-Agent: Nira/1.0';
$ch=curl_init($url);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_ENCODING=>'',CURLOPT_HTTPHEADER=>$headers]);
if($_SERVER['REQUEST_METHOD']==='POST'){$data=sanitize_crlf(sanitize_xss(file_get_contents('php://input')));curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,$data);}
curl_setopt($ch,CURLOPT_WRITEFUNCTION,function($ch,$d){$info=curl_getinfo($ch);if(stripos($info['content_type']??'','html')!==false)$d=sanitize_xss($d);echo$d;flush();return strlen($d);});
curl_setopt($ch,CURLOPT_HEADERFUNCTION,function($ch,$h){if(stripos($h,'Transfer-Encoding')===0||stripos($h,'Content-Length')===0||stripos($h,'Connection')===0)return strlen($h);header(trim(sanitize_crlf($h)));return strlen($h);});
if(!curl_exec($ch)){http_response_code(502);echo"Error: ".curl_error($ch);}
$ip=curl_getinfo($ch,CURLINFO_PRIMARY_IP);
($ip&&(is_private_ip($ip)||is_blacklisted(null,$ip)))&&(http_response_code(403),exit("Blocked after connect"));
curl_close($ch);
