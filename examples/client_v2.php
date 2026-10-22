<?php
declare(strict_types=1);
// Reference helper: HTTPS is mandatory. Never embed a management or application secret in a distributed client.
function v2_key(string $secret,string $purpose): string {
    if (!preg_match('/^[a-f0-9]{64}$/D',$secret)) throw new InvalidArgumentException('Invalid secret');
    return hash_hkdf('sha256',hex2bin($secret),32,'guyi-v2/'.$purpose);
}
function v2_request(string $url,array $data,int $version,?array $session=null,?array $management=null): array {
    if (parse_url($url,PHP_URL_SCHEME)!=='https') throw new InvalidArgumentException('HTTPS URL required');
    $raw=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $nonce=bin2hex(random_bytes(16)); $timestamp=(string)time();
    $headers=['Content-Type: application/json','X-Timestamp: '.$timestamp,'X-Nonce: '.$nonce,'X-Key-Version: '.$version];
    $secret=null;
    if ($session) { $principal='session:'.$session['session_id']; $secret=$session['session_key']; $headers[]='X-Session-Id: '.$session['session_id']; }
    if ($management) { $principal='management:'.$management['id']; $secret=$management['secret']; $headers[]='X-Credential-Id: '.$management['id']; }
    if ($secret!==null) {
        $canonical=implode("\n",['v2','POST',parse_url($url,PHP_URL_PATH),$principal,(string)$version,$timestamp,$nonce,hash('sha256',$raw)]);
        $headers[]='X-Signature: '.hash_hmac('sha256',$canonical,v2_key($secret,'request-sign'));
    }
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$raw,'timeout'=>15,'ignore_errors'=>true,'follow_location'=>0], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $rawResponse=file_get_contents($url,false,$context);
    if ($rawResponse===false) throw new RuntimeException('Transport failed');
    $response=json_decode($rawResponse,true,16,JSON_THROW_ON_ERROR);
    if (!isset($response['encrypted_data'])) {
        if (!in_array($data['action'] ?? '',['activate','config'],true) && ($response['code'] ?? 0)===200) throw new RuntimeException('Refusing plaintext authenticated response');
        return $response;
    }
    $decryptSecret=$session['session_key'] ?? $management['api_secret'] ?? null;
    if (!is_string($decryptSecret)) throw new RuntimeException('Response decryption credential missing');
    $aad=$response['aad'] ?? '';
    // app_id is public, but the caller must obtain/check its own expected ID from trusted configuration.
    $appId=$session['app_id'] ?? $management['app_id'] ?? null;
    if (!is_int($appId) || !hash_equals(implode(':',['v2',$appId,$version,$nonce,'200']),$aad)) throw new RuntimeException('Response is not bound to this request');
    $bytes=base64_decode($response['encrypted_data'],true);
    if ($bytes===false || strlen($bytes)<29) throw new RuntimeException('Invalid ciphertext');
    $plain=openssl_decrypt(substr($bytes,28),'aes-256-gcm',v2_key($decryptSecret,'response-encrypt'),OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16),$aad);
    if ($plain===false) throw new RuntimeException('Response authentication failed');
    return json_decode($plain,true,16,JSON_THROW_ON_ERROR);
}
