<?php
require_once __DIR__ . '/includes/courses.php';
require_once __DIR__ . '/includes/ratings.php';
require_once __DIR__ . '/includes/ui-helpers.php';
require_once __DIR__ . '/includes/ui-icons.php';

$stmt = $pdo->prepare('SELECT * FROM course_activities WHERE id=?');
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$activity = $stmt->fetch();
if (!$activity) {
    http_response_code(404);
    exit('Atividade não encontrada.');
}
$course = courseFind($pdo, (int) $activity['course_id']);
if (!$course || !courseCanWatch($pdo, $user, $course)) {
    http_response_code($user ? 403 : 401);
    exit('Acesso restrito ao curso.');
}
$manager = courseManager($user, $course);
$hasDeadline = !empty($activity['deadline']);
$isExpired = $hasDeadline && strtotime((string) $activity['deadline']) < time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit;
    }
    try {
        if (($_POST['action'] ?? '') === 'answer') {
            if ($manager || $user['role'] !== 'student') {
                http_response_code(403);
                exit;
            }
            if ($isExpired) {
                throw new InvalidArgumentException('O prazo desta atividade terminou.');
            }
            $answer = courseText('answer', 10000);
            $pdo->prepare('INSERT INTO activity_submissions (activity_id,student_id,answer) VALUES (?,?,?) ON DUPLICATE KEY UPDATE answer=VALUES(answer),grade=NULL,feedback=\'\'')
                ->execute([$activity['id'], $user['id'], $answer]);
            setFlash('success', 'Resposta salva com sucesso.');
        } elseif (($_POST['action'] ?? '') === 'grade' && $manager) {
            $grade = filter_var($_POST['grade'] ?? '', FILTER_VALIDATE_FLOAT);
            if ($grade === false || $grade < 0 || $grade > 10) {
                throw new InvalidArgumentException('A nota deve estar entre 0 e 10.');
            }
            $pdo->prepare('UPDATE activity_submissions SET grade=?,feedback=? WHERE activity_id=? AND student_id=?')
                ->execute([$grade, courseText('feedback', 500, false), $activity['id'], (int) ($_POST['student_id'] ?? 0)]);
            setFlash('success', 'Avaliação salva com sucesso.');
        } else {
            http_response_code(403);
            exit;
        }
    } catch (InvalidArgumentException $error) {
        setFlash('error', $error->getMessage());
    }
    redirect('activity.php?id=' . (int) $activity['id']);
}

$stmt = $pdo->prepare('SELECT a.*,u.name AS student_name,(u.avatar_mime IS NOT NULL) AS has_avatar FROM activity_submissions a JOIN users u ON u.id=a.student_id WHERE a.activity_id=? AND (?=1 OR a.student_id=?) ORDER BY a.grade IS NULL DESC, u.name');
$stmt->execute([$activity['id'], $manager ? 1 : 0, $user['id']]);
$answers = $stmt->fetchAll();

$myAnswer = !$manager && $answers ? $answers[0] : null;
$gradedCount = count(array_filter($answers, fn($a) => $a['grade'] !== null));
$pendingCount = count($answers) - $gradedCount;

