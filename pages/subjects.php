<?php
require_once __DIR__ . '/../includes/courses.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/ui-helpers.php';
require_once __DIR__ . '/../includes/ui-icons.php';

const HUB_TITLE_MAX = 100;
const HUB_DESC_MAX = 500;

function hubUrl(int $subjectId = 0, string $view = 'todos', string $hash = '#course-list'): string
{
    $params = [];
    if ($subjectId > 0) {
        $params['subject_id'] = $subjectId;
    }
    if ($view !== 'todos') {
        $params['view'] = $view;
    }
    return 'subjects.php' . ($params ? '?' . http_build_query($params) : '') . $hash;
}

$role = (string) ($user['role'] ?? '');
$userId = (int) ($user['id'] ?? 0);
$isStudent = $role === 'student';
$isTeacher = $role === 'teacher';
$isAdmin = $role === 'admin';
$isModerator = $role === 'moderator';
$canCreateCourse = $isTeacher || $isAdmin;
$canSeeDrafts = $isAdmin || $isModerator;
$canModerateCourses = $canSeeDrafts;

// ---------------------------------------------------------------
// Dados
// ---------------------------------------------------------------
$subjects = [];
try {
    $subjects = $pdo->query('SELECT * FROM subjects ORDER BY name')->fetchAll();
} catch (Throwable $e) {
}

$selectedSubject = null;
$requestedSubject = (int) ($_GET['subject_id'] ?? 0);
foreach ($subjects as $s) {
    if ((int) $s['id'] === $requestedSubject) {
        $selectedSubject = $s;
        break;
    }
}
$subjectId = $selectedSubject ? (int) $selectedSubject['id'] : 0;

$myEnrolledSubjects = [];
$myEnrolledCourses = [];
if ($isStudent) {
    try {
        $stmt = $pdo->prepare('SELECT subject_id FROM subject_enrollments WHERE student_id = ?');
        $stmt->execute([$userId]);
        $myEnrolledSubjects = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
    }
    try {
        $stmt = $pdo->prepare('SELECT course_id FROM course_enrollments WHERE student_id = ?');
        $stmt->execute([$userId]);
        $myEnrolledCourses = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
    }
}
$isEnrolledInSelected = $selectedSubject && in_array($subjectId, $myEnrolledSubjects, true);

$allCourses = [];
try {
    $stmt = $pdo->prepare(
        'SELECT c.*, s.name AS subject_name, u.name AS teacher_name,
                (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id AND l.is_published = 1) AS lesson_count
           FROM courses c
           JOIN subjects s ON s.id = c.subject_id
           JOIN users u ON u.id = c.teacher_id AND u.activate = 1
          WHERE (c.is_published = 1 OR c.teacher_id = ? OR ? = 1)
          ORDER BY c.created_at DESC, c.id DESC'
    );
    $stmt->execute([$userId, $canSeeDrafts ? 1 : 0]);
    $allCourses = $stmt->fetchAll();
} catch (Throwable $e) {
}

$countBySubject = [];
foreach ($allCourses as $c) {
    $sid = (int) $c['subject_id'];
    $countBySubject[$sid] = ($countBySubject[$sid] ?? 0) + 1;
}

$scopeCourses = $subjectId > 0
    ? array_values(array_filter($allCourses, fn($c) => (int) $c['subject_id'] === $subjectId))
    : $allCourses;

$allowedViews = ['todos'];
if ($isTeacher) {
    $allowedViews[] = 'meus';
}
if ($canSeeDrafts) {
    $allowedViews[] = 'rascunhos';
}
$view = in_array($_GET['view'] ?? '', $allowedViews, true) ? (string) $_GET['view'] : 'todos';

$visibleCourses = array_values(array_filter($scopeCourses, function ($c) use ($view, $userId) {
    if ($view === 'meus') {
        return (int) $c['teacher_id'] === $userId;
    }
    if ($view === 'rascunhos') {
        return !(int) $c['is_published'];
    }
    return true;
}));

$draftCount = count(array_filter($scopeCourses, fn($c) => !(int) $c['is_published']));
$myCount = count(array_filter($scopeCourses, fn($c) => (int) $c['teacher_id'] === $userId));

