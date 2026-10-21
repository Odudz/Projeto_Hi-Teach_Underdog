<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/forum-upload.php';
require_once __DIR__ . '/../includes/forum-rules.php';

const FORUM_PER_PAGE = 12;
const FORUM_SHELF_LIMIT = 12;

$subjects = $pdo->query('SELECT id, name FROM subjects ORDER BY name')->fetchAll();
$subjectIds = array_map('intval', array_column($subjects, 'id'));

$newSubject = 0;
$newTitle = '';
$newBody = '';

$studentForumCount = 0;
if ($user && $user['role'] === 'student') {
    $stmtStudentForums = $pdo->prepare('SELECT COUNT(*) FROM forum_topics WHERE user_id = ?');
    $stmtStudentForums->execute([$user['id']]);
    $studentForumCount = (int) $stmtStudentForums->fetchColumn();
}

$studentForumLimit = FORUM_STUDENT_LIMIT;
$studentCanCreateForum = $user && $user['role'] === 'student' && $studentForumCount < $studentForumLimit;
$canCreateForum = $user && (
    in_array($user['role'], ['teacher', 'admin'], true) || $studentCanCreateForum
);
$canUploadForumFiles = $user && in_array($user['role'], ['teacher', 'admin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) {
        $gate = 'login';
        $baseUrl = '../';
        include_once __DIR__ . '/../includes/acesso-restrito.php';
    }

    if (forumExceedsPostMaxSize()) {
        setFlash('error', 'O envio excedeu o limite total permitido pelo servidor. Reduza a quantidade/tamanho dos anexos e tente novamente.');
        redirect('forum.php');
    }

    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Sua sessão expirou para esta ação. Recarregue a página e tente novamente.');
        redirect('forum.php');
    }

    if (!$canCreateForum) {
        if ($user['role'] === 'student') {
            setFlash('error', 'Você já atingiu o limite de ' . FORUM_STUDENT_LIMIT . ' fóruns criados como aluno.');
        } else {
            setFlash('error', 'Apenas professores aprovados, administradores e alunos dentro do limite podem criar fóruns.');
        }
        redirect('forum.php');
    }

    if ($user['role'] === 'student') {
        $stmtStudentForums = $pdo->prepare('SELECT COUNT(*) FROM forum_topics WHERE user_id = ?');
        $stmtStudentForums->execute([$user['id']]);
        if ((int) $stmtStudentForums->fetchColumn() >= $studentForumLimit) {
            setFlash('error', 'Você já atingiu o limite de ' . FORUM_STUDENT_LIMIT . ' fóruns criados como aluno.');
            redirect('forum.php');
        }
    }

    $newSubject = (int) ($_POST['subject_id'] ?? 0);
    $newTitle = forumCleanTitle((string) ($_POST['title'] ?? ''));
    $newBody = forumCleanBody((string) ($_POST['body'] ?? ''));

    if (!in_array($newSubject, $subjectIds, true)) {
        setFlash('error', 'Escolha uma matéria válida.');
    } elseif (($forumProblem = forumValidate($newTitle, $newBody)) !== null) {
        setFlash('error', $forumProblem);
    } else {
        $pdo->beginTransaction();
        try {
            if ($user['role'] === 'student') {
                $stmtLockUser = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
                $stmtLockUser->execute([$user['id']]);

                $stmtStudentForums = $pdo->prepare('SELECT COUNT(*) FROM forum_topics WHERE user_id = ?');
                $stmtStudentForums->execute([$user['id']]);
                if ((int) $stmtStudentForums->fetchColumn() >= $studentForumLimit) {
                    throw new RuntimeException('Você já atingiu o limite de ' . FORUM_STUDENT_LIMIT . ' fóruns criados como aluno.');
                }
            }

            $pdo->prepare('INSERT INTO forum_topics (subject_id, user_id, title, body) VALUES (?, ?, ?, ?)')
                ->execute([$newSubject, $user['id'], $newTitle, $newBody]);
            $topicId = (int) $pdo->lastInsertId();

            if (!empty($_FILES['attachments']['name'][0])) {
                if (!$canUploadForumFiles) {
                    throw new RuntimeException('Apenas professores aprovados e administradores podem enviar anexos no fórum.');
                }
                saveForumAttachments($_FILES['attachments'], $topicId, null, $pdo);
            }

            $pdo->commit();
            setFlash('success', 'Fórum criado!');
            redirect('forum-topic.php?id=' . $topicId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('forum.php:create_topic_failed: ' . $e->getMessage());
            setFlash('error', 'Não foi possível criar o fórum com os anexos informados.');
        }
    }
}

