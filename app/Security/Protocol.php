<?php
declare(strict_types=1);
final class ApiFailure extends RuntimeException {}
final class Protocol {
    public static function fail(int $status, string $message): void { throw new ApiFailure($message, $status); }
    public static function input(): array {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); self::fail(405, 'POST JSON required'); }
        if (!empty($_GET)) self::fail(400, 'Query parameters are not accepted');
        $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true);
        if (($_SERVER['HTTPS'] ?? '') !== 'on' && !($local && getenv('GUYI_ALLOW_LOCAL_HTTP') === '1')) self::fail(426, 'HTTPS required');
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') self::fail(415, 'application/json required');
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if ($raw === false || strlen($raw) > 65536) self::fail(413, 'Request too large');
        try { $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); } catch (JsonException $e) { self::fail(400, 'Invalid JSON'); }
        if (!is_array($data) || array_is_list($data)) self::fail(400, 'JSON object required');
        return [$data,$raw];
    }
    public static function text(array $data, string $name, int $max, bool $required=true): string {
        $v=$data[$name] ?? '';
        if (!is_string($v) || strlen($v)>$max || ($required && $v==='') || str_contains($v,"\0")) self::fail(400,'Invalid '.$name);
        return $v;
    }
    public static function metadata(): array {
        $t=$_SERVER['HTTP_X_TIMESTAMP'] ?? ''; $n=$_SERVER['HTTP_X_NONCE'] ?? ''; $v=$_SERVER['HTTP_X_KEY_VERSION'] ?? '';
        if (!preg_match('/^[0-9]{10}$/D',$t) || abs(time()-(int)$t)>300) self::fail(401,'Expired or invalid timestamp');
        if (!preg_match('/^[a-f0-9]{32}$/D',$n)) self::fail(400,'Invalid nonce');
        if (!preg_match('/^[1-9][0-9]{0,8}$/D',$v)) self::fail(400,'Invalid key version');
        return [(int)$t,$n,(int)$v];
    }
    public static function key(string $secret,string $purpose,string $salt=''): string {
        if (!preg_match('/^[a-f0-9]{64}$/D',$secret)) self::fail(503,'Credential unavailable');
        return hash_hkdf('sha256',hex2bin($secret),32,'guyi-v2/'.$purpose,$salt);
    }
    public static function authenticate(string $raw,string $principal,string $secret,array $m): void {
        $canonical=implode("\n",['v2','POST',parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH),$principal,(string)$m[2],(string)$m[0],$m[1],hash('sha256',$raw)]);
        $s=$_SERVER['HTTP_X_SIGNATURE'] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/D',$s) || !hash_equals(hash_hmac('sha256',$canonical,self::key($secret,'request-sign')),$s)) self::fail(401,'Invalid signature');
    }
    public static function nonce(PDO $pdo,string $principal,array $m): void {
        try { $pdo->prepare('INSERT INTO api_nonces (principal,nonce,expires_at) VALUES (?,?,?)')->execute([hash('sha256',$principal.'/'.$m[2]),$m[1],time()+600]); }
        catch (PDOException $e) { if (($e->errorInfo[1] ?? 0)===1062) self::fail(409,'Request already used'); throw $e; }
    }
    public static function rate(PDO $pdo,string $principal,int $limit,int $window=60): void {
        $bucket=hash('sha256',$principal.'/'.intdiv(time(),$window)); $own=!$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO api_rate_limits (bucket,hits,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=hits+1')->execute([$bucket,time()+$window*2]);
            $s=$pdo->prepare('SELECT hits FROM api_rate_limits WHERE bucket=?'); $s->execute([$bucket]); $hits=(int)$s->fetchColumn();
            if ($own) $pdo->commit();
        } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        if ($hits>$limit) { header('Retry-After: '.$window); self::fail(429,'Too many requests'); }
        if (random_int(1,100)===1) foreach (['api_rate_limits','api_nonces','api_sessions'] as $table) $pdo->prepare("DELETE FROM $table WHERE expires_at < ? LIMIT 1000")->execute([time()]);
    }
    public static function envelope(array $body,string $secret,string $aad): array {
        if (!function_exists('openssl_encrypt')) self::fail(503,'Encryption unavailable');
        $iv=random_bytes(12); $tag='';
        $c=openssl_encrypt(json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'aes-256-gcm',self::key($secret,'response-encrypt'),OPENSSL_RAW_DATA,$iv,$tag,$aad,16);
        if ($c===false || strlen($tag)!==16) self::fail(503,'Encryption unavailable');
        return ['protocol'=>'v2','aad'=>$aad,'encrypted_data'=>base64_encode($iv.$tag.$c)];
    }
    public static function respond(int $status,string $msg,$data=null): void {
        http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
        echo json_encode(['code'=>$status,'msg'=>$msg,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); exit;
    }
}
