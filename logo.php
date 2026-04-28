<?php
require_once __DIR__ . '/config.php';

$ext = getDb()->query("SELECT value FROM settings WHERE key='logo_ext'")->fetchColumn();
if (!$ext) { http_response_code(404); exit; }

$file = __DIR__ . '/data/logo.' . $ext;
if (!is_file($file)) { http_response_code(404); exit; }

$mimes = [
    'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png'  => 'image/png',  'gif'  => 'image/gif',
    'webp' => 'image/webp', 'svg'  => 'image/svg+xml',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

header("Content-Type: $mime");
header("Cache-Control: public, max-age=86400");
header("Content-Length: " . filesize($file));
readfile($file);
