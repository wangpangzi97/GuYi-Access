<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/app/Security/Protocol.php';
$r=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
$b=base64_decode($r['envelope']['encrypted_data'],true);
$plain=openssl_decrypt(substr($b,28),'aes-256-gcm',Protocol::key($r['secret'],'response-encrypt'),OPENSSL_RAW_DATA,substr($b,0,12),substr($b,12,16),$r['envelope']['aad']);
if ($plain===false) { echo 'null'; exit(2); } echo $plain;
