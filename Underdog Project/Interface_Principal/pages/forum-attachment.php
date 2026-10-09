<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/forum-upload.php';

$attachmentId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT fa.original_name, fa.stored_name, fa.mime_type, fa.file_size, fa.topic_id, fa.post_id, p.topic_id AS post_topic_id
     FROM forum_attachments fa
     JOIN forum_topics t ON t.id = fa.topic_id
     LEFT JOIN forum_posts p ON p.id = fa.post_id
     WHERE fa.id = ?'
);
$stmt->execute([$attachmentId]);
$attachment = $stmt->fetch();

if (!$attachment) {
    http_response_code(404);
    exit('Anexo não encontrado.');
}

if ($attachment['post_id'] !== null && (int) $attachment['topic_id'] !== (int) $attachment['post_topic_id']) {
    http_response_code(404);
    exit('Anexo inválido.');
}

$allowed = forumAllowedMimeTypes();
if (!isset($allowed[$attachment['mime_type']])) {
    http_response_code(403);
    exit('Tipo de anexo não permitido.');
}

try {
    $file = forumAttachmentPath((string) $attachment['stored_name']);
} catch (Throwable) {
    http_response_code(404);
    exit('Anexo inválido.');
}

if (!is_file($file)) {
    http_response_code(404);
    exit('Arquivo indisponível no servidor.');
}

$detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!is_string($detectedMime) || !isset($allowed[$detectedMime])) {
    http_response_code(403);
    exit('Tipo de anexo não permitido.');
}

$cleanName = forumSafeOriginalName((string) $attachment['original_name']);
$baseName = pathinfo($cleanName, PATHINFO_FILENAME);
$baseName = $baseName !== '' ? $baseName : 'arquivo';
$downloadName = $baseName . '.' . $allowed[$detectedMime];
$asciiSource = function_exists('iconv')
    ? (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $downloadName) ?: '')
    : $downloadName;
$asciiFallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $asciiSource);
$asciiFallback = trim((string) $asciiFallback, '._-');
if ($asciiFallback === '') {
    $asciiFallback = 'arquivo.' . $allowed[$detectedMime];
}

$disposition = in_array($detectedMime, forumInlineMimeTypes(), true) ? 'inline' : 'attachment';

header('Content-Type: ' . $detectedMime);
header('Content-Length: ' . (string) filesize($file));
header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($asciiFallback, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
readfile($file);
exit;
