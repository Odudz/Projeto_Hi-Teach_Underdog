<?php
require_once __DIR__ . '/../includes/ratings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if (!$user) {
    http_response_code(401);
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit;
}
// Só alunos avaliam (professores e equipe apenas visualizam a média)
if (($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    exit;
}

$type = (string) ($_POST['type'] ?? '');
$courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$stars = filter_var($_POST['stars'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
$requestedTarget = filter_var($_POST['target_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!in_array($type, RATING_TYPES, true) || !$courseId || !$stars) {
    http_response_code(400);
    exit;
}

$course = courseFind($pdo, (int) $courseId);
if (!$course || !courseCanWatch($pdo, $user, $course)) {
    http_response_code(403);
    exit;
}

$back = '../pages/course.php?id=' . (int) $courseId;

try {
    if ($type === 'teacher') {
        // O professor é sempre o do próprio curso (não confia no valor enviado pelo navegador)
        $targetId = (int) $course['teacher_id'];
        $back = '../pages/course.php?id=' . (int) $courseId . '#rating-teacher';
    } elseif ($type === 'lesson') {
        $targetId = (int) $requestedTarget;
        $stmt = $pdo->prepare('SELECT id FROM lessons WHERE id = ? AND course_id = ? AND is_published = 1');
        $stmt->execute([$targetId, (int) $courseId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(404);
            exit;
        }
        $back = '../pages/video.php?id=' . $targetId . '#rating-lesson';
    } else {
        $targetId = (int) $requestedTarget;
        $stmt = $pdo->prepare('SELECT id FROM course_activities WHERE id = ? AND course_id = ?');
        $stmt->execute([$targetId, (int) $courseId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(404);
            exit;
        }
        $back = '../activity.php?id=' . $targetId . '#rating-activity';
    }

    ratingSave($pdo, (int) $user['id'], $type, $targetId, (int) $stars);
    setFlash('success', 'Obrigado pela sua avaliação!');
} catch (Throwable $error) {
    error_log('rating.php: ' . $error->getMessage());
    setFlash('error', 'Não foi possível salvar sua avaliação. Tente novamente.');
}

redirect($back);
