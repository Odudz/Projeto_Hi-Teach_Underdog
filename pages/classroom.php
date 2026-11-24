<?php
require_once __DIR__ . '/../includes/course-content.php';
require_once __DIR__ . '/../includes/ui-helpers.php';
require_once __DIR__ . '/../includes/ui-icons.php';

function classroomFormatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
}

// ---------------------------------------------------------------
// Modo 1: Sem ?id= -> Hub "Minhas Salas Virtuais"
// ---------------------------------------------------------------
if (!isset($_GET['id']) || trim((string) $_GET['id']) === '') {
    if (!$user) {
        $gate = 'login';
        $baseUrl = '../';
        include_once __DIR__ . '/../includes/acesso-restrito.php';
    }

    $role = (string) $user['role'];
    $uid = (int) $user['id'];
    $rooms = [];

    try {
        if ($role === 'student') {
            $stmt = $pdo->prepare(
                'SELECT c.*, s.name AS subject_name, u.name AS teacher_name,
                        (SELECT COUNT(*) FROM course_materials m WHERE m.course_id = c.id) AS material_count,
                        (SELECT COUNT(*) FROM course_activities a WHERE a.course_id = c.id) AS activity_count,
                        (SELECT COUNT(*) FROM course_activities a
                          WHERE a.course_id = c.id
                            AND NOT EXISTS (
                                SELECT 1 FROM activity_submissions sub
                                WHERE sub.activity_id = a.id AND sub.student_id = ?
                            )
                        ) AS pending_count
                 FROM courses c
                 JOIN subjects s ON s.id = c.subject_id
                 JOIN users u ON u.id = c.teacher_id AND u.activate = 1
                 WHERE c.is_published = 1
                   AND (
                       EXISTS (SELECT 1 FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.student_id = ?)
                       OR EXISTS (SELECT 1 FROM subject_enrollments se WHERE se.subject_id = c.subject_id AND se.student_id = ?)
                   )
                 ORDER BY pending_count DESC, c.updated_at DESC'
            );
            $stmt->execute([$uid, $uid, $uid]);
            $rooms = $stmt->fetchAll();
        } elseif ($role === 'teacher') {
            $stmt = $pdo->prepare(
                'SELECT c.*, s.name AS subject_name, u.name AS teacher_name,
                        (SELECT COUNT(*) FROM course_materials m WHERE m.course_id = c.id) AS material_count,
                        (SELECT COUNT(*) FROM course_activities a WHERE a.course_id = c.id) AS activity_count,
                        (SELECT COUNT(*) FROM activity_submissions sub
                           JOIN course_activities a ON a.id = sub.activity_id
                          WHERE a.course_id = c.id AND sub.grade IS NULL) AS pending_count
                 FROM courses c
                 JOIN subjects s ON s.id = c.subject_id
                 JOIN users u ON u.id = c.teacher_id
                 WHERE c.teacher_id = ?
                 ORDER BY pending_count DESC, c.updated_at DESC'
            );
            $stmt->execute([$uid]);
            $rooms = $stmt->fetchAll();
        } elseif ($role === 'admin') {
            $rooms = $pdo->query(
                'SELECT c.*, s.name AS subject_name, u.name AS teacher_name,
                        (SELECT COUNT(*) FROM course_materials m WHERE m.course_id = c.id) AS material_count,
                        (SELECT COUNT(*) FROM course_activities a WHERE a.course_id = c.id) AS activity_count,
                        (SELECT COUNT(*) FROM activity_submissions sub
                           JOIN course_activities a ON a.id = sub.activity_id
                          WHERE a.course_id = c.id AND sub.grade IS NULL) AS pending_count
                 FROM courses c
                 JOIN subjects s ON s.id = c.subject_id
                 JOIN users u ON u.id = c.teacher_id AND u.activate = 1
                 ORDER BY c.updated_at DESC LIMIT 60'
            )->fetchAll();
        }
    } catch (Throwable $e) {
        error_log('classroom.php hub: ' . $e->getMessage());
    }

    $pageId = 'classroom';
    $pageTitle = 'Salas Virtuais';
    $baseUrl = '../';
    $extraCss = ['ui-kit', 'subjects-hub', 'classroom'];
    $extraJs = ['ui-kit'];
    include __DIR__ . '/../includes/cabecalho.php';
?>
    <main id="main-content" class="main-content sh-main">
        <header class="sh-hero<?= ' sh-hero--' . e($role) ?>">
            <div class="sh-hero-top"><?= uiRoleBadge($role) ?></div>
            <h1 class="sh-hero-title">Salas Virtuais</h1>
            <p class="sh-hero-lead">
                <?= $role === 'teacher'
                    ? 'Acesse suas salas para publicar apostilas, criar atividades e corrigir entregas dos alunos.'
                    : ($role === 'admin'
                        ? 'Acompanhe materiais, atividades e entregas de todos os cursos da plataforma.'
                        : 'Acesse materiais de apoio, envie suas atividades e acompanhe suas notas em cada curso.') ?>
            </p>
            <div class="sh-hero-actions">
                <a class="button button-primary" href="subjects.php">Explorar matérias</a>
                <?php if ($role === 'teacher'): ?>
                    <a class="button" href="teacher-dashboard.php">Painel do professor</a>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!$rooms): ?>
            <div class="ui-empty">
                <?= uiIcon('course', 32) ?>
                <strong><?= $role === 'teacher' ? 'Você ainda não tem cursos com sala virtual.' : 'Você ainda não está matriculado em nenhum curso.' ?></strong>
                <span><?= $role === 'teacher' ? 'Crie um curso em Matérias para liberar sua sala virtual.' : 'Escolha uma matéria e faça sua matrícula para acessar materiais e atividades.' ?></span>
                <a class="button button-primary button-small" href="subjects.php">Ir para Matérias</a>
            </div>
        <?php else: ?>
            <div class="cr-hub-grid">
                <?php foreach ($rooms as $room):
                    $cId = (int) $room['id'];
                    $pending = (int) ($room['pending_count'] ?? 0);
                    $isDraft = !(int) $room['is_published'];
                ?>
                    <article class="cr-hub-card<?= $isDraft ? ' is-draft' : '' ?>">
                        <div class="sh-card-top">
                            <span class="chip chip-accent"><?= e($room['subject_name']) ?></span>
                            <?php if ($isDraft): ?><span class="chip chip-draft">Rascunho</span><?php endif; ?>
                            <?php if ($pending > 0): ?>
                                <span class="chip chip-danger">
                                    <?= $pending ?>
                                    <?= $role === 'student' ? ($pending === 1 ? 'pendente' : 'pendentes') : ($pending === 1 ? 'para corrigir' : 'para corrigir') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h2 class="cr-hub-title"><?= e($room['title']) ?></h2>
                        <p class="cr-item-sub">Professor: <?= e($room['teacher_name']) ?></p>
                        <div class="ap-chips">
                            <span class="chip chip-muted"><?= (int) $room['material_count'] ?>
                                <?= (int) $room['material_count'] === 1 ? 'material' : 'materiais' ?></span>
                            <span class="chip chip-muted"><?= (int) $room['activity_count'] ?>
                                <?= (int) $room['activity_count'] === 1 ? 'atividade' : 'atividades' ?></span>
                        </div>
                        <div class="cr-hub-foot">
                            <a class="button button-primary button-small" href="classroom.php?id=<?= $cId ?>">Entrar na sala
                                <?= uiIcon('arrow', 14) ?></a>
                            <a class="button button-small" href="course.php?id=<?= $cId ?>">Ver aulas</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
