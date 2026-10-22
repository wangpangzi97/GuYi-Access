<?php
require_once dirname(__DIR__,2).'/config/config.php';
require_once dirname(__DIR__,2).'/app/Infrastructure/Database/Database.php';
if (($_SERVER['HTTPS'] ?? '')!=='on' && !(getenv('GUYI_ALLOW_LOCAL_HTTP')==='1' && in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true))) { http_response_code(426); exit('HTTPS required'); }
session_start();
$db=new Database();
setcookie('admin_trust','',['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$csrf_token=$_SESSION['csrf_token']; $is_trusted=false; $login_error=null;
if (!empty($_SESSION['admin_logged_in']) && hash_equals(hash('sha256',(string)$db->getAdminHash()),$_SESSION['admin_fingerprint'] ?? '') && time()-($_SESSION['admin_seen'] ?? 0)<=3600) { header('Location: ../admin/cards.php'); exit; }
$sysConf=$db->getSystemSettings();
$conf_site_title=$sysConf['site_title'] ?? 'GuYi Access'; $conf_favicon=$sysConf['favicon'] ?? ''; $conf_avatar=$sysConf['admin_avatar'] ?? '';
$conf_bg_pc=''; $conf_bg_mobile=''; $conf_bg_blur='1';
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
    try {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($csrf_token,$_POST['csrf_token'])) Protocol::fail(403,'页面已过期，请刷新');
        $username=Protocol::text($_POST,'username',50); $password=Protocol::text($_POST,'password',1024);
        Protocol::rate($db->pdo,'login-ip/'.($_SERVER['REMOTE_ADDR'] ?? ''),30,900);
        Protocol::rate($db->pdo,'login-account/'.hash('sha256',$username),10,900);
        $hash=(string)$db->getAdminHash();
        if (!hash_equals((string)$db->getAdminUsername(),$username) || !$hash || !password_verify($password,$hash)) Protocol::fail(401,'账号或密码无效');
        session_regenerate_id(true); $_SESSION['admin_logged_in']=true; $_SESSION['admin_seen']=time(); $_SESSION['admin_fingerprint']=hash('sha256',$hash); $_SESSION['csrf_token']=bin2hex(random_bytes(32));
        header('Location: ../admin/cards.php'); exit;
    } catch (Throwable $e) { http_response_code($e instanceof ApiFailure?$e->getCode():503); $login_error=$e instanceof ApiFailure?$e->getMessage():'服务暂不可用'; }
}
require_once dirname(__DIR__,2).'/resources/views/auth/login.php';
