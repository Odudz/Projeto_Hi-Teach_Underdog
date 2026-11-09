<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/courses.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/ui-helpers.php';
require_once __DIR__ . '/../includes/forum-upload.php';
require_once __DIR__ . '/../includes/live.php';

// Permite acesso de Administradores e Moderadores
if (!$user || !in_array($user['role'], ['moderator', 'admin'], true)) {
    $gate = $user ? 'moderator' : 'login';
    $baseUrl = '../';
    include_once __DIR__ . '/../includes/acesso-restrito.php';
}

$isAdmin = ($user['role'] === 'admin');
$modAction = (string) ($_POST['mod_action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('moderator-dashboard.php');

    // 1. Gestão de Matérias: Criar (Apenas Admin)
    if ($modAction === 'create_subject') {
        if (!$isAdmin) {
            setFlash('error', 'Apenas administradores podem cadastrar matérias.');
            redirect('moderator-dashboard.php#mod-subjects');
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            setFlash('error', 'O nome da matéria deve ter entre 2 e 80 caracteres.');
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO subjects (name, description) VALUES (?, ?)');
                $stmt->execute([$name, $description !== '' ? $description : null]);
                setFlash('success', "Matéria \"{$name}\" cadastrada com sucesso!");
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    setFlash('error', 'Já existe uma matéria cadastrada com esse nome.');
                } else {
                    error_log('create_subject: ' . $e->getMessage());
                    setFlash('error', 'Não foi possível cadastrar a matéria.');
                }
            }
        }
        redirect('moderator-dashboard.php#mod-subjects');
    }

    // 2. Gestão de Matérias: Editar (Apenas Admin)
    if ($modAction === 'edit_subject') {
        if (!$isAdmin) {
            setFlash('error', 'Apenas administradores podem editar matérias.');
            redirect('moderator-dashboard.php#mod-subjects');
        }
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($subjectId <= 0 || mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            setFlash('error', 'Dados inválidos para a matéria.');
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE subjects SET name = ?, description = ? WHERE id = ?');
                $stmt->execute([$name, $description !== '' ? $description : null, $subjectId]);
                setFlash('success', 'Matéria atualizada com sucesso!');
            } catch (PDOException $e) {
                setFlash('error', 'Já existe outra matéria com esse nome.');
            }
        }
        redirect('moderator-dashboard.php#mod-subjects');
    }

    // 3. Gestão de Matérias: Excluir (Apenas Admin)
    if ($modAction === 'delete_subject') {
        if (!$isAdmin) {
            setFlash('error', 'Apenas administradores podem excluir matérias.');
            redirect('moderator-dashboard.php#mod-subjects');
        }
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        if ($subjectId <= 0) {
            setFlash('error', 'Matéria inválida.');
        } else {
            $stmtTopics = $pdo->prepare('SELECT COUNT(*) FROM forum_topics WHERE subject_id = ?');
            $stmtTopics->execute([$subjectId]);
            $countTopics = (int) $stmtTopics->fetchColumn();

            $stmtCourses = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE subject_id = ?');
            $stmtCourses->execute([$subjectId]);
            $countCourses = (int) $stmtCourses->fetchColumn();

            if ($countTopics > 0 || $countCourses > 0) {
                setFlash('error', "Não é possível excluir: existem {$countCourses} curso(s) e {$countTopics} fórum(ns) vinculados.");
            } else {
                $pdo->prepare('DELETE FROM subjects WHERE id = ?')->execute([$subjectId]);
                setFlash('success', 'Matéria excluída com sucesso.');
            }
        }
        redirect('moderator-dashboard.php#mod-subjects');
    }

    // 4. Moderação de Cursos: Excluir Curso
    if ($modAction === 'delete_course') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        if ($courseId > 0) {
            $stmt = $pdo->prepare('SELECT stored_name FROM course_materials WHERE course_id = ?');
            $stmt->execute([$courseId]);
            $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $pdo->prepare('DELETE FROM courses WHERE id = ?')->execute([$courseId]);

            foreach ($files as $name) {
                $path = __DIR__ . '/../storage/private/materials/' . $name;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            setFlash('success', 'Curso removido da plataforma.');
        }
        redirect('moderator-dashboard.php#mod-courses');
    }

    // 5. Excluir Tópico do Fórum
    if (isset($_POST['forum_topic_id'])) {
        $topicToDelete = (int) $_POST['forum_topic_id'];
        $stmtFiles = $pdo->prepare('SELECT stored_name FROM forum_attachments WHERE topic_id = ?');
        $stmtFiles->execute([$topicToDelete]);
        $storedNames = $stmtFiles->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare('DELETE FROM forum_topics WHERE id = ?')->execute([$topicToDelete]);
        forumCleanupFiles($storedNames);
        setFlash('success', 'Tópico excluído com sucesso.');
        redirect('moderator-dashboard.php#mod-forums');
    }

    // 6. Revisão de Professores (Apenas Admin)
    $teacherId = (int) ($_POST['user_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';

    if ($teacherId && $teacherId !== (int) $user['id'] && in_array($decision, ['approve', 'reject'], true)) {
        if (!$isAdmin) {
            setFlash('error', 'Apenas administradores podem aprovar ou recusar professores.');
            redirect('moderator-dashboard.php#pending-teachers');
        }

        $stmt = $pdo->prepare("UPDATE teacher_profiles SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$decision === 'approve' ? 'approved' : 'rejected', $user['id'], $teacherId]);

        if ($stmt->rowCount() > 0 && $decision === 'approve') {
            $pdo->prepare("UPDATE users SET role = 'teacher' WHERE id = ? AND role = 'student'")->execute([$teacherId]);
            setFlash('success', 'Professor aprovado com sucesso!');
        } else {
            setFlash('success', 'Solicitação de professor recusada.');
        }
        redirect('moderator-dashboard.php#pending-teachers');
    }
}

// ---------------------------------------------------------------
// Consultas do Painel com Proteção Total contra Tabelas Vazias
// ---------------------------------------------------------------
$pending = [];
try {
    $pending = $pdo->query(
        "SELECT u.id AS user_id, u.name, u.email, u.created_at, t.specializations, (u.avatar_mime IS NOT NULL) AS has_avatar
         FROM teacher_profiles t JOIN users u ON u.id = t.user_id
         WHERE t.status = 'pending' AND u.activate = 1 ORDER BY u.created_at"
    )->fetchAll();
} catch (Throwable $e) {
}

$proofsByUser = [];
if ($pending) {
    $ids = array_map('intval', array_column($pending, 'user_id'));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare("SELECT id, user_id, original_name FROM teacher_proofs WHERE user_id IN ($placeholders) ORDER BY id");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $proof) {
            $proofsByUser[(int) $proof['user_id']][] = $proof;
        }
    } catch (Throwable $e) {
    }
}