<?php
    include __DIR__ . '/../includes/rodape.php';
    exit;
}

// ---------------------------------------------------------------
// Modo 2: ?id=X -> Sala Virtual de um Curso
// ---------------------------------------------------------------
$course = courseFind($pdo, (int) ($_GET['id'] ?? 0));
if (!$course) {
    courseAbort(404, 'Curso não encontrado.');
}
if (!courseCanWatch($pdo, $user, $course)) {
    courseAbort($user ? 403 : 401, 'Matricule-se no curso para acessar a sala.');
}

$courseId = (int) $course['id'];
$role = (string) ($user['role'] ?? '');
$userId = (int) ($user['id'] ?? 0);
$manager = courseManager($user, $course);
courseContentActions($pdo, $course, $user);

$stmt = $pdo->prepare('SELECT * FROM course_materials WHERE course_id = ? ORDER BY id DESC');
$stmt->execute([$courseId]);
$materials = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM course_activities WHERE course_id = ? ORDER BY COALESCE(deadline, "9999-12-31"), id');
$stmt->execute([$courseId]);
$activities = $stmt->fetchAll();

// Status de entregas (para aluno: sua entrega; para gestor: totais por atividade)
$mySubmissions = [];
$subCounts = [];
if ($activities) {
    if ($manager) {
        $stmt = $pdo->prepare(
            'SELECT a.id AS activity_id,
                    COUNT(s.student_id) AS total,
                    SUM(CASE WHEN s.student_id IS NOT NULL AND s.grade IS NULL THEN 1 ELSE 0 END) AS pending
             FROM course_activities a
             LEFT JOIN activity_submissions s ON s.activity_id = a.id
             WHERE a.course_id = ?
             GROUP BY a.id'
        );
        $stmt->execute([$courseId]);
        foreach ($stmt->fetchAll() as $row) {
            $subCounts[(int) $row['activity_id']] = [
                'total' => (int) $row['total'],
                'pending' => (int) $row['pending'],
            ];
        }
    } else {
        $stmt = $pdo->prepare(
            'SELECT s.activity_id, s.grade, s.feedback, s.submitted_at
             FROM activity_submissions s
             JOIN course_activities a ON a.id = s.activity_id
             WHERE a.course_id = ? AND s.student_id = ?'
        );
        $stmt->execute([$courseId, $userId]);
        foreach ($stmt->fetchAll() as $row) {
            $mySubmissions[(int) $row['activity_id']] = $row;
        }
    }
}

