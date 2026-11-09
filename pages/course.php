<?php
require_once __DIR__ . '/../includes/courses.php';

try {
    $course = courseFind($pdo, (int) ($_GET['id'] ?? 0));
} catch (Throwable $e) {
    $course = null;
}

if (!$course) {
    courseAbort(404, 'Curso não encontrado.');
}

$manager = courseManager($user, $course);
if (!$course['is_published'] && !$manager) {
    courseAbort(403, 'Este curso não está disponível.');
}

$canWatch = courseCanWatch($pdo, $user, $course);
$lessons = [];

try {
    $stmt = $pdo->prepare('SELECT * FROM lessons WHERE course_id=? AND (is_published=1 OR ?=1) ORDER BY display_order,id');
    $stmt->execute([$course['id'], $manager ? 1 : 0]);
    $lessons = $canWatch ? $stmt->fetchAll() : [];
} catch (Throwable $e) {
    // Ignora silenciosamente
}

$completed = 0;
if ($canWatch && $user['role'] === 'student') {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM lesson_progress p JOIN lessons l ON l.id=p.lesson_id WHERE l.course_id=? AND l.is_published=1 AND p.student_id=?');
        $stmt->execute([$course['id'], $user['id']]);
        $completed = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // Ignora silenciosamente
    }
}

$editing = null;
foreach ($lessons as $lesson) {
    if ($manager && (int) ($_GET['edit_lesson'] ?? 0) === (int) $lesson['id']) {
        $editing = $lesson;
    }
}

$subjects = [];
if ($manager) {
    try {
        $subjects = $pdo->query('SELECT id,name FROM subjects ORDER BY name')->fetchAll();
    } catch (Throwable $e) {
    }
}

