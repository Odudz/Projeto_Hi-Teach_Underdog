<?php
include_once __DIR__ . '/../config/conexao.php';

$topicId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT t.id, t.title, t.body, t.is_closed, t.created_at, s.name AS subject, u.name AS author
     FROM forum_topics t
     JOIN subjects s ON s.id = t.subject_id
     JOIN users u ON u.id = t.user_id
     WHERE t.id = ?'
);
$stmt->execute([$topicId]);
$topic = $stmt->fetch();

if (!$topic) {
    setFlash('error', 'Tópico não encontrado.');
    redirect('forum.php');
}

// Professores e administradores moderam o fórum
$self = 'forum-topic.php?id=' . $topicId;
$replyText = '';
$isPendingTeacher = false;
if ($user && $user['role'] === 'student') {
    $stmtPendingTeacher = $pdo->prepare(
        "SELECT 1 FROM teacher_profiles WHERE user_id = ? AND status = 'pending' LIMIT 1"
    );
    $stmtPendingTeacher->execute([$user['id']]);
    $isPendingTeacher = (bool) $stmtPendingTeacher->fetchColumn();
}

$canModerate = $user && (
    in_array($user['role'], ['admin', 'teacher'], true) || $isPendingTeacher
);
$canUploadForumFiles = $canModerate;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) {
        $gate = 'login';
        $baseUrl = '../';
        include_once __DIR__ . '/../includes/acesso-restrito.php';
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'reply') {
        $replyText = trim($_POST['body'] ?? '');

        if ($topic['is_closed']) {
            setFlash('error', 'Este tópico está fechado para novas respostas.');
        } elseif (mb_strlen($replyText) < 2 || mb_strlen($replyText) > 2000) {
            setFlash('error', 'A mensagem deve ter de 2 a 2000 caracteres.');
        } else {
            if (!empty($_FILES['attachments']['name'][0]) && !$canUploadForumFiles) {
                setFlash('error', 'Apenas professores ou professores em análise podem enviar arquivos nos fóruns.');
                redirect($self);
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO forum_posts (topic_id, user_id, body) VALUES (?, ?, ?)')
                    ->execute([$topicId, $user['id'], $replyText]);
                $postId = (int) $pdo->lastInsertId();
                if (!empty($_FILES['attachments']['name'][0])) {
                    require_once __DIR__ . '/../includes/forum-upload.php';
                    saveForumAttachments($_FILES['attachments'], $topicId, $postId, $pdo);
                }
                $pdo->commit();
                redirect($self . '#topic-messages');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                setFlash('error', $e->getMessage());
            }
        }
    } elseif ($canModerate && $action === 'toggle_close') {
        $pdo->prepare('UPDATE forum_topics SET is_closed = 1 - is_closed WHERE id = ?')->execute([$topicId]);
        setFlash('success', $topic['is_closed'] ? 'Tópico reaberto.' : 'Tópico fechado.');
        redirect($self);
    } elseif ($canModerate && $action === 'delete_post') {
        $pdo->prepare('DELETE FROM forum_posts WHERE id = ? AND topic_id = ?')
            ->execute([(int) ($_POST['post_id'] ?? 0), $topicId]);
        setFlash('success', 'Mensagem excluída.');
        redirect($self . '#topic-messages');
    } elseif ($canModerate && $action === 'delete_topic') {
        $pdo->prepare('DELETE FROM forum_topics WHERE id = ?')->execute([$topicId]);
        setFlash('success', 'Tópico excluído.');
        redirect('forum.php');
    } else {
        setFlash('error', 'Solicitação inválida.');
        redirect($self);
    }
}

$stmt = $pdo->prepare(
    'SELECT p.id, p.body, p.created_at, u.name AS author
     FROM forum_posts p
     JOIN users u ON u.id = p.user_id
     WHERE p.topic_id = ?
     ORDER BY p.created_at, p.id'
);
$stmt->execute([$topicId]);
$posts = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT id, original_name, mime_type, file_size FROM forum_attachments WHERE topic_id = ? ORDER BY id');
$stmt->execute([$topicId]);
$topicAttachments = $stmt->fetchAll();

$postAttachments = [];
$stmt = $pdo->prepare('SELECT id, post_id, original_name, mime_type, file_size FROM forum_attachments WHERE topic_id = ? AND post_id IS NOT NULL ORDER BY id');
$stmt->execute([$topicId]);
foreach ($stmt->fetchAll() as $attachment) {
    $postAttachments[(int) $attachment['post_id']][] = $attachment;
}

