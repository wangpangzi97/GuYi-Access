<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Security/Protocol.php';
$db=null;
try {
    [$data,$raw]=Protocol::input(); $meta=Protocol::metadata();
    $appKey=Protocol::text($data,'app_key',64); $action=Protocol::text($data,'action',24);
    if (!preg_match('/^[a-f0-9]{64}$/D',$appKey)) Protocol::fail(400,'Invalid app_key');
    $adminActions=['generate','ban','unban','del_card','kick'];
    if (!in_array($action,array_merge(['activate','verify','heartbeat','config','unbind'],$adminActions),true)) Protocol::fail(403,'Action unavailable');
    require_once dirname(__DIR__,2).'/config/config.php';
    require_once dirname(__DIR__).'/Infrastructure/Database/Database.php';
    $db=new Database(); Protocol::rate($db->pdo,'api/'.($_SERVER['REMOTE_ADDR'] ?? ''),60);
    $db->pdo->beginTransaction();
    $app=$db->getAppIdByKey($appKey,true);
    if (!$app || (int)$app['status']!==1) Protocol::fail(403,'Application unavailable');
    if ($action!=='config' && $meta[2] !== (int)$app['key_version']) Protocol::fail(401,'Credential version revoked');
    if (!preg_match('/^[a-f0-9]{64}$/D',$app['api_secret']) || hash_equals($appKey,$app['api_secret'])) Protocol::fail(503,'Rotate application secret before use');
    $update=['version'=>$app['app_version'],'url'=>$app['update_url'],'log'=>$app['notes'],'force'=>(int)$app['force_update']];
    $variables=static function(bool $public) use($db,$app): array { $r=[]; foreach($db->getAppVariables($app['id'],$public) as $v) $r[$v['key_name']]=$v['value']; return $r; };
    if ($action==='config') { $r=['key_version'=>(int)$app['key_version'],'update'=>$update,'variables'=>$variables(true)]; $db->pdo->commit(); Protocol::respond(200,'OK',$r); }
    if (in_array($action,$adminActions,true)) {
        $id=$_SERVER['HTTP_X_CREDENTIAL_ID'] ?? '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id)) Protocol::fail(403,'Management credential required');
        $all=json_decode(getenv('GUYI_MANAGEMENT_CREDENTIALS') ?: '{}',true,8,JSON_THROW_ON_ERROR); $c=$all[$id] ?? null;
        if (!is_array($c) || ($c['app_key'] ?? '')!==$appKey || !in_array($action,$c['actions'] ?? [],true)) Protocol::fail(403,'Operation not authorized');
        if (!is_int($c['expires_at'] ?? null) || $c['expires_at']<=time()) Protocol::fail(403,'Management credential expired');
        if (!is_string($c['secret'] ?? null)) Protocol::fail(503,'Credential unavailable');
        Protocol::authenticate($raw,'management:'.$id,$c['secret'],$meta); Protocol::nonce($db->pdo,'management:'.$id,$meta);
        $result=null; $responseSecret=$app['api_secret'];
        if ($action==='generate') {
            $num=$data['num'] ?? 1; $type=Protocol::text($data,'type',20); $hours=$data['custom_hours'] ?? 0;
            if (!is_int($num) || $num<1 || $num>1000 || (!isset(CARD_TYPES[$type]) && $type!=='custom')) Protocol::fail(400,'Invalid card parameters');
            if ((!is_int($hours) && !is_float($hours)) || $hours<0 || $hours>87600 || ($type==='custom' && $hours<=0)) Protocol::fail(400,'Invalid duration');
            $result=['cards'=>$db->generateCards($num,$type,Protocol::text($data,'pre',20,false),'',16,Protocol::text($data,'note',500,false),$app['id'],(int)($hours*3600))];
        } else {
            $card=Protocol::text($data,'card_code',50); $s=$db->pdo->prepare('SELECT id FROM cards WHERE card_code=? AND app_id=? FOR UPDATE'); $s->execute([$card,$app['id']]); $row=$s->fetch();
            if (!$row) Protocol::fail(404,'Card unavailable');
            if ($action==='ban' || $action==='unban') $db->updateCardStatus($row['id'],$action==='ban'?2:1);
            if ($action==='del_card') $db->deleteCard($row['id']);
            if ($action==='kick') $db->resetDeviceBindingByCardId($row['id']);
            $db->pdo->prepare('DELETE FROM api_sessions WHERE card_code=?')->execute([$card]);
        }
    } else {
        $device=Protocol::text($data,'device_hash',100); $custom=Protocol::text($data,'custom_data',4096,false);
        if ($action==='activate') {
            $card=Protocol::text($data,'card_code',50); Protocol::nonce($db->pdo,'activate:'.$app['id'].':'.hash('sha256',$card),$meta);
        } else {
            $sid=$_SERVER['HTTP_X_SESSION_ID'] ?? ''; if (!preg_match('/^[a-f0-9]{64}$/D',$sid)) Protocol::fail(401,'Session required');
            $s=$db->pdo->prepare('SELECT * FROM api_sessions WHERE session_id=? AND app_id=? AND expires_at>? FOR UPDATE'); $s->execute([$sid,$app['id'],time()]); $session=$s->fetch();
            if (!$session || (int)$session['key_version']!==$meta[2] || !hash_equals($session['device_hash'],$device)) Protocol::fail(401,'Session unavailable');
            $card=$session['card_code']; $responseSecret=bin2hex(Protocol::key($app['api_secret'],'session',$sid));
            Protocol::authenticate($raw,'session:'.$sid,$responseSecret,$meta); Protocol::nonce($db->pdo,'session:'.$sid,$meta);
        }
        $verified=$db->verifyCard($card,$device,$appKey,$custom);
        if (!$verified['success']) Protocol::fail(403,$verified['message']);
        if ($action==='unbind') {
            if (!$db->unbindCardByApi($card,$app['id'],$device)) Protocol::fail(409,'Unbind unavailable or cooling down');
            $result=['unbound'=>true];
        } else {
            $result=['expire_time'=>$verified['expire_time'],'custom_data'=>$verified['custom_data'] ?? '', 'update'=>$update,'variables'=>$variables(false)];
            if ($action==='activate') {
                $sid=bin2hex(random_bytes(32)); $sessionKey=bin2hex(Protocol::key($app['api_secret'],'session',$sid)); $expires=min(time()+900,strtotime($verified['expire_time']));
                $db->pdo->prepare('DELETE FROM api_sessions WHERE card_code=?')->execute([$card]);
                $db->pdo->prepare('INSERT INTO api_sessions (session_id,app_id,card_code,device_hash,key_version,expires_at) VALUES (?,?,?,?,?,?)')->execute([$sid,$app['id'],$card,$device,$meta[2],$expires]);
                $result+=['session_id'=>$sid,'session_key'=>$sessionKey,'key_version'=>$meta[2],'expires_at'=>$expires];
            }
        }
    }
    // Bootstrap delivers only a per-session secret over mandatory HTTPS. Never an app secret.
    if ($action==='activate') { $db->pdo->commit(); Protocol::respond(200,'OK',$result); }
    // Encrypt before commit; a crypto failure cannot commit an API mutation.
    $envelope=Protocol::envelope(['code'=>200,'msg'=>'OK','data'=>$result],$responseSecret,implode(':',['v2',$app['id'],$meta[2],$meta[1],'200']));
    $db->pdo->commit(); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($envelope,JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if ($db && isset($db->pdo) && $db->pdo->inTransaction()) $db->pdo->rollBack();
    if ($e instanceof ApiFailure) Protocol::respond($e->getCode(),$e->getMessage());
    error_log('GuYi API failure: '.get_class($e)); Protocol::respond(503,'Service unavailable');
}