$pageId = 'course';
$pageTitle = $course['title'];
$baseUrl = '../';
$extraCss = ['forum', 'courses'];
include __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content courses-content" data-course-id="<?= (int)$course['id'] ?>">
    <section id="course-detail" class="section forum-hero">
        <span class="forum-hero-deco forum-hero-deco-a" aria-hidden="true"></span><span
            class="forum-hero-deco forum-hero-deco-b" aria-hidden="true"></span><span
            class="forum-hero-deco forum-hero-deco-c" aria-hidden="true"></span>
        <a class="forum-back" href="subjects.php?subject_id=<?= (int)$course['subject_id'] ?>#course-list">← Voltar às
            matérias</a>
        <div class="forum-tile-top"><span
                class="forum-chip forum-chip-subject"><?= e($course['subject_name']) ?></span><?php if (!$course['is_published']): ?><span
                    class="forum-chip">Rascunho</span><?php endif; ?></div>
        <h1 class="forum-hero-title"><?= e($course['title']) ?></h1>
        <p class="forum-hero-lead"><?= e($course['description']) ?></p>
        <p class="forum-hero-by"><span class="tile-avatar tile-avatar-initials"
                aria-hidden="true"><?= e(mb_strtoupper(mb_substr($course['teacher_name'], 0, 1))) ?></span>Professor:
            <?= e($course['teacher_name']) ?></p>
        <?php if (!$user): ?><a class="button" href="../auth/login.php?next=pages/subjects.php">Entre para se
                matricular</a>
        <?php elseif ($user['role'] === 'student' && $course['is_published']): ?>
            <form method="post" action="../endpoints/course.php"><?= csrfInput() ?><input type="hidden" name="course_id"
                    value="<?= (int) $course['id'] ?>"><input type="hidden" name="action"
                    value="<?= $canWatch ? 'leave' : 'enroll' ?>"><button
                    class="button button-primary"><?= $canWatch ? 'Sair do curso' : 'Matricular-se' ?></button></form>
        <?php endif; ?>
    </section>
    <section id="course-materials" class="section forum-shelf">
        <h2 class="section-title">Aulas e materiais<?php if ($canWatch): ?> <span
                class="dm-count"><?= count($lessons) ?></span><?php endif; ?></h2>
        <?php if ($canWatch && $user['role'] === 'student'): ?>
            <div class="course-progress">
                <p>Progresso: <?= $completed ?> / <?= count($lessons) ?> aulas concluídas.</p><progress
                    value="<?= $completed ?>" max="<?= max(1, count($lessons)) ?>"
                    aria-label="Aulas concluídas"><?= $completed ?> / <?= count($lessons) ?></progress>
            </div>
        <?php endif; ?>
        <?php if (!$canWatch): ?>
            <div class="forum-empty">
                <p>Matricule-se para acessar as aulas deste curso.</p>
            </div><?php elseif (!$lessons): ?>
            <div class="forum-empty">
                <p>Nenhuma aula publicada.</p>
            </div>
        <?php endif; ?>
        <ul class="forum-grid course-lesson-list"><?php foreach ($lessons as $lessonIndex => $lesson): ?>
                <li class="forum-tile course-lesson">
                    <div class="forum-tile-top"><span class="forum-chip">Aula
                            <?= str_pad((string)($lessonIndex + 1), 2, '0', STR_PAD_LEFT) ?></span><span
                            class="forum-chip forum-chip-subject"><?= $lesson['youtube_video_id'] ? 'Vídeo do YouTube' : 'Videoaula' ?></span><?php if (!$lesson['is_published']): ?><span
                                class="forum-chip">Rascunho</span><?php endif; ?></div>
                    <h3 class="forum-tile-title"><a class="forum-tile-link"
                            href="video.php?id=<?= (int)$lesson['id'] ?>"><?= e($lesson['title']) ?></a></h3>
                    <p class="forum-tile-desc"><?= e($lesson['description']) ?></p>
                    <div class="forum-tile-foot"><span>Assistir à aula →</span><?php if ($manager): ?>
                            <div class="course-tile-actions"><a class="forum-filter"
                                    href="course.php?id=<?= (int)$course['id'] ?>&amp;edit_lesson=<?= (int)$lesson['id'] ?>#lesson-editor">Editar</a>
                                <form method="post" action="../endpoints/course.php"><?= csrfInput() ?><input type="hidden"
                                        name="action" value="delete_lesson"><input type="hidden" name="course_id"
                                        value="<?= (int)$course['id'] ?>"><input type="hidden" name="lesson_id"
                                        value="<?= (int)$lesson['id'] ?>"><button class="forum-filter course-delete"
                                        data-confirm="Excluir esta aula?">Excluir aula</button></form>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php if ($manager): ?>
        <section id="lesson-editor" class="section course-form-panel">
            <h2 class="section-title"><?= $editing ? 'Editar aula' : 'Adicionar vídeo do YouTube' ?></h2>
            <form class="form" method="post" action="../endpoints/course.php">
                <?= csrfInput() ?><input type="hidden" name="action" value="save_lesson"><input type="hidden"
                    name="course_id" value="<?= (int) $course['id'] ?>"><input type="hidden" name="lesson_id"
                    value="<?= (int) ($editing['id'] ?? 0) ?>">
                <label>Título<input class="form-input" name="title" maxlength="100"
                        value="<?= e($editing['title'] ?? '') ?>" required></label>
                <label>Descrição<textarea class="form-input" name="description"
                        maxlength="500"><?= e($editing['description'] ?? '') ?></textarea></label>
                <label>URL do YouTube<input class="form-input" name="youtube_url" type="url" maxlength="500"
                        value="<?= !empty($editing['youtube_video_id']) ? e('https://www.youtube.com/watch?v=' . $editing['youtube_video_id']) : '' ?>"
                        required></label>
                <label>Ordem<input class="form-input" name="display_order" type="number" min="0" max="1000000"
                        value="<?= (int) ($editing['display_order'] ?? 0) ?>" required></label>
                <label><input type="checkbox" name="is_published"
                        <?= !$editing || $editing['is_published'] ? 'checked' : '' ?>> Publicada</label><button
                    class="button button-primary">Salvar aula</button>
            </form>
        </section>
        <section class="section course-form-panel">
            <h2 class="section-title">Editar curso</h2>
            <form class="form" method="post" action="../endpoints/course.php"><?= csrfInput() ?><input type="hidden"
                    name="action" value="update"><input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
                <label>Título<input class="form-input" name="title" maxlength="100" value="<?= e($course['title']) ?>"
                        required></label><label>Descrição<textarea class="form-input" name="description"
                        maxlength="500"><?= e($course['description']) ?></textarea></label><label>Matéria<select
                        class="form-input" name="subject_id"><?php foreach ($subjects as $subject): ?><option
                                value="<?= (int) $subject['id'] ?>"
                                <?= (int) $subject['id'] === (int) $course['subject_id'] ? 'selected' : '' ?>>
                                <?= e($subject['name']) ?></option><?php endforeach; ?></select></label><label><input
                        type="checkbox" name="is_published" <?= $course['is_published'] ? 'checked' : '' ?>>
                    Publicado</label><button class="button button-primary">Salvar curso</button>
            </form>
            <form method="post" action="../endpoints/course.php"><?= csrfInput() ?><input type="hidden" name="action"
                    value="delete"><input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>"><button
                    class="button button-secondary"
                    data-confirm="Excluir o curso e todas as suas aulas e matrículas?">Excluir curso</button></form>
        </section>
    <?php endif; ?>
    <nav class="section course-links" aria-label="Acesso rápido ao curso"><a class="forum-filter"
            href="classroom.php?id=<?= (int)$course['id'] ?>">Sala virtual: materiais e atividades</a><a
            class="forum-filter" href="forum.php?subject=<?= (int)$course['subject_id'] ?>">Fóruns da matéria</a><a
            class="forum-filter" href="live-class.php">Aulas ao vivo</a></nav>
</main>
<?php include __DIR__ . '/../includes/rodape.php'; ?>
