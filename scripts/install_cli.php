<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$path=dirname(__DIR__).'/config/local.php';
if (is_file($path)) { fwrite(STDERR,"Already installed; use scripts/migrate.php.\n"); exit(1); }
$values=[];
foreach (['DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS'] as $key) {
    $v=getenv('GUYI_'.$key); if ($v===false || ($v==='' && $key!=='DB_PASS')) throw new RuntimeException('Missing GUYI_'.$key);
    $values[$key]=$v; define($key,$v);
}
$user=getenv('GUYI_ADMIN_USER') ?: ''; $password=getenv('GUYI_ADMIN_PASSWORD') ?: '';
if (!preg_match('/^[A-Za-z0-9_-]{3,50}$/D',$user) || strlen($password)<12) throw new RuntimeException('Explicit admin username and password >=12 bytes required');
$values['SYS_SECRET']=bin2hex(random_bytes(32)); define('SYS_SECRET',$values['SYS_SECRET']); define('DB_INSTALLED_CHECK',true);
require_once dirname(__DIR__).'/app/Infrastructure/Database/Database.php';
$db=new Database(true);
if ($db->pdo->query('SELECT COUNT(*) FROM admin')->fetchColumn()) throw new RuntimeException('Existing administrator; refusing to overwrite');
$text="<?php\n"; foreach($values as $k=>$v) $text.='define('.var_export($k,true).','.var_export($v,true).");\n";
$fp=fopen($path,'x'); if (!$fp) throw new RuntimeException('Cannot exclusively create configuration');
try {
    chmod($path,0600);
    $db->pdo->beginTransaction();
    $db->pdo->prepare('INSERT INTO admin(id,username,password_hash) VALUES(1,?,?)')->execute([$user,password_hash($password,PASSWORD_DEFAULT)]);
    if (fwrite($fp,$text)!==strlen($text) || !fflush($fp)) throw new RuntimeException('Incomplete config write');
    $db->pdo->commit();
} catch (Throwable $e) {
    if ($db->pdo->inTransaction()) $db->pdo->rollBack();
    fclose($fp); unlink($path); throw $e;
}
fclose($fp);
chmod($path,0600); echo "Installed. Keep config/local.php private; remove GUYI_ADMIN_PASSWORD from the environment.\n";