$filter = (int) ($_GET['subject'] ?? 0);
$search = forumCleanTitle((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 60) {
    $search = mb_substr($search, 0, 60);
}

$baseWhere = [];
$baseParams = [];
if ($filter) {
    $baseWhere[] = 't.subject_id = ?';
    $baseParams[] = $filter;
}
if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\') . '%';
    $baseWhere[] = '(t.title LIKE ? OR t.body LIKE ?)';
    $baseParams[] = $like;
    $baseParams[] = $like;
}

function forumQuery(PDO $pdo, array $where, array $params, int $limit, int $offset = 0): array
{
    $clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare(
        "SELECT t.id, t.user_id, t.title, t.body, t.is_closed, t.created_at, s.name AS subject,
                u.id AS author_id, u.name AS author, u.avatar_mime AS author_avatar_mime,
                (SELECT COUNT(*) FROM forum_posts p JOIN users pu ON pu.id = p.user_id AND pu.activate = 1 WHERE p.topic_id = t.id) AS replies,
                TIMESTAMPDIFF(SECOND, GREATEST(t.created_at, COALESCE((SELECT MAX(p2.created_at) FROM forum_posts p2 WHERE p2.topic_id = t.id), t.created_at)), NOW()) AS idle
         FROM forum_topics t
         JOIN subjects s ON s.id = t.subject_id
         JOIN users u ON u.id = t.user_id AND u.activate = 1
         $clause
         ORDER BY idle ASC, t.id DESC
         LIMIT $limit OFFSET $offset"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function forumCount(PDO $pdo, array $where, array $params): int
{
    $clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM forum_topics t JOIN users u ON u.id = t.user_id AND u.activate = 1 $clause");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$totalTopics = forumCount($pdo, $baseWhere, $baseParams);
$totalPages = max(1, (int) ceil($totalTopics / FORUM_PER_PAGE));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
$topics = forumQuery($pdo, $baseWhere, $baseParams, FORUM_PER_PAGE, ($page - 1) * FORUM_PER_PAGE);

$mine = [];
$joined = [];
$mineTotal = 0;
$joinedTotal = 0;
if ($user) {
    $whereMine = array_merge($baseWhere, ['t.user_id = ?']);
    $paramsMine = array_merge($baseParams, [(int) $user['id']]);
    $mineTotal = forumCount($pdo, $whereMine, $paramsMine);
    $mine = forumQuery($pdo, $whereMine, $paramsMine, FORUM_SHELF_LIMIT);

    $whereJoined = array_merge($baseWhere, ['t.user_id <> ?', 'EXISTS (SELECT 1 FROM forum_posts jp WHERE jp.topic_id = t.id AND jp.user_id = ?)']);
    $paramsJoined = array_merge($baseParams, [(int) $user['id'], (int) $user['id']]);
    $joinedTotal = forumCount($pdo, $whereJoined, $paramsJoined);
    $joined = forumQuery($pdo, $whereJoined, $paramsJoined, FORUM_SHELF_LIMIT);
}

$urlFor = fn (array $extra = []) => 'forum.php' . (($query = http_build_query(array_filter(array_merge(['subject' => $filter, 'q' => $search], $extra), fn ($value) => $value !== '' && $value !== 0 && $value !== null))) !== '' ? '?' . $query : '');

$previewTopic = [
    'id' => 0,
    'title' => $newTitle,
    'body' => $newBody,
    'is_closed' => 0,
    'subject' => '',
    'author_id' => $user ? (int) $user['id'] : 0,
    'author' => $user ? $user['name'] : '',
    'author_avatar_mime' => $user ? $user['avatar_mime'] : null,
    'replies' => 0,
    'idle' => 0,
];
foreach ($subjects as $subject) {
    if ((int) $subject['id'] === $newSubject) {
        $previewTopic['subject'] = $subject['name'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $openModal = 'forum-new-modal';
}

if ($canCreateForum) {
    ob_start();
    ?>
<div id="forum-new-modal" class="modal-overlay">
  <section class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="forum-new-title">
    <header class="modal-header">
      <h2 id="forum-new-title" class="modal-title">Novo fórum</h2>
      <button type="button" class="modal-close" data-modal-close aria-label="Fechar">×</button>
    </header>
    <div class="forum-new-grid">
      <form id="forum-new-form" class="form" action="forum.php" method="post" enctype="multipart/form-data">
        <?= csrfInput() ?>
        <div class="form-group">
          <label class="form-label" for="new-subject">Matéria</label>
          <select id="new-subject" name="subject_id" class="form-input" required>
            <option value="">Selecione</option>
            <?php foreach ($subjects as $subject): ?>
              <option value="<?= (int) $subject['id'] ?>"<?= $newSubject === (int) $subject['id'] ? ' selected' : '' ?>><?= e($subject['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <div class="form-label-row">
            <label class="form-label" for="new-title">Título</label>
            <small class="field-counter" data-count="title" data-max="<?= FORUM_TITLE_MAX ?>">0/<?= FORUM_TITLE_MAX ?></small>
          </div>
          <input type="text" id="new-title" name="title" class="form-input" maxlength="<?= FORUM_TITLE_MAX ?>" value="<?= e($newTitle) ?>" autocomplete="off" required>
        </div>

        <div class="form-group">
          <div class="form-label-row">
            <label class="form-label" for="new-body">Descrição</label>
            <small class="field-counter" data-count="body" data-max="<?= FORUM_BODY_MAX ?>">0/<?= FORUM_BODY_MAX ?></small>
          </div>
          <textarea id="new-body" name="body" class="form-input forum-new-body" rows="5" maxlength="<?= FORUM_BODY_MAX ?>" required><?= e($newBody) ?></textarea>
        </div>

        <?php if ($canUploadForumFiles): ?>
          <div class="form-group">
            <label class="form-label" for="new-attachments">Anexos</label>
            <input type="file" id="new-attachments" name="attachments[]" class="form-input" multiple
                   accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip">
          </div>
        <?php endif; ?>

        <button type="submit" id="forum-new-form-submit" class="button button-primary">Publicar fórum</button>
      </form>

      <aside class="forum-preview" aria-label="Prévia do fórum">
        <span class="forum-preview-label">Prévia</span>
        <ul class="forum-grid forum-grid-single">
          <?= forumRenderTile($previewTopic, 0, true) ?>
        </ul>
      </aside>
    </div>
  </section>
</div>
    <?php
    $modals = ob_get_clean();
}

$pageId = 'forum';
$pageTitle = 'Fóruns';
$extraCss = ['forum'];
$extraJs = ['forum-hub'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';

$renderShelf = function (string $id, string $title, array $items, int $total) use ($user): void {
    if (!$items) {
        return;
    }
    ?>
    <section id="<?= e($id) ?>" class="section forum-shelf">
      <h2 class="section-title"><?= e($title) ?> <span class="dm-count"><?= $total ?></span></h2>
      <ul class="forum-grid">
        <?php foreach ($items as $index => $topic): ?>
          <?= forumRenderTile($topic + ['is_owner' => $user && (int) $topic['user_id'] === (int) $user['id']], $index) ?>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php
};
?>
<main id="main-content" class="main-content">
  <section id="forum-header" class="section forum-hero">
    <span class="forum-hero-deco forum-hero-deco-a" aria-hidden="true"></span>
    <span class="forum-hero-deco forum-hero-deco-b" aria-hidden="true"></span>
    <span class="forum-hero-deco forum-hero-deco-c" aria-hidden="true"></span>
    <h1 class="forum-hero-title">Fóruns de estudo</h1>

    <ul class="forum-stats" aria-label="Resumo dos fóruns">
      <li class="forum-stat"><strong><?= $totalTopics ?></strong><span><?= $totalTopics === 1 ? 'fórum' : 'fóruns' ?></span></li>
      <li class="forum-stat"><strong><?= count($subjects) ?></strong><span>matérias</span></li>
    </ul>

    <div class="forum-hero-tools">
      <?php if ($canCreateForum): ?>
        <button type="button" class="button button-primary" data-modal-open="forum-new-modal">Novo fórum</button>
        <?php if ($user['role'] === 'student'): ?>
          <span class="forum-chip"><?= $studentForumCount ?> de <?= $studentForumLimit ?> fóruns</span>
        <?php endif; ?>
      <?php elseif ($user && $user['role'] === 'student'): ?>
        <span class="forum-chip"><?= $studentForumCount ?> de <?= $studentForumLimit ?> fóruns</span>
      <?php elseif (!$user): ?>
        <a class="button button-primary" href="../auth/login.php?next=pages/forum.php">Entrar para participar</a>
      <?php endif; ?>
    </div>
  </section>

  <section id="forum-toolbar" class="forum-toolbar">
    <form class="forum-search" action="forum.php" method="get" role="search">
      <?php if ($filter): ?>
        <input type="hidden" name="subject" value="<?= $filter ?>">
      <?php endif; ?>
      <svg class="forum-search-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="search" name="q" class="form-input" value="<?= e($search) ?>" placeholder="Buscar fórum" aria-label="Buscar fórum" maxlength="60" autocomplete="off">
      <button type="submit" class="button button-primary button-small">Buscar</button>
    </form>
    <nav class="forum-chips" aria-label="Filtrar por matéria">
      <a class="forum-filter<?= $filter === 0 ? ' is-active' : '' ?>" href="<?= e($urlFor(['subject' => 0])) ?>">Todas</a>
      <?php foreach ($subjects as $subject): ?>
        <a class="forum-filter<?= $filter === (int) $subject['id'] ? ' is-active' : '' ?>" href="<?= e($urlFor(['subject' => (int) $subject['id']])) ?>"><?= e($subject['name']) ?></a>
      <?php endforeach; ?>
    </nav>
  </section>

  <?php $renderShelf('forum-mine', 'Abertos por mim', $mine, $mineTotal); ?>
  <?php $renderShelf('forum-joined', 'Participo', $joined, $joinedTotal); ?>

  <section id="forum-list" class="section forum-shelf">
    <h2 class="section-title">Todos os fóruns <span class="dm-count"><?= $totalTopics ?></span></h2>

    <?php if (!$topics): ?>
      <div class="forum-empty">
        <p>Nenhum fórum encontrado.</p>
        <?php if ($search !== '' || $filter): ?>
          <a class="button button-small" href="forum.php">Limpar filtros</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <ul class="forum-grid">
        <?php foreach ($topics as $index => $topic): ?>
          <?= forumRenderTile($topic + ['is_owner' => $user && (int) $topic['user_id'] === (int) $user['id']], $index) ?>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
      <nav id="forum-pagination" class="forum-pager" aria-label="Paginação">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <?php if ($i === $page): ?>
            <strong class="forum-page is-active" aria-current="page"><?= $i ?></strong>
          <?php else: ?>
            <a class="forum-page" href="<?= e($urlFor(['page' => $i])) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
