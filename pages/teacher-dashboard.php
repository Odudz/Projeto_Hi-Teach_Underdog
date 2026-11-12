<?php
require_once __DIR__ . '/../includes/courses.php';

if (!$user || $user['role'] !== 'teacher') {
    $gate = $user ? 'teacher' : 'login';
    $baseUrl = '../';
    include_once __DIR__ . '/../includes/acesso-restrito.php';
}

// 1. Cursos do professor
$stmt = $pdo->prepare(
    'SELECT c.*, s.name AS subject_name,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
            (SELECT COUNT(*) FROM course_enrollments e WHERE e.course_id = c.id) AS student_count
     FROM courses c 
     JOIN subjects s ON s.id = c.subject_id 
     WHERE c.teacher_id = ? 
     ORDER BY c.updated_at DESC'
);
$stmt->execute([$user['id']]);
$courses = $stmt->fetchAll();

// 2. Respostas dos alunos nas atividades dos cursos deste professor
$stmt = $pdo->prepare(
    'SELECT sub.*, act.title AS activity_title, c.title AS course_title, u.name AS student_name
     FROM activity_submissions sub
     JOIN course_activities act ON act.id = sub.activity_id
     JOIN courses c ON c.id = act.course_id
     JOIN users u ON u.id = sub.student_id
     WHERE c.teacher_id = ?
     ORDER BY sub.submitted_at DESC LIMIT 50'
);
$stmt->execute([$user['id']]);
$submissions = $stmt->fetchAll();

// 3. Fóruns criados pelo professor
$stmt = $pdo->prepare(
    'SELECT t.*, s.name AS subject_name,
            (SELECT COUNT(*) FROM forum_posts p WHERE p.topic_id = t.id) AS reply_count
     FROM forum_topics t
     JOIN subjects s ON s.id = t.subject_id
     WHERE t.user_id = ?
     ORDER BY t.created_at DESC'
);
$stmt->execute([$user['id']]);
$teacherTopics = $stmt->fetchAll();

$pageId = 'teacher-dashboard';
$pageTitle = 'Painel do Professor';
$extraCss = ['tabs', 'staff'];
$extraJs = ['tabs', 'staff'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
    <header class="staff-header">
        <div>
            <h1 class="staff-title">Painel do Professor</h1>
            <p class="staff-subtitle">Gerencie seus cursos, salas de aula, avaliações e interações com os alunos.</p>
        </div>
        <div class="staff-header-actions">
            <a class="button button-primary button-small" href="subjects.php#course-create">Novo Curso</a>
            <a class="button button-secondary button-small" href="live-class.php">Iniciar Live</a>
        </div>
    </header>

    <!-- ABA 1: MEUS CURSOS -->
    <section class="section" data-tab="Meus Cursos" data-tab-badge="<?= count($courses) ?>">
        <h2 class="section-title">Cursos Ministrados</h2>
        <?php if (!$courses): ?>
            <div class="empty-state">
                <strong>Você ainda não criou nenhum curso.</strong>
                <span>Cadastre seu primeiro curso para começar a adicionar aulas e materiais.</span>
                <div style="margin-top: var(--space-2);">
                    <a class="button button-primary button-small" href="subjects.php#course-create">Criar Primeiro Curso</a>
                </div>
            </div>
        <?php else: ?>
            <div class="people-grid">
                <?php foreach ($courses as $c): ?>
                    <article class="card" style="display:flex; flex-direction:column; justify-content:space-between;">
                        <div>
                            <div class="item-head">
                                <span class="chip chip-muted"><?= e($c['subject_name']) ?></span>
                                <span
                                    class="chip <?= $c['is_published'] ? 'chip-primary' : 'chip-muted' ?>"><?= $c['is_published'] ? 'Publicado' : 'Rascunho' ?></span>
                            </div>
                            <h3 style="margin-top:var(--space-2);"><?= e($c['title']) ?></h3>
                            <p class="card-text" style="font-size:0.9rem;">
                                <?= e(mb_strimwidth((string)$c['description'], 0, 100, '...')) ?></p>
                        </div>
                        <div
                            style="margin-top:var(--space-3); padding-top:var(--space-2); border-top:2px dashed var(--color-surface-strong);">
                            <small class="item-note"><?= (int)$c['lesson_count'] ?> aula(s) · <?= (int)$c['student_count'] ?>
                                aluno(s)</small>
                            <div style="display:flex; gap:var(--space-2); margin-top:var(--space-2);">
                                <a class="button button-small button-primary"
                                    href="course.php?id=<?= (int)$c['id'] ?>">Gerenciar Aulas</a>
                                <a class="button button-small button-secondary"
                                    href="classroom.php?id=<?= (int)$c['id'] ?>">Sala Virtual</a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ABA 2: RESPOSTAS E AVALIAÇÕES -->
    <section class="section" data-tab="Avaliações" data-tab-badge="<?= count($submissions) ?>">
        <h2 class="section-title">Respostas e Atividades dos Alunos</h2>
        <?php if (!$submissions): ?>
            <div class="empty-state">
                <strong>Nenhum aluno enviou respostas de atividades ainda.</strong>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th class="table-head">Aluno</th>
                        <th class="table-head">Curso</th>
                        <th class="table-head">Atividade</th>
                        <th class="table-head">Nota</th>
                        <th class="table-head">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $sub): ?>
                        <tr class="table-row">
                            <td class="table-cell"><strong><?= e($sub['student_name']) ?></strong></td>
                            <td class="table-cell"><?= e($sub['course_title']) ?></td>
                            <td class="table-cell"><?= e($sub['activity_title']) ?></td>
                            <td class="table-cell">
                                <?= $sub['grade'] !== null ? '<span class="chip chip-primary">' . number_format((float)$sub['grade'], 1, ',', '') . ' / 10</span>' : '<span class="chip chip-muted">Pendente</span>' ?>
                            </td>
                            <td class="table-cell">
                                <a class="button button-small button-primary"
                                    href="../activity.php?id=<?= (int)$sub['activity_id'] ?>">Avaliar Resposta</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <!-- ABA 3: MEUS FÓRUNS -->
    <section class="section" data-tab="Fóruns">
        <div class="item-head">
            <h2 class="section-title" style="margin:0; border:0; padding:0;">Fóruns que você abriu</h2>
            <a class="button button-small button-primary" href="forum.php">Criar Fórum</a>
        </div>
        <?php if (!$teacherTopics): ?>
            <div class="empty-state" style="margin-top: var(--space-3);">
                <strong>Você ainda não abriu nenhum fórum de discussão.</strong>
            </div>
        <?php else: ?>
            <table class="table" style="margin-top: var(--space-3);">
                <thead>
                    <tr>
                        <th class="table-head">Título</th>
                        <th class="table-head">Matéria</th>
                        <th class="table-head">Respostas</th>
                        <th class="table-head">Data</th>
                        <th class="table-head">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teacherTopics as $topic): ?>
                        <tr class="table-row">
                            <td class="table-cell"><strong><?= e($topic['title']) ?></strong></td>
                            <td class="table-cell"><?= e($topic['subject_name']) ?></td>
                            <td class="table-cell"><span class="chip chip-muted"><?= (int)$topic['reply_count'] ?></span></td>
                            <td class="table-cell"><?= e(date('d/m/Y', strtotime($topic['created_at']))) ?></td>
                            <td class="table-cell">
                                <a class="button button-small button-secondary"
                                    href="forum-topic.php?id=<?= (int)$topic['id'] ?>">Abrir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
