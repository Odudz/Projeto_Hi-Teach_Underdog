<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/forum-upload.php';
require_once __DIR__ . '/../includes/chat-rules.php';
require_once __DIR__ . '/../includes/forum-rules.php';
require_once __DIR__ . '/../includes/rbac.php';

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
$canModerate = $user && in_array($user['role'], ['admin', 'moderator', 'teacher'], true);
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
    $isFetch = ($_GET['ajax'] ?? '') === '1' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

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
        $oversize = 'O envio excedeu o limite total permitido pelo servidor.';
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

        if (!$isOwner) {
            $editError = 'Só quem abriu o fórum pode editá-lo.';
            $editStatus = 403;
        } else {
            $editError = forumValidate($newTitle, $newBody);
            $editStatus = 422;
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
            chatJson(403, ['ok' => false, 'error' => 'Este fórum está fechado para edições.']);
        }
        if ((int) $post['age'] > CHAT_EDIT_SECONDS) {
            chatJson(403, ['ok' => false, 'error' => 'O prazo para editar essa mensagem acabou.']);
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
                $replyError = 'Apenas professores e administradores podem enviar anexos no fórum.';
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
                error_log('reply_failed: ' . $e->getMessage());
                $replyError = 'Não foi possível enviar sua resposta.';
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

        if (!$post) {
            $deleteError = 'Essa mensagem não existe mais.';
        } else {
            $isOwn = (int) $post['user_id'] === (int) $user['id'];
            if (!$canModerate && (!$isOwn || (int)$post['age'] > CHAT_DELETE_SECONDS)) {
                $deleteError = 'Você não pode excluir esta mensagem.';
            }
        }

        if ($deleteError === null) {
            try {
                $pdo->beginTransaction();
                $stmtStored = $pdo->prepare('SELECT stored_name FROM forum_attachments WHERE topic_id = ? AND post_id = ?');
                $stmtStored->execute([$topicId, $postId]);
                $storedNames = array_column($stmtStored->fetchAll(PDO::FETCH_ASSOC), 'stored_name');

                $pdo->prepare('DELETE FROM forum_posts WHERE id = ? AND topic_id = ?')->execute([$postId, $topicId]);
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
                $deleteError = 'Não foi possível excluir a mensagem.';
            }
        }

        if ($deleteError !== null) {
            if ($isFetch) {
                chatJson(403, ['ok' => false, 'error' => $deleteError]);
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

            $pdo->prepare('DELETE FROM forum_topics WHERE id = ?')->execute([$topicId]);
            $pdo->commit();
            forumCleanupFiles($storedNames);
            setFlash('success', 'Fórum excluído.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', 'Não foi possível excluir o fórum.');
            redirect($self);
        }
        redirect('forum.php');
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
        $stmt->execute(array_map(fn($row) => (int) $row['id'], $fresh));
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

    $stmt = $pdo->prepare('SELECT COUNT(*) AS replies FROM forum_posts WHERE topic_id = ?');
    $stmt->execute([$topicId]);
    $totals = $stmt->fetch();

    chatJson(200, [
        'ok' => true,
        'html' => $html,
        'last_id' => $last,
        'replies' => (int) $totals['replies'],
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
    $title = 'Ver perfil de ' . $person['author'];
    $initial = mb_strtoupper(mb_substr(trim($person['author']), 0, 1));

    $html = '<a class="chat-avatar" href="' . e($href) . '" title="' . e($title) . '">';
    if (!empty($person['author_avatar_mime'])) {
        $html .= '<img class="chat-avatar-image" src="../endpoints/image.php?user=' . $id . '&amp;type=avatar" alt="">';
    } else {
        $html .= '<span class="chat-avatar-initials">' . e($initial) . '</span>';
    }
    return $html . '</a>';
}

function chatAttachments(array $list): string
{
    if (!$list) {
        return '';
    }
    $html = '<div class="forum-attachments"><strong>Anexos:</strong> ';
    foreach ($list as $attachment) {
        $html .= '<a class="card-link" href="forum-attachment.php?id=' . (int) $attachment['id'] . '">'
            . e($attachment['original_name']) . '</a> ';
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
    <li class="chat-message<?= $isOwn ? ' is-own' : '' ?>" id="post-<?= (int) $post['id'] ?>"
        data-post-id="<?= (int) $post['id'] ?>" data-edit-left="<?= $editLeft ?>"
        data-delete-left="<?= $canModerate ? 0 : $deleteLeft ?>">
        <?= chatAvatar($post, $viewer) ?>
        <div class="chat-bubble">
            <div class="chat-meta">
                <a class="chat-author" href="<?= e($authorHref) ?>"><?= e($post['author']) ?></a>
                <time class="chat-time"
                    datetime="<?= e(date('c', $timestamp)) ?>"><?= e(date('d/m/Y H:i', $timestamp)) ?></time>
                <span class="chat-edited" data-edited<?= $post['edited_at'] === null ? ' hidden' : '' ?>>(editada)</span>
            </div>
            <p class="chat-text" data-text><?= e($post['body']) ?></p>
            <?= chatAttachments($attachments) ?>

            <div
                style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-top:var(--space-2);">
                <!-- BOTÃO DE DENUNCIAR MENSAGEM (Visível para outros usuários logados) -->
                <?php if ($viewer && !$isOwn): ?>
                    <details class="decision" style="display:inline-block;">
                        <summary class="button button-small button-secondary" style="font-size:12px; padding:2px 8px;">Denunciar
                        </summary>
                        <form class="decision-form" action="../endpoints/report.php" method="post"
                            style="min-width:250px; padding:var(--space-2);">
                            <?= csrfInput() ?>
                            <input type="hidden" name="target_type" value="post">
                            <input type="hidden" name="target_id" value="<?= (int) $post['id'] ?>">
                            <label class="field" style="font-size:12px;">
                                <span>Motivo da denúncia</span>
                                <select name="category" class="form-input" style="font-size:12px;" required>
                                    <?php foreach (RBAC_REPORT_CATEGORIES as $key => $lbl): ?>
                                        <option value="<?= e($key) ?>"><?= e($lbl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field field-wide" style="font-size:12px;">
                                <span>Observação (opcional)</span>
                                <input type="text" name="details" class="form-input" maxlength="255"
                                    placeholder="Descreva o problema" style="font-size:12px;">
                            </label>
                            <button type="submit" class="button button-small button-danger" style="font-size:12px;">Enviar
                                Denúncia</button>
                        </form>
                    </details>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>

                <?php if ($editLeft > 0 || $canDelete): ?>
                    <div class="chat-actions" style="margin:0;">
                        <?php if ($editLeft > 0): ?>
                            <button type="button" class="chat-action" data-edit aria-label="Editar mensagem">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 20h9" />
                                    <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z" />
                                </svg>
                            </button>
                        <?php endif; ?>
                        <?php if ($canDelete): ?>
                            <form class="chat-delete-form" action="<?= e($self) ?>" method="post"
                                data-confirm="Excluir esta mensagem?">
                                <?= csrfInput() ?>
                                <input type="hidden" name="action" value="delete_post">
                                <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                                <button type="submit" class="chat-action chat-action-danger" data-delete
                                    aria-label="Excluir mensagem">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                        stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6" />
                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                        <path d="M10 11v6M14 11v6" />
                                    </svg>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </li>
<?php
    return (string) ob_get_clean();
}

$pageId = 'forum-topic';
$pageTitle = $topic['title'];
$extraCss = ['forum', 'staff'];
$extraJs = ['forum', 'forum-topic'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
    <section id="topic-header" class="section forum-hero topic-hero<?= $topic['is_closed'] ? ' is-closed' : '' ?>"
        data-closed="<?= $topic['is_closed'] ? '1' : '0' ?>">
        <nav class="forum-crumbs">
            <a class="forum-back" href="forum.php">Fóruns</a>
            <span class="forum-chip forum-chip-subject"><?= e($topic['subject']) ?></span>
            <span class="forum-chip"><?= $topic['is_closed'] ? 'Fechado' : 'Aberto' ?></span>
        </nav>

        <div id="topic-view" class="topic-view">
            <h1 class="forum-hero-title"><?= e($topic['title']) ?></h1>
            <p class="topic-desc"><?= e($topic['body']) ?></p>
            <?php if ($topicAttachments): ?>
                <?= chatAttachments($topicAttachments) ?>
            <?php endif; ?>
        </div>

        <div class="topic-meta">
            <p class="topic-info forum-hero-by">
                <?= chatAvatar($topic, $user) ?>
                <span>
                    <a class="forum-author-name"
                        href="profile-view.php?id=<?= (int) $topic['author_id'] ?>"><?= e($topic['author']) ?></a>
                    <time
                        datetime="<?= e(date('c', strtotime($topic['created_at']))) ?>"><?= e(date('d/m/Y H:i', strtotime($topic['created_at']))) ?></time>
                </span>
            </p>

            <ul class="forum-stats">
                <li class="forum-stat"><strong data-stat-replies><?= count($posts) ?></strong><span>respostas</span>
                </li>
                <li class="forum-stat"><span>Atividade <?= e(forumAgo((int) $topic['idle'])) ?></span></li>
            </ul>
        </div>

        <div class="forum-hero-tools"
            style="display:flex; flex-wrap:wrap; gap:var(--space-2); margin-top:var(--space-2);">
            <?php if ($canManage): ?>
                <form class="inline-form" action="<?= e($self) ?>" method="post">
                    <?= csrfInput() ?>
                    <button type="submit" name="action" value="toggle_close" class="button button-small button-secondary">
                        <?= $topic['is_closed'] ? 'Destrancar Fórum' : 'Trancar Fórum' ?>
                    </button>
                    <button type="submit" name="action" value="delete_topic" class="button button-small button-danger"
                        data-confirm="Excluir este fórum e todas as mensagens?">Excluir Fórum</button>
                </form>
            <?php endif; ?>

            <!-- BOTÃO DE DENUNCIAR O FÓRUM INTEIRO (Para outros usuários) -->
            <?php if ($user && !$isOwner): ?>
                <details class="decision" style="display:inline-block;">
                    <summary class="button button-small button-secondary">Denunciar este Fórum</summary>
                    <form class="decision-form" action="../endpoints/report.php" method="post"
                        style="min-width:280px; padding:var(--space-3);">
                        <?= csrfInput() ?>
                        <input type="hidden" name="target_type" value="topic">
                        <input type="hidden" name="target_id" value="<?= (int) $topic['id'] ?>">
                        <label class="field">
                            <span>Motivo da denúncia</span>
                            <select name="category" class="form-input" required>
                                <?php foreach (RBAC_REPORT_CATEGORIES as $key => $lbl): ?>
                                    <option value="<?= e($key) ?>"><?= e($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field field-wide">
                            <span>Observação (opcional)</span>
                            <input type="text" name="details" class="form-input" maxlength="255"
                                placeholder="Explique brevemente a infração">
                        </label>
                        <button type="submit" class="button button-small button-danger">Enviar Denúncia</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    </section>

    <section id="topic-messages" class="section">
        <h2 class="section-title">Mensagens</h2>
        <ul id="message-list" class="chat-list" data-action="<?= e($self) ?>" data-csrf="<?= e(csrfToken()) ?>"
            data-limit="<?= CHAT_MESSAGE_MAX ?>" data-max-lines="<?= CHAT_MESSAGE_MAX_LINES ?>"
            data-topic-poll="<?= e($self) ?>&amp;poll=1">
            <?php if (!$posts): ?>
                <li class="dm-start">Ainda não há mensagens neste fórum.</li>
            <?php endif; ?>
            <?php foreach ($posts as $post): ?>
                <?= chatRenderMessage($post, $postAttachments[(int) $post['id']] ?? [], $user, (bool) $canModerate, (bool) $topic['is_closed'], $self) ?>
            <?php endforeach; ?>
        </ul>
    </section>

    <section id="topic-reply" class="section">
        <?php if ($topic['is_closed']): ?>
            <div class="write-locked">
                <p>Este fórum está fechado para respostas.</p>
            </div>
        <?php elseif (!$user): ?>
            <div class="write-locked">
                <p><a class="card-link" href="../auth/login.php?next=pages/forum-topic.php%3Fid%3D<?= $topicId ?>">Entre na
                        sua conta</a> para responder.</p>
            </div>
        <?php else: ?>
            <form id="reply-form" class="form write-sheet" action="<?= e($self) ?>" method="post"
                enctype="multipart/form-data">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="reply">
                <textarea id="reply-text" name="body" class="form-input chat-input write-area" rows="6"
                    placeholder="Escreva sua resposta..." data-chat-limit="<?= CHAT_MESSAGE_MAX ?>"
                    data-chat-lines="<?= CHAT_MESSAGE_MAX_LINES ?>" required><?= e($replyText) ?></textarea>
                <p id="reply-error" class="chat-edit-error" role="alert" hidden></p>
                <?php if ($canUploadForumFiles): ?>
                    <input type="file" id="reply-attachments" name="attachments[]" class="form-input write-files" multiple
                        accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip">
                <?php endif; ?>
                <div class="chat-tools write-tools">
                    <small id="reply-counter" class="field-counter" aria-live="polite">0/<?= CHAT_MESSAGE_MAX ?></small>
                    <button type="submit" id="reply-form-submit" class="button button-primary write-send">Enviar
                        Resposta</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
