<?php
// Only explicit environment variables or ignored local configuration may hold secrets.
require_once dirname(__DIR__).'/app/Security/Admin.php';
if (!defined('BASE_PATH')) define('BASE_PATH',dirname(__DIR__));
$local=getenv('GUYI_CONFIG_FILE') ?: __DIR__.'/local.php';
if (is_file($local)) require_once $local;
foreach (['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS','SYS_SECRET'] as $name) {
    $v=getenv('GUYI_'.$name); if (!defined($name) && $v!==false) define($name,$v);
}
$ready=true; foreach(['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS','SYS_SECRET'] as $name) if (!defined($name)) $ready=false;
if (!defined('DB_INSTALLED_CHECK') && $ready) define('DB_INSTALLED_CHECK',true);
if (!$ready && PHP_SAPI!=='cli') { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); exit('Not configured. Run the CLI installer.'); }
if (defined('SYS_SECRET') && !preg_match('/^[a-f0-9]{64}$/D',SYS_SECRET)) { http_response_code(503); exit('Rotate SYS_SECRET to 32 random bytes encoded as lowercase hex.'); }
date_default_timezone_set('UTC');
ini_set('display_errors','0'); ini_set('log_errors','1');
ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1'); ini_set('session.gc_maxlifetime','3600');
session_set_cookie_params(['lifetime'=>3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);

if (!defined('CARD_TYPES')) {
    define('CARD_TYPES', [
        'hour' => ['name' => '小时卡', 'duration' => 3600],
        'day' => ['name' => '天卡', 'duration' => 86400],
        'week' => ['name' => '周卡', 'duration' => 604800],
        'month' => ['name' => '月卡', 'duration' => 2592000],
        'season' => ['name' => '季卡', 'duration' => 7776000],
        'year' => ['name' => '年卡', 'duration' => 31536000],
    ]);
}
