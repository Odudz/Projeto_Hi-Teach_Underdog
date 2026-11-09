<?php
require_once __DIR__ . '/../includes/courses.php';
require_once __DIR__ . '/../includes/rbac.php';

$subjects = [];
try {
    $subjects = $pdo->query('SELECT * FROM subjects ORDER BY name')->fetchAll();
} catch (Throwable $e) {
}

$subjectId = (int) ($_GET['subject_id'] ?? 0);
$selectedSubject = null;
if ($subjectId > 0) {
    foreach ($subjects as $s) {
        if ((int) $s['id'] === $subjectId) {
            $selectedSubject = $s;
            break;
        }
    }
}

$myEnrolledSubjects = [];
if ($user && $user['role'] === 'student') {
    try {
        $stmtEnrolled = $pdo->prepare('SELECT subject_id FROM subject_enrollments WHERE student_id = ?');
        $stmtEnrolled->execute([$user['id']]);
        $myEnrolledSubjects = array_map('intval', $stmtEnrolled->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
    }
}
$isEnrolledInSelected = ($selectedSubject && in_array((int)$selectedSubject['id'], $myEnrolledSubjects, true));

$courses = [];
try {
    $stmt = $pdo->prepare('SELECT c.*, s.name AS subject_name, u.name AS teacher_name FROM courses c JOIN subjects s ON s.id=c.subject_id JOIN users u ON u.id=c.teacher_id AND u.activate=1 WHERE (c.is_published=1 OR c.teacher_id=? OR ?=1) AND (?=0 OR c.subject_id=?) ORDER BY c.created_at DESC,c.id DESC');
    $stmt->execute([$user['id'] ?? 0, ($user['role'] ?? '') === 'admin' ? 1 : 0, $subjectId, $subjectId]);
    $courses = $stmt->fetchAll();
} catch (Throwable $e) {
}

$pageId = 'subjects';
$pageTitle = $selectedSubject ? $selectedSubject['name'] . ' | Matérias' : 'Matérias';
$baseUrl = '../';
$extraCss = ['forum', 'courses'];
include __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content courses-content">
    <section class="section forum-hero">
        <span class="forum-hero-deco forum-hero-deco-a" aria-hidden="true"></span>
        <span class="forum-hero-deco forum-hero-deco-b" aria-hidden="true"></span>
        <span class="forum-hero-deco forum-hero-deco-c" aria-hidden="true"></span>
        <h1 class="forum-hero-title">Seu espaço de aprendizado</h1>
        <p class="forum-hero-lead">Explore as matérias, matricule-se para desbloquear todas as videoaulas e materiais de
            apoio.</p>
        <ul class="forum-stats" aria-label="Resumo das matérias e cursos">
            <li class="forum-stat"><strong><?= count($subjects) ?></strong><span>matérias</span></li>
            <li class="forum-stat"><strong><?= count($courses) ?></strong><span>cursos nesta seleção</span></li>
        </ul>
        <div class="forum-hero-tools">
            <a class="button button-primary" href="#course-list">Explorar cursos</a>
            <?php if ($user && in_array($user['role'], ['teacher', 'admin'], true)): ?>
                <a class="button button-secondary" href="#course-create">Criar curso</a>
            <?php endif; ?>
        </div>
    </section>

    <!-- SELEÇÃO E NAVEGAÇÃO DE MATÉRIAS -->
    <section id="subject-list" class="section forum-shelf">
        <div class="courses-section-heading">
            <h2 class="section-title">Matérias <span class="dm-count"><?= count($subjects) ?></span></h2>
            <a class="forum-filter<?= $subjectId === 0 ? ' is-active' : '' ?>" href="subjects.php"
                <?= $subjectId === 0 ? ' aria-current="page"' : '' ?>>Todas as matérias</a>
        </div>
        <div class="forum-grid subject-grid">
            <?php foreach ($subjects as $index => $subject):
                $sId = (int) $subject['id'];
                $isSubEnrolled = in_array($sId, $myEnrolledSubjects, true);
            ?>
                <article class="forum-tile subject-card<?= $subjectId === $sId ? ' is-selected' : '' ?>"
                    style="--i: <?= min($index, 8) ?>">
                    <div class="forum-tile-top">
                        <span class="course-subject-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 6c-3-3-7-3-10-2v15c3-1 7-1 10 2 3-3 7-3 10-2V4c-3-1-7-1-10 2Z" />
                                <path d="M12 6v15" />
                            </svg>
                        </span>
                        <?php if ($subjectId === $sId): ?><span class="forum-chip">Selecionada</span><?php endif; ?>
                        <?php if ($isSubEnrolled): ?><span
                                class="forum-chip forum-chip-status">Matriculado</span><?php endif; ?>
                    </div>
                    <h3 class="forum-tile-title">
                        <a class="forum-tile-link" href="subjects.php?subject_id=<?= $sId ?>#course-list"
                            <?= $subjectId === $sId ? ' aria-current="page"' : '' ?>><?= e($subject['name']) ?></a>
                    </h3>
                    <p class="forum-tile-desc"><?= e($subject['description']) ?></p>
                    <div class="forum-tile-foot">
                        <span>Ver cursos e aulas →</span>
                        <a class="course-secondary-link" href="forum.php?subject=<?= $sId ?>">Fóruns</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- PAINEL DE MATRÍCULA NA MATÉRIA SELECIONADA -->
    <?php if ($selectedSubject): ?>
        <section id="subject-enrollment-panel" class="section course-form-panel">
            <div class="courses-section-heading">
                <div class="forum-tile-top">
                    <span class="forum-chip forum-chip-subject"><?= e($selectedSubject['name']) ?></span>
                    <?php if ($isEnrolledInSelected): ?>
                        <span class="forum-chip forum-chip-status">Matrícula Ativa</span>
                    <?php endif; ?>
                </div>
                <h2 class="section-title">Matrícula em <?= e($selectedSubject['name']) ?></h2>
                <?php if (!empty($selectedSubject['description'])): ?>
                    <p class="forum-hero-lead" style="margin-top: var(--space-1);"><?= e($selectedSubject['description']) ?></p>
                <?php endif; ?>
            </div>

            <div style="margin-top: var(--space-3);">
                <?php if (!$user): ?>
                    <p class="form-note">Entre na sua conta de aluno para se matricular nesta matéria e liberar acesso a todas
                        as aulas e materiais.</p>
                    <a class="button button-primary"
                        href="../auth/login.php?next=pages/subjects.php%3Fsubject_id%3D<?= (int)$selectedSubject['id'] ?>%23course-list">Entrar
                        para se matricular</a>
                <?php elseif ($user['role'] === 'student'): ?>
                    <?php if ($isEnrolledInSelected): ?>
                        <p class="form-note" style="color: var(--color-text); font-weight: 800;">
                            ✓ Você está matriculado nesta matéria! Todas as videoaulas, salas virtuais e materiais de apoio de todos
                            os cursos abaixo estão liberados para os seus estudos.
                        </p>
                        <form method="post" action="../endpoints/course.php" style="margin-top: var(--space-3);"
                            onsubmit="return confirm('Deseja realmente cancelar sua matrícula na matéria <?= e(addslashes($selectedSubject['name'])) ?>?');">
                            <?= csrfInput() ?>
                            <input type="hidden" name="action" value="leave_subject">
                            <input type="hidden" name="subject_id" value="<?= (int) $selectedSubject['id'] ?>">
                            <button type="submit" class="button button-secondary">Cancelar matrícula nesta matéria</button>
                        </form>
                    <?php else: ?>
                        <p class="form-note">
                            Matricule-se nesta matéria para ter acesso imediato a todas as videoaulas, apostilas e salas de aula dos
                            cursos listados abaixo.
                        </p>
                        <form method="post" action="../endpoints/course.php" style="margin-top: var(--space-3);">
                            <?= csrfInput() ?>
                            <input type="hidden" name="action" value="enroll_subject">
                            <input type="hidden" name="subject_id" value="<?= (int) $selectedSubject['id'] ?>">
                            <button type="submit" class="button button-primary">Matricular-se nesta matéria</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="form-note">Você está navegando como <strong><?= e(rbacRoleLabel($user['role'])) ?></strong>. Seu
                        acesso aos cursos e materiais é livre para visualização e moderação.</p>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- LISTAGEM DE CURSOS -->
    <section id="course-list" class="section forum-shelf">
        <div class="courses-section-heading">
            <h2 class="section-title">
                Cursos <?= $selectedSubject ? 'de ' . e($selectedSubject['name']) : '' ?>
                <span class="dm-count"><?= count($courses) ?></span>
            </h2>
            <?php if ($selectedSubject): ?>
                <small class="form-note">Mostrando cursos exclusivos da matéria selecionada.</small>
            <?php endif; ?>
        </div>

        <?php if (!$courses): ?>
            <div class="forum-empty">
                <p>Nenhum curso disponível nesta matéria no momento.</p>
                <a class="button button-secondary" href="subjects.php#course-list">Ver todas as matérias</a>
            </div>
        <?php else: ?>
            <div class="forum-grid">
                <?php foreach ($courses as $course):
                    $isCourseEnrolled = $user && courseEnrolled($pdo, (int)$course['id'], (int)$user['id']);
                ?>
                    <article class="forum-tile course-card">
                        <div class="forum-tile-top">
                            <span class="forum-chip forum-chip-subject"><?= e($course['subject_name']) ?></span>
                            <?php if (!$course['is_published']): ?><span class="forum-chip">Rascunho</span><?php endif; ?>
                            <?php if ($isCourseEnrolled): ?><span
                                    class="forum-chip forum-chip-status">Matriculado</span><?php endif; ?>
                        </div>
                        <h3 class="forum-tile-title"><a class="forum-tile-link"
                                href="course.php?id=<?= (int)$course['id'] ?>"><?= e($course['title']) ?></a></h3>
                        <p class="forum-tile-desc"><?= e($course['description']) ?></p>
                        <div class="forum-tile-foot">
                            <span class="forum-tile-author">
                                <span class="tile-avatar tile-avatar-initials"
                                    aria-hidden="true"><?= e(mb_strtoupper(mb_substr($course['teacher_name'], 0, 1))) ?></span>
                                <span>Professor: <?= e($course['teacher_name']) ?></span>
                            </span>
                            <span>Abrir curso →</span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- FORMULÁRIO DE CRIAÇÃO DE CURSO (PROFESSORES E ADMINS) -->
    <?php if ($user && in_array($user['role'], ['teacher', 'admin'], true)): ?>
        <section id="course-create" class="section course-form-panel">
            <h2 class="section-title">Criar curso</h2>
            <form class="form" method="post" action="../endpoints/course.php">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="create">
                <label>Título
                    <input class="form-input" name="title" maxlength="100" required>
                </label>
                <label>Descrição
                    <textarea class="form-input" name="description" maxlength="500"></textarea>
                </label>
                <label>Matéria
                    <select class="form-input" name="subject_id" required>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?= (int) $subject['id'] ?>"
                                <?= $subjectId === (int)$subject['id'] ? 'selected' : '' ?>>
                                <?= e($subject['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <input type="checkbox" name="is_published" checked> Publicado
                </label>
                <button class="button button-primary">Criar curso</button>
            </form>
        </section>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/rodape.php'; ?>
