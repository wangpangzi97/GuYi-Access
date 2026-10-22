<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('GUYI_TESTING')!=='1' || !str_ends_with(getenv('GUYI_DB_NAME') ?: '', '_test')) { http_response_code(404); exit(1); }
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/app/Infrastructure/Database/Database.php';
$db=new Database(true); $mode=$argv[1] ?? '';
if ($mode==='seed') {
    foreach(['api_sessions','api_nonces','api_rate_limits','active_devices','usage_logs','cards','app_variables','applications','blacklists','system_settings','admin'] as $t) $db->pdo->exec("DELETE FROM $t");
    new Database(true);
    if ($db->pdo->query('SELECT COUNT(*) FROM admin')->fetchColumn()) throw new RuntimeException('Default administrator was recreated');
    $q=$db->pdo->prepare('INSERT INTO applications(id,app_name,app_key,api_secret,key_version,status) VALUES(?,?,?,?,1,?)');
    $q->execute([1,'Fixture',str_repeat('a',64),str_repeat('c',64),1]); $q->execute([2,'Other',str_repeat('b',64),str_repeat('d',64),1]); $q->execute([3,'Disabled',str_repeat('e',64),str_repeat('f',64),0]);
    $q=$db->pdo->prepare('INSERT INTO cards(card_code,card_type,app_id,duration) VALUES(?,?,?,?)');
    foreach(['TEST-YEAR-CARD','RACE-CARD'] as $code) $q->execute([$code,'year',1,31536000]); $q->execute(['OTHER-CARD','year',2,31536000]);
    $db->pdo->exec("INSERT INTO app_variables(app_id,key_name,value,is_public) VALUES(1,'public','notice',1),(1,'private','licensed-only',0)");
    $db->pdo->prepare('INSERT INTO admin(id,username,password_hash) VALUES(1,?,?)')->execute(['fixture-admin',password_hash('fixture-password-only-123',PASSWORD_DEFAULT)]);
    $db->saveSystemSettings(['site_title'=>'KEEP-TITLE','favicon'=>'https://example.invalid/icon.png','admin_avatar'=>'https://example.invalid/avatar.png']);
    echo "seeded\n";
} elseif ($mode==='rotate') {
    $db->resetAppSecret(1); echo json_encode($db->getAppIdByKey(str_repeat('a',64)));
} elseif ($mode==='card') {
    $s=$db->pdo->prepare('SELECT expire_time,device_hash,last_unbind_at FROM cards WHERE card_code=?'); $s->execute([$argv[2] ?? 'TEST-YEAR-CARD']); echo json_encode($s->fetch());
} elseif ($mode==='race') {
    usleep(100000); echo json_encode($db->verifyCard('RACE-CARD',$argv[2],str_repeat('a',64)));
} elseif ($mode==='settings') {
    echo json_encode($db->getSystemSettings());
} elseif ($mode==='change-password') {
    $db->updateAdminPassword('changed-fixture-only-123'); echo "changed\n";
} elseif ($mode==='count') {
    echo $db->pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn();
} elseif ($mode==='restore-test') {
    $tables=['applications','app_variables','cards','active_devices','usage_logs','blacklists','system_settings','admin']; $data=[];
    foreach($tables as $t) $data[$t]=$db->pdo->query("SELECT * FROM $t")->fetchAll();
    $before=hash('sha256',json_encode($data));
    $bad=$data; $bad['cards'][]=$data['cards'][0];
    try { $db->importAllData($bad); throw new RuntimeException('Expected constraint failure'); } catch(PDOException $expected) {}
    $after=[]; foreach($tables as $t) $after[$t]=$db->pdo->query("SELECT * FROM $t")->fetchAll();
    if (!hash_equals($before,hash('sha256',json_encode($after)))) throw new RuntimeException('Failed restore changed original data');
    $bad=$data; $bad['admin'][0]['unknown_column']=1;
    try { $db->importAllData($bad); throw new RuntimeException('Expected schema rejection'); } catch(InvalidArgumentException $expected) {}
    $db->importAllData($data);
    echo "rollback-safe restore and schema rejection passed\n";
} else { throw new InvalidArgumentException('Unknown fixture command'); }
