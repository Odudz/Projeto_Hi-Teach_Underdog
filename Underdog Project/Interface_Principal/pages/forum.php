<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/forum-upload.php';

const FORUM_PER_PAGE = 10;

$subjects = $pdo->query('SELECT id, name FROM subjects ORDER BY name')->fetchAll();
$subjectIds = array_map('intval', array_column($subjects, 'id'));

$newSubject = 0;
$newTitle = '';
$newBody = '';
$canCreateForum = $user && in_array($user['role'], ['teacher', 'admin'], true);
$canUploadForumFiles = $canCreateForum;

// Criar novo tópico
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
        setFlash('error', 'Apenas professores aprovados e administradores podem criar fóruns.');
        redirect('forum.php');
    }

    $newSubject = (int) ($_POST['subject_id'] ?? 0);
    $newTitle = trim($_POST['title'] ?? '');
    $newBody = trim($_POST['body'] ?? '');

    if (!in_array($newSubject, $subjectIds, true)) {
        setFlash('error', 'Escolha uma matéria válida.');
    } elseif (mb_strlen($newTitle) < 5 || mb_strlen($newTitle) > 120) {
        setFlash('error', 'O título deve ter de 5 a 120 caracteres.');
    } elseif (mb_strlen($newBody) < 10 || mb_strlen($newBody) > 2000) {
        setFlash('error', 'A descrição deve ter de 10 a 2000 caracteres.');
    } else {
        $pdo->beginTransaction();
        try {
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
            setFlash('success', 'Tópico criado!');
            redirect('forum-topic.php?id=' . $topicId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('forum.php:create_topic_failed: ' . $e->getMessage());
            setFlash('error', 'Não foi possível criar o tópico com os anexos informados.');
        }
    }
}

// Listagem com filtro e paginação
$filter = (int) ($_GET['subject'] ?? 0);
$where = $filter ? 'WHERE t.subject_id = ?' : '';
$params = $filter ? [$filter] : [];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM forum_topics t $where");
$stmt->execute($params);
$totalPages = max(1, (int) ceil($stmt->fetchColumn() / FORUM_PER_PAGE));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
$offset = ($page - 1) * FORUM_PER_PAGE;

$stmt = $pdo->prepare(
    "SELECT t.id, t.title, t.is_closed, t.created_at, s.name AS subject, u.name AS author,
            (SELECT COUNT(*) FROM forum_posts p WHERE p.topic_id = t.id) AS replies
     FROM forum_topics t
     JOIN subjects s ON s.id = t.subject_id
     JOIN users u ON u.id = t.user_id
     $where
     ORDER BY t.created_at DESC
     LIMIT " . FORUM_PER_PAGE . " OFFSET $offset"
);
$stmt->execute($params);
$topics = $stmt->fetchAll();

$pageId = 'forum';
$pageTitle = 'Fóruns';
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="forum-filter" class="section">
    <h2 class="section-title">Filtrar por matéria</h2>

    <form id="forum-filter-form" class="form" action="forum.php" method="get">
      <div class="form-group">
        <label class="form-label" for="filter-subject">Matéria</label>
        <select id="filter-subject" name="subject" class="form-input">
          <option value="0">Todas</option>
          <?php foreach ($subjects as $subject): ?>
            <option value="<?= (int) $subject['id'] ?>"<?= $filter === (int) $subject['id'] ? ' selected' : '' ?>><?= e($subject['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit" id="forum-filter-form-submit" class="button button-primary">Filtrar</button>
    </form>
  </section>

  <section id="forum-list" class="section">
    <h2 class="section-title">Fóruns</h2>

    <?php if (!$topics): ?>
      <p id="forum-empty" class="form-note">Nenhum fórum encontrado. Que tal abrir o primeiro debate?</p>
    <?php endif; ?>

    <?php foreach ($topics as $topic): ?>
      <article class="card forum-card">
        <h3 class="card-title"><?= e($topic['title']) ?><?= $topic['is_closed'] ? ' 🔒' : '' ?></h3>
        <p class="card-text">
          Matéria: <?= e($topic['subject']) ?> |
          Criado por: <?= e($topic['author']) ?> |
          Data: <?= e(date('d/m/Y H:i', strtotime($topic['created_at']))) ?> |
          Respostas: <?= (int) $topic['replies'] ?>
        </p>
        <a class="card-link" href="forum-topic.php?id=<?= (int) $topic['id'] ?>">Entrar</a>
      </article>
    <?php endforeach; ?>

    <?php if ($totalPages > 1): ?>
      <nav id="forum-pagination" aria-label="Paginação">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <?php if ($i === $page): ?>
            <strong aria-current="page"><?= $i ?></strong>
          <?php else: ?>
            <a class="card-link" href="forum.php?<?= e(http_build_query(['subject' => $filter, 'page' => $i])) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>
  </section>

  <section id="forum-new" class="section">
    <h2 class="section-title">Abrir novo debate</h2>

    <?php if (!$user): ?>
      <p class="form-note">
        <a class="card-link" href="../auth/login.php?next=pages/forum.php">Entre na sua conta</a>
        para criar um tópico e participar das discussões.
      </p>
    <?php elseif (!$canCreateForum): ?>
      <p class="form-note">Somente professores aprovados e administradores podem abrir novos fóruns. Você pode participar dos debates já existentes.</p>
    <?php else: ?>
      <form id="forum-new-form" class="form" action="forum.php" method="post" enctype="multipart/form-data">
        <?= csrfInput() ?>
        <div class="form-group">
          <label class="form-label" for="new-subject">Matéria</label>
          <select id="new-subject" name="subject_id" class="form-input" required>
            <option value="">Selecione...</option>
            <?php foreach ($subjects as $subject): ?>
              <option value="<?= (int) $subject['id'] ?>"<?= $newSubject === (int) $subject['id'] ? ' selected' : '' ?>><?= e($subject['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label" for="new-title">Título</label>
          <input type="text" id="new-title" name="title" class="form-input" maxlength="120" value="<?= e($newTitle) ?>" required>
        </div>

        <div class="form-group">
          <label class="form-label" for="new-body">Descrição do debate</label>
          <textarea id="new-body" name="body" class="form-input" rows="5" maxlength="2000" required><?= e($newBody) ?></textarea>
        </div>

        <div class="form-group">
          <label class="form-label" for="new-attachments">Imagens e arquivos (opcional)</label>
          <input type="file" id="new-attachments" name="attachments[]" class="form-input" multiple
                 accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip">
          <small class="form-note">Até 5 arquivos, 10 MB por arquivo.</small>
        </div>

        <button type="submit" id="forum-new-form-submit" class="button button-primary">Publicar tópico</button>
      </form>
    <?php endif; ?>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
