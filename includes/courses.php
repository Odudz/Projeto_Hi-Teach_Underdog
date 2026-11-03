<?php
require_once __DIR__ . '/../config/conexao.php';
function courseManager(?array $account, array $course): bool
{
    return $account && ($account['role'] === 'admin' ||
        ($account['role'] === 'teacher' && (int) $account['id'] === (int) $course['teacher_id']));
}
function courseFind(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT c.*, s.name AS subject_name, u.name AS teacher_name FROM courses c JOIN subjects s ON s.id=c.subject_id JOIN users u ON u.id=c.teacher_id AND u.activate=1 WHERE c.id=?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}
function courseEnrolled(PDO $pdo, int $courseId, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM course_enrollments WHERE course_id=? AND student_id=?');
    $stmt->execute([$courseId, $userId]);
    return (bool) $stmt->fetchColumn();
}
function courseCanWatch(PDO $pdo, ?array $account, array $course): bool
{
    return courseManager($account, $course) || ($account && $course['is_published'] && courseEnrolled($pdo, (int) $course['id'], (int) $account['id']));
}
function courseAbort(int $status, string $message): never
{
    http_response_code($status);
    $pageTitle = 'Curso';
    $baseUrl = '../';
    global $user, $pdo;
    include __DIR__ . '/cabecalho.php';
    echo '<main class="main-content"><section class="section"><p>' . e($message) . '</p><a href="subjects.php">Voltar às matérias</a></section></main>';
    include __DIR__ . '/rodape.php';
    exit;
}
function youtubeVideoId(string $url): ?string
{
    $parts = parse_url(trim($url));
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
        return null;
    }
    $host = strtolower($parts['host'] ?? '');
    $path = $parts['path'] ?? '';
    $id = null;
    if ($host === 'youtu.be') {
        $id = substr($path, 1);
    } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
        if ($path === '/watch') {
            parse_str($parts['query'] ?? '', $query);
            $id = $query['v'] ?? null;
        } elseif (preg_match('~^/(?:embed|shorts)/([^/]+)/?$~D', $path, $match)) {
            $id = $match[1];
        }
    }
    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) ? $id : null;
}
function courseText(string $key, int $max, bool $required = true): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException('Valor inválido.');
    }
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $max) {
        throw new InvalidArgumentException('Preencha os campos respeitando os limites indicados.');
    }
    return $value;
}