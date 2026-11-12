<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/courses.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/ui-helpers.php';
require_once __DIR__ . '/../includes/ui-icons.php';
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

/** Texto de linha única: colapsa espaços/quebras e remove as pontas. */
function dashText($value): string
{
    $clean = preg_replace('/\s+/u', ' ', (string) $value);
    return trim($clean ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('moderator-dashboard.php');

    // 1. Gestão de Matérias: Criar (Apenas Admin)
    if ($modAction === 'create_subject') {
        if (!$isAdmin) {
            setFlash('error', 'Apenas administradores podem cadastrar matérias.');
            redirect('moderator-dashboard.php#mod-subjects');
        }
        $name = dashText($_POST['name'] ?? '');
        $description = dashText($_POST['description'] ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            setFlash('error', 'O nome da matéria deve ter entre 2 e 60 caracteres.');
        } elseif (mb_strlen($description) > 250) {
            setFlash('error', 'A descrição deve ter no máximo 250 caracteres.');
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
        $name = dashText($_POST['name'] ?? '');
        $description = dashText($_POST['description'] ?? '');

        if ($subjectId <= 0 || mb_strlen($name) < 2 || mb_strlen($name) > 60 || mb_strlen($description) > 250) {
            setFlash('error', 'Dados inválidos: o nome deve ter de 2 a 60 e a descrição até 250 caracteres.');
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

    // 4b. Moderação de Cursos: Publicar / Despublicar (admin e moderador)
    if ($modAction === 'toggle_course_publish') {
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $target = 'moderator-dashboard.php#mod-courses';
        if ((string) ($_POST['return'] ?? '') === 'subjects') {
            $params = [];
            $returnSubject = (int) ($_POST['subject_id'] ?? 0);
            if ($returnSubject > 0) {
                $params['subject_id'] = $returnSubject;
            }
            $returnView = (string) ($_POST['view'] ?? '');
            if (in_array($returnView, ['meus', 'rascunhos'], true)) {
                $params['view'] = $returnView;
            }
            $target = 'subjects.php' . ($params ? '?' . http_build_query($params) : '') . '#course-list';
        }
        if ($courseId > 0) {
            $stmt = $pdo->prepare('UPDATE courses SET is_published = 1 - is_published WHERE id = ?');
            $stmt->execute([$courseId]);
            setFlash($stmt->rowCount() > 0 ? 'success' : 'error',
                $stmt->rowCount() > 0 ? 'Status de publicação do curso atualizado.' : 'Curso não encontrado.');
        }
        redirect($target);
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

// ---------------------------------------------------------------
// Dados derivados para o resumo
// ---------------------------------------------------------------
$pendingCount = count($pending);
$draftCourses = array_values(array_filter($courseList, fn($c) => !(int) $c['is_published']));
$emptyCourses = array_values(array_filter($courseList, fn($c) => (int) $c['lesson_count'] === 0));
$idleSubjects = array_values(array_filter($subjectList, fn($s) => (int) $s['course_count'] === 0 && (int) $s['topic_count'] === 0));
$publishedCount = $countCourses - count($draftCourses);
$recentCourses = array_slice($courseList, 0, 5);

$pageId = 'moderator-dashboard';
$pageTitle = $isAdmin ? 'Painel da Administração' : 'Painel de Moderação';
$extraCss = ['ui-kit', 'admin-panels'];
$extraJs = ['ui-kit', 'staff'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content ap-main">

    <!-- ====================== HERO ====================== -->
    <header class="ap-hero <?= $isAdmin ? 'ap-hero--admin' : 'ap-hero--moderator' ?>">
        <div class="ap-hero-text">
            <div class="ap-hero-top"><?= uiRoleBadge($user['role']) ?></div>
            <h1 class="ap-title"><?= $isAdmin ? 'Painel da Administração' : 'Painel de Moderação' ?></h1>
            <p class="ap-subtitle">Gestão de matérias, aprovação de professores, cursos e supervisão da comunidade.</p>
        </div>
        <div class="ap-hero-actions">
            <?php if ($isAdmin): ?>
                <a class="button button-small" href="admin-users.php"><?= uiIcon('users', 16) ?>Usuários e cargos</a>
            <?php endif; ?>
            <a class="button button-small" href="moderation.php"><?= uiIcon('shield', 16) ?>Fila de moderação</a>
            <a class="button button-small button-primary" href="subjects.php"><?= uiIcon('book', 16) ?>Ver matérias no site</a>
        </div>
    </header>

    <div class="ap-shell">

        <!-- ====================== NAVEGAÇÃO ====================== -->
        <nav class="ap-nav" data-ap-nav aria-label="Seções do painel">
            <?= apNavButton('mod-overview', 'overview', 'Resumo') ?>
            <?= apNavButton('pending-teachers', 'teacher', 'Professores', $pendingCount, true) ?>
            <?= apNavButton('mod-subjects', 'book', 'Matérias', $countSubjects) ?>
            <?= apNavButton('mod-courses', 'course', 'Cursos', $countCourses) ?>
            <?= apNavButton('mod-forums', 'forum', 'Fóruns', count($forumTopics)) ?>
            <span class="ap-nav-sep">Atalhos</span>
            <?php if ($isAdmin): ?><?= apNavLink('admin-users.php', 'users', 'Usuários e cargos') ?><?php endif; ?>
            <?= apNavLink('moderation.php', 'shield', 'Fila de moderação') ?>
            <?= apNavLink('live-class.php', 'live', 'Aulas ao vivo') ?>
        </nav>

        <div class="ap-content">

            <!-- ====================== RESUMO ====================== -->
            <section id="mod-overview" class="ap-panel" data-ap-panel>
                <div class="ap-panel-head">
                    <div>
                        <h2 class="ap-panel-title">Resumo</h2>
                        <p class="ap-panel-sub">Visão geral da plataforma e o que precisa da sua atenção.</p>
                    </div>
                </div>

                <div class="ap-stats">
                    <?= apStat('users', $countUsers, 'Usuários ativos', $countTeachers . ' professores', $isAdmin ? 'admin-users.php' : null) ?>
                    <?= apStat('teacher', $pendingCount, 'Professores pendentes', 'aguardando validação', '#pending-teachers', $pendingCount > 0) ?>
                    <?= apStat('book', $countSubjects, 'Matérias', 'disciplinas ativas', '#mod-subjects') ?>
                    <?= apStat('course', $countCourses, 'Cursos', $countLessons . ' videoaulas', '#mod-courses') ?>
                    <?= apStat('forum', $countTopics, 'Fóruns', 'tópicos de estudo', '#mod-forums') ?>
                </div>

                <div class="ap-overview-grid">
                    <!-- Precisa de atenção -->
                    <section class="ap-card" aria-labelledby="ap-todo-title">
                        <h3 class="ap-card-title" id="ap-todo-title"><?= uiIcon('alert', 20) ?>Precisa de atenção</h3>
                        <?php if (!$pendingCount && !$draftCourses && !$emptyCourses && !$idleSubjects): ?>
                            <ul class="ap-todo">
                                <li class="ap-todo-item is-ok">
                                    <?= uiIcon('check', 20) ?>
                                    <span class="ap-todo-text"><strong>Tudo em dia</strong><small>Nenhuma pendência no momento.</small></span>
                                </li>
                            </ul>
                        <?php else: ?>
                            <ul class="ap-todo">
                                <?php if ($pendingCount): ?>
                                    <li><a class="ap-todo-item" href="#pending-teachers">
                                        <?= uiIcon('teacher', 20) ?>
                                        <span class="ap-todo-text"><strong><?= $pendingCount ?> <?= $pendingCount === 1 ? 'professor aguardando' : 'professores aguardando' ?></strong><small><?= $isAdmin ? 'Revise os comprovantes e aprove ou recuse.' : 'A aprovação final é feita por um administrador.' ?></small></span>
                                        <span class="chip chip-danger"><?= $pendingCount ?></span>
                                    </a></li>
                                <?php endif; ?>
                                <?php if ($draftCourses): ?>
                                    <li><a class="ap-todo-item" href="#mod-courses">
                                        <?= uiIcon('course', 20) ?>
                                        <span class="ap-todo-text"><strong><?= count($draftCourses) ?> <?= count($draftCourses) === 1 ? 'curso em rascunho' : 'cursos em rascunho' ?></strong><small>Ainda não aparecem para os alunos.</small></span>
                                        <span class="chip"><?= count($draftCourses) ?></span>
                                    </a></li>
                                <?php endif; ?>
                                <?php if ($emptyCourses): ?>
                                    <li><a class="ap-todo-item" href="#mod-courses">
                                        <?= uiIcon('eye', 20) ?>
                                        <span class="ap-todo-text"><strong><?= count($emptyCourses) ?> <?= count($emptyCourses) === 1 ? 'curso sem aulas' : 'cursos sem aulas' ?></strong><small>Cursos vazios podem confundir os alunos.</small></span>
                                        <span class="chip"><?= count($emptyCourses) ?></span>
                                    </a></li>
                                <?php endif; ?>
                                <?php if ($idleSubjects): ?>
                                    <li><a class="ap-todo-item" href="#mod-subjects">
                                        <?= uiIcon('book', 20) ?>
                                        <span class="ap-todo-text"><strong><?= count($idleSubjects) ?> <?= count($idleSubjects) === 1 ? 'matéria sem conteúdo' : 'matérias sem conteúdo' ?></strong><small>Sem cursos nem fóruns vinculados.</small></span>
                                        <span class="chip"><?= count($idleSubjects) ?></span>
                                    </a></li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                    <!-- Aulas ao vivo -->
                    <section class="ap-card" aria-labelledby="ap-live-title">
                        <h3 class="ap-card-title" id="ap-live-title"><?= uiIcon('live', 20) ?>Ao vivo agora <span class="chip<?= $lives ? ' chip-danger' : '' ?>"><?= count($lives) ?></span></h3>
                        <?php if (!$lives): ?>
                            <p class="ap-muted">Nenhuma transmissão no ar neste momento.</p>
                        <?php else: ?>
                            <ul class="ap-list">
                                <?php foreach ($lives as $live): ?>
                                    <li><a class="ap-live" href="live-watch.php?id=<?= (int) $live['id'] ?>">
                                        <span class="ap-live-dot" aria-hidden="true"></span>
                                        <span class="ap-todo-text">
                                            <strong class="ui-clamp-1"><?= e($live['title']) ?></strong>
                                            <small class="ui-clamp-1"><?= e($live['teacher_name']) ?><?= !empty($live['subject']) ? ' · ' . e($live['subject']) : '' ?></small>
                                        </span>
                                        <span class="chip chip-muted"><?= e(uiDuration((int) $live['elapsed_seconds'])) ?></span>
                                    </a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                    <!-- Cursos recentes -->
                    <section class="ap-card" aria-labelledby="ap-recent-title">
                        <h3 class="ap-card-title" id="ap-recent-title"><?= uiIcon('course', 20) ?>Cursos recentes</h3>
                        <?php if (!$recentCourses): ?>
                            <p class="ap-muted">Nenhum curso criado ainda.</p>
                        <?php else: ?>
                            <ul class="ap-list">
                                <?php foreach ($recentCourses as $c): ?>
                                    <li><a class="ap-todo-item" href="course.php?id=<?= (int) $c['id'] ?>">
                                        <span class="ap-todo-text">
                                            <strong class="ui-clamp-1"><?= e($c['title']) ?></strong>
                                            <small class="ui-clamp-1"><?= e($c['subject_name']) ?> · <?= e($c['teacher_name']) ?></small>
                                        </span>
                                        <span class="chip <?= $c['is_published'] ? 'chip-primary' : 'chip-draft' ?>"><?= $c['is_published'] ? 'Publicado' : 'Rascunho' ?></span>
                                    </a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>
                </div>
            </section>

            <!-- ====================== PROFESSORES ====================== -->
            <section id="pending-teachers" class="ap-panel" data-ap-panel>
                <div class="ap-panel-head">
                    <div>
                        <h2 class="ap-panel-title">Professores aguardando aprovação <span class="chip<?= $pendingCount ? ' chip-danger' : '' ?>"><?= $pendingCount ?></span></h2>
                        <p class="ap-panel-sub">Confira os dados e comprovantes antes de aprovar.</p>
                    </div>
                </div>

                <?php if (!$isAdmin): ?>
                    <div class="ui-panel ui-panel-info ui-note">Moderadores acompanham as solicitações. A aprovação ou recusa é feita por um administrador.</div>
                <?php endif; ?>

                <?php if (!$pending): ?>
                    <div class="ui-empty" id="pending-empty">
                        <strong>Nenhuma solicitação pendente no momento.</strong>
                        <span>Quando novos professores se cadastrarem, seus dados e comprovações aparecerão aqui.</span>
                    </div>
                <?php else: ?>
                    <div id="pending-teachers-list" class="ap-grid ap-grid--wide">
                        <?php foreach ($pending as $teacher):
                            $tid = (int) $teacher['user_id'];
                            $specs = parseSpecializations((string) $teacher['specializations']);
                        ?>
                            <article class="ap-card">
                                <header class="ap-teacher-head">
                                    <div class="ap-person">
                                        <?= uiAvatar($tid, (string) $teacher['name'], (bool) ($teacher['has_avatar'] ?? false)) ?>
                                        <div>
                                            <strong class="ui-clamp-1"><?= e($teacher['name']) ?></strong>
                                            <span class="ap-id">#<?= $tid ?></span>
                                            <div class="ap-muted ui-ellipsis" title="<?= e($teacher['email']) ?>"><?= e($teacher['email']) ?></div>
                                        </div>
                                    </div>
                                    <time class="ap-muted">Cadastro em <?= e(date('d/m/Y H:i', strtotime($teacher['created_at']))) ?></time>
                                </header>

                                <div>
                                    <span class="ap-label">Especializações</span>
                                    <div class="ap-chips">
                                        <?php if (!$specs): ?><span class="ap-muted">Não informadas.</span><?php endif; ?>
                                        <?php foreach (array_slice($specs, 0, 8) as $sp): ?>
                                            <span class="chip chip-accent" title="<?= e($sp) ?>"><?= e(mb_strimwidth($sp, 0, 40, '…')) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($specs) > 8): ?><span class="chip chip-muted">+<?= count($specs) - 8 ?></span><?php endif; ?>
                                    </div>
                                </div>

                                <div>
                                    <span class="ap-label">Comprovantes</span>
                                    <div class="ap-chips">
                                        <?php if (!empty($proofsByUser[$tid])): ?>
                                            <?php foreach ($proofsByUser[$tid] as $number => $proof): ?>
                                                <a class="button button-small" href="../endpoints/teacher-proof.php?id=<?= (int) $proof['id'] ?>">Comprovante <?= $number + 1 ?></a>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <a class="button button-small" href="../endpoints/teacher-proof.php?user=<?= $tid ?>">Visualizar comprovante</a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <footer class="ap-teacher-actions">
                                    <?php if ($isAdmin): ?>
                                        <form action="moderator-dashboard.php" method="post">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="user_id" value="<?= $tid ?>">
                                            <button type="submit" name="decision" value="approve" class="button button-small button-primary"
                                                data-confirm="<?= e($teacher['name']) ?> passará a ter o cargo de professor e poderá criar cursos."
                                                data-confirm-title="Aprovar professor?" data-confirm-yes="Aprovar"><?= uiIcon('check', 14) ?>Aprovar professor</button>
                                        </form>
                                        <form action="moderator-dashboard.php" method="post">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="user_id" value="<?= $tid ?>">
                                            <button type="submit" name="decision" value="reject" class="button button-small"
                                                data-confirm="A solicitação de <?= e($teacher['name']) ?> será recusada."
                                                data-confirm-title="Recusar solicitação?" data-confirm-yes="Recusar" data-confirm-tone="danger">Recusar</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="chip chip-muted"><?= uiIcon('lock', 12) ?>Aprovação restrita a administradores</span>
                                    <?php endif; ?>
                                </footer>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ====================== MATÉRIAS ====================== -->
            <section id="mod-subjects" class="ap-panel" data-ap-panel>
                <div class="ap-panel-head">
                    <div>
                        <h2 class="ap-panel-title">Matérias <span class="chip"><?= $countSubjects ?></span></h2>
                        <p class="ap-panel-sub"><?= $isAdmin ? 'Cadastre, edite e organize as disciplinas da plataforma.' : 'Somente administradores criam ou editam matérias. Você pode acompanhá-las aqui.' ?></p>
                    </div>
                </div>

                <?php if ($isAdmin): ?>
                    <details id="subject-create" class="ui-details">
                        <summary>
                            <span class="ui-summary-icon is-plus"><?= uiIcon('plus', 20) ?></span>
                            <span class="ui-summary-main"><strong>Cadastrar nova matéria</strong><small>Nome de 2 a 60 caracteres e descrição curta.</small></span>
                            <span class="ui-chevron" aria-hidden="true"></span>
                        </summary>
                        <div class="ui-details-body"><div class="ui-details-inner">
                            <form class="ui-form" action="moderator-dashboard.php" method="post">
                                <?= csrfInput() ?>
                                <input type="hidden" name="mod_action" value="create_subject">
                                <label class="ui-field">
                                    <span class="ui-field-top"><span class="ui-field-label">Nome</span></span>
                                    <input type="text" name="name" class="form-input" minlength="2" maxlength="60" required autocomplete="off" placeholder="Ex.: Redes de Computadores">
                                </label>
                                <label class="ui-field">
                                    <span class="ui-field-top"><span class="ui-field-label">Descrição</span></span>
                                    <textarea name="description" class="form-input" maxlength="250" data-max-lines="4" placeholder="Resumo curto do que a matéria cobre."></textarea>
                                </label>
                                <div class="ui-actions"><button type="submit" class="button button-primary">Salvar matéria</button></div>
                            </form>
                        </div></div>
                    </details>
                <?php endif; ?>

                <?php if (!$subjectList): ?>
                    <div class="ui-empty"><strong>Nenhuma matéria cadastrada.</strong></div>
                <?php else: ?>
                    <div data-ui-filter="#ap-subject-scope">
                        <div class="ap-toolbar">
                            <label class="ap-search">
                                <?= uiIcon('search', 18) ?><span class="ui-sr">Buscar matéria</span>
                                <input type="search" class="form-input" maxlength="80" placeholder="Buscar matéria" data-filter-input autocomplete="off">
                            </label>
                            <span class="ap-result-count"><span data-filter-count><?= $countSubjects ?></span> de <?= $countSubjects ?></span>
                        </div>
                    </div>
                    <div id="ap-subject-scope">
                        <ul class="ap-grid">
                            <?php foreach ($subjectList as $sub):
                                $linked = (int) $sub['course_count'] + (int) $sub['topic_count'];
                                $subDesc = trim((string) ($sub['description'] ?? ''));
                            ?>
                                <li class="ap-card" data-filter-item data-filter-text="<?= e($sub['name'] . ' ' . $subDesc) ?>">
                                    <div class="ap-subject-top">
                                        <span class="ap-subject-icon"><?= uiIcon('book', 20) ?></span>
                                        <h3 class="ap-subject-name ui-clamp-2" title="<?= e($sub['name']) ?>"><?= e($sub['name']) ?></h3>
                                    </div>
                                    <p class="ap-subject-desc ui-clamp-3<?= $subDesc === '' ? ' is-empty' : '' ?>"><?= $subDesc !== '' ? e($subDesc) : 'Sem descrição.' ?></p>
                                    <div class="ap-chips">
                                        <span class="chip chip-muted"><?= (int) $sub['course_count'] ?> <?= (int) $sub['course_count'] === 1 ? 'curso' : 'cursos' ?></span>
                                        <span class="chip chip-muted"><?= (int) $sub['topic_count'] ?> <?= (int) $sub['topic_count'] === 1 ? 'fórum' : 'fóruns' ?></span>
                                    </div>
                                    <?php if ($isAdmin): ?>
                                        <details class="ui-details ui-details-flat">
                                            <summary><?= uiIcon('edit', 16) ?><span class="ui-summary-main"><strong>Gerenciar matéria</strong></span><span class="ui-chevron" aria-hidden="true"></span></summary>
                                            <div class="ui-details-body"><div class="ui-details-inner">
                                                <form class="ui-form" action="moderator-dashboard.php" method="post">
                                                    <?= csrfInput() ?>
                                                    <input type="hidden" name="mod_action" value="edit_subject">
                                                    <input type="hidden" name="subject_id" value="<?= (int) $sub['id'] ?>">
                                                    <label class="ui-field">
                                                        <span class="ui-field-top"><span class="ui-field-label">Nome</span></span>
                                                        <input type="text" name="name" class="form-input" value="<?= e($sub['name']) ?>" minlength="2" maxlength="60" required autocomplete="off">
                                                    </label>
                                                    <label class="ui-field">
                                                        <span class="ui-field-top"><span class="ui-field-label">Descrição</span></span>
                                                        <textarea name="description" class="form-input" maxlength="250" data-max-lines="4"><?= e($subDesc) ?></textarea>
                                                    </label>
                                                    <div class="ui-actions"><button type="submit" class="button button-primary button-small">Salvar alterações</button></div>
                                                </form>
                                                <div class="ap-manage-actions">
                                                    <form action="moderator-dashboard.php" method="post">
                                                        <?= csrfInput() ?>
                                                        <input type="hidden" name="mod_action" value="delete_subject">
                                                        <input type="hidden" name="subject_id" value="<?= (int) $sub['id'] ?>">
                                                        <button type="submit" class="button button-small button-delete"<?= $linked > 0 ? ' disabled' : '' ?>
                                                            data-confirm="A matéria “<?= e(mb_strimwidth((string) $sub['name'], 0, 60, '…')) ?>” será excluída."
                                                            data-confirm-title="Excluir matéria?" data-confirm-yes="Excluir" data-confirm-tone="danger"><?= uiIcon('trash', 14) ?>Excluir matéria</button>
                                                    </form>
                                                    <?php if ($linked > 0): ?><p class="ui-note">Para excluir, remova antes os cursos e fóruns vinculados.</p><?php endif; ?>
                                                </div>
                                            </div></div>
                                        </details>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="ui-empty" data-filter-empty hidden><strong>Nenhuma matéria encontrada.</strong></div>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ====================== CURSOS ====================== -->
            <section id="mod-courses" class="ap-panel" data-ap-panel>
                <div class="ap-panel-head">
                    <div>
                        <h2 class="ap-panel-title">Cursos da plataforma <span class="chip"><?= $countCourses ?></span></h2>
                        <p class="ap-panel-sub"><?= $isAdmin
                            ? 'Publique, despublique ou exclua qualquer curso da plataforma.'
                            : 'Publique, despublique ou exclua cursos. A criação de conteúdo fica com os professores.' ?></p>
                    </div>
                </div>

                <?php if (!$courseList): ?>
                    <div class="ui-empty"><strong>Nenhum curso cadastrado ainda.</strong></div>
                <?php else: ?>
                    <div data-ui-filter="#ap-course-scope">
                        <div class="ap-toolbar">
                            <label class="ap-search">
                                <?= uiIcon('search', 18) ?><span class="ui-sr">Buscar curso</span>
                                <input type="search" class="form-input" maxlength="80" placeholder="Curso, matéria ou professor" data-filter-input autocomplete="off">
                            </label>
                            <div class="ap-pills" role="group" aria-label="Filtrar por situação">
                                <button type="button" class="ap-pill is-active" data-filter-tag="all" aria-pressed="true">Todos <small><?= $countCourses ?></small></button>
                                <button type="button" class="ap-pill" data-filter-tag="published" aria-pressed="false">Publicados <small><?= $publishedCount ?></small></button>
                                <button type="button" class="ap-pill" data-filter-tag="draft" aria-pressed="false">Rascunhos <small><?= count($draftCourses) ?></small></button>
                                <button type="button" class="ap-pill" data-filter-tag="empty" aria-pressed="false">Sem aulas <small><?= count($emptyCourses) ?></small></button>
                            </div>
                            <span class="ap-result-count"><span data-filter-count><?= $countCourses ?></span> de <?= $countCourses ?></span>
                        </div>
                    </div>
                    <div id="ap-course-scope">
                        <ul class="ap-list">
                            <?php foreach ($courseList as $c):
                                $cid = (int) $c['id'];
                                $pub = (bool) (int) $c['is_published'];
                                $tags = ($pub ? 'published' : 'draft') . ((int) $c['lesson_count'] === 0 ? ' empty' : '');
                            ?>
                                <li class="ap-row<?= $pub ? '' : ' is-draft' ?>" data-filter-item
                                    data-filter-text="<?= e($c['title'] . ' ' . $c['subject_name'] . ' ' . $c['teacher_name']) ?>"
                                    data-filter-tags="<?= e($tags) ?>">
                                    <div class="ap-row-main">
                                        <h3 class="ap-row-title ui-clamp-2"><a href="course.php?id=<?= $cid ?>"><?= e($c['title']) ?></a></h3>
                                        <div class="ap-row-meta">
                                            <span class="chip chip-accent" title="<?= e($c['subject_name']) ?>"><?= e($c['subject_name']) ?></span>
                                            <span class="chip <?= $pub ? 'chip-primary' : 'chip-draft' ?>"><?= $pub ? 'Publicado' : 'Rascunho' ?></span>
                                            <span class="chip chip-muted"><?= (int) $c['lesson_count'] ?> aulas</span>
                                            <span class="chip chip-muted"><?= (int) $c['student_count'] ?> alunos</span>
                                            <span class="ap-muted ui-ellipsis" title="<?= e($c['teacher_name']) ?>">Prof. <?= e($c['teacher_name']) ?></span>
                                        </div>
                                    </div>
                                    <div class="ap-row-actions">
                                        <form action="moderator-dashboard.php" method="post">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="mod_action" value="toggle_course_publish">
                                            <input type="hidden" name="course_id" value="<?= $cid ?>">
                                            <button type="submit" class="button button-small <?= $pub ? '' : 'button-primary' ?>"
                                                data-confirm="<?= $pub ? 'O curso deixará de aparecer para os alunos.' : 'O curso ficará visível para todos os alunos.' ?>"
                                                data-confirm-title="<?= $pub ? 'Despublicar curso?' : 'Publicar curso?' ?>"
                                                data-confirm-yes="<?= $pub ? 'Despublicar' : 'Publicar' ?>"><?= $pub ? 'Despublicar' : 'Publicar' ?></button>
                                        </form>
                                        <a class="button button-small" href="course.php?id=<?= $cid ?>">Acessar</a>
                                        <form action="moderator-dashboard.php" method="post">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="mod_action" value="delete_course">
                                            <input type="hidden" name="course_id" value="<?= $cid ?>">
                                            <button type="submit" class="button button-small button-delete" title="Excluir curso" aria-label="Excluir curso <?= e($c['title']) ?>"
                                                data-confirm="O curso “<?= e(mb_strimwidth((string) $c['title'], 0, 60, '…')) ?>”, suas aulas e matrículas serão apagados para sempre."
                                                data-confirm-title="Excluir curso?" data-confirm-yes="Excluir curso" data-confirm-tone="danger"><?= uiIcon('trash', 14) ?></button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="ui-empty" data-filter-empty hidden><strong>Nenhum curso encontrado com esses filtros.</strong></div>
                    </div>
                <?php endif; ?>
            </section>

            <!-- ====================== FÓRUNS ====================== -->
            <section id="mod-forums" class="ap-panel" data-ap-panel>
                <div class="ap-panel-head">
                    <div>
                        <h2 class="ap-panel-title">Tópicos dos fóruns <span class="chip"><?= count($forumTopics) ?></span></h2>
                        <p class="ap-panel-sub">Os 30 tópicos mais recentes. Para denúncias e sanções use a fila de moderação.</p>
                    </div>
                    <a class="button button-small" href="moderation.php"><?= uiIcon('shield', 16) ?>Fila de moderação</a>
                </div>

                <?php if (!$forumTopics): ?>
                    <div class="ui-empty"><strong>Nenhum tópico criado ainda.</strong></div>
                <?php else: ?>
                    <div data-ui-filter="#ap-forum-scope">
                        <div class="ap-toolbar">
                            <label class="ap-search">
                                <?= uiIcon('search', 18) ?><span class="ui-sr">Buscar tópico</span>
                                <input type="search" class="form-input" maxlength="80" placeholder="Título, matéria ou autor" data-filter-input autocomplete="off">
                            </label>
                            <span class="ap-result-count"><span data-filter-count><?= count($forumTopics) ?></span> de <?= count($forumTopics) ?></span>
                        </div>
                    </div>
                    <div id="ap-forum-scope">
                        <ul class="ap-list">
                            <?php foreach ($forumTopics as $topic): ?>
                                <li class="ap-row" data-filter-item data-filter-text="<?= e($topic['title'] . ' ' . $topic['subject_name'] . ' ' . $topic['author']) ?>">
                                    <div class="ap-row-main">
                                        <h3 class="ap-row-title ui-clamp-2"><a href="forum-topic.php?id=<?= (int) $topic['id'] ?>"><?= e($topic['title']) ?></a></h3>
                                        <div class="ap-row-meta">
                                            <span class="chip chip-accent" title="<?= e($topic['subject_name']) ?>"><?= e($topic['subject_name']) ?></span>
                                            <span class="chip chip-muted"><?= (int) $topic['post_count'] ?> <?= (int) $topic['post_count'] === 1 ? 'resposta' : 'respostas' ?></span>
                                            <span class="ap-muted ui-ellipsis">por <?= e($topic['author']) ?> · <?= e(date('d/m/Y', strtotime($topic['created_at']))) ?></span>
                                        </div>
                                    </div>
                                    <div class="ap-row-actions">
                                        <a class="button button-small" href="forum-topic.php?id=<?= (int) $topic['id'] ?>">Revisar</a>
                                        <form action="moderator-dashboard.php" method="post">
                                            <?= csrfInput() ?>
                                            <input type="hidden" name="forum_topic_id" value="<?= (int) $topic['id'] ?>">
                                            <button type="submit" class="button button-small button-delete" aria-label="Excluir tópico <?= e($topic['title']) ?>"
                                                data-confirm="O tópico e todas as mensagens dele serão apagados."
                                                data-confirm-title="Excluir tópico?" data-confirm-yes="Excluir tópico" data-confirm-tone="danger"><?= uiIcon('trash', 14) ?>Excluir</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="ui-empty" data-filter-empty hidden><strong>Nenhum tópico encontrado.</strong></div>
                    </div>
                <?php endif; ?>
            </section>

        </div>
    </div>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
