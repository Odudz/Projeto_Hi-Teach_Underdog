<?php
require_once __DIR__ . '/../includes/courses.php';
require_once __DIR__ . '/../includes/ui-helpers.php';
require_once __DIR__ . '/../includes/ui-icons.php';

const COURSE_TITLE_MAX = 100;
const COURSE_DESC_MAX = 500;
const LESSON_URL_MAX = 500;

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

$role = (string) ($user['role'] ?? '');
$userId = (int) ($user['id'] ?? 0);
$isStudent = $role === 'student';
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
if ($canWatch && $isStudent) {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM lesson_progress p JOIN lessons l ON l.id=p.lesson_id WHERE l.course_id=? AND l.is_published=1 AND p.student_id=?');
        $stmt->execute([$course['id'], $user['id']]);
        $completed = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        // Ignora silenciosamente
    }
}

// Matrícula direta x acesso pela matéria (define qual botão mostrar ao aluno)
$directEnrolled = false;
$viaSubject = false;
if ($isStudent && $canWatch) {
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM course_enrollments WHERE course_id=? AND student_id=?');
        $stmt->execute([$course['id'], $userId]);
        $directEnrolled = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
    }
    $viaSubject = !$directEnrolled;
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

$courseId = (int) $course['id'];
$lessonTotal = count($lessons);
$pageId = 'course';
$pageTitle = $course['title'];
$baseUrl = '../';
$extraCss = ['ui-kit', 'subjects-hub'];
$extraJs = ['ui-kit'];
include __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content sh-main" data-course-id="<?= $courseId ?>">

    <!-- ====================== HERO DO CURSO ====================== -->
    <header id="course-detail" class="sh-hero cp-hero<?= $role !== '' ? ' sh-hero--' . e($role) : '' ?>">
        <a class="cp-hero-back" href="subjects.php?subject_id=<?= (int) $course['subject_id'] ?>#course-list">← Voltar às matérias</a>
        <div class="sh-hero-top">
            <a class="chip chip-accent" href="subjects.php?subject_id=<?= (int) $course['subject_id'] ?>#course-list" title="<?= e($course['subject_name']) ?>"><?= uiIcon('book', 14) ?><?= e($course['subject_name']) ?></a>
            <?php if (!$course['is_published']): ?><span class="chip chip-draft">Rascunho</span><?php endif; ?>
            <?php if ($manager): ?><span class="chip chip-primary">Você gerencia este curso</span><?php endif; ?>
        </div>
        <h1 class="cp-hero-title"><?= e($course['title']) ?></h1>
        <?php if (trim((string) $course['description']) !== ''): ?>
            <p class="sh-hero-lead"><?= e($course['description']) ?></p>
        <?php endif; ?>
        <p class="cp-teacher">
            <span class="sh-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $course['teacher_name'], 0, 1))) ?></span>
            <span class="ui-wrap">Professor: <?= e($course['teacher_name']) ?></span>
        </p>

        <?php if ($canWatch && $isStudent): ?>
            <div class="cp-progress">
                <span><?= $completed ?> de <?= $lessonTotal ?> aulas concluídas</span>
                <progress value="<?= $completed ?>" max="<?= max(1, $lessonTotal) ?>" aria-label="Aulas concluídas"><?= $completed ?> / <?= $lessonTotal ?></progress>
            </div>
        <?php endif; ?>

        <div class="sh-hero-actions">
            <?php if (!$user): ?>
                <a class="button button-primary" href="../auth/login.php?next=pages/subjects.php">Entre para se matricular</a>
            <?php elseif ($isStudent && $course['is_published']): ?>
                <?php if ($directEnrolled || !$canWatch): ?>
                    <form method="post" action="../endpoints/course.php">
                        <?= csrfInput() ?>
                        <input type="hidden" name="course_id" value="<?= $courseId ?>">
                        <input type="hidden" name="action" value="<?= $directEnrolled ? 'leave' : 'enroll' ?>">
                        <?php if ($directEnrolled): ?>
                            <button class="button" data-confirm="Você deixará de ter acesso direto às aulas deste curso."
                                data-confirm-title="Sair do curso?" data-confirm-yes="Sair do curso" data-confirm-tone="danger">Sair do curso</button>
                        <?php else: ?>
                            <button class="button button-primary">Matricular-se</button>
                        <?php endif; ?>
                    </form>
                <?php elseif ($viaSubject): ?>
                    <span class="chip chip-primary"><?= uiIcon('check', 14) ?>Acesso liberado pela matrícula na matéria</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </header>

    <div class="cp-layout">

        <!-- ====================== AULAS ====================== -->
        <section id="course-materials" class="cp-main" aria-labelledby="cp-lessons-title">
            <div class="sh-list-head">
                <h2 id="cp-lessons-title">
                    <span>Aulas e materiais</span>
                    <?php if ($canWatch): ?><span class="sh-count"><?= $lessonTotal ?></span><?php endif; ?>
                </h2>
            </div>

            <?php if (!$canWatch): ?>
                <div class="ui-empty">
                    <?= uiIcon('lock', 28) ?>
                    <strong>
                        <?= $isStudent ? 'Matricule-se para acessar as aulas deste curso.' : 'As aulas ficam disponíveis para alunos matriculados.' ?>
                    </strong>
                </div>
            <?php elseif (!$lessons): ?>
                <div class="ui-empty">
                    <strong><?= $manager ? 'Nenhuma aula cadastrada ainda.' : 'Nenhuma aula publicada.' ?></strong>
                    <?php if ($manager): ?><span>Use “Adicionar aula” ao lado para incluir o primeiro vídeo.</span><?php endif; ?>
                </div>
            <?php else: ?>
                <ol class="cp-lessons">
                    <?php foreach ($lessons as $lessonIndex => $lesson):
                        $lId = (int) $lesson['id'];
                        $lessonPublished = (bool) (int) $lesson['is_published'];
                    ?>
                        <li class="cp-lesson<?= !$lessonPublished ? ' is-draft' : '' ?>" style="--i: <?= min($lessonIndex, 10) ?>">
                            <span class="cp-lesson-num"><?= str_pad((string) ($lessonIndex + 1), 2, '0', STR_PAD_LEFT) ?></span>
                            <div class="cp-lesson-body">
                                <h3 class="cp-lesson-title ui-clamp-2"><a href="video.php?id=<?= $lId ?>"><?= e($lesson['title']) ?></a></h3>
                                <?php if (trim((string) $lesson['description']) !== ''): ?>
                                    <p class="cp-lesson-desc ui-clamp-2"><?= e($lesson['description']) ?></p>
                                <?php endif; ?>
                                <div class="cp-lesson-chips">
                                    <span class="chip chip-muted"><?= $lesson['youtube_video_id'] ? 'Vídeo do YouTube' : 'Videoaula' ?></span>
                                    <?php if (!$lessonPublished): ?><span class="chip chip-draft">Rascunho</span><?php endif; ?>
                                </div>
                            </div>
                            <div class="cp-lesson-actions">
                                <?php if ($manager): ?>
                                    <a class="button button-small" href="course.php?id=<?= $courseId ?>&amp;edit_lesson=<?= $lId ?>#lesson-editor"><?= uiIcon('edit', 14) ?>Editar</a>
                                    <form method="post" action="../endpoints/course.php">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="delete_lesson">
                                        <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                        <input type="hidden" name="lesson_id" value="<?= $lId ?>">
                                        <button class="button button-small" data-confirm="A aula “<?= e(mb_strimwidth((string) $lesson['title'], 0, 60, '…')) ?>” será removida do curso."
                                            data-confirm-title="Excluir aula?" data-confirm-yes="Excluir aula" data-confirm-tone="danger"><?= uiIcon('trash', 14) ?>Excluir</button>
                                    </form>
                                <?php else: ?>
                                    <?= uiIcon('arrow', 20) ?>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <!-- ====================== LATERAL ====================== -->
        <aside class="cp-side" aria-label="Ferramentas do curso">
            <nav class="ui-panel cp-links" aria-label="Acesso rápido ao curso">
                <a class="cp-link" href="classroom.php?id=<?= $courseId ?>"><?= uiIcon('course', 18) ?><span>Sala virtual: materiais e atividades</span></a>
                <a class="cp-link" href="forum.php?subject=<?= (int) $course['subject_id'] ?>"><?= uiIcon('forum', 18) ?><span>Fóruns da matéria</span></a>
                <a class="cp-link" href="live-class.php"><?= uiIcon('live', 18) ?><span>Aulas ao vivo</span></a>
            </nav>

            <?php if ($manager): ?>
                <!-- Adicionar / editar aula -->
                <details id="lesson-editor" class="ui-details"<?= $editing ? ' open' : '' ?>>
                    <summary>
                        <span class="ui-summary-icon<?= $editing ? '' : ' is-plus' ?>"><?= uiIcon($editing ? 'edit' : 'plus', 20) ?></span>
                        <span class="ui-summary-main">
                            <strong><?= $editing ? 'Editar aula' : 'Adicionar aula' ?></strong>
                            <small><?= $editing ? 'Ajuste título, vídeo e ordem.' : 'Cole o link de um vídeo do YouTube.' ?></small>
                        </span>
                        <span class="ui-chevron" aria-hidden="true"></span>
                    </summary>
                    <div class="ui-details-body"><div class="ui-details-inner">
                        <form class="ui-form" method="post" action="../endpoints/course.php">
                            <?= csrfInput() ?>
                            <input type="hidden" name="action" value="save_lesson">
                            <input type="hidden" name="course_id" value="<?= $courseId ?>">
                            <input type="hidden" name="lesson_id" value="<?= (int) ($editing['id'] ?? 0) ?>">
                            <label class="ui-field">
                                <span class="ui-field-top"><span class="ui-field-label">Título</span></span>
                                <input class="form-input" name="title" maxlength="<?= COURSE_TITLE_MAX ?>" value="<?= e($editing['title'] ?? '') ?>" required autocomplete="off">
                            </label>
                            <label class="ui-field">
                                <span class="ui-field-top"><span class="ui-field-label">Descrição</span></span>
                                <textarea class="form-input" name="description" maxlength="<?= COURSE_DESC_MAX ?>" data-max-lines="6"><?= e($editing['description'] ?? '') ?></textarea>
                            </label>
                            <label class="ui-field">
                                <span class="ui-field-top"><span class="ui-field-label">URL do YouTube</span></span>
                                <input class="form-input" name="youtube_url" type="url" maxlength="<?= LESSON_URL_MAX ?>" placeholder="https://www.youtube.com/watch?v=..."
                                    value="<?= !empty($editing['youtube_video_id']) ? e('https://www.youtube.com/watch?v=' . $editing['youtube_video_id']) : '' ?>" required>
                            </label>
                            <label class="ui-field">
                                <span class="ui-field-top"><span class="ui-field-label">Ordem</span><span class="ui-field-hint">0 aparece primeiro</span></span>
                                <input class="form-input" name="display_order" type="number" min="0" max="1000000" value="<?= (int) ($editing['display_order'] ?? 0) ?>" required>
                            </label>
                            <label class="ui-check">
                                <input type="checkbox" name="is_published" <?= !$editing || $editing['is_published'] ? 'checked' : '' ?>>
                                <span>Publicada</span>
                            </label>
                            <div class="ui-actions">
                                <button type="submit" class="button button-primary">Salvar aula</button>
                                <?php if ($editing): ?><a class="button" href="course.php?id=<?= $courseId ?>#course-materials">Cancelar</a><?php endif; ?>
                            </div>
                        </form>
                    </div></div>
                </details>

                <!-- Editar curso -->
                <details id="course-edit" class="ui-details">
                    <summary>
                        <span class="ui-summary-icon"><?= uiIcon('edit', 20) ?></span>
                        <span class="ui-summary-main">
                            <strong>Editar curso</strong>
                            <small>Título, descrição, matéria e publicação.</small>
                        </span>
                        <span class="ui-chevron" aria-hidden="true"></span>
                    </summary>
                    <div class="ui-details-body"><div class="ui-details-inner">
                        <form class="ui-form" method="post" action="../endpoints/course.php">
                            <?= csrfInput() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="course_id" value="<?= $courseId ?>">
                            <label class="ui-field">
                                <span class="ui-field-top"><span class="ui-field-label">Título</span></span>
                                <input class="form-input" name="title" maxlength="<?= COURSE_TITLE_MAX ?>" value="<?= e($course['title']) ?>" required autocomplete="off">
                            </label>
                            <label class="ui-field">
                                <span class="ui-field-top"><span class="ui-field-label">Descrição</span></span>
                                <textarea class="form-input" name="description" maxlength="<?= COURSE_DESC_MAX ?>" data-max-lines="8"><?= e($course['description']) ?></textarea>
                            </label>
                            <label class="ui-field">
                                <span class="ui-field-top"><span class="ui-field-label">Matéria</span></span>
                                <select class="form-input" name="subject_id">
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?= (int) $subject['id'] ?>" <?= (int) $subject['id'] === (int) $course['subject_id'] ? 'selected' : '' ?>>
                                            <?= e(mb_strimwidth((string) $subject['name'], 0, 70, '…')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="ui-check">
                                <input type="checkbox" name="is_published" <?= $course['is_published'] ? 'checked' : '' ?>>
                                <span>Publicado</span>
                            </label>
                            <div class="ui-actions">
                                <button type="submit" class="button button-primary">Salvar curso</button>
                            </div>
                        </form>
                    </div></div>
                </details>

                <!-- Zona de exclusão -->
                <details id="course-danger" class="ui-details is-danger">
                    <summary>
                        <span class="ui-summary-icon"><?= uiIcon('trash', 20) ?></span>
                        <span class="ui-summary-main">
                            <strong>Excluir curso</strong>
                            <small>Ação permanente.</small>
                        </span>
                        <span class="ui-chevron" aria-hidden="true"></span>
                    </summary>
                    <div class="ui-details-body"><div class="ui-details-inner">
                        <p class="ui-note">Isso apaga o curso, todas as aulas, materiais e matrículas ligadas a ele. Não dá para desfazer.</p>
                        <form method="post" action="../endpoints/course.php">
                            <?= csrfInput() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="course_id" value="<?= $courseId ?>">
                            <button class="button button-delete" data-confirm="O curso “<?= e(mb_strimwidth((string) $course['title'], 0, 60, '…')) ?>”, suas aulas e matrículas serão apagados para sempre."
                                data-confirm-title="Excluir este curso?" data-confirm-yes="Excluir curso" data-confirm-tone="danger">Excluir curso</button>
                        </form>
                    </div></div>
                </details>
            <?php endif; ?>
        </aside>
    </div>
</main>
<?php include __DIR__ . '/../includes/rodape.php'; ?>