$extraCss = ['ui-kit', 'subjects-hub', 'classroom', 'ratings'];
$extraJs = ['ui-kit'];
$pageId = 'activity';
$pageTitle = $activity['title'];
$baseUrl = '';
include __DIR__ . '/includes/cabecalho.php';
?>
<main id="main-content" class="main-content sh-main" data-course-id="<?= (int) $course['id'] ?>">

    <!-- ====================== HERO DA ATIVIDADE ====================== -->
    <header class="sh-hero<?= $manager ? ' sh-hero--teacher' : '' ?>">
        <div class="sh-hero-top">
            <a class="cp-hero-back" href="pages/classroom.php?id=<?= (int) $course['id'] ?>">← Voltar à sala virtual</a>
            <span class="chip chip-accent"><?= e($course['subject_name']) ?></span>
            <span class="chip <?= $isExpired ? 'chip-danger' : 'chip-muted' ?>">
                <?= $hasDeadline ? ($isExpired ? 'Prazo encerrado em ' : 'Prazo: ') . e(date('d/m/Y H:i', strtotime((string) $activity['deadline']))) : 'Sem prazo' ?>
            </span>
        </div>
        <h1 class="cp-hero-title"><?= e($activity['title']) ?></h1>
        <p class="cp-teacher">
            <span class="sh-avatar"
                aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $course['teacher_name'], 0, 1))) ?></span>
            <span class="ui-wrap"><?= e($course['title']) ?> · Prof. <?= e($course['teacher_name']) ?></span>
        </p>

        <?php if ($manager): ?>
            <ul class="sh-stats">
                <li class="sh-stat">
                    <strong><?= count($answers) ?></strong><span><?= count($answers) === 1 ? 'entrega' : 'entregas' ?></span>
                </li>
                <li class="sh-stat"><strong><?= $pendingCount ?></strong><span>para corrigir</span></li>
                <li class="sh-stat"><strong><?= $gradedCount ?></strong><span>corrigidas</span></li>
            </ul>
            <div class="sh-hero-actions">
                <a class="button button-small"
                    href="pages/classroom.php?id=<?= (int) $course['id'] ?>&amp;edit_activity=<?= (int) $activity['id'] ?>#activity-editor"><?= uiIcon('edit', 14) ?>Editar
                    atividade</a>
            </div>
        <?php endif; ?>
    </header>

    <div class="cr-layout">
        <div class="cr-main">

            <!-- ENUNCIADO -->
            <section class="ui-panel" aria-labelledby="act-statement-title">
                <h2 id="act-statement-title" style="margin-bottom: var(--space-2);">Enunciado</h2>
                <div class="cr-statement"><?= nl2br(e($activity['statement'])) ?></div>
            </section>

            <?php if (!$manager): ?>
                <!-- VISÃO DO ALUNO -->
                <section class="ui-panel" aria-labelledby="act-answer-title">
                    <div class="cr-section-head" style="margin-bottom: var(--space-3);">
                        <h2 id="act-answer-title">Sua entrega</h2>
                        <?php if ($myAnswer && $myAnswer['grade'] !== null): ?>
                            <span class="chip chip-primary">Nota: <?= number_format((float) $myAnswer['grade'], 2, ',', '.') ?>
                                / 10</span>
                        <?php elseif ($myAnswer): ?>
                            <span class="chip chip-accent">Enviada · Aguardando avaliação</span>
                        <?php elseif ($isExpired): ?>
                            <span class="chip chip-danger">Prazo encerrado</span>
                        <?php else: ?>
                            <span class="chip chip-muted">Pendente</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($myAnswer && ($myAnswer['grade'] !== null || trim((string) $myAnswer['feedback']) !== '')): ?>
                        <div class="cr-feedback" style="margin-bottom: var(--space-3);">
                            <strong>Avaliação do professor:</strong>
                            <p style="margin-top: var(--space-1);">
                                Nota:
                                <strong><?= $myAnswer['grade'] !== null ? number_format((float) $myAnswer['grade'], 2, ',', '.') . ' / 10' : 'Aguardando' ?></strong>
                            </p>
                            <?php if (trim((string) $myAnswer['feedback']) !== ''): ?>
                                <p style="margin-top: var(--space-1);"><?= nl2br(e($myAnswer['feedback'])) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isExpired): ?>
                        <div class="cr-notice-warn" style="margin-bottom: var(--space-3);">
                            <?= uiIcon('lock', 20) ?>
                            <span>O prazo de envio desta atividade encerrou em
                                <?= e(date('d/m/Y H:i', strtotime((string) $activity['deadline']))) ?>.</span>
                        </div>
                        <?php if ($myAnswer): ?>
                            <p class="ui-note" style="margin-bottom: var(--space-1);">Resposta enviada em
                                <?= e(date('d/m/Y H:i', strtotime((string) $myAnswer['submitted_at']))) ?>:</p>
                            <div class="cr-answer-box"><?= nl2br(e($myAnswer['answer'])) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <form class="ui-form" method="post">
                            <?= csrfInput() ?>
                            <input type="hidden" name="action" value="answer">
                            <label class="ui-field">
                                <span class="ui-field-top">
                                    <span class="ui-field-label">Sua resposta</span>
                                    <?php if ($myAnswer): ?><span class="ui-field-hint">Reenviar limpa a nota anterior para nova
                                            correção</span><?php endif; ?>
                                </span>
                                <textarea class="form-input" name="answer" maxlength="10000" required
                                    placeholder="Escreva sua resposta aqui..."><?= e($myAnswer['answer'] ?? '') ?></textarea>
                            </label>
                            <div class="ui-actions">
                                <button
                                    class="button button-primary"><?= $myAnswer ? 'Atualizar resposta' : 'Enviar resposta' ?></button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <!-- VISÃO DO PROFESSOR / ADMIN -->
                <section class="cr-section" aria-labelledby="act-submissions-title">
                    <div class="cr-section-head">
                        <h2 id="act-submissions-title">Respostas dos alunos <span
                                class="sh-count"><?= count($answers) ?></span></h2>
                    </div>

                    <?php if (!$answers): ?>
                        <div class="ui-empty">
                            <strong>Nenhum aluno enviou resposta ainda.</strong>
                            <span>As entregas dos alunos aparecerão aqui para correção.</span>
                        </div>
                    <?php else: ?>
                        <div class="cr-submissions">
                            <?php foreach ($answers as $answer):
                                $isGraded = $answer['grade'] !== null;
                            ?>
                                <article class="cr-submission">
                                    <header class="cr-submission-head">
                                        <div class="ap-person">
                                            <?= uiAvatar((int) $answer['student_id'], (string) $answer['student_name'], (bool) $answer['has_avatar'], 'sm', '') ?>
                                            <div>
                                                <strong><?= e($answer['student_name']) ?></strong>
                                                <div class="ui-note">Enviado em
                                                    <?= e(date('d/m/Y H:i', strtotime((string) $answer['submitted_at']))) ?></div>
                                            </div>
                                        </div>
                                        <span class="chip <?= $isGraded ? 'chip-primary' : 'chip-danger' ?>">
                                            <?= $isGraded ? 'Nota: ' . number_format((float) $answer['grade'], 2, ',', '.') . ' / 10' : 'Aguardando correção' ?>
                                        </span>
                                    </header>

                                    <div class="cr-answer-box"><?= nl2br(e($answer['answer'])) ?></div>

                                    <form class="ui-form" method="post">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="action" value="grade">
                                        <input type="hidden" name="student_id" value="<?= (int) $answer['student_id'] ?>">
                                        <div class="ui-form-grid">
                                            <label class="ui-field">
                                                <span class="ui-field-top"><span class="ui-field-label">Nota (0 a 10)</span></span>
                                                <input class="form-input" name="grade" type="number" min="0" max="10" step="0.01"
                                                    value="<?= e($answer['grade'] ?? '') ?>" required>
                                            </label>
                                            <label class="ui-field is-wide">
                                                <span class="ui-field-top"><span class="ui-field-label">Comentário para o aluno
                                                        (opcional)</span></span>
                                                <textarea class="form-input" name="feedback" maxlength="500"
                                                    data-max-lines="4"><?= e($answer['feedback'] ?? '') ?></textarea>
                                            </label>
                                        </div>
                                        <div class="ui-actions">
                                            <button
                                                class="button button-primary button-small"><?= $isGraded ? 'Atualizar avaliação' : 'Salvar nota' ?></button>
                                        </div>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>

        <!-- LATERAL -->
        <aside class="cr-side" aria-label="Avaliação e atalhos">
            <div class="ui-panel">
                <?php
                $canRateActivity = !$manager && $user['role'] === 'student';
                echo ratingRender(
                    $pdo,
                    'activity',
                    (int) $activity['id'],
                    (int) $course['id'],
                    (int) $user['id'],
                    $canRateActivity,
                    $canRateActivity ? 'Avalie esta atividade' : 'Avaliação da atividade',
                    'rating-activity'
                );
                ?>
            </div>

            <nav class="ui-panel cp-links" aria-label="Atalhos">
                <a class="cp-link"
                    href="pages/classroom.php?id=<?= (int) $course['id'] ?>"><?= uiIcon('list', 18) ?><span>Sala virtual
                        do curso</span></a>
                <a class="cp-link"
                    href="pages/course.php?id=<?= (int) $course['id'] ?>"><?= uiIcon('course', 18) ?><span>Aulas do
                        curso</span></a>
            </nav>
        </aside>
    </div>
</main>
<?php include __DIR__ . '/includes/rodape.php'; ?>
