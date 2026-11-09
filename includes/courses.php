<?php
require_once __DIR__ . '/../config/conexao.php';

// ---------------------------------------------------------------
// Criação Automática do Schema de Cursos, Matérias e Matrículas
// ---------------------------------------------------------------
function coursesEnsureSchema(PDO $pdo): void
{
    if (!empty($_SESSION['courses_schema_checked_v5'])) {
        return;
    }
    try {
        $tail = 'ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci';

        // Criação de tabelas sem Foreign Keys estritas para garantir que nunca quebre
        // por conta de tipos de dados da tabela users (INT vs INT UNSIGNED)

        // Matérias
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS subjects (
                id INT NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                description VARCHAR(255) NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_subject_name (name)
            ) $tail"
        );

        $hasDesc = (bool) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'description'"
        )->fetchColumn();
        if (!$hasDesc) {
            @$pdo->exec("ALTER TABLE subjects ADD COLUMN description VARCHAR(255) NULL DEFAULT NULL AFTER name");
        }

        // Matrículas nas Matérias
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS subject_enrollments (
                subject_id INT NOT NULL,
                student_id INT NOT NULL,
                enrolled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (subject_id, student_id)
            ) $tail"
        );

        // Cursos
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS courses (
                id INT NOT NULL AUTO_INCREMENT,
                subject_id INT NOT NULL,
                teacher_id INT NOT NULL,
                title VARCHAR(120) NOT NULL,
                description TEXT NULL DEFAULT NULL,
                is_published TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_courses_subject (subject_id),
                KEY idx_courses_teacher (teacher_id)
            ) $tail"
        );

        // Aulas (Vídeos do YouTube)
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS lessons (
                id INT NOT NULL AUTO_INCREMENT,
                course_id INT NOT NULL,
                teacher_id INT NOT NULL,
                title VARCHAR(120) NOT NULL,
                description TEXT NULL DEFAULT NULL,
                youtube_video_id VARCHAR(32) NOT NULL,
                display_order INT NOT NULL DEFAULT 0,
                is_published TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_lessons_course (course_id, display_order)
            ) $tail"
        );

        // Progresso dos Alunos nas Aulas
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS lesson_progress (
                lesson_id INT NOT NULL,
                student_id INT NOT NULL,
                completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (lesson_id, student_id)
            ) $tail"
        );

        // Matrículas em Cursos
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS course_enrollments (
                course_id INT NOT NULL,
                student_id INT NOT NULL,
                enrolled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (course_id, student_id)
            ) $tail"
        );

        // Materiais de Apoio
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS course_materials (
                id INT NOT NULL AUTO_INCREMENT,
                course_id INT NOT NULL,
                creator_id INT NOT NULL,
                title VARCHAR(120) NOT NULL,
                stored_name VARCHAR(64) NOT NULL,
                original_name VARCHAR(180) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                file_size INT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_materials_course (course_id)
            ) $tail"
        );

        // Atividades do Curso
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS course_activities (
                id INT NOT NULL AUTO_INCREMENT,
                course_id INT NOT NULL,
                title VARCHAR(120) NOT NULL,
                statement TEXT NOT NULL,
                deadline DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_activities_course (course_id)
            ) $tail"
        );

        // Respostas e Avaliações de Atividades
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS activity_submissions (
                activity_id INT NOT NULL,
                student_id INT NOT NULL,
                answer TEXT NOT NULL,
                grade DECIMAL(4,2) NULL DEFAULT NULL,
                feedback VARCHAR(500) NULL DEFAULT NULL,
                submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (activity_id, student_id)
            ) $tail"
        );

        $_SESSION['courses_schema_checked_v5'] = true;
    } catch (Throwable $e) {
        error_log('courses.php: schema_failed: ' . $e->getMessage());
    }
}

// Executa verificação defensiva de schema
coursesEnsureSchema($pdo);

function courseManager(?array $account, array $course): bool
{
    return $account && ($account['role'] === 'admin' ||
        ($account['role'] === 'teacher' && (int) $account['id'] === (int) $course['teacher_id']));
}

function subjectFind(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM subjects WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function subjectEnrolled(PDO $pdo, int $subjectId, int $userId): bool
{
    if ($subjectId <= 0 || $userId <= 0) return false;
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM subject_enrollments WHERE subject_id=? AND student_id=?');
        $stmt->execute([$subjectId, $userId]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function courseFind(PDO $pdo, int $id): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT c.*, s.name AS subject_name, u.name AS teacher_name FROM courses c JOIN subjects s ON s.id=c.subject_id JOIN users u ON u.id=c.teacher_id AND u.activate=1 WHERE c.id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function courseEnrolled(PDO $pdo, int $courseId, int $userId): bool
{
    if ($courseId <= 0 || $userId <= 0) return false;
    try {
        // Matrícula direta no curso
        $stmt = $pdo->prepare('SELECT 1 FROM course_enrollments WHERE course_id=? AND student_id=?');
        $stmt->execute([$courseId, $userId]);
        if ((bool) $stmt->fetchColumn()) return true;

        // Matrícula ativa na matéria
        $stmt = $pdo->prepare('SELECT 1 FROM courses c JOIN subject_enrollments se ON se.subject_id=c.subject_id WHERE c.id=? AND se.student_id=?');
        $stmt->execute([$courseId, $userId]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function courseCanWatch(PDO $pdo, ?array $account, array $course): bool
{
    if (!$account) return false;
    if (courseManager($account, $course)) return true;
    if (empty($course['is_published'])) return false;

    $userId = (int) $account['id'];
    $courseId = (int) ($course['id'] ?? 0);
    $subjectId = (int) ($course['subject_id'] ?? 0);

    return courseEnrolled($pdo, $courseId, $userId) || ($subjectId > 0 && subjectEnrolled($pdo, $subjectId, $userId));
}

function courseAbort(int $status, string $message): never
{
    http_response_code($status);
    $pageTitle = 'Curso';
    $baseUrl = '../';
    global $user, $pdo;
    include __DIR__ . '/cabecalho.php';
    echo '<main class="main-content"><section class="section"><p>' . e($message) . '</p><a class="button button-primary" href="subjects.php">Voltar às matérias</a></section></main>';
    include __DIR__ . '/rodape.php';
    exit;
}

function youtubeVideoId(string $url): ?string
{
    $parts = parse_url(trim($url));
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
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
    if (!is_string($value)) throw new InvalidArgumentException('Valor inválido.');
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $max) {
        throw new InvalidArgumentException('Preencha os campos respeitando os limites indicados.');
    }
    return $value;
}
