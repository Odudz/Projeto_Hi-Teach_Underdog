<?php

const FORUM_MAX_FILE_SIZE = 10 * 1024 * 1024;
const FORUM_MAX_FILES = 5;
const FORUM_UPLOAD_DIR = __DIR__ . '/../uploads/forum';

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

function forumInlineMimeTypes(): array
{
    return [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
    ];
}

function forumUploadErrorMessage(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Um dos arquivos excede o limite permitido pelo servidor.',
        UPLOAD_ERR_PARTIAL => 'Um dos arquivos foi enviado parcialmente. Tente novamente.',
        UPLOAD_ERR_NO_TMP_DIR => 'Falha no servidor ao receber o arquivo temporário.',
        UPLOAD_ERR_CANT_WRITE => 'Falha no servidor ao gravar o arquivo enviado.',
        UPLOAD_ERR_EXTENSION => 'Upload bloqueado por uma extensão de segurança do servidor.',
        default => 'Um dos arquivos não pôde ser enviado.',
    };
}

function forumGetPhpIniBytes(string $key): int
{
    $value = trim((string) ini_get($key));
    if ($value === '') {
        return 0;
    }

    $unit = strtolower($value[strlen($value) - 1]);
    $bytes = (int) $value;
    return match ($unit) {
        'g' => $bytes * 1024 * 1024 * 1024,
        'm' => $bytes * 1024 * 1024,
        'k' => $bytes * 1024,
        default => (int) $value,
    };
}

function forumExceedsPostMaxSize(): bool
{
    $postMaxSize = forumGetPhpIniBytes('post_max_size');
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    return $postMaxSize > 0 && $contentLength > $postMaxSize;
}

function forumUploadDir(): string
{
    if (!is_dir(FORUM_UPLOAD_DIR) && !mkdir(FORUM_UPLOAD_DIR, 0755, true) && !is_dir(FORUM_UPLOAD_DIR)) {
        throw new RuntimeException('Não foi possível preparar a pasta de uploads do fórum.');
    }

    return FORUM_UPLOAD_DIR;
}

function forumSafeOriginalName(string $name): string
{
    $name = trim(str_replace(["\0", '/', '\\'], ' ', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    $name = trim($name);
    return $name !== '' ? mb_substr($name, 0, 180) : 'arquivo';
}

function forumIsValidStoredName(string $storedName): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,8}$/', $storedName);
}

function forumAttachmentPath(string $storedName): string
{
    if (!forumIsValidStoredName($storedName)) {
        throw new RuntimeException('Anexo inválido.');
    }
    return forumUploadDir() . '/' . $storedName;
}

function forumDeletePhysicalFile(string $storedName): bool
{
    $path = forumAttachmentPath($storedName);
    if (!is_file($path)) {
        return true;
    }
    return @unlink($path);
}

function forumCleanupFiles(array $storedNames): void
{
    foreach ($storedNames as $storedName) {
        try {
            if (!forumDeletePhysicalFile((string) $storedName)) {
                error_log('forum-upload.php:cleanup_failed: ' . (string) $storedName);
            }
        } catch (Throwable $e) {
            error_log('forum-upload.php:cleanup_invalid_name: ' . $e->getMessage());
        }
    }
}

function forumPostBelongsToTopic(PDO $pdo, int $topicId, int $postId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM forum_posts WHERE id = ? AND topic_id = ?');
    $stmt->execute([$postId, $topicId]);
    return (bool) $stmt->fetchColumn();
}

function saveForumAttachments(array $files, int $topicId, ?int $postId, PDO $pdo): array
{
    if (!isset($files['name'], $files['type'], $files['tmp_name'], $files['error'], $files['size'])) {
        throw new RuntimeException('Estrutura de upload inválida.');
    }
    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
        if (!is_array($files[$key])) {
            throw new RuntimeException('Estrutura de upload inválida.');
        }
    }

    if (count($files['name']) !== count($files['error'])
        || count($files['name']) !== count($files['tmp_name'])
        || count($files['name']) !== count($files['size'])) {
        throw new RuntimeException('Estrutura de upload inválida.');
    }

    if ($postId !== null && !forumPostBelongsToTopic($pdo, $topicId, $postId)) {
        throw new RuntimeException('O post informado não pertence a este tópico.');
    }

    if (count($files['name']) === 0) {
        return [];
    }

    $allowed = forumAllowedMimeTypes();
    $uploadDir = forumUploadDir();

    $validIndexes = [];
    foreach ($files['name'] as $i => $name) {
        $errorCode = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($errorCode !== UPLOAD_ERR_NO_FILE) {
            $validIndexes[] = $i;
        }
    }
    if (count($validIndexes) > FORUM_MAX_FILES) {
        throw new RuntimeException('Você pode enviar no máximo 5 arquivos.');
    }

    $saved = [];
    $storedNames = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    try {
        foreach ($validIndexes as $i) {
            $errorCode = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($errorCode !== UPLOAD_ERR_OK) {
                throw new RuntimeException(forumUploadErrorMessage($errorCode));
            }

            $size = (int) ($files['size'][$i] ?? 0);
            if ($size <= 0 || $size > FORUM_MAX_FILE_SIZE) {
                throw new RuntimeException('Cada arquivo deve ter entre 1 byte e 10 MB.');
            }

            $tmp = (string) ($files['tmp_name'][$i] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp) || !is_file($tmp)) {
                throw new RuntimeException('Arquivo temporário inválido.');
            }
            $realSize = @filesize($tmp);
            if (!is_int($realSize) || $realSize <= 0 || $realSize > FORUM_MAX_FILE_SIZE) {
                throw new RuntimeException('Cada arquivo deve ter entre 1 byte e 10 MB.');
            }

            $mime = $finfo->file($tmp);
            if (!is_string($mime) || !isset($allowed[$mime])) {
                throw new RuntimeException('Um dos arquivos possui um tipo não permitido.');
            }

            $extension = $allowed[$mime];
            $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
            $destination = $uploadDir . '/' . $storedName;
            if (!move_uploaded_file($tmp, $destination)) {
                throw new RuntimeException('Não foi possível salvar um dos arquivos enviados.');
            }

            $storedNames[] = $storedName;
            $originalName = forumSafeOriginalName((string) ($files['name'][$i] ?? 'arquivo'));

            $stmt = $pdo->prepare(
                'INSERT INTO forum_attachments (topic_id, post_id, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$topicId, $postId, $originalName, $storedName, $mime, $realSize]);
            $saved[] = ['name' => $originalName, 'id' => (int) $pdo->lastInsertId()];
        }
    } catch (Throwable $e) {
        forumCleanupFiles($storedNames);
        throw $e;
    }

    return $saved;
}
