<?php

const FORUM_MAX_FILE_SIZE = 10 * 1024 * 1024;
const FORUM_MAX_FILES = 5;

function forumAllowedMimeTypes(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/zip' => 'zip',
    ];
} 

function saveForumAttachments(array $files, int $topicId, ?int $postId, PDO $pdo): array
{
    if (!isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $allowed = forumAllowedMimeTypes();
    $uploadDir = __DIR__ . '/../uploads/forum';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Não foi possível criar a pasta de uploads.');
    }

    $validIndexes = [];
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $validIndexes[] = $i;
        }
    }
    if (count($validIndexes) > FORUM_MAX_FILES) {
        throw new RuntimeException('Você pode enviar no máximo 5 arquivos.');
    }

    $saved = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    foreach ($validIndexes as $i) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Um dos arquivos não pôde ser enviado.');
        }
        $size = (int) ($files['size'][$i] ?? 0);
        if ($size <= 0 || $size > FORUM_MAX_FILE_SIZE) {
            throw new RuntimeException('Cada arquivo deve ter entre 1 byte e 10 MB.');
        }

        $tmp = $files['tmp_name'][$i] ?? '';
        $mime = $finfo->file($tmp);
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Tipo de arquivo não permitido: ' . ($files['name'][$i] ?? 'arquivo'));
        }

        $extension = $allowed[$mime];
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $uploadDir . '/' . $storedName;
        if (!move_uploaded_file($tmp, $destination)) {
            throw new RuntimeException('Não foi possível salvar um dos arquivos enviados.');
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO forum_attachments (topic_id, post_id, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$topicId, $postId, basename($files['name'][$i]), $storedName, $mime, $size]);
        } catch (Throwable $e) {
            @unlink($destination);
            throw $e;
        }

        $saved[] = ['name' => basename($files['name'][$i]), 'id' => (int) $pdo->lastInsertId()];
    }

    return $saved;
}