// ---------------------------------------------------------------
// Textos por perfil
// ---------------------------------------------------------------
if ($isTeacher) {
    $heroTitle = 'Seus cursos e matérias';
    $heroLead = 'Crie cursos, publique suas aulas e acompanhe o que ainda está em rascunho.';
} elseif ($isAdmin) {
    $heroTitle = 'Gestão de matérias e cursos';
    $heroLead = 'Acompanhe todos os cursos da plataforma, inclusive rascunhos, e crie novos conteúdos.';
} elseif ($isModerator) {
    $heroTitle = 'Moderação de cursos';
    $heroLead = 'Acompanhe os cursos da plataforma e publique ou despublique conteúdos quando necessário.';
} else {
    $heroTitle = 'Seu espaço de aprendizado';
    $heroLead = 'Escolha uma matéria, matricule-se e desbloqueie todas as videoaulas e materiais de apoio.';
}

$heroStats = [
    ['value' => count($subjects), 'label' => 'matérias'],
    ['value' => count($allCourses), 'label' => $canSeeDrafts ? 'cursos na plataforma' : 'cursos disponíveis'],
];
if ($isStudent) {
    $heroStats[] = ['value' => count($myEnrolledSubjects), 'label' => 'matrículas ativas'];
}
if ($isTeacher) {
    $heroStats[] = ['value' => count(array_filter($allCourses, fn($c) => (int) $c['teacher_id'] === $userId)), 'label' => 'meus cursos'];
}
if ($canSeeDrafts) {
    $heroStats[] = ['value' => count(array_filter($allCourses, fn($c) => !(int) $c['is_published'])), 'label' => 'rascunhos'];
}

