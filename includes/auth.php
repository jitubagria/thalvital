<?php
require_once __DIR__.'/db.php';
function boot_session(): void { if(session_status()===PHP_SESSION_NONE){ session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')]); session_start(); } if(isset($_SESSION['last_seen'])&&time()-$_SESSION['last_seen']>SESSION_TIMEOUT){ session_unset();session_destroy(); } $_SESSION['last_seen']=time(); }
boot_session();
function staff(): ?array { return $_SESSION['staff']??null; }
function require_staff(array $roles=[]): array { if(!staff()){ header('Location: /staff/login.php');exit; } $s=staff(); if($roles&&!in_array($s['role'],$roles,true)){http_response_code(403);exit('Access denied');} return $s; }
function require_staff_center(array $roles=[]): array {
    $s = require_staff($roles);
    $centerId = (int)($s['center_id'] ?? 0);
    if (!$centerId) {
        header('Location: /staff/select-center.php');
        exit;
    }
    $q = db()->prepare('SELECT id,org_id,name FROM blood_centers WHERE id=? AND active=1');
    $q->execute([$centerId]);
    $center = $q->fetch();
    if (!$center || !can_org($s, (int)$center['org_id'])) {
        http_response_code(403);
        exit('Invalid working center');
    }
    $s['_center_name'] = $center['name'];
    return $s;
}
function can_org(array $s,int $org):bool{return $s['role']==='super_admin'||(int)$s['org_id']===$org;}
function can_center(array $s,int $center):bool{return in_array($s['role'],['super_admin','dept_admin'],true)||(int)$s['center_id']===$center;}
// $field/$before/$after are optional — old calls (6 args) keep working and log NULLs for them.
// Use them for sensitive edits (antibody add, blood-group / phenotype anchor change) to capture
// which field changed and its before->after value.
function audit(string $action,string $table,$id=null,$center=null,string $type='staff',$actor=null,$field=null,$before=null,$after=null):void{ $actor=$actor??(staff()['id']??null); db()->prepare('INSERT INTO audit_log(actor_type,actor_id,action,target_table,target_id,field,before_value,after_value,center_id,ip) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$type,$actor,$action,$table,$id,$field,$before,$after,$center,$_SERVER['REMOTE_ADDR']??null]); }
function portal_throttle_keys(string $last4): array { $ip=$_SERVER['REMOTE_ADDR']??'unknown'; return [hash('sha256',AADHAAR_SALT.'|portal-account|'.$last4),hash('sha256',AADHAAR_SALT.'|portal-ip|'.$ip)]; }
function portal_login_is_throttled(string $last4): bool { $keys=portal_throttle_keys($last4); $in=implode(',',array_fill(0,count($keys),'?')); $q=db()->prepare("SELECT 1 FROM portal_login_attempts WHERE throttle_key IN ($in) AND locked_until>NOW() LIMIT 1"); $q->execute($keys); return (bool)$q->fetchColumn(); }
function portal_login_failed(string $last4): void { $pdo=db(); foreach(portal_throttle_keys($last4) as $key){ $q=$pdo->prepare('SELECT failure_count,locked_until FROM portal_login_attempts WHERE throttle_key=?'); $q->execute([$key]); $row=$q->fetch(); $count=(!$row||($row['locked_until']&&strtotime($row['locked_until'])<=time()))?1:(int)$row['failure_count']+1; $delay=$count<3?0:min(900,30*(2**($count-3))); $until=$delay?date('Y-m-d H:i:s',time()+$delay):null; $pdo->prepare('INSERT INTO portal_login_attempts(throttle_key,failure_count,locked_until,last_attempt_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE failure_count=VALUES(failure_count),locked_until=VALUES(locked_until),last_attempt_at=NOW()')->execute([$key,$count,$until]); if($delay){audit('portal_login_throttled','portal_login_attempts',substr($key,0,16),null,'patient',null);} } }
function portal_login_succeeded(string $last4): void { $keys=portal_throttle_keys($last4); $in=implode(',',array_fill(0,count($keys),'?')); db()->prepare("DELETE FROM portal_login_attempts WHERE throttle_key IN ($in)")->execute($keys); }
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function check_csrf():void{if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(419);exit('Invalid request token');}}
