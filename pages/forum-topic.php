<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/forum-upload.php';

const CHAT_MESSAGE_MAX = 280;
const CHAT_MESSAGE_MAX_LINES = 5;
const CHAT_EDIT_SECONDS = 180;
const CHAT_DELETE_SECONDS = 300;

$topicId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT t.id, t.title, t.body, t.is_closed, t.created_at, s.name AS subject,
            u.id AS author_id, u.name AS author, u.avatar_mime AS author_avatar_mime
     FROM forum_topics t
     JOIN subjects s ON s.id = t.subject_id
     JOIN users u ON u.id = t.user_id AND u.activate = 1
     WHERE t.id = ?'
);
$stmt->execute([$topicId]);
$topic = $stmt->fetch();

if (!$topic) {
    setFlash('error', 'Tópico não encontrado.');
    redirect('forum.php');
}

$self = 'forum-topic.php?id=' . $topicId;
$replyText = '';
$canModerate = $user && in_array($user['role'], ['admin', 'teacher'], true);
$canUploadForumFiles = $user && in_array($user['role'], ['admin', 'teacher'], true);

function chatJson(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function chatNormalize(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2064}\x{2800}\x{3164}\x{115F}\x{1160}\x{FEFF}\x{00AD}]/u', '', $text) ?? $text;
    $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;
    $text = preg_replace('/\n{2,}/', "\n", $text) ?? $text;
    return trim($text);
}

function chatContainsLink(string $text): bool
{
    $flat = mb_strtolower(preg_replace('/[\s\x{200B}-\x{200F}]+/u', '', $text) ?? $text);
    if (preg_match('~(https?:|ftp:|www\.|://|mailto:|wa\.me|bit\.ly|t\.me|discord\.gg|tinyurl)~u', $flat)) {
        return true;
    }
    if (preg_match('~[a-z0-9._%+\-]+@[a-z0-9\-]+\.[a-z]{2,}~u', $flat)) {
        return true;
    }
    if (preg_match('~[a-z0-9\-]+\.(?:com|net|org|info|biz|xyz|site|online|shop|store|click|vip|icu|br|ru|cn)(?![a-z0-9])~u', $flat)) {
        return true;
    }
    $dotted = mb_strtolower(preg_replace('/\s*(\[\s*(dot|ponto)\s*\]|\(\s*(dot|ponto)\s*\))\s*/iu', '.', $text) ?? $text);
    return (bool) preg_match('~[a-z0-9\-]+\.(?:com|net|org|info|biz|xyz|top|site|online|club|shop|store|link|click|live|app|dev|io|ly|me|co|tv|cc|gg|gl|ws|vip|icu|cyou|ru|cn|tk|ml|ga|cf|gq|pw|br|pt|uk|fr)(?![a-z0-9])~iu', $dotted);
}

function chatValidate(string $text): ?string
{
    $length = mb_strlen($text);
    if ($length === 0) {
        return 'A mensagem não pode ficar vazia.';
    }
    if ($length > CHAT_MESSAGE_MAX) {
        return 'A mensagem pode ter no máximo ' . CHAT_MESSAGE_MAX . " caracteres (você digitou $length).";
    }
    if (substr_count($text, "\n") + 1 > CHAT_MESSAGE_MAX_LINES) {
        return 'A mensagem pode ter no máximo ' . CHAT_MESSAGE_MAX_LINES . ' linhas.';
    }
    if (chatContainsLink($text)) {
        return 'Links e endereços de sites não são permitidos nas mensagens.';
    }
    return null;
}