$pageId = 'subjects';
$pageTitle = $selectedSubject ? $selectedSubject['name'] . ' | Matérias' : 'Matérias';
$baseUrl = '../';
$extraCss = ['ui-kit', 'subjects-hub'];
$extraJs = ['ui-kit'];
include __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content sh-main">

    <!-- ====================== HERO ====================== -->
    <header class="sh-hero<?= $role !== '' ? ' sh-hero--' . e($role) : '' ?>">
        <div class="sh-hero-top">
            <?php if ($user): ?><?= uiRoleBadge($role) ?><?php endif; ?>
        </div>
        <h1 class="sh-hero-title"><?= e($heroTitle) ?></h1>
        <p class="sh-hero-lead"><?= e($heroLead) ?></p>
        <ul class="sh-stats" aria-label="Resumo">
            <?php foreach ($heroStats as $stat): ?>
                <li class="sh-stat"><strong><?= (int) $stat['value'] ?></strong><span><?= e($stat['label']) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <div class="sh-hero-actions">
            <a class="button button-primary" href="#course-list">Explorar cursos</a>
            <?php if ($canCreateCourse): ?>
                <a class="button" href="#course-create"><?= uiIcon('plus', 18) ?>Criar curso</a>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
                <a class="button" href="moderator-dashboard.php"><?= uiIcon('shield', 18) ?>Painel da administração</a>
            <?php elseif ($isModerator): ?>
                <a class="button" href="moderator-dashboard.php#mod-courses"><?= uiIcon('shield', 18) ?>Painel de moderação</a>
                <a class="button" href="moderation.php">Fila de moderação</a>
            <?php elseif ($isTeacher): ?>
                <a class="button" href="teacher-dashboard.php">Painel do professor</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="sh-layout">

        <!-- ====================== LATERAL: MATÉRIAS ====================== -->
        <aside class="sh-side" aria-label="Matérias">
            <h2 class="sh-side-title"><span>Matérias</span><span class="sh-count"><?= count($subjects) ?></span></h2>
            <?php if (count($subjects) > 7): ?>
                <div data-ui-filter="#sh-subject-nav">
                    <label class="sh-search">
                        <?= uiIcon('search', 18) ?>
                        <span class="ui-sr">Buscar matéria</span>
                        <input type="search" class="form-input" maxlength="60" placeholder="Buscar matéria" data-filter-input autocomplete="off">
                    </label>
                </div>
            <?php endif; ?>
            <nav id="sh-subject-nav" class="sh-subject-list" aria-label="Lista de matérias">
                <a class="sh-subject<?= $subjectId === 0 ? ' is-active' : '' ?>" href="<?= e(hubUrl(0, $view === 'rascunhos' || $view === 'meus' ? $view : 'todos')) ?>"
                    <?= $subjectId === 0 ? ' aria-current="page"' : '' ?>>
                    <?= uiIcon('list', 18) ?>
                    <span class="sh-subject-name ui-ellipsis">Todas as matérias</span>
                    <span class="sh-count"><?= count($allCourses) ?></span>
                </a>
                <?php foreach ($subjects as $subject):
                    $sId = (int) $subject['id'];
                ?>
                    <a class="sh-subject<?= $subjectId === $sId ? ' is-active' : '' ?>"
                        href="<?= e(hubUrl($sId, $view === 'rascunhos' || $view === 'meus' ? $view : 'todos')) ?>"
                        data-filter-item data-filter-text="<?= e($subject['name']) ?>"
                        <?= $subjectId === $sId ? ' aria-current="page"' : '' ?>>
                        <?php if ($isStudent && in_array($sId, $myEnrolledSubjects, true)): ?>
                            <span class="sh-dot" title="Você está matriculado"></span>
                        <?php else: ?>
                            <?= uiIcon('book', 18) ?>
                        <?php endif; ?>
                        <span class="sh-subject-name ui-ellipsis" title="<?= e($subject['name']) ?>"><?= e($subject['name']) ?></span>
                        <span class="sh-count"><?= (int) ($countBySubject[$sId] ?? 0) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <!-- ====================== CONTEÚDO ====================== -->
        <div class="sh-content">

            <!-- Cabeçalho da matéria / matrícula -->
            <?php if ($selectedSubject): ?>
                <section id="subject-enrollment-panel" class="sh-subject-head" aria-labelledby="sh-subject-title">
                    <div class="sh-subject-chips">
                        <span class="chip chip-accent"><?= uiIcon('book', 14) ?>Matéria</span>
                        <?php if ($isEnrolledInSelected): ?><span class="chip chip-primary"><?= uiIcon('check', 14) ?>Matrícula ativa</span><?php endif; ?>
                    </div>
                    <h2 id="sh-subject-title"><?= e($selectedSubject['name']) ?></h2>
                    <?php if (!empty($selectedSubject['description'])): ?>
                        <p><?= e($selectedSubject['description']) ?></p>
                    <?php endif; ?>
                    <div class="sh-subject-chips">
                        <a class="button button-small" href="forum.php?subject=<?= $subjectId ?>"><?= uiIcon('forum', 16) ?>Fóruns da matéria</a>
                    </div>

                    <?php if (!$user): ?>
                        <div class="sh-enroll">
                            <p>Entre na sua conta de aluno para se matricular nesta matéria e liberar todas as aulas e materiais.</p>
                            <a class="button button-primary button-small"
                                href="../auth/login.php?next=pages/subjects.php%3Fsubject_id%3D<?= $subjectId ?>%23course-list">Entrar para se matricular</a>
                        </div>
                    <?php elseif ($isStudent): ?>
                        <?php if ($isEnrolledInSelected): ?>
                            <div class="sh-enroll is-on">
                                <p><strong>Você está matriculado.</strong> Todas as videoaulas, salas virtuais e materiais dos cursos abaixo estão liberados.</p>
                                <form method="post" action="../endpoints/course.php">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="action" value="leave_subject">
                                    <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
                                    <button type="submit" class="button button-small"
                                        data-confirm="Você perderá o acesso às aulas e materiais de <?= e($selectedSubject['name']) ?> até se matricular de novo."
                                        data-confirm-title="Cancelar matrícula?" data-confirm-yes="Cancelar matrícula" data-confirm-tone="danger">Cancelar matrícula</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="sh-enroll">
                                <p>Matricule-se para ter acesso imediato a todas as videoaulas, apostilas e salas dos cursos desta matéria.</p>
                                <form method="post" action="../endpoints/course.php">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="action" value="enroll_subject">
                                    <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
                                    <button type="submit" class="button button-primary button-small">Matricular-se nesta matéria</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="sh-enroll">
                            <p>
                                <?php if ($isTeacher): ?>
                                    Matrículas são feitas pelos alunos. Aqui você acompanha os cursos desta matéria e cria os seus.
                                <?php elseif ($isAdmin): ?>
                                    Você vê todos os cursos desta matéria, inclusive rascunhos. Matérias são editadas no painel da administração.
                                <?php else: ?>
                                    Você pode publicar ou despublicar cursos desta matéria. A criação de conteúdo fica com os professores.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="sh-subject-head" aria-labelledby="sh-subject-title">
                    <h2 id="sh-subject-title">Todos os cursos</h2>
                    <p>Escolha uma matéria na lista ao lado para filtrar os cursos, se matricular e acessar os fóruns dela.</p>
                </section>
            <?php endif; ?>

            <!-- Criar curso (professor e admin) -->
            <?php if ($canCreateCourse): ?>
                <details id="course-create" class="ui-details sh-create"<?= isset($_GET['criar']) ? ' open' : '' ?>>
                    <summary>
                        <span class="ui-summary-icon is-plus"><?= uiIcon('plus', 20) ?></span>
                        <span class="ui-summary-main">
                            <strong>Criar novo curso</strong>
                            <small>Defina título, matéria e se ele já ficará publicado.</small>
                        </span>
                        <span class="ui-chevron" aria-hidden="true"></span>
                    </summary>
                    <div class="ui-details-body">
                        <div class="ui-details-inner">
                            <form class="ui-form" method="post" action="../endpoints/course.php">
                                <?= csrfInput() ?>
                                <input type="hidden" name="action" value="create">
                                <label class="ui-field">
                                    <span class="ui-field-top"><span class="ui-field-label">Título</span></span>
                                    <input class="form-input" name="title" maxlength="<?= HUB_TITLE_MAX ?>" required autocomplete="off">
                                </label>
                                <label class="ui-field">
                                    <span class="ui-field-top"><span class="ui-field-label">Descrição</span></span>
                                    <textarea class="form-input" name="description" maxlength="<?= HUB_DESC_MAX ?>" data-max-lines="8"
                                        placeholder="Conte em poucas linhas o que o aluno vai aprender."></textarea>
                                </label>
                                <label class="ui-field">
                                    <span class="ui-field-top"><span class="ui-field-label">Matéria</span></span>
                                    <select class="form-input" name="subject_id" required>
                                        <?php foreach ($subjects as $subject): ?>
                                            <option value="<?= (int) $subject['id'] ?>" <?= $subjectId === (int) $subject['id'] ? 'selected' : '' ?>>
                                                <?= e(mb_strimwidth((string) $subject['name'], 0, 70, '…')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="ui-check">
                                    <input type="checkbox" name="is_published" checked>
                                    <span>Publicar agora (visível para os alunos)</span>
                                </label>
                                <div class="ui-actions">
                                    <button type="submit" class="button button-primary">Criar curso</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </details>
            <?php endif; ?>

            <!-- Lista de cursos -->
            <section id="course-list" aria-label="Cursos">
                <div class="sh-content" data-ui-filter="#sh-course-scope">
                    <div class="sh-list-head">
                        <h2>
                            <span>Cursos<?= $selectedSubject ? ' de ' . e(mb_strimwidth((string) $selectedSubject['name'], 0, 60, '…')) : '' ?></span>
                            <span class="sh-count" data-filter-count><?= count($visibleCourses) ?></span>
                        </h2>
                    </div>

                    <div class="sh-toolbar">
                        <label class="sh-search">
                            <?= uiIcon('search', 18) ?>
                            <span class="ui-sr">Buscar curso</span>
                            <input type="search" class="form-input" maxlength="80" placeholder="Buscar por curso ou professor" data-filter-input autocomplete="off">
                        </label>
                        <?php if (count($allowedViews) > 1): ?>
                            <nav class="sh-pills" aria-label="Filtrar cursos">
                                <a class="sh-pill<?= $view === 'todos' ? ' is-active' : '' ?>" href="<?= e(hubUrl($subjectId, 'todos')) ?>">Todos (<?= count($scopeCourses) ?>)</a>
                                <?php if ($isTeacher): ?>
                                    <a class="sh-pill<?= $view === 'meus' ? ' is-active' : '' ?>" href="<?= e(hubUrl($subjectId, 'meus')) ?>">Meus cursos (<?= $myCount ?>)</a>
                                <?php endif; ?>
                                <?php if ($canSeeDrafts): ?>
                                    <a class="sh-pill<?= $view === 'rascunhos' ? ' is-active' : '' ?>" href="<?= e(hubUrl($subjectId, 'rascunhos')) ?>">Rascunhos (<?= $draftCount ?>)</a>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    </div>

                    <div id="sh-course-scope">
                        <?php if (!$visibleCourses): ?>
                            <div class="ui-empty">
                                <strong>
                                    <?php if ($view === 'rascunhos'): ?>Nenhum rascunho no momento.
                                    <?php elseif ($view === 'meus'): ?>Você ainda não criou cursos nesta seleção.
                                    <?php else: ?>Nenhum curso disponível nesta seleção.<?php endif; ?>
                                </strong>
                                <?php if ($selectedSubject || $view !== 'todos'): ?>
                                    <a class="button button-small" href="subjects.php#course-list">Ver todos os cursos</a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <ul class="sh-grid">
                                <?php foreach ($visibleCourses as $index => $course):
                                    $cId = (int) $course['id'];
                                    $published = (bool) (int) $course['is_published'];
                                    $isOwn = (int) $course['teacher_id'] === $userId;
                                    $hasAccess = $isStudent && (in_array($cId, $myEnrolledCourses, true)
                                        || in_array((int) $course['subject_id'], $myEnrolledSubjects, true));
                                    $lessonCount = (int) ($course['lesson_count'] ?? 0);
                                    $searchText = $course['title'] . ' ' . $course['teacher_name'] . ' ' . $course['subject_name'];
                                ?>
                                    <li class="sh-card<?= !$published ? ' is-draft' : '' ?>" style="--i: <?= min($index, 8) ?>"
                                        data-filter-item data-filter-text="<?= e($searchText) ?>">
                                        <div class="sh-card-top">
                                            <?php if (!$selectedSubject): ?><span class="chip chip-accent" title="<?= e($course['subject_name']) ?>"><?= e($course['subject_name']) ?></span><?php endif; ?>
                                            <?php if (!$published): ?><span class="chip chip-draft">Rascunho</span><?php endif; ?>
                                            <?php if ($isTeacher && $isOwn): ?><span class="chip chip-primary">Meu curso</span><?php endif; ?>
                                            <?php if ($hasAccess): ?><span class="chip chip-primary"><?= uiIcon('check', 12) ?>Matriculado</span><?php endif; ?>
                                        </div>
                                        <h3 class="sh-card-title ui-clamp-2">
                                            <a href="course.php?id=<?= $cId ?>"><?= e($course['title']) ?></a>
                                        </h3>
                                        <?php $desc = trim((string) $course['description']); ?>
                                        <p class="sh-card-desc ui-clamp-3<?= $desc === '' ? ' is-empty' : '' ?>"><?= $desc !== '' ? e($desc) : 'Este curso ainda não tem descrição.' ?></p>
                                        <div class="sh-card-meta">
                                            <span class="chip chip-muted"><?= $lessonCount ?> <?= $lessonCount === 1 ? 'aula' : 'aulas' ?></span>
                                        </div>
                                        <div class="sh-card-foot">
                                            <span class="sh-teacher">
                                                <span class="sh-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $course['teacher_name'], 0, 1))) ?></span>
                                                <span class="ui-ellipsis" title="<?= e($course['teacher_name']) ?>"><?= e($course['teacher_name']) ?></span>
                                            </span>
                                            <span class="sh-open">Abrir <?= uiIcon('arrow', 14) ?></span>
                                        </div>
                                        <?php if ($canModerateCourses): ?>
                                            <form class="sh-card-actions" method="post" action="moderator-dashboard.php">
                                                <?= csrfInput() ?>
                                                <input type="hidden" name="mod_action" value="toggle_course_publish">
                                                <input type="hidden" name="course_id" value="<?= $cId ?>">
                                                <input type="hidden" name="return" value="subjects">
                                                <input type="hidden" name="subject_id" value="<?= $subjectId ?>">
                                                <input type="hidden" name="view" value="<?= e($view) ?>">
                                                <button type="submit" class="button button-small <?= $published ? '' : 'button-primary' ?>"
                                                    data-confirm="<?= $published ? 'O curso deixará de aparecer para os alunos.' : 'O curso ficará visível para todos os alunos.' ?>"
                                                    data-confirm-title="<?= $published ? 'Despublicar curso?' : 'Publicar curso?' ?>"
                                                    data-confirm-yes="<?= $published ? 'Despublicar' : 'Publicar' ?>">
                                                    <?= $published ? 'Despublicar' : 'Publicar' ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="ui-empty" data-filter-empty hidden>
                                <strong>Nenhum curso encontrado para essa busca.</strong>
                                <span>Tente outro nome de curso ou professor.</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/rodape.php'; ?>
