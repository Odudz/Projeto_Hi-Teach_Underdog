<?php
require_once __DIR__ . '/../includes/courses.php';
require_once __DIR__ . '/../includes/rbac.php';

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
        courseAbort(403, 'Ação não permitida.');
    }
    $pdo->prepare('INSERT IGNORE INTO lesson_progress (lesson_id,student_id) VALUES (?,?)')->execute([$lesson['id'], $user['id']]);
    setFlash('success', 'Aula marcada como concluída.');
    redirect('video.php?id=' . (int)$lesson['id']);
}

$videoId = $lesson['youtube_video_id'];
$pageId = 'video';
$pageTitle = $lesson['title'];
$baseUrl = '../';
$extraCss = ['courses'];
$extraJs = ['youtube'];

include __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content" data-course-id="<?= (int)$course['id'] ?>">
    <div class="video-layout">
        <header class="video-header">
            <a class="forum-back" href="course.php?id=<?= (int) $course['id'] ?>">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4"
                    stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12" />
                    <polyline points="12 19 5 12 12 5" />
                </svg>
                Voltar para <?= e($course['title']) ?>
            </a>
            <h1 class="video-title"><?= e($lesson['title']) ?></h1>
        </header>

        <section class="video-player-container">
            <?php if ($videoId && preg_match('/^[A-Za-z0-9_-]{11}$/D', $videoId)): ?>
                <div class="youtube-player">
                    <iframe id="youtube-player" title="<?= e($lesson['title']) ?>"
                        src="https://www.youtube.com/embed/<?= e($videoId) ?>?enablejsapi=1&amp;playsinline=1"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                        referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                </div>
                <div id="youtube-error" class="video-notice" role="status">
                    <div class="video-notice-icon">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path
                                d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                            <line x1="12" y1="9" x2="12" y2="13" />
                            <line x1="12" y1="17" x2="12.01" y2="17" />
                        </svg>
                    </div>
                    <div class="video-notice-text">
                        <strong>O vídeo não carregou?</strong>
                        <span>Ele pode estar privado, indisponível ou com a reprodução em outros sites desativada pelo
                            autor.</span>
                    </div>
                    <a class="button button-small button-secondary"
                        href="https://www.youtube.com/watch?v=<?= e($videoId) ?>" target="_blank" rel="noopener noreferrer">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                            <polyline points="15 3 21 3 21 9" />
                            <line x1="10" y1="14" x2="21" y2="3" />
                        </svg>
                        Abrir direto no YouTube
                    </a>
                </div>
            <?php else: ?>
                <div class="video-placeholder">
                    <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18" />
                        <line x1="7" y1="2" x2="7" y2="22" />
                        <line x1="17" y1="2" x2="17" y2="22" />
                        <line x1="2" y1="12" x2="22" y2="12" />
                        <line x1="2" y1="7" x2="7" y2="7" />
                        <line x1="2" y1="17" x2="7" y2="17" />
                        <line x1="17" y1="17" x2="22" y2="17" />
                        <line x1="17" y1="7" x2="22" y2="7" />
                    </svg>
                    <p>O arquivo original desta aula não está disponível. O professor pode adicionar uma URL do YouTube
                        editando a aula.</p>
                </div>
            <?php endif; ?>
        </section>

        <div class="video-details-grid">
            <div class="video-info">
                <h2 class="section-title">Sobre a aula</h2>
                <div class="video-description">
                    <p><?= nl2br(e($lesson['description'])) ?></p>
                </div>
                <div class="video-instructor">
                    <span class="tile-avatar tile-avatar-initials"
                        aria-hidden="true"><?= e(mb_strtoupper(mb_substr($course['teacher_name'], 0, 1))) ?></span>
                    <div class="instructor-details">
                        <span class="instructor-role">Professor(a)</span>
                        <strong class="instructor-name"><?= e($course['teacher_name']) ?></strong>
                    </div>
                </div>
            </div>

            <div class="video-actions-panel">
                <?php if ($user['role'] === 'student'): ?>
                    <div class="action-card">
                        <h3>Progresso</h3>
                        <p>Marque esta aula como concluída para acompanhar o seu desempenho no curso.</p>
                        <form method="post" class="action-form">
                            <?= csrfInput() ?>
                            <button class="button button-primary action-btn">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
                                    stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                    <polyline points="22 4 12 14.01 9 11.01" />
                                </svg>
                                Concluir aula
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="action-card">
                        <h3>Visão de <?= e(rbacRoleLabel($user['role'])) ?></h3>
                        <p>Você pode visualizar o conteúdo, mas o progresso e estatísticas são registrados apenas para
                            alunos matriculados.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/rodape.php'; ?>