$pageId = 'forum-topic';
$pageTitle = $topic['title'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="topic-header" class="section">
    <h2 class="section-title"><?= e($topic['title']) ?><?= $topic['is_closed'] ? ' 🔒' : '' ?></h2>
    <p id="topic-info" class="topic-info">
      <?= e($topic['subject']) ?> · Criado por <?= e($topic['author']) ?> em <?= e(date('d/m/Y H:i', strtotime($topic['created_at']))) ?>
    </p>
    <a class="card-link" href="forum.php">&larr; Voltar aos fóruns</a>

    <?php if ($canModerate): ?>
      <form id="topic-moderation" class="inline-form" action="<?= e($self) ?>" method="post">
        <button type="submit" name="action" value="toggle_close" class="button button-secondary">
          <?= $topic['is_closed'] ? 'Reabrir tópico' : 'Fechar tópico' ?>
        </button>
        <button type="submit" name="action" value="delete_topic" class="button button-danger"
                onclick="return confirm('Excluir este tópico e todas as mensagens?');">Excluir tópico</button>
      </form>
    <?php endif; ?>
  </section>

  <section id="topic-messages" class="section">
    <h2 class="section-title">Mensagens</h2>
    <ul id="message-list" class="message-list">
      <li class="message-item">
        <span class="message-author"><?= e($topic['author']) ?> (autor do tópico)</span>
        <p class="message-text"><?= nl2br(e($topic['body'])) ?></p>
        <?php if ($topicAttachments): ?>
          <div class="forum-attachments">
            <strong>Anexos:</strong>
            <?php foreach ($topicAttachments as $attachment): ?>
              <a class="card-link" href="forum-attachment.php?id=<?= (int) $attachment['id'] ?>" target="_blank" rel="noopener">
                <?= e($attachment['original_name']) ?> (<?= e(number_format(((int) $attachment['file_size']) / 1024, 0, ',', '.')) ?> KB)
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </li>

      <?php foreach ($posts as $post): ?>
        <li class="message-item" id="post-<?= (int) $post['id'] ?>">
          <span class="message-author"><?= e($post['author']) ?> · <?= e(date('d/m/Y H:i', strtotime($post['created_at']))) ?></span>
          <p class="message-text"><?= nl2br(e($post['body'])) ?></p>
          <?php if (!empty($postAttachments[(int) $post['id']])): ?>
            <div class="forum-attachments">
              <strong>Anexos:</strong>
              <?php foreach ($postAttachments[(int) $post['id']] as $attachment): ?>
                <a class="card-link" href="forum-attachment.php?id=<?= (int) $attachment['id'] ?>" target="_blank" rel="noopener">
                  <?= e($attachment['original_name']) ?> (<?= e(number_format(((int) $attachment['file_size']) / 1024, 0, ',', '.')) ?> KB)
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($canModerate): ?>
            <form class="inline-form" action="<?= e($self) ?>" method="post">
              <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
              <button type="submit" name="action" value="delete_post" class="button button-danger button-small"
                      onclick="return confirm('Excluir esta mensagem?');">Excluir</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section id="topic-reply" class="section">
    <h2 class="section-title">Responder</h2>

    <?php if ($topic['is_closed']): ?>
      <p class="form-note">Este tópico está fechado para novas respostas.</p>
    <?php elseif (!$user): ?>
      <p class="form-note">
        <a class="card-link" href="../auth/login.php?next=pages/forum-topic.php">Entre na sua conta</a> para responder.
      </p>
    <?php else: ?>
      <form id="reply-form" class="form" action="<?= e($self) ?>" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="reply">
        <div class="form-group">
          <label class="form-label" for="reply-text">Mensagem</label>
          <textarea id="reply-text" name="body" class="form-input" rows="4" maxlength="2000" required><?= e($replyText) ?></textarea>
        </div>
        <?php if ($canUploadForumFiles): ?>
          <div class="form-group">
            <label class="form-label" for="reply-attachments">Imagens e arquivos (opcional)</label>
            <input type="file" id="reply-attachments" name="attachments[]" class="form-input" multiple
                   accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip">
            <small class="form-note">Até 5 arquivos, 10 MB por arquivo.</small>
          </div>
        <?php endif; ?>

        <button type="submit" id="reply-form-submit" class="button button-primary">Enviar</button>
      </form>
    <?php endif; ?>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>