function parseSpecializations(string $raw): array
{
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return array_values(array_filter(array_map('strval', $decoded)));
    }
    return trim($raw) !== '' ? [trim($raw)] : [];
}

$subjectList = [];
try {
    $subjectList = $pdo->query(
        "SELECT s.*, 
                (SELECT COUNT(*) FROM courses c WHERE c.subject_id = s.id) AS course_count,
                (SELECT COUNT(*) FROM forum_topics f WHERE f.subject_id = s.id) AS topic_count
         FROM subjects s ORDER BY s.name ASC"
    )->fetchAll();
} catch (Throwable $e) {
}

$courseList = [];
try {
    $courseList = $pdo->query(
        "SELECT c.*, s.name AS subject_name, u.name AS teacher_name,
                (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
                (SELECT COUNT(*) FROM course_enrollments e WHERE e.course_id = c.id) AS student_count
         FROM courses c
         JOIN subjects s ON s.id = c.subject_id
         JOIN users u ON u.id = c.teacher_id
         ORDER BY c.created_at DESC"
    )->fetchAll();
} catch (Throwable $e) {
}

$forumTopics = [];
try {
    $forumTopics = $pdo->query(
        'SELECT t.id, t.title, t.created_at, s.name AS subject_name, u.name AS author,
                (SELECT COUNT(*) FROM forum_posts p WHERE p.topic_id = t.id) AS post_count
         FROM forum_topics t 
         JOIN subjects s ON s.id = t.subject_id
         JOIN users u ON u.id = t.user_id AND u.activate = 1
         ORDER BY t.created_at DESC LIMIT 30'
    )->fetchAll();
} catch (Throwable $e) {
}

$countUsers = 0;
$countTeachers = 0;
$countCourses = count($courseList);
$countLessons = 0;
$countSubjects = count($subjectList);
$countTopics = 0;

try {
    $countUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE activate = 1")->fetchColumn();
} catch (Throwable $e) {
}
try {
    $countTeachers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND activate = 1")->fetchColumn();
} catch (Throwable $e) {
}
try {
    $countLessons = (int) $pdo->query("SELECT COUNT(*) FROM lessons")->fetchColumn();
} catch (Throwable $e) {
}
try {
    $countTopics = (int) $pdo->query("SELECT COUNT(*) FROM forum_topics")->fetchColumn();
} catch (Throwable $e) {
}

$lives = liveStaffList($pdo);

$pageId = 'moderator-dashboard';
$pageTitle = $isAdmin ? 'Painel da Administração' : 'Painel de Moderação';
$extraCss = ['tabs', 'staff'];
$extraJs = ['tabs', 'staff'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
    <header class="staff-header">
        <div>
            <h1 class="staff-title"><?= $isAdmin ? 'Painel da Administração' : 'Painel de Moderação' ?></h1>
            <p class="staff-subtitle">Gestão de matérias, aprovação de professores, cursos e supervisão da comunidade.
            </p>
        </div>
        <div class="staff-header-actions">
            <?php if ($isAdmin): ?>
                <a class="button button-small button-secondary" href="admin-users.php">Usuários e cargos</a>
            <?php endif; ?>
            <a class="button button-small button-secondary" href="moderation.php">Fila de Moderação</a>
            <a class="button button-small button-primary" href="subjects.php">Ver matérias no site</a>
        </div>
    </header>

    <!-- ABA 1: RESUMO -->
    <section id="mod-overview" class="section" data-tab="Resumo">
        <div class="staff-summary">
            <?= uiStat('Usuários Ativos', $countUsers, $isAdmin ? 'admin-users.php' : null, $countTeachers . ' professores cadastrados') ?>
            <?= uiStat('Professores Pendentes', count($pending), '#pending-teachers', 'aguardando validação', count($pending) > 0) ?>
            <?= uiStat('Disciplinas', $countSubjects, '#mod-subjects', 'matérias ativas') ?>
            <?= uiStat('Cursos Criados', $countCourses, '#mod-courses', $countLessons . ' videoaulas cadastradas') ?>
            <?= uiStat('Fóruns', $countTopics, '#mod-forums', 'tópicos de estudo') ?>
        </div>
    </section>

    <!-- ABA 2: PROFESSORES PENDENTES -->
    <section id="pending-teachers" class="section" data-tab="Professores" data-tab-badge="<?= count($pending) ?>">
        <h2 class="section-title">Professores aguardando aprovação</h2>
        <?php if (!$pending): ?>
            <div id="pending-empty" class="empty-state">
                <strong>Nenhuma solicitação pendente no momento.</strong>
                <span>Quando novos professores se cadastrarem, seus dados e comprovações aparecerão aqui.</span>
            </div>
        <?php else: ?>
            <div id="pending-teachers-list" class="item-list">
                <?php foreach ($pending as $teacher): $tid = (int)$teacher['user_id']; ?>
                    <article class="item-card">
                        <header class="item-head">
                            <div class="person">
                                <?= uiAvatar($tid, (string)$teacher['name'], (bool)($teacher['has_avatar'] ?? false)) ?>
                                <div>
                                    <strong><?= e($teacher['name']) ?></strong> <span class="tag-id">#<?= $tid ?></span>
                                    <div class="item-note"><?= e($teacher['email']) ?></div>
                                </div>
                            </div>
                            <time class="item-time">Cadastro em
                                <?= e(date('d/m/Y H:i', strtotime($teacher['created_at']))) ?></time>
                        </header>

                        <div class="item-main">
                            <div class="chip-row">
                                <span class="chip-label">Especializações:</span>
                                <?php foreach (parseSpecializations((string)$teacher['specializations']) as $sp): ?>
                                    <span class="chip chip-accent"><?= e($sp) ?></span>
                                <?php endforeach; ?>
                            </div>

                            <div class="chip-row" style="margin-top: var(--space-2);">
                                <span class="chip-label">Comprovantes:</span>
                                <?php if (!empty($proofsByUser[$tid])): ?>
                                    <?php foreach ($proofsByUser[$tid] as $number => $proof): ?>
                                        <a class="button button-small button-secondary"
                                            href="../endpoints/teacher-proof.php?id=<?= (int)$proof['id'] ?>">Comprovante
                                            <?= $number + 1 ?></a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <a class="button button-small button-secondary"
                                        href="../endpoints/teacher-proof.php?user=<?= $tid ?>">Visualizar Comprovante</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <footer class="item-actions">
                            <?php if ($isAdmin): ?>
                                <form class="inline-form" action="moderator-dashboard.php" method="post">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="user_id" value="<?= $tid ?>">
                                    <button type="submit" name="decision" value="approve"
                                        class="button button-small button-primary">Aprovar Professor</button>
                                </form>
                                <form class="inline-form" action="moderator-dashboard.php" method="post"
                                    onsubmit="return confirm('Recusar a solicitação deste professor?');">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="user_id" value="<?= $tid ?>">
                                    <button type="submit" name="decision" value="reject"
                                        class="button button-small button-danger">Recusar</button>
                                </form>
                            <?php else: ?>
                                <span class="chip chip-muted">Aprovação restrita a Administradores</span>
                            <?php endif; ?>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ABA 3: GESTÃO DE MATÉRIAS -->
    <section id="mod-subjects" class="section" data-tab="Matérias" data-tab-badge="<?= count($subjectList) ?>">
        <div class="item-head">
            <h2 class="section-title" style="margin:0; border:0; padding:0;">Disciplinas e Matérias</h2>
            <?php if ($isAdmin): ?>
                <details class="decision">
                    <summary class="button button-small button-primary">Cadastrar Nova Matéria</summary>
                    <form class="decision-form" action="moderator-dashboard.php" method="post"
                        style="margin-top: var(--space-2);">
                        <?= csrfInput() ?>
                        <input type="hidden" name="mod_action" value="create_subject">
                        <label class="field">
                            <span>Nome da Matéria *</span>
                            <input type="text" name="name" class="form-input" maxlength="80"
                                placeholder="Ex.: Engenharia de Software" required>
                        </label>
                        <label class="field">
                            <span>Descrição da Disciplina</span>
                            <input type="text" name="description" class="form-input" maxlength="255"
                                placeholder="Resumo dos conteúdos abordados">
                        </label>
                        <button type="submit" class="button button-primary button-small">Salvar Matéria</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>

        <table class="table" style="margin-top: var(--space-3);">
            <thead>
                <tr>
                    <th class="table-head">Nome</th>
                    <th class="table-head">Descrição</th>
                    <th class="table-head">Cursos</th>
                    <th class="table-head">Fóruns</th>
                    <?php if ($isAdmin): ?><th class="table-head">Ações</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjectList as $sub): ?>
                    <tr class="table-row">
                        <td class="table-cell"><strong><?= e($sub['name']) ?></strong></td>
                        <td class="table-cell"><?= e($sub['description'] ?: '—') ?></td>
                        <td class="table-cell"><span class="chip chip-muted"><?= (int)$sub['course_count'] ?></span></td>
                        <td class="table-cell"><span class="chip chip-muted"><?= (int)$sub['topic_count'] ?></span></td>
                        <?php if ($isAdmin): ?>
                            <td class="table-cell">
                                <details class="decision" style="display:inline-block; margin-right:4px;">
                                    <summary class="button button-small button-secondary">Editar</summary>
                                    <form class="decision-form" action="moderator-dashboard.php" method="post">
                                        <?= csrfInput() ?>
                                        <input type="hidden" name="mod_action" value="edit_subject">
                                        <input type="hidden" name="subject_id" value="<?= (int)$sub['id'] ?>">
                                        <label class="field">
                                            <span>Nome da Matéria</span>
                                            <input type="text" name="name" class="form-input" value="<?= e($sub['name']) ?>"
                                                required>
                                        </label>
                                        <label class="field">
                                            <span>Descrição</span>
                                            <input type="text" name="description" class="form-input"
                                                value="<?= e($sub['description']) ?>">
                                        </label>
                                        <button type="submit" class="button button-primary button-small">Atualizar</button>
                                    </form>
                                </details>
                                <form action="moderator-dashboard.php" method="post" style="display:inline;"
                                    onsubmit="return confirm('Deseja excluir a matéria <?= e(addslashes($sub['name'])) ?>?');">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="mod_action" value="delete_subject">
                                    <input type="hidden" name="subject_id" value="<?= (int)$sub['id'] ?>">
                                    <button type="submit" class="button button-small button-danger"
                                        <?= ((int)$sub['course_count'] > 0 || (int)$sub['topic_count'] > 0) ? 'title="Não pode excluir: possui conteúdos vinculados" disabled' : '' ?>>Excluir</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <!-- ABA 4: GESTÃO DE CURSOS -->
    <section id="mod-courses" class="section" data-tab="Cursos" data-tab-badge="<?= count($courseList) ?>">
        <h2 class="section-title">Cursos da Plataforma</h2>
        <?php if (!$courseList): ?>
            <div class="empty-state">
                <strong>Nenhum curso cadastrado ainda.</strong>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th class="table-head">Título</th>
                        <th class="table-head">Matéria</th>
                        <th class="table-head">Professor</th>
                        <th class="table-head">Aulas</th>
                        <th class="table-head">Alunos</th>
                        <th class="table-head">Status</th>
                        <th class="table-head">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courseList as $c): ?>
                        <tr class="table-row">
                            <td class="table-cell"><strong><?= e($c['title']) ?></strong></td>
                            <td class="table-cell"><?= e($c['subject_name']) ?></td>
                            <td class="table-cell"><?= e($c['teacher_name']) ?></td>
                            <td class="table-cell"><?= (int)$c['lesson_count'] ?></td>
                            <td class="table-cell"><?= (int)$c['student_count'] ?></td>
                            <td class="table-cell">
                                <span class="chip <?= $c['is_published'] ? 'chip-primary' : 'chip-muted' ?>">
                                    <?= $c['is_published'] ? 'Publicado' : 'Rascunho' ?>
                                </span>
                            </td>
                            <td class="table-cell">
                                <a class="button button-small button-secondary"
                                    href="course.php?id=<?= (int)$c['id'] ?>">Acessar</a>
                                <form action="moderator-dashboard.php" method="post" style="display:inline;"
                                    onsubmit="return confirm('Deseja excluir este curso permanentemente?');">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="mod_action" value="delete_course">
                                    <input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>">
                                    <button type="submit" class="button button-small button-danger">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <!-- ABA 5: MODERAÇÃO DE FÓRUNS -->
    <section id="mod-forums" class="section" data-tab="Fóruns">
        <h2 class="section-title">Fóruns de Discussão</h2>
        <?php if (!$forumTopics): ?>
            <div class="empty-state">
                <strong>Nenhum tópico criado ainda.</strong>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th class="table-head">Título</th>
                        <th class="table-head">Matéria</th>
                        <th class="table-head">Autor</th>
                        <th class="table-head">Respostas</th>
                        <th class="table-head">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($forumTopics as $topic): ?>
                        <tr class="table-row">
                            <td class="table-cell"><strong><?= e($topic['title']) ?></strong></td>
                            <td class="table-cell"><?= e($topic['subject_name']) ?></td>
                            <td class="table-cell"><?= e($topic['author']) ?></td>
                            <td class="table-cell"><span class="chip chip-muted"><?= (int)$topic['post_count'] ?></span></td>
                            <td class="table-cell">
                                <a class="button button-small button-secondary"
                                    href="forum-topic.php?id=<?= (int) $topic['id'] ?>">Revisar</a>
                                <form class="inline-form" action="moderator-dashboard.php" method="post" style="display:inline;"
                                    onsubmit="return confirm('Excluir este tópico e todas as mensagens?');">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="forum_topic_id" value="<?= (int) $topic['id'] ?>">
                                    <button type="submit" class="button button-small button-danger">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
