<?php
include_once __DIR__ . '/../config/conexao.php';

$type = $_GET['type'] ?? '';
if (!in_array($type, ['avatar', 'banner'], true)) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare("SELECT {$type}_data AS data, {$type}_mime AS mime FROM users WHERE id = ? AND is_active = 1");
$stmt->execute([(int) ($_GET['user'] ?? 0)]);
$image = $stmt->fetch();

if (!$image || $image['data'] === null) {
    http_response_code(404);
    exit;
}

$etag = '"' . md5($image['data']) . '"';
header('ETag: ' . $etag);
header('Cache-Control: no-cache');

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $image['mime']);
header('Content-Length: ' . strlen($image['data']));
echo $image['data'];
