<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/forum-upload.php';

require_once __DIR__ . '/../includes/chat-rules.php';
require_once __DIR__ . '/../includes/forum-rules.php';

$topicId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT t.id, t.title, t.body, t.is_closed, t.created_at, s.name AS subject,
            u.id AS author_id, u.name AS author, u.avatar_mime AS author_avatar_mime,
            TIMESTAMPDIFF(SECOND, GREATEST(t.created_at, COALESCE((SELECT MAX(p.created_at) FROM forum_posts p WHERE p.topic_id = t.id), t.created_at)), NOW()) AS idle
     FROM forum_topics t
     JOIN subjects s ON s.id = t.subject_id
     JOIN users u ON u.id = t.user_id AND u.activate = 1
     WHERE t.id = ?'
);
$stmt->execute([$topicId]);
$topic = $stmt->fetch();

if (!$topic) {
    setFlash('error', 'Fórum não encontrado.');
    redirect('forum.php');
}

$self = 'forum-topic.php?id=' . $topicId;
$replyText = '';
$canModerate = $user && in_array($user['role'], ['admin', 'teacher'], true);
$canUploadForumFiles = $user && in_array($user['role'], ['admin', 'teacher'], true);
$isOwner = $user && (int) $user['id'] === (int) $topic['author_id'];
$canManage = $isOwner || $canModerate;

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

    if ($action === 'edit_topic') {
        $newTitle = forumCleanTitle((string) ($_POST['title'] ?? ''));
        $newBody = forumCleanBody((string) ($_POST['body'] ?? ''));
        $editError = null;
        $editStatus = 422;

        if (!$isOwner) {
            $editError = 'Só quem abriu o fórum pode editá-lo.';
            $editStatus = 403;
        } else {
            $editError = forumValidate($newTitle, $newBody);
        }

        if ($editError === null) {
            $pdo->prepare('UPDATE forum_topics SET title = ?, body = ? WHERE id = ?')->execute([$newTitle, $newBody, $topicId]);
            if ($isFetch) {
                chatJson(200, ['ok' => true, 'title' => $newTitle, 'body' => $newBody]);
            }
            setFlash('success', 'Fórum atualizado.');
            redirect($self);
        }
        if ($isFetch) {
            chatJson($editStatus, ['ok' => false, 'error' => $editError]);
        }
        setFlash('error', $editError);
        redirect($self);
    }

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
            chatJson(403, ['ok' => false, 'error' => 'Este fórum está fechado, então as mensagens não podem mais ser editadas.']);
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
            $replyError = 'Este fórum está fechado para novas respostas.';
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
    } elseif ($canManage && $action === 'toggle_close') {
        $pdo->prepare('UPDATE forum_topics SET is_closed = 1 - is_closed WHERE id = ?')->execute([$topicId]);
        setFlash('success', $topic['is_closed'] ? 'Fórum reaberto.' : 'Fórum fechado.');
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
    } elseif ($canManage && $action === 'delete_topic') {
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
            setFlash('success', 'Fórum excluído.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('forum-topic.php:delete_topic_failed: ' . $e->getMessage());
            setFlash('error', 'Não foi possível excluir o fórum.');
            redirect($self);
        }
        redirect('forum.php');
    } else {
        setFlash('error', 'Solicitação inválida.');
        redirect($self);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['poll'])) {
    $after = max(0, (int) ($_GET['after'] ?? 0));
    $stmt = $pdo->prepare(
        'SELECT p.id, p.body, p.created_at, p.edited_at, p.user_id,
                TIMESTAMPDIFF(SECOND, p.created_at, NOW()) AS age,
                u.id AS author_id, u.name AS author, u.avatar_mime AS author_avatar_mime
         FROM forum_posts p
         JOIN users u ON u.id = p.user_id AND u.activate = 1
         WHERE p.topic_id = ? AND p.id > ?
         ORDER BY p.id LIMIT 50'
    );
    $stmt->execute([$topicId, $after]);
    $fresh = $stmt->fetchAll();

    $freshFiles = [];
    if ($fresh) {
        $marks = implode(',', array_fill(0, count($fresh), '?'));
        $stmt = $pdo->prepare("SELECT id, post_id, original_name, mime_type, file_size FROM forum_attachments WHERE post_id IN ($marks) ORDER BY id");
        $stmt->execute(array_map(fn ($row) => (int) $row['id'], $fresh));
        foreach ($stmt->fetchAll() as $file) {
            $freshFiles[(int) $file['post_id']][] = $file;
        }
    }

    $html = '';
    $last = $after;
    foreach ($fresh as $row) {
        $html .= chatRenderMessage($row, $freshFiles[(int) $row['id']] ?? [], $user, (bool) $canModerate, (bool) $topic['is_closed'], $self);
        $last = max($last, (int) $row['id']);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) AS replies, COUNT(DISTINCT user_id) AS people FROM forum_posts WHERE topic_id = ?');
    $stmt->execute([$topicId]);
    $totals = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM forum_posts WHERE topic_id = ? AND user_id <> ?');
    $stmt->execute([$topicId, (int) $topic['author_id']]);

    chatJson(200, [
        'ok' => true,
        'html' => $html,
        'last_id' => $last,
        'replies' => (int) $totals['replies'],
        'people' => 1 + (int) $stmt->fetchColumn(),
        'closed' => (bool) $topic['is_closed'],
    ]);
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

$participants = [(int) $topic['author_id'] => $topic];
foreach ($posts as $post) {
    $participants[(int) $post['user_id']] ??= $post;
}
$participantCount = count($participants);
$stack = array_slice(array_values($participants), 0, 5);
$descLong = mb_strlen($topic['body']) > 220 || substr_count($topic['body'], "\n") > 3;

$pageId = 'forum-topic';
$pageTitle = $topic['title'];
$extraCss = ['forum'];
$extraJs = ['forum', 'forum-topic'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="topic-header" class="section forum-hero topic-hero<?= $topic['is_closed'] ? ' is-closed' : '' ?>" data-closed="<?= $topic['is_closed'] ? '1' : '0' ?>">
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

    <div id="topic-view" class="topic-view">
      <h1 class="forum-hero-title" data-topic-title><?= e($topic['title']) ?></h1>
      <p class="topic-desc<?= $descLong ? ' is-clamped' : '' ?>" data-topic-desc><?= e($topic['body']) ?></p>
      <?php if ($descLong): ?>
        <button type="button" class="topic-more" data-topic-more aria-expanded="false">Ver tudo</button>
      <?php endif; ?>
      <?php if ($topicAttachments): ?>
        <?= chatAttachments($topicAttachments) ?>
      <?php endif; ?>
    </div>

    <?php if ($isOwner): ?>
      <form id="topic-edit" class="topic-edit" action="<?= e($self) ?>" method="post" hidden>
        <?= csrfInput() ?>
        <input type="hidden" name="action" value="edit_topic">
        <div class="form-group">
          <div class="form-label-row">
            <label class="form-label" for="edit-title">Título</label>
            <small class="field-counter" data-count="title" data-max="<?= FORUM_TITLE_MAX ?>">0/<?= FORUM_TITLE_MAX ?></small>
          </div>
          <input type="text" id="edit-title" name="title" class="form-input" maxlength="<?= FORUM_TITLE_MAX ?>" value="<?= e($topic['title']) ?>" autocomplete="off" required>
        </div>
        <div class="form-group">
          <div class="form-label-row">
            <label class="form-label" for="edit-body">Descrição</label>
            <small class="field-counter" data-count="body" data-max="<?= FORUM_BODY_MAX ?>">0/<?= FORUM_BODY_MAX ?></small>
          </div>
          <textarea id="edit-body" name="body" class="form-input forum-new-body" rows="5" maxlength="<?= FORUM_BODY_MAX ?>" required><?= e($topic['body']) ?></textarea>
        </div>
        <p class="chat-edit-error" role="alert" data-edit-error hidden></p>
        <div class="topic-edit-bar">
          <button type="button" class="button button-small" data-edit-cancel>Cancelar</button>
          <button type="submit" class="button button-primary button-small" data-edit-save>Salvar</button>
        </div>
      </form>
    <?php endif; ?>

    <div class="topic-meta">
      <p id="topic-info" class="topic-info forum-hero-by">
        <?= chatAvatar($topic, $user) ?>
        <span>
          <a class="forum-author-name" href="profile-view.php?id=<?= (int) $topic['author_id'] ?>"><?= e($topic['author']) ?></a>
          <time datetime="<?= e(date('c', strtotime($topic['created_at']))) ?>"><?= e(date('d/m/Y H:i', strtotime($topic['created_at']))) ?></time>
        </span>
      </p>

      <ul class="topic-people" aria-label="Participantes">
        <?php foreach ($stack as $person): ?>
          <li><?= chatAvatar($person, $user) ?></li>
        <?php endforeach; ?>
        <?php if ($participantCount > count($stack)): ?>
          <li class="topic-people-more">+<?= $participantCount - count($stack) ?></li>
        <?php endif; ?>
      </ul>

      <ul class="forum-stats" aria-label="Resumo do fórum">
        <li class="forum-stat"><strong data-stat-replies><?= count($posts) ?></strong><span>respostas</span></li>
        <li class="forum-stat"><strong data-stat-people><?= $participantCount ?></strong><span>participantes</span></li>
        <li class="forum-stat"><span>Atividade <?= e(forumAgo((int) $topic['idle'])) ?></span></li>
      </ul>
    </div>

    <?php if ($canManage): ?>
      <form id="topic-moderation" class="inline-form forum-hero-tools" action="<?= e($self) ?>" method="post">
        <?= csrfInput() ?>
        <?php if ($isOwner): ?>
          <button type="button" class="button button-secondary" data-edit-open>Editar</button>
        <?php endif; ?>
        <button type="submit" name="action" value="toggle_close" class="button button-secondary">
          <?= $topic['is_closed'] ? 'Destrancar' : 'Trancar' ?>
        </button>
        <button type="submit" name="action" value="delete_topic" class="button button-danger"
                data-confirm="Excluir este fórum e as <?= count($posts) ?> mensagens? Não dá para voltar atrás."
                data-confirm-title="Excluir fórum" data-confirm-yes="Sim, excluir">Excluir</button>
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
      data-topic-poll="<?= e($self) ?>&amp;poll=1"
    >
      <?php if (!$posts): ?>
        <li class="dm-start" data-topic-empty>Ainda não há mensagens.</li>
      <?php endif; ?>
      <?php foreach ($posts as $post): ?>
        <?= chatRenderMessage($post, $postAttachments[(int) $post['id']] ?? [], $user, (bool) $canModerate, (bool) $topic['is_closed'], $self) ?>
      <?php endforeach; ?>
    </ul>
  </section>

  <section id="topic-reply" class="section">
    <?php if ($topic['is_closed']): ?>
      <div class="write-locked"><p>Este fórum está fechado.</p></div>
    <?php elseif (!$user): ?>
      <div class="write-locked">
        <p><a class="card-link" href="../auth/login.php?next=pages/forum-topic.php%3Fid%3D<?= $topicId ?>">Entre na sua conta</a> para responder.</p>
      </div>
    <?php else: ?>
      <form id="reply-form" class="form write-sheet" action="<?= e($self) ?>" method="post" enctype="multipart/form-data">
        <?= csrfInput() ?>
        <input type="hidden" name="action" value="reply">
        <label class="visually-hidden" for="reply-text">Mensagem</label>
        <textarea
          id="reply-text"
          name="body"
          class="form-input chat-input write-area"
          rows="6"
          placeholder="Mensagem"
          data-chat-limit="<?= CHAT_MESSAGE_MAX ?>"
          data-chat-lines="<?= CHAT_MESSAGE_MAX_LINES ?>"
          aria-describedby="reply-counter"
          required
        ><?= e($replyText) ?></textarea>
        <p id="reply-error" class="chat-edit-error" role="alert" hidden></p>
        <?php if ($canUploadForumFiles): ?>
          <input type="file" id="reply-attachments" name="attachments[]" class="form-input write-files" aria-label="Anexos" multiple
                 accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip">
        <?php endif; ?>
        <div class="chat-tools write-tools">
          <div class="emoji-wrap">
            <button type="button" class="emoji-toggle" data-emoji-target="reply-text" aria-label="Inserir emoji" aria-haspopup="true" aria-expanded="false">😊</button>
          </div>
          <small id="reply-counter" class="field-counter" aria-live="polite">0/<?= CHAT_MESSAGE_MAX ?></small>
          <button type="submit" id="reply-form-submit" class="button button-primary write-send">Enviar</button>
        </div>
      </form>
    <?php endif; ?>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
