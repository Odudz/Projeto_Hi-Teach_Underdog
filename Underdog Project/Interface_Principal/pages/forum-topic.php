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

// Professores aprovados e administradores moderam o fórum
$canModerate = $user && in_array($user['role'], ['admin', 'teacher'], true);
$self = 'forum-topic.php?id=' . $topicId;
$replyText = '';

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
            $pdo->prepare('INSERT INTO forum_posts (topic_id, user_id, body) VALUES (?, ?, ?)')
                ->execute([$topicId, $user['id'], $replyText]);
            redirect($self . '#topic-messages');
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
      </li>

      <?php foreach ($posts as $post): ?>
        <li class="message-item" id="post-<?= (int) $post['id'] ?>">
          <span class="message-author"><?= e($post['author']) ?> · <?= e(date('d/m/Y H:i', strtotime($post['created_at']))) ?></span>
          <p class="message-text"><?= nl2br(e($post['body'])) ?></p>
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
      <form id="reply-form" class="form" action="<?= e($self) ?>" method="post">
        <input type="hidden" name="action" value="reply">
        <div class="form-group">
          <label class="form-label" for="reply-text">Mensagem</label>
          <textarea id="reply-text" name="body" class="form-input" rows="4" maxlength="2000" required><?= e($replyText) ?></textarea>
        </div>
        <button type="submit" id="reply-form-submit" class="button button-primary">Enviar</button>
      </form>
    <?php endif; ?>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
