<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/jaas/jaas_jwt.php';
require_once __DIR__ . '/jaas/jaas_player.php';

const LIVE_TITLE_MAX = 80;      
const LIVE_SUBJECT_MAX = 60;    

const LIVE_STALE_SECONDS = 180;

const LIVE_LOGIN_PATH = 'auth/login.php';
const LIVE_REGISTER_PATH = 'auth/register.php';

function liveCurrentUser(): ?array
{
    global $user;
    return $user ?: null;
}

function liveIsTeacher(?array $user): bool
{
    return $user !== null && ($user['role'] ?? '') === 'teacher';
}

function liveCsrfToken(): string
{
    if (empty($_SESSION['live_csrf'])) {
        $_SESSION['live_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['live_csrf'];
}

function liveCsrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(liveCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}
function liveCsrfCheck(): bool
{
    $sent = (string) ($_POST['csrf'] ?? '');
    return $sent !== '' && hash_equals($_SESSION['live_csrf'] ?? '', $sent);
}

function liveValidateInput(string $title, string $subject): array
{
    $errors = [];
    $titleLength = mb_strlen($title);
    if ($titleLength < 3 || $titleLength > LIVE_TITLE_MAX) {
        $errors[] = 'O nome da live deve ter de 3 a ' . LIVE_TITLE_MAX . ' caracteres.';
    }
    $subjectLength = mb_strlen($subject);
    if ($subjectLength < 2 || $subjectLength > LIVE_SUBJECT_MAX) {
        $errors[] = 'Informe a matéria (de 2 a ' . LIVE_SUBJECT_MAX . ' caracteres).';
    }
    return $errors;
}

function liveOnlineSql(string $alias = 'l'): string
{
    return "($alias.status = 'live' AND $alias.last_seen_at >= NOW() - INTERVAL " . (int) LIVE_STALE_SECONDS . ' SECOND)';
}

function liveCreate(PDO $pdo, int $teacherId, string $title, string $subject): int
{
    
    $pdo->prepare("UPDATE live_classes SET status = 'ended', ended_at = NOW() WHERE teacher_id = ? AND status = 'live'")
        ->execute([$teacherId]);
    $roomKey = bin2hex(random_bytes(12));
    $pdo->prepare('INSERT INTO live_classes (teacher_id, title, subject, room_key) VALUES (?, ?, ?, ?)')
        ->execute([$teacherId, $title, $subject, $roomKey]);
    return (int) $pdo->lastInsertId();
}
function liveFind(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT l.*, u.name AS teacher_name, ' . liveOnlineSql('l') . ' AS is_online
           FROM live_classes l
           JOIN users u ON u.id = l.teacher_id
          WHERE l.id = ? AND u.activate = 1'
    );
    $stmt->execute([$id]);
    $live = $stmt->fetch();
    return $live ?: null;
}

function liveActiveList(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT l.id, l.title, l.subject, l.started_at, u.name AS teacher_name
           FROM live_classes l
           JOIN users u ON u.id = l.teacher_id
          WHERE u.activate = 1 AND ' . liveOnlineSql('l') . '
          ORDER BY l.started_at DESC'
    );
    return $stmt->fetchAll();
}
function liveTeacherActive(PDO $pdo, int $teacherId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT l.id, l.title
           FROM live_classes l
          WHERE l.teacher_id = ? AND ' . liveOnlineSql('l') . '
          LIMIT 1'
    );
    $stmt->execute([$teacherId]);
    $live = $stmt->fetch();
    return $live ?: null;
}
function liveEnd(PDO $pdo, int $id, int $teacherId): void
{
    $pdo->prepare("UPDATE live_classes SET status = 'ended', ended_at = NOW() WHERE id = ? AND teacher_id = ? AND status = 'live'")
        ->execute([$id, $teacherId]);
}

function liveHeartbeat(PDO $pdo, int $id, int $teacherId): void
{
    $pdo->prepare("UPDATE live_classes SET last_seen_at = NOW() WHERE id = ? AND teacher_id = ? AND status = 'live'")
        ->execute([$id, $teacherId]);
}