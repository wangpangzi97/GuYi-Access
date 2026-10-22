<?php
declare(strict_types=1);
require_once __DIR__.'/Protocol.php';
function js_attr($value): string { return htmlspecialchars(json_encode($value, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function safe_avatar(array $file): string {
    if (($file['error'] ?? 1)!==UPLOAD_ERR_OK || ($file['size'] ?? 0)>2097152 || !is_uploaded_file($file['tmp_name'] ?? '')) throw new InvalidArgumentException('头像上传无效或超过2MiB');
    $info=getimagesize($file['tmp_name']);
    if (!$info || $info[0]>2048 || $info[1]>2048 || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)) throw new InvalidArgumentException('头像仅支持2048像素内JPEG/PNG/WebP');
    $im=imagecreatefromstring(file_get_contents($file['tmp_name'])); if (!$im) throw new InvalidArgumentException('头像内容无效');
    $dir=dirname(__DIR__,2).'/assets/ResourceStorage'; if (!is_dir($dir) && !mkdir($dir,0755,true)) throw new RuntimeException('上传目录不可写');
    $name='avatar_'.bin2hex(random_bytes(16)).'.png';
    try { if (!imagepng($im,$dir.'/'.$name)) throw new RuntimeException('头像保存失败'); } finally { imagedestroy($im); }
    return '../assets/ResourceStorage/'.$name;
}
function safe_image_url(string $url): string {
    if (strlen($url)>2048 || !filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_SCHEME)!=='https') throw new InvalidArgumentException('头像链接必须是HTTPS URL');
    return $url;
}
