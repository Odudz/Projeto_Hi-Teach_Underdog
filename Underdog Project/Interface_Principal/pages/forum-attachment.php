<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/forum-upload.php';

$attachmentId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT original_name, stored_name, mime_type, file_size FROM forum_attachments WHERE id = ?');
$stmt->execute([$attachmentId]);
$attachment = $stmt->fetch();

if (!$attachment) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$allowed = forumAllowedMimeTypes();
if (!isset($allowed[$attachment['mime_type']])) {
    http_response_code(403);
    exit('Tipo de arquivo não permitido.');
}

$file = __DIR__ . '/../uploads/forum/' . basename($attachment['stored_name']);
if (!is_file($file)) {
    http_response_code(404);
    exit('Arquivo não encontrado no servidor.');
}

header('Content-Type: ' . $attachment['mime_type']);
header('Content-Length: ' . (string) filesize($file));
header('Content-Disposition: inline; filename="' . rawurlencode($attachment['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($file);
exit;