$editingActivity = null;
foreach ($activities as $item) {
    if ($manager && (int) ($_GET['edit_activity'] ?? 0) === (int) $item['id']) {
        $editingActivity = $item;
    }
}

$totalActivities = count($activities);
$doneActivities = count($mySubmissions);
$gradedGrades = array_values(array_filter(array_map(fn($s) => $s['grade'] !== null ? (float) $s['grade'] : null, $mySubmissions), fn($g) => $g !== null));
$avgGrade = $gradedGrades ? array_sum($gradedGrades) / count($gradedGrades) : null;
$totalPendingGrading = array_sum(array_column($subCounts, 'pending'));

$pageId = 'classroom';
$pageTitle = 'Sala: ' . $course['title'];
$baseUrl = '../';
$extraCss = ['ui-kit', 'subjects-hub', 'classroom'];
$extraJs = ['ui-kit'];
include __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content sh-main" data-course-id="<?= $courseId ?>">

    <!-- ====================== HERO DA SALA VIRTUAL ====================== -->
    <header class="sh-hero<?= $role !== '' ? ' sh-hero--' . e($role) : '' ?>">
        <div class="sh-hero-top">
            <a class="cp-hero-back" href="course.php?id=<?= $courseId ?>">← Voltar ao curso</a>
            <span class="chip chip-accent"><?= uiIcon('book', 14) ?><?= e($course['subject_name']) ?></span>
            <?php if (!$course['is_published']): ?><span class="chip chip-draft">Rascunho</span><?php endif; ?>
            <?php if ($manager): ?><span class="chip chip-primary">Gestão da sala</span><?php endif; ?>
        </div>
        <h1 class="cp-hero-title">Sala Virtual: <?= e($course['title']) ?></h1>
        <p class="cp-teacher">
            <span class="sh-avatar"
                aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $course['teacher_name'], 0, 1))) ?></span>
            <span class="ui-wrap">Professor(a): <?= e($course['teacher_name']) ?></span>
        </p>

        <ul class="sh-stats" aria-label="Resumo da sala">
            <li class="sh-stat">
                <strong><?= count($materials) ?></strong><span><?= count($materials) === 1 ? 'material' : 'materiais' ?></span>
            </li>
            <li class="sh-stat">
                <strong><?= $totalActivities ?></strong><span><?= $totalActivities === 1 ? 'atividade' : 'atividades' ?></span>
            </li>
            <?php if ($manager): ?>
                <li class="sh-stat">
                    <strong><?= $totalPendingGrading ?></strong><span><?= $totalPendingGrading === 1 ? 'entrega para corrigir' : 'entregas para corrigir' ?></span>
                </li>
            <?php else: ?>
                <li class="sh-stat"><strong><?= $doneActivities ?> / <?= $totalActivities ?></strong><span>entregues</span>
                </li>
                <?php if ($avgGrade !== null): ?>
                    <li class="sh-stat"><strong><?= number_format($avgGrade, 1, ',', '.') ?></strong><span>média das
                            notas</span></li>
                <?php endif; ?>
            <?php endif; ?>
        </ul>

        <div class="sh-hero-actions">
            <a class="button button-small" href="course.php?id=<?= $courseId ?>"><?= uiIcon('course', 16) ?>Videoaulas
                do curso</a>
            <a class="button button-small" href="classroom.php"><?= uiIcon('list', 16) ?>Todas as salas</a>
            <a class="button button-small"
                href="forum.php?subject=<?= (int) $course['subject_id'] ?>"><?= uiIcon('forum', 16) ?>Fórum da
                matéria</a>
        </div>
    </header>

    <div class="cr-layout">

        <!-- ====================== COLUNA PRINCIPAL ====================== -->
        <div class="cr-main">

            <!-- MATERIAIS -->
            <section class="cr-section" aria-labelledby="cr-materials-title">
                <div class="cr-section-head">
                    <h2 id="cr-materials-title"><?= uiIcon('book', 22) ?><span>Materiais de apoio</span> <span
                            class="sh-count"><?= count($materials) ?></span></h2>
                </div>

                <?php if (!$materials): ?>
                    <div class="ui-empty">
                        <strong>Nenhum material publicado ainda.</strong>
                        <span><?= $manager ? 'Use “Publicar material” ao lado para enviar apostilas, slides ou imagens.' : 'Quando o professor enviar arquivos de apoio, eles aparecerão aqui.' ?></span>
                    </div>
                <?php else: ?>
                    <?php if (count($materials) > 4): ?>
                        <div data-ui-filter="#cr-materials-scope">
                            <label class="sh-search">
                                <?= uiIcon('search', 18) ?>
                                <span class="ui-sr">Buscar material</span>
                                <input type="search" class="form-input" maxlength="80" placeholder="Buscar material por nome"
                                    data-filter-input autocomplete="off">
                            </label>
                        </div>
                    <?php endif; ?>

                    <div id="cr-materials-scope">
                        <ul class="cr-list">
                            <?php foreach ($materials as $material):
                                $mId = (int) $material['id'];
                                $ext = strtoupper(pathinfo((string) $material['original_name'], PATHINFO_EXTENSION) ?: 'ARQ');
                                $ext = mb_substr($ext, 0, 5);
                            ?>
                                <li class="cr-item" data-filter-item
                                    data-filter-text="<?= e($material['title'] . ' ' . $material['original_name']) ?>">
                                    <span class="cr-ext" aria-hidden="true"><?= e($ext) ?></span>
                                    <div class="cr-item-body">
                                        <h3 class="cr-item-title"><?= e($material['title']) ?></h3>
                                        <p class="cr-item-sub"><?= e($material['original_name']) ?> ·
                                            <?= e(classroomFormatBytes((int) $material['file_size'])) ?></p>
                                    </div>
                                    <div class="cr-item-actions">
                                        <a class="button button-primary button-small"
                                            href="../endpoints/material.php?id=<?= $mId ?>">Baixar</a>
                                        <?php if ($manager): ?>
                                            <form method="post">
                                                <?= csrfInput() ?>
                                                <input type="hidden" name="action" value="delete_material">
                                                <input type="hidden" name="material_id" value="<?= $mId ?>">
                                                <button class="button button-small button-delete"
                                                    data-confirm="O material “<?= e(mb_strimwidth((string) $material['title'], 0, 50, '…')) ?>” será excluído."
                                                    data-confirm-title="Excluir material?" data-confirm-yes="Excluir"
                                                    data-confirm-tone="danger"><?= uiIcon('trash', 14) ?>Excluir</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="ui-empty" data-filter-empty hidden><strong>Nenhum material encontrado para essa
                                busca.</strong></div>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ATIVIDADES -->
            <section class="cr-section" aria-labelledby="cr-activities-title">
                <div class="cr-section-head">
                    <h2 id="cr-activities-title"><?= uiIcon('audit', 22) ?><span>Atividades</span> <span
                            class="sh-count"><?= $totalActivities ?></span></h2>
                </div>

                <?php if (!$activities): ?>
                    <div class="ui-empty">
                        <strong>Nenhuma atividade publicada ainda.</strong>
                        <span><?= $manager ? 'Use “Nova atividade” ao lado para criar exercícios com ou sem prazo.' : 'Quando o professor publicar tarefas ou exercícios, eles aparecerão aqui.' ?></span>
                    </div>
                <?php else: ?>
                    <ul class="cr-list">
                        <?php foreach ($activities as $activity):
                            $aId = (int) $activity['id'];
                            $hasDeadline = !empty($activity['deadline']);
                            $isExpired = $hasDeadline && strtotime((string) $activity['deadline']) < time();
                            $sub = $mySubmissions[$aId] ?? null;
                            $counts = $subCounts[$aId] ?? ['total' => 0, 'pending' => 0];
                            $isDone = $sub !== null;
                            $isGraded = $isDone && $sub['grade'] !== null;
                        ?>
                            <li class="cr-item<?= $isDone ? ' is-done' : ($isExpired && !$manager ? ' is-expired' : '') ?>">
                                <span class="cr-ext cr-ext--activity"
                                    aria-hidden="true"><?= uiIcon($isDone ? 'check' : 'edit', 22) ?></span>
                                <div class="cr-item-body">
                                    <h3 class="cr-item-title"><a
                                            href="../activity.php?id=<?= $aId ?>"><?= e($activity['title']) ?></a></h3>
                                    <p class="cr-item-sub ui-clamp-2"><?= e($activity['statement']) ?></p>
                                    <div class="cr-item-meta">
                                        <span class="chip <?= $isExpired ? 'chip-danger' : 'chip-muted' ?>">
                                            <?= $hasDeadline ? ($isExpired ? 'Encerrada em ' : 'Prazo: ') . e(date('d/m/Y H:i', strtotime((string) $activity['deadline']))) : 'Sem prazo' ?>
                                        </span>
                                        <?php if ($manager): ?>
                                            <span class="chip chip-muted"><?= $counts['total'] ?>
                                                <?= $counts['total'] === 1 ? 'entrega' : 'entregas' ?></span>
                                            <?php if ($counts['pending'] > 0): ?>
                                                <span class="chip chip-danger"><?= $counts['pending'] ?> para corrigir</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if ($isGraded): ?>
                                                <span class="chip chip-primary">Nota:
                                                    <?= number_format((float) $sub['grade'], 2, ',', '.') ?> / 10</span>
                                            <?php elseif ($isDone): ?>
                                                <span class="chip chip-accent">Enviada · Aguardando nota</span>
                                            <?php elseif ($isExpired): ?>
                                                <span class="chip chip-danger">Não enviada</span>
                                            <?php else: ?>
                                                <span class="chip chip-muted">Pendente</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="cr-item-actions">
                                    <a class="button button-primary button-small" href="../activity.php?id=<?= $aId ?>">
                                        <?= $manager ? 'Ver entregas' : ($isDone ? 'Ver resposta' : 'Responder') ?>
                                    </a>
                                    <?php if ($manager): ?>
                                        <a class="button button-small"
                                            href="classroom.php?id=<?= $courseId ?>&amp;edit_activity=<?= $aId ?>#activity-editor"><?= uiIcon('edit', 14) ?>Editar</a>
                                        <form method="post">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="action" value="delete_activity">
                                            <input type="hidden" name="activity_id" value="<?= $aId ?>">
                                            <button class="button button-small button-delete"
                                                data-confirm="Excluir a atividade “<?= e(mb_strimwidth((string) $activity['title'], 0, 50, '…')) ?>” e todas as respostas dos alunos?"
                                                data-confirm-title="Excluir atividade?" data-confirm-yes="Excluir"
                                                data-confirm-tone="danger"><?= uiIcon('trash', 14) ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>

        <!-- ====================== BARRA LATERAL ====================== -->
        <aside class="cr-side" aria-label="Ferramentas da sala">

            <?php if ($manager): ?>
                <!-- Publicar Material -->
                <details class="ui-details">
                    <summary>
                        <span class="ui-summary-icon is-plus"><?= uiIcon('plus', 20) ?></span>
                        <span class="ui-summary-main">
                            <strong>Publicar material</strong>
                            <small>PDF, Office ou imagem até 10 MB.</small>
                        </span>
                        <span class="ui-chevron" aria-hidden="true"></span>
                    </summary>
                    <div class="ui-details-body">
                        <div class="ui-details-inner">
                            <form class="ui-form" method="post" enctype="multipart/form-data">
                                <?= csrfInput() ?>
                                <input type="hidden" name="action" value="material">
                                <label class="ui-field">
                                    <span class="ui-field-top"><span class="ui-field-label">Título do material</span></span>
                                    <input class="form-input" name="title" maxlength="100" required autocomplete="off"
                                        placeholder="Ex.: Slides da Aula 01">
                                </label>
                                <label class="ui-field">
                                    <span class="ui-field-top"><span class="ui-field-label">Arquivo (até 10
                                            MB)</span></span>
                                    <input class="form-input" type="file" name="material" required>
                                </label>
                                <div class="ui-actions">
                                    <button class="button button-primary">Publicar material</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </details>

                <!-- Criar / Editar Atividade -->
                <details id="activity-editor" class="ui-details" <?= $editingActivity ? ' open' : '' ?>>
                    <summary>
                        <span
                            class="ui-summary-icon<?= $editingActivity ? '' : ' is-plus' ?>"><?= uiIcon($editingActivity ? 'edit' : 'plus', 20) ?></span>
                        <span class="ui-summary-main">
                            <strong><?= $editingActivity ? 'Editar atividade' : 'Nova atividade' ?></strong>
                            <small><?= $editingActivity ? 'Altere enunciado e prazo.' : 'Crie uma tarefa com prazo opcional.' ?></small>
                        </span>
                        <span class="ui-chevron" aria-hidden="true"></span>
                    </summary>
                    <div class="ui-details-body">
                        <div class="ui-details-inner">
                            <form class="ui-form" method="post">
                                <?= csrfInput() ?>
                                <input type="hidden" name="action" value="activity">
                                <input type="hidden" name="activity_id" value="<?= (int) ($editingActivity['id'] ?? 0) ?>">
                                <label class="ui-field">
                                    <span class="ui-field-top"><span class="ui-field-label">Título</span></span>
                                    <input class="form-input" name="title" maxlength="100"
                                        value="<?= e($editingActivity['title'] ?? '') ?>" required autocomplete="off"
                                        placeholder="Ex.: Lista de Exercícios 1">
                                </label>
                                <label class="ui-field">
                                    <span class="ui-field-top"><span class="ui-field-label">Enunciado</span></span>
                                    <textarea class="form-input" name="statement" maxlength="10000" required
                                        placeholder="Descreva o que os alunos devem responder."><?= e($editingActivity['statement'] ?? '') ?></textarea>
                                </label>
                                <label class="ui-field">
                                    <span class="ui-field-top"><span class="ui-field-label">Prazo opcional</span></span>
                                    <input class="form-input" type="datetime-local" name="deadline"
                                        value="<?= !empty($editingActivity['deadline']) ? e(date('Y-m-d\TH:i', strtotime((string) $editingActivity['deadline']))) : '' ?>">
                                </label>
                                <div class="ui-actions">
                                    <button class="button button-primary">Salvar atividade</button>
                                    <?php if ($editingActivity): ?>
                                        <a class="button" href="classroom.php?id=<?= $courseId ?>">Cancelar</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </details>
            <?php else: ?>
                <section class="ui-panel" aria-label="Seu andamento">
                    <h3>Seu andamento</h3>
                    <div class="cp-progress" style="margin-top: var(--space-2);">
                        <span><?= $doneActivities ?> de <?= $totalActivities ?> atividades entregues</span>
                        <progress value="<?= $doneActivities ?>" max="<?= max(1, $totalActivities) ?>"
                            aria-label="Atividades entregues"><?= $doneActivities ?> / <?= $totalActivities ?></progress>
                    </div>
                    <?php if ($avgGrade !== null): ?>
                        <p class="ui-note" style="margin-top: var(--space-2);">Média atual nas atividades corrigidas:
                            <strong><?= number_format($avgGrade, 2, ',', '.') ?> / 10</strong>
                        </p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <nav class="ui-panel cp-links" aria-label="Atalhos do curso">
                <a class="cp-link" href="course.php?id=<?= $courseId ?>"><?= uiIcon('course', 18) ?><span>Videoaulas do
                        curso</span></a>
                <a class="cp-link"
                    href="forum.php?subject=<?= (int) $course['subject_id'] ?>"><?= uiIcon('forum', 18) ?><span>Tirar
                        dúvidas no fórum</span></a>
                <a class="cp-link" href="live-class.php"><?= uiIcon('live', 18) ?><span>Aulas ao vivo</span></a>
            </nav>

        </aside>
    </div>
</main>
<?php include __DIR__ . '/../includes/rodape.php'; ?>
