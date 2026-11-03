<?php
require_once __DIR__ . '/../includes/courses.php';
$stmt = $pdo->prepare('SELECT * FROM lessons WHERE id=?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$lesson = $stmt->fetch();
if (!$lesson) {
    courseAbort(404, 'Aula não encontrada.');
}
$course = courseFind($pdo, (int) $lesson['course_id']);
if (!$course || !courseCanWatch($pdo, $user, $course) || (!$lesson['is_published'] && !courseManager($user, $course))) {
    courseAbort($user ? 403 : 401, 'Você precisa de acesso ao curso para assistir a esta aula.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null) || $user['role'] !== 'student') {
        courseAbort(403,'Ação não permitida.');
    }
    $pdo->prepare('INSERT IGNORE INTO lesson_progress (lesson_id,student_id) VALUES (?,?)')->execute([$lesson['id'],$user['id']]);
    setFlash('success','Aula marcada como concluída.');
    redirect('video.php?id=' . (int)$lesson['id']);
}
$videoId = $lesson['youtube_video_id'];
$pageId = 'video';
$pageTitle = $lesson['title'];
$baseUrl = '../';
$extraCss = ['courses'];
$extraJs = ['youtube'];
include __DIR__ . '/../includes/cabecalho.php';?>
<main id="main-content" class="main-content" data-course-id="<?= (int)$course['id'] ?>"><section id="video-player-section" class="section"><a id="back-to-course" href="course.php?id=<?= (int) $course['id'] ?>">Voltar para <?= e($course['title']) ?></a><h2 class="section-title"><?= e($lesson['title']) ?></h2>
<?php if ($videoId && preg_match('/^[A-Za-z0-9_-]{11}$/D', $videoId)): ?>
<div class="youtube-player"><iframe id="youtube-player" title="<?= e($lesson['title']) ?>" src="https://www.youtube.com/embed/<?= e($videoId) ?>?enablejsapi=1&amp;playsinline=1" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>
<p id="youtube-error" role="status">Se o vídeo não carregar, ele pode estar privado, indisponível ou com incorporação desativada. <a href="https://www.youtube.com/watch?v=<?= e($videoId) ?>" target="_blank" rel="noopener noreferrer">Abrir no YouTube</a></p>
<?php else: ?><p>O arquivo original desta aula não está disponível nesta instalação. O professor pode adicionar uma URL do YouTube editando a aula.</p><?php endif; ?>
<p><?= nl2br(e($lesson['description'])) ?></p>
<p>Professor: <?= e($course['teacher_name']) ?></p>
<?php if ($user['role'] === 'student'): ?>
<form method="post"><?= csrfInput() ?><button class="button button-primary">Marcar aula como concluída</button></form>
<?php endif; ?>
</section></main>
<?php include __DIR__ . '/../includes/rodape.php'; ?>