function chatFindPost(PDO $pdo, int $postId, int $topicId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, user_id, body, edited_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
         FROM forum_posts WHERE id = ? AND topic_id = ?'
    );
    $stmt->execute([$postId, $topicId]);
    return $stmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isFetch = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

    if (!$user) {
        if ($isFetch) {
            chatJson(401, ['ok' => false, 'error' => 'Entre na sua conta para continuar.']);
        }
        $gate = 'login';
        $baseUrl = '../';
        include_once __DIR__ . '/../includes/acesso-restrito.php';
    }

    $action = $_POST['action'] ?? '';

    if (forumExceedsPostMaxSize() && ($action === 'reply' || $isFetch)) {
        $oversize = 'O envio excedeu o limite total permitido pelo servidor. Reduza a quantidade/tamanho dos anexos e tente novamente.';
        if ($isFetch) {
            chatJson(413, ['ok' => false, 'error' => $oversize]);
        }
        setFlash('error', $oversize);
        redirect($self);
    }

    requireCsrf($self, $isFetch);

    if ($action === 'edit_post') {
        $postId = (int) ($_POST['post_id'] ?? 0);
        $newBody = chatNormalize((string) ($_POST['body'] ?? ''));
        $post = chatFindPost($pdo, $postId, $topicId);

        if (!$post) {
            chatJson(404, ['ok' => false, 'error' => 'Essa mensagem não existe mais.']);
        }
        if ((int) $post['user_id'] !== (int) $user['id']) {
            chatJson(403, ['ok' => false, 'error' => 'Você só pode editar as suas próprias mensagens.']);
        }
        if ($topic['is_closed']) {
            chatJson(403, ['ok' => false, 'error' => 'Este tópico está fechado, então as mensagens não podem mais ser editadas.']);
        }
        if ((int) $post['age'] > CHAT_EDIT_SECONDS) {
            chatJson(403, ['ok' => false, 'error' => 'O prazo de ' . (CHAT_EDIT_SECONDS / 60) . ' minutos para editar essa mensagem já acabou.']);
        }
        $invalid = chatValidate($newBody);
        if ($invalid !== null) {
            chatJson(422, ['ok' => false, 'error' => $invalid]);
        }

        $edited = $newBody !== $post['body'];
        if ($edited) {
            $pdo->prepare('UPDATE forum_posts SET body = ?, edited_at = NOW() WHERE id = ?')->execute([$newBody, $postId]);
        }
        chatJson(200, ['ok' => true, 'body' => $newBody, 'edited' => $edited || $post['edited_at'] !== null]);
    }

    if ($action === 'reply') {
        $replyText = chatNormalize((string) ($_POST['body'] ?? ''));
        $replyError = null;
        $replyStatus = 422;

        if ($topic['is_closed']) {
            $replyError = 'Este tópico está fechado para novas respostas.';
            $replyStatus = 403;
        } else {
            $replyError = chatValidate($replyText);
            if ($replyError === null && !empty($_FILES['attachments']['name'][0]) && !$canUploadForumFiles) {
                $replyError = 'Apenas professores aprovados e administradores podem enviar anexos no fórum.';
                $replyStatus = 403;
            }
        }

        if ($replyError === null) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO forum_posts (topic_id, user_id, body) VALUES (?, ?, ?)')
                    ->execute([$topicId, $user['id'], $replyText]);
                $postId = (int) $pdo->lastInsertId();
                if (!empty($_FILES['attachments']['name'][0])) {
                    saveForumAttachments($_FILES['attachments'], $topicId, $postId, $pdo);
                }
                $pdo->commit();

                if ($isFetch) {
                    $stmtNew = $pdo->prepare(
                        'SELECT p.id, p.body, p.created_at, p.edited_at, p.user_id,
                                TIMESTAMPDIFF(SECOND, p.created_at, NOW()) AS age,
                                u.id AS author_id, u.name AS author, u.avatar_mime AS author_avatar_mime
                         FROM forum_posts p
                         JOIN users u ON u.id = p.user_id
                         WHERE p.id = ?'
                    );
                    $stmtNew->execute([$postId]);
                    $newPost = $stmtNew->fetch();
                    $stmtFiles = $pdo->prepare('SELECT id, post_id, original_name, mime_type, file_size FROM forum_attachments WHERE post_id = ? ORDER BY id');
                    $stmtFiles->execute([$postId]);
                    chatJson(200, [
                        'ok' => true,
                        'id' => $postId,
                        'html' => chatRenderMessage($newPost, $stmtFiles->fetchAll(), $user, (bool) $canModerate, (bool) $topic['is_closed'], $self),
                    ]);
                }
                redirect($self . '#post-' . $postId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('forum-topic.php:reply_failed: ' . $e->getMessage());
                $replyError = 'Não foi possível enviar sua resposta com os anexos informados.';
                $replyStatus = 500;
            }
        }

        if ($isFetch) {
            chatJson($replyStatus, ['ok' => false, 'error' => $replyError]);
        }
        setFlash('error', $replyError);
    } elseif ($canModerate && $action === 'toggle_close') {
        $pdo->prepare('UPDATE forum_topics SET is_closed = 1 - is_closed WHERE id = ?')->execute([$topicId]);
        setFlash('success', $topic['is_closed'] ? 'Tópico reaberto.' : 'Tópico fechado.');
        redirect($self);
    } elseif ($action === 'delete_post') {
        $postId = (int) ($_POST['post_id'] ?? 0);
        $post = $postId > 0 ? chatFindPost($pdo, $postId, $topicId) : null;
        $deleteError = null;
        $deleteStatus = 403;

        if (!$post) {
            $deleteError = 'Essa mensagem não existe mais.';
            $deleteStatus = 404;
        } else {
            $isOwn = (int) $post['user_id'] === (int) $user['id'];
            if (!$canModerate) {
                if (!$isOwn) {
                    $deleteError = 'Você só pode excluir as suas próprias mensagens.';
                } elseif ((int) $post['age'] > CHAT_DELETE_SECONDS) {
                    $deleteError = 'O prazo de ' . (CHAT_DELETE_SECONDS / 60) . ' minutos para excluir essa mensagem já acabou.';
                }
            }
        }

        if ($deleteError === null) {
            try {
                $pdo->beginTransaction();
                $stmtStored = $pdo->prepare('SELECT stored_name FROM forum_attachments WHERE topic_id = ? AND post_id = ?');
                $stmtStored->execute([$topicId, $postId]);
                $storedNames = array_column($stmtStored->fetchAll(PDO::FETCH_ASSOC), 'stored_name');

                $stmtDelete = $pdo->prepare('DELETE FROM forum_posts WHERE id = ? AND topic_id = ?');
                $stmtDelete->execute([$postId, $topicId]);
                if ($stmtDelete->rowCount() < 1) {
                    throw new RuntimeException('Mensagem não encontrada.');
                }

                $pdo->commit();
                forumCleanupFiles($storedNames);
                if ($isFetch) {
                    chatJson(200, ['ok' => true, 'id' => $postId]);
                }
                setFlash('success', 'Mensagem excluída.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('forum-topic.php:delete_post_failed: ' . $e->getMessage());
                $deleteError = 'Não foi possível excluir a mensagem.';
                $deleteStatus = 500;
            }
        }

        if ($deleteError !== null) {
            if ($isFetch) {
                chatJson($deleteStatus, ['ok' => false, 'error' => $deleteError]);
            }
            setFlash('error', $deleteError);
        }
        redirect($self . '#topic-messages');
    } elseif ($canModerate && $action === 'delete_topic') {
        try {
            $pdo->beginTransaction();
            $stmtStored = $pdo->prepare('SELECT stored_name FROM forum_attachments WHERE topic_id = ?');
            $stmtStored->execute([$topicId]);
            $storedNames = array_column($stmtStored->fetchAll(PDO::FETCH_ASSOC), 'stored_name');

            $stmtDelete = $pdo->prepare('DELETE FROM forum_topics WHERE id = ?');
            $stmtDelete->execute([$topicId]);
            if ($stmtDelete->rowCount() < 1) {
                throw new RuntimeException('Tópico não encontrado.');
            }

            $pdo->commit();
            forumCleanupFiles($storedNames);
            setFlash('success', 'Tópico excluído.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('forum-topic.php:delete_topic_failed: ' . $e->getMessage());
            setFlash('error', 'Não foi possível excluir o tópico.');
            redirect($self);
        }
        redirect('forum.php');
    } else {
        setFlash('error', 'Solicitação inválida.');
        redirect($self);
    }
}

$stmt = $pdo->prepare(
    'SELECT p.id, p.body, p.created_at, p.edited_at, p.user_id,
            TIMESTAMPDIFF(SECOND, p.created_at, NOW()) AS age,
            u.id AS author_id, u.name AS author, u.avatar_mime AS author_avatar_mime
     FROM forum_posts p
     JOIN users u ON u.id = p.user_id AND u.activate = 1
     WHERE p.topic_id = ?
     ORDER BY p.created_at, p.id'
);
$stmt->execute([$topicId]);
$posts = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT id, original_name, mime_type, file_size FROM forum_attachments WHERE topic_id = ? AND post_id IS NULL ORDER BY id');
$stmt->execute([$topicId]);
$topicAttachments = $stmt->fetchAll();

$postAttachments = [];
$stmt = $pdo->prepare('SELECT id, post_id, original_name, mime_type, file_size FROM forum_attachments WHERE topic_id = ? AND post_id IS NOT NULL ORDER BY id');
$stmt->execute([$topicId]);
foreach ($stmt->fetchAll() as $attachment) {
    $postAttachments[(int) $attachment['post_id']][] = $attachment;
}

function chatAvatar(array $person, ?array $viewer): string
{
    $id = (int) $person['author_id'];
    $isSelf = $viewer && (int) $viewer['id'] === $id;
    $href = $isSelf ? 'profile.php' : 'profile-view.php?id=' . $id;
    $title = $isSelf ? 'Ver o meu perfil' : 'Ver o perfil de ' . $person['author'];
    $initial = mb_strtoupper(mb_substr(trim($person['author']), 0, 1));

    $html = '<a class="chat-avatar" href="' . e($href) . '" title="' . e($title) . '" aria-label="' . e($title) . '">';
    if (!empty($person['author_avatar_mime'])) {
        $html .= '<img class="chat-avatar-image" src="../endpoints/image.php?user=' . $id . '&amp;type=avatar" alt="">';
    } else {
        $html .= '<span class="chat-avatar-initials" aria-hidden="true">' . e($initial) . '</span>';
    }
    return $html . '</a>';
}

function chatAttachments(array $list): string
{
    if (!$list) {
        return '';
    }
    $html = '<div class="forum-attachments"><strong>Anexos:</strong>';
    foreach ($list as $attachment) {
        $html .= '<a class="card-link" href="forum-attachment.php?id=' . (int) $attachment['id'] . '" target="_blank" rel="noopener">'
            . e($attachment['original_name']) . ' (' . e(number_format(((int) $attachment['file_size']) / 1024, 0, ',', '.')) . ' KB)</a>';
    }
    return $html . '</div>';
}

function chatRenderMessage(array $post, array $attachments, ?array $viewer, bool $canModerate, bool $topicClosed, string $self): string
{
    $isOwn = $viewer && (int) $viewer['id'] === (int) $post['user_id'];
    $editLeft = $isOwn && !$topicClosed ? max(0, CHAT_EDIT_SECONDS - (int) $post['age']) : 0;
    $deleteLeft = $isOwn ? max(0, CHAT_DELETE_SECONDS - (int) $post['age']) : 0;
    $canDelete = $canModerate || $deleteLeft > 0;
    $authorHref = $isOwn ? 'profile.php' : 'profile-view.php?id=' . (int) $post['author_id'];
    $timestamp = strtotime($post['created_at']);

    ob_start();
    ?>
    <li
      class="chat-message<?= $isOwn ? ' is-own' : '' ?>"
      id="post-<?= (int) $post['id'] ?>"
      data-post-id="<?= (int) $post['id'] ?>"
      data-edit-left="<?= $editLeft ?>"
      data-delete-left="<?= $canModerate ? 0 : $deleteLeft ?>"
    >
      <?= chatAvatar($post, $viewer) ?>
      <div class="chat-bubble">
        <div class="chat-meta">
          <a class="chat-author" href="<?= e($authorHref) ?>"><?= e($post['author']) ?></a>
          <time class="chat-time" datetime="<?= e(date('c', $timestamp)) ?>"><?= e(date('d/m/Y H:i', $timestamp)) ?></time>
          <span class="chat-edited" data-edited<?= $post['edited_at'] === null ? ' hidden' : '' ?><?= $post['edited_at'] !== null ? ' title="Editada em ' . e(date('d/m/Y H:i', strtotime($post['edited_at']))) . '"' : '' ?>>(editada)</span>
        </div>
        <p class="chat-text" data-text><?= e($post['body']) ?></p>
        <?= chatAttachments($attachments) ?>
        <?php if ($editLeft > 0 || $canDelete): ?>
          <div class="chat-actions">
            <?php if ($editLeft > 0): ?>
              <button type="button" class="chat-action" data-edit aria-label="Editar mensagem" title="Editar (até 3 min depois de enviar)">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
              </button>
            <?php endif; ?>
            <?php if ($canDelete): ?>
              <form class="chat-delete-form" action="<?= e($self) ?>" method="post" data-confirm="Excluir esta mensagem?">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="delete_post">
                <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                <button type="submit" class="chat-action chat-action-danger" data-delete aria-label="Excluir mensagem" title="<?= $canModerate && !$isOwn ? 'Excluir (moderação)' : 'Excluir (até 5 min depois de enviar)' ?>">
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                </button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </li>
    <?php
    return (string) ob_get_clean();
}

$participants = array_unique(array_merge([(int) $topic['author_id']], array_map('intval', array_column($posts, 'user_id'))));

$pageId = 'forum-topic';
$pageTitle = $topic['title'];
$extraCss = ['forum'];
$extraJs = ['forum'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="topic-header" class="section forum-hero<?= $topic['is_closed'] ? ' is-closed' : '' ?>">
    <span class="forum-hero-deco forum-hero-deco-a" aria-hidden="true"></span>
    <span class="forum-hero-deco forum-hero-deco-b" aria-hidden="true"></span>
    <span class="forum-hero-deco forum-hero-deco-c" aria-hidden="true"></span>

    <nav class="forum-crumbs" aria-label="Navegação do fórum">
      <a class="forum-back" href="forum.php">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
        Fóruns
      </a>
      <span class="forum-chip forum-chip-subject"><?= e($topic['subject']) ?></span>
      <span class="forum-chip forum-chip-status"><?= $topic['is_closed'] ? 'Fechado' : 'Aberto' ?></span>
    </nav>

    <h2 class="forum-hero-title"><?= e($topic['title']) ?><?= $topic['is_closed'] ? ' 🔒' : '' ?></h2>

    <p id="topic-info" class="topic-info forum-hero-by">
      <?= chatAvatar($topic, $user) ?>
      <span>
        Criado por
        <a class="forum-author-name" href="profile-view.php?id=<?= (int) $topic['author_id'] ?>"><?= e($topic['author']) ?></a>
        <time datetime="<?= e(date('c', strtotime($topic['created_at']))) ?>">em <?= e(date('d/m/Y H:i', strtotime($topic['created_at']))) ?></time>
      </span>
    </p>

    <ul class="forum-stats" aria-label="Resumo do tópico">
      <li class="forum-stat"><strong data-stat-replies><?= count($posts) ?></strong><span>respostas</span></li>
      <li class="forum-stat"><strong><?= count($participants) ?></strong><span>participantes</span></li>
    </ul>

    <?php if ($canModerate): ?>
      <form id="topic-moderation" class="inline-form forum-hero-tools" action="<?= e($self) ?>" method="post">
        <?= csrfInput() ?>
        <button type="submit" name="action" value="toggle_close" class="button button-secondary">
          <?= $topic['is_closed'] ? 'Reabrir tópico' : 'Fechar tópico' ?>
        </button>
        <button type="submit" name="action" value="delete_topic" class="button button-danger"
                data-confirm="Excluir este tópico e todas as mensagens?">Excluir tópico</button>
      </form>
    <?php endif; ?>
  </section>

  <section id="topic-messages" class="section">
    <h2 class="section-title">Mensagens</h2>
    <ul
      id="message-list"
      class="chat-list"
      data-action="<?= e($self) ?>"
      data-csrf="<?= e(csrfToken()) ?>"
      data-limit="<?= CHAT_MESSAGE_MAX ?>"
      data-max-lines="<?= CHAT_MESSAGE_MAX_LINES ?>"
    >
      <li class="chat-message<?= $user && (int) $user['id'] === (int) $topic['author_id'] ? ' is-own' : '' ?>">
        <?= chatAvatar($topic, $user) ?>
        <div class="chat-bubble">
          <div class="chat-meta">
            <a class="chat-author" href="profile-view.php?id=<?= (int) $topic['author_id'] ?>"><?= e($topic['author']) ?></a>
            <span class="chat-badge">autor do tópico</span>
          </div>
          <p class="chat-text"><?= e($topic['body']) ?></p>
          <?= chatAttachments($topicAttachments) ?>
        </div>
      </li>

      <?php foreach ($posts as $post): ?>
        <?= chatRenderMessage($post, $postAttachments[(int) $post['id']] ?? [], $user, (bool) $canModerate, (bool) $topic['is_closed'], $self) ?>
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
        <?= csrfInput() ?>
        <input type="hidden" name="action" value="reply">
        <div class="form-group">
          <label class="form-label" for="reply-text">Mensagem</label>
          <textarea
            id="reply-text"
            name="body"
            class="form-input chat-input"
            rows="3"
            data-chat-limit="<?= CHAT_MESSAGE_MAX ?>"
            data-chat-lines="<?= CHAT_MESSAGE_MAX_LINES ?>"
            aria-describedby="reply-counter reply-rules"
            required
          ><?= e($replyText) ?></textarea>
          <p id="reply-error" class="chat-edit-error" role="alert" hidden></p>
          <div class="chat-tools">
            <div class="emoji-wrap">
              <button type="button" class="emoji-toggle" data-emoji-target="reply-text" aria-label="Inserir emoji" aria-haspopup="true" aria-expanded="false">😊</button>
            </div>
            <small id="reply-counter" class="field-counter" aria-live="polite">0/<?= CHAT_MESSAGE_MAX ?></small>
          </div>
          <small id="reply-rules" class="form-note">Até <?= CHAT_MESSAGE_MAX ?> caracteres e <?= CHAT_MESSAGE_MAX_LINES ?> linhas. Links não são permitidos.</small>
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
