<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/chat-rules.php';
require_once __DIR__ . '/../includes/social.php';

if (!$user) {
    $gate = 'login';
    $baseUrl = '../';
    include_once __DIR__ . '/../includes/acesso-restrito.php';
}

socialEnsureSchema($pdo);

$me = (int) $user['id'];
$withId = (int) ($_GET['with'] ?? 0);
$self = 'contacts.php' . ($withId > 0 ? '?with=' . $withId : '');
$isFetch = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

const DM_PAGE_SIZE = 100;
const DM_PAGE_SIZE_ALL = 500;

function dmAvatar(array $person): string
{
    $id = (int) $person['id'];
    $name = (string) $person['name'];
    $html = '<a class="chat-avatar" href="profile-view.php?id=' . $id . '" title="Ver o perfil de ' . e($name) . '" aria-label="Ver o perfil de ' . e($name) . '">';
    if (!empty($person['avatar_mime'])) {
        $html .= '<img class="chat-avatar-image" src="../endpoints/image.php?user=' . $id . '&amp;type=avatar" alt="">';
    } else {
        $html .= '<span class="chat-avatar-initials" aria-hidden="true">' . e(socialInitials($name)) . '</span>';
    }
    return $html . '</a>';
}

function dmRenderMessage(array $message, int $viewerId, array $people, string $self): string
{
    $isOwn = (int) $message['sender_id'] === $viewerId;
    $author = $people[(int) $message['sender_id']] ?? ['id' => (int) $message['sender_id'], 'name' => 'Usuário', 'avatar_mime' => null];
    $editLeft = $isOwn ? max(0, CHAT_EDIT_SECONDS - (int) $message['age']) : 0;
    $deleteLeft = $isOwn ? max(0, CHAT_DELETE_SECONDS - (int) $message['age']) : 0;
    $timestamp = time() - (int) $message['age'];

    ob_start();
    ?>
    <li
      class="chat-message<?= $isOwn ? ' is-own' : '' ?>"
      id="post-<?= (int) $message['id'] ?>"
      data-post-id="<?= (int) $message['id'] ?>"
      data-edit-left="<?= $editLeft ?>"
      data-delete-left="<?= $deleteLeft ?>"
    >
      <?= dmAvatar($author) ?>
      <div class="chat-bubble">
        <div class="chat-meta">
          <span class="chat-author"><?= e($isOwn ? 'Você' : $author['name']) ?></span>
          <time class="chat-time" datetime="<?= e(date('c', $timestamp)) ?>"><?= e(date('d/m/Y H:i', $timestamp)) ?></time>
          <span class="chat-edited" data-edited<?= $message['edited_at'] === null ? ' hidden' : '' ?>>(editada)</span>
        </div>
        <p class="chat-text" data-text><?= e($message['body']) ?></p>
        <?php if ($editLeft > 0 || $deleteLeft > 0): ?>
          <div class="chat-actions">
            <?php if ($editLeft > 0): ?>
              <button type="button" class="chat-action" data-edit aria-label="Editar mensagem" title="Editar (até 3 min depois de enviar)">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
              </button>
            <?php endif; ?>
            <?php if ($deleteLeft > 0): ?>
              <form class="chat-delete-form" action="<?= e($self) ?>" method="post" data-confirm="Excluir esta mensagem?">
                <?= csrfInput() ?>
                <input type="hidden" name="action" value="delete_post">
                <input type="hidden" name="post_id" value="<?= (int) $message['id'] ?>">
                <button type="submit" class="chat-action chat-action-danger" data-delete aria-label="Excluir mensagem" title="Excluir (até 5 min depois de enviar)">
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

function dmFindMessage(PDO $pdo, int $id, int $a, int $b): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, sender_id, recipient_id, body, edited_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
         FROM private_messages
         WHERE id = ? AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))'
    );
    $stmt->execute([$id, $a, $b, $b, $a]);
    return $stmt->fetch() ?: null;
}

function dmFetchOne(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT id, sender_id, recipient_id, body, created_at, edited_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
         FROM private_messages WHERE id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function dmMarkRead(PDO $pdo, int $me, int $other): void
{
    $pdo->prepare('UPDATE private_messages SET read_at = NOW() WHERE recipient_id = ? AND sender_id = ? AND read_at IS NULL')
        ->execute([$me, $other]);
}

function dmIcon(string $name, int $size = 20): string
{
    $paths = [
        'search' => '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'copy' => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'dots' => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'hourglass' => '<path d="M6 2h12M6 22h12"/><path d="M17 2v4a5 5 0 0 1-2 4l-3 2 3 2a5 5 0 0 1 2 4v4"/><path d="M7 2v4a5 5 0 0 0 2 4l3 2-3 2a5 5 0 0 0-2 4v4"/>',
        'hello' => '<path d="M18 11V6a2 2 0 0 0-4 0v5"/><path d="M14 10V4a2 2 0 0 0-4 0v6"/><path d="M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.9-5.9-2.4L3.4 16a2 2 0 0 1 3.2-2.4L8 15"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    ];
    return '<svg class="dm-icon" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function dmFriendForm(string $endpoint, int $targetId, string $return, string $action, string $label, string $class = 'dm-menu-item', string $confirm = '', string $title = '', string $yes = ''): string
{
    ob_start();
    ?>
    <form method="post" action="../endpoints/<?= e($endpoint) ?>.php"<?= $confirm !== '' ? ' data-confirm="' . e($confirm) . '" data-confirm-title="' . e($title) . '" data-confirm-yes="' . e($yes) . '"' : '' ?>>
      <?= csrfInput() ?>
      <input type="hidden" name="user_id" value="<?= $targetId ?>">
      <input type="hidden" name="return" value="<?= e($return) ?>">
      <button type="submit" name="action" value="<?= e($action) ?>" class="<?= e($class) ?>"><?= e($label) ?></button>
    </form>
    <?php
    return (string) ob_get_clean();
}

$contact = null;
if ($withId > 0 && $withId !== $me) {
    $stmt = $pdo->prepare('SELECT id, name, role, avatar_mime, banner_mime FROM users WHERE id = ? AND activate = 1');
    $stmt->execute([$withId]);
    $contact = $stmt->fetch() ?: null;
}

$state = $contact ? socialDmState($pdo, $me, $withId) : null;
$mode = $state['mode'] ?? '';
$relation = $state['relation'] ?? 'none';
$thread = $state['thread'] ?? null;
$clearedMe = (int) ($thread['cleared_me'] ?? 0);
$stateKey = $contact ? $mode . ':' . ($thread['status'] ?? 'none') . ':' . $relation : '';

$replyText = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($self, $isFetch);
    $action = (string) ($_POST['action'] ?? '');

    if (!$contact) {
        $message = 'Essa conversa não existe.';
        if ($isFetch) {
            chatJson(404, ['ok' => false, 'error' => $message]);
        }
        setFlash('error', $message);
        redirect('contacts.php');
    }

    if ($action === 'reply') {
        $replyText = chatNormalize((string) ($_POST['body'] ?? ''));
        $error = chatValidate($replyText);
        $status = 422;

        if ($error === null) {
            try {
                $result = socialDmSend($pdo, $me, $withId, $replyText);
                if ($result[0]) {
                    $newId = (int) $result[1];
                    if ($isFetch) {
                        $people = [$me => ['id' => $me, 'name' => $user['name'], 'avatar_mime' => $user['avatar_mime']], $withId => $contact];
                        chatJson(200, [
                            'ok' => true,
                            'id' => $newId,
                            'kind' => $result[2],
                            'html' => dmRenderMessage(dmFetchOne($pdo, $newId), $me, $people, $self),
                        ]);
                    }
                    redirect($self . '#post-' . $newId);
                }
                $error = (string) $result[1];
                $status = (int) $result[2];
            } catch (Throwable $failure) {
                error_log('contacts.php:reply_failed: ' . $failure->getMessage());
                $error = 'Não foi possível enviar sua mensagem.';
                $status = 500;
            }
        }
        if ($isFetch) {
            chatJson($status, ['ok' => false, 'error' => $error]);
        }
        setFlash('error', $error);
    } elseif ($action === 'edit_post') {
        $post = dmFindMessage($pdo, (int) ($_POST['post_id'] ?? 0), $me, $withId);
        $newBody = chatNormalize((string) ($_POST['body'] ?? ''));

        if (!$post) {
            chatJson(404, ['ok' => false, 'error' => 'Essa mensagem não existe mais.']);
        }
        if ((int) $post['sender_id'] !== $me) {
            chatJson(403, ['ok' => false, 'error' => 'Você só pode editar as suas próprias mensagens.']);
        }
        if ($mode !== 'open') {
            chatJson(403, ['ok' => false, 'error' => 'Não é possível editar mensagens nesta conversa agora.']);
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
            $pdo->prepare('UPDATE private_messages SET body = ?, edited_at = NOW() WHERE id = ? AND sender_id = ?')
                ->execute([$newBody, (int) $post['id'], $me]);
        }
        chatJson(200, ['ok' => true, 'body' => $newBody, 'edited' => $edited || $post['edited_at'] !== null]);
    } elseif ($action === 'delete_post') {
        $post = dmFindMessage($pdo, (int) ($_POST['post_id'] ?? 0), $me, $withId);
        $error = null;
        $status = 403;

        if (!$post) {
            $error = 'Essa mensagem não existe mais.';
            $status = 404;
        } elseif ((int) $post['sender_id'] !== $me) {
            $error = 'Você só pode excluir as suas próprias mensagens.';
        } elseif ((int) $post['age'] > CHAT_DELETE_SECONDS) {
            $error = 'O prazo de ' . (CHAT_DELETE_SECONDS / 60) . ' minutos para excluir essa mensagem já acabou.';
        }

        if ($error === null) {
            $pdo->prepare('DELETE FROM private_messages WHERE id = ? AND sender_id = ?')->execute([(int) $post['id'], $me]);
            socialThreadPruneEmptyPending($pdo, $me, $withId);
            if ($isFetch) {
                chatJson(200, ['ok' => true, 'id' => (int) $post['id']]);
            }
            setFlash('success', 'Mensagem excluída.');
        } else {
            if ($isFetch) {
                chatJson($status, ['ok' => false, 'error' => $error]);
            }
            setFlash('error', $error);
        }
        redirect($self);
    } else {
        if ($isFetch) {
            chatJson(400, ['ok' => false, 'error' => 'Solicitação inválida.']);
        }
        setFlash('error', 'Solicitação inválida.');
        redirect($self);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['poll'])) {
    if (!$contact) {
        chatJson(403, ['ok' => false, 'error' => 'Conversa indisponível.', 'html' => '', 'last_id' => 0]);
    }
    $after = max(0, (int) ($_GET['after'] ?? 0), $clearedMe);
    $stmt = $pdo->prepare(
        'SELECT id, sender_id, recipient_id, body, created_at, edited_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
         FROM private_messages
         WHERE id > ? AND sender_id = ? AND recipient_id = ?
         ORDER BY id LIMIT 50'
    );
    $stmt->execute([$after, $withId, $me]);
    $fresh = $stmt->fetchAll();
    $people = [$me => ['id' => $me, 'name' => $user['name'], 'avatar_mime' => $user['avatar_mime']], $withId => $contact];
    $html = '';
    $last = max($after, (int) ($_GET['after'] ?? 0));
    foreach ($fresh as $message) {
        $html .= dmRenderMessage($message, $me, $people, $self);
        $last = max($last, (int) $message['id']);
    }
    if ($fresh) {
        dmMarkRead($pdo, $me, $withId);
    }
    chatJson(200, ['ok' => true, 'html' => $html, 'last_id' => $last, 'count' => count($fresh), 'state' => $stateKey, 'mode' => $mode]);
}

if ($withId > 0 && !$contact) {
    setFlash('error', 'Essa conversa não existe.');
    redirect('contacts.php');
}

$conversations = socialConversations($pdo, $me);
$friends = socialFriends($pdo, $me);
$friendRequestsIn = socialRequests($pdo, $me, true);
$friendRequestsOut = socialRequests($pdo, $me, false);
$contactRequests = socialContactRequests($pdo, $me);
$blockedList = socialBlockedUsers($pdo, $me);
$ownStatus = socialOwnStatus($pdo, $me);

$unreadTotal = 0;
foreach ($conversations as $row) {
    if ((int) $row['id'] !== $withId) {
        $unreadTotal += (int) $row['unread'];
    }
}
$requestsTotal = count($friendRequestsIn) + count($contactRequests);

$conversation = [];
$moreOlder = false;
$lastId = 0;
$contactPresence = [];
if ($contact) {
    $limit = isset($_GET['all']) ? DM_PAGE_SIZE_ALL : DM_PAGE_SIZE;
    $stmt = $pdo->prepare(
        "SELECT * FROM (
            SELECT id, sender_id, recipient_id, body, created_at, edited_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
            FROM private_messages
            WHERE ((sender_id = :a1 AND recipient_id = :b1) OR (sender_id = :b2 AND recipient_id = :a2)) AND id > :cut
            ORDER BY id DESC LIMIT " . ($limit + 1) . "
         ) recent ORDER BY id"
    );
    $stmt->execute(['a1' => $me, 'b1' => $withId, 'b2' => $withId, 'a2' => $me, 'cut' => $clearedMe]);
    $conversation = $stmt->fetchAll();
    if (count($conversation) > $limit) {
        $moreOlder = true;
        array_shift($conversation);
    }
    foreach ($conversation as $row) {
        if ((int) $row['sender_id'] === $withId) {
            $lastId = max($lastId, (int) $row['id']);
        }
    }
    dmMarkRead($pdo, $me, $withId);

    if ($relation === 'friends') {
        $stmt = $pdo->prepare(
            'SELECT p.status AS presence_status, TIMESTAMPDIFF(SECOND, p.last_seen_at, NOW()) AS seen_age
             FROM users u LEFT JOIN user_presence p ON p.user_id = u.id WHERE u.id = ?'
        );
        $stmt->execute([$withId]);
        $contactPresence = $stmt->fetch() ?: [];
    }
}

$people = [$me => ['id' => $me, 'name' => $user['name'], 'avatar_mime' => $user['avatar_mime']]];
if ($contact) {
    $people[$withId] = $contact;
}

$chatReturn = 'chat:' . $withId;
$myTag = socialTag($user);

$pageId = 'contacts';
$pageTitle = $contact ? 'Conversa com ' . $contact['name'] : 'Contatos';
$extraCss = ['forum', 'profile', 'social', 'tabs', 'contacts'];
$extraJs = ['forum', 'tabs', 'contacts'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content" data-badge-total="<?= (int) $socialBadge['total'] ?>">
  <section id="contact-hero" class="contact-hero">
    <div class="contact-hero-main">
      <?= socialAvatar($user, 'friend-avatar friend-avatar-xl') ?>
      <div class="contact-hero-text">
        <h1 class="contact-hero-title">Contatos</h1>
        <button type="button" id="contacts-refresh" class="refresh-pill" hidden>Há novidades · atualizar</button>
      </div>
    </div>
    <div class="contact-hero-side">
      <div class="my-code">
        <span class="my-code-label">Seu código</span>
        <button type="button" class="tag-chip" data-copy="<?= e($myTag) ?>" aria-label="Copiar seu código <?= e($myTag) ?>">
          <span class="tag-chip-text" data-copy-label><?= e($myTag) ?></span>
          <?= dmIcon('copy', 18) ?>
        </button>
      </div>
      <div class="my-status">
        <span class="my-code-label">Meu status</span>
        <?= socialStatusPicker($ownStatus, $contact ? $chatReturn : 'contacts') ?>
      </div>
    </div>
  </section>

  <section id="conversations" class="section" data-tab="Conversas" data-tab-badge="<?= $unreadTotal ?>">
    <div class="dm-layout">
      <aside id="convo-side" class="dm-side" aria-label="Lista de conversas">
        <?php if ($conversations): ?>
          <div class="convo-search">
            <?= dmIcon('search', 20) ?>
            <input type="search" id="convo-filter" class="form-input" placeholder="Buscar conversa" aria-label="Buscar conversa" autocomplete="off" data-filter="#convo-list" data-filter-empty="#convo-none">
          </div>
          <ul id="convo-list" class="convo-list" data-banner-root>
            <?php foreach ($conversations as $index => $row): ?>
              <?php
              $rowId = (int) $row['id'];
              $isCurrent = $rowId === $withId;
              $unread = $isCurrent ? 0 : (int) $row['unread'];
              $last = $row['last'];
              $preview = $last
                  ? (((int) $last['sender_id'] === $me ? 'Você: ' : '') . socialPreview((string) $last['body']))
                  : 'Nenhuma mensagem ainda';
              $showDot = (int) $row['is_friend'] === 1 && !(int) $row['blocked_by_me'] && !(int) $row['blocked_me'];
              $rowAwaiting = $row['thread_status'] !== 'accepted' && (int) $row['initiator_id'] === $me && (int) $row['is_friend'] === 0;
              ?>
              <li class="convo-item<?= $isCurrent ? ' is-current' : '' ?><?= $unread > 0 ? ' has-unread' : '' ?><?= !empty($row['banner_mime']) ? ' has-banner' : '' ?>" style="--i: <?= min($index, 12) ?>" data-name="<?= e(mb_strtolower($row['name'])) ?>">
                <?php if (!empty($row['banner_mime'])): ?>
                  <img class="convo-banner" data-banner="../endpoints/image.php?user=<?= $rowId ?>&amp;type=banner" alt="" decoding="async">
                <?php endif; ?>
                <a class="convo-link" href="contacts.php?with=<?= $rowId ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
                  <span class="convo-avatar">
                    <?= socialAvatar($row, 'friend-avatar friend-avatar-md') ?>
                    <?= $showDot ? socialPresenceDot($row, $rowId) : '' ?>
                  </span>
                  <span class="convo-text">
                    <span class="convo-top">
                      <strong class="convo-name"><?= e($row['name']) ?></strong>
                      <span class="tag-id">#<?= $rowId ?></span>
                      <?php if ($last): ?>
                        <time class="convo-time"><?= e(socialShortTime((int) $last['age'])) ?></time>
                      <?php endif; ?>
                    </span>
                    <span class="convo-bottom">
                      <span class="convo-preview"><?= e($preview) ?></span>
                      <?php if ((int) $row['blocked_by_me']): ?>
                        <span class="convo-chip convo-chip-warn">Bloqueado</span>
                      <?php elseif ($rowAwaiting): ?>
                        <span class="convo-chip">Aguardando</span>
                      <?php elseif ((int) $row['is_friend'] === 0): ?>
                        <span class="convo-chip">Contato</span>
                      <?php endif; ?>
                      <?php if ($unread > 0): ?>
                        <span class="unread-badge" aria-label="<?= $unread ?> <?= $unread === 1 ? 'mensagem não lida' : 'mensagens não lidas' ?>"><?= $unread > 99 ? '99+' : $unread ?></span>
                      <?php endif; ?>
                    </span>
                  </span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
          <p id="convo-none" class="soft-note" hidden>Nenhuma conversa com esse nome.</p>
        <?php else: ?>
          <div class="soft-empty">
            <span class="soft-empty-art"><?= dmIcon('chat', 40) ?></span>
            <h3>Nada por aqui ainda</h3>
            <div class="soft-empty-actions">
              <a class="button button-primary button-small" href="#add">Adicionar alguém</a>
              <a class="button button-small" href="#friends">Ver amigos</a>
            </div>
          </div>
        <?php endif; ?>
      </aside>

      <div id="conversation" class="dm-main">
        <?php if (!$contact): ?>
          <div class="dm-empty dm-welcome">
            <span class="dm-welcome-art"><?= dmIcon('chat', 44) ?></span>
            <h2>Escolha uma conversa</h2>
          </div>
        <?php else: ?>
          <header class="dm-header<?= !empty($contact['banner_mime']) ? ' has-banner' : '' ?>">
            <?php if (!empty($contact['banner_mime'])): ?>
              <span class="dm-header-bg" aria-hidden="true"><img class="dm-header-banner" data-banner="../endpoints/image.php?user=<?= $withId ?>&amp;type=banner" alt="" decoding="async"></span>
            <?php endif; ?>
            <a class="button button-small dm-back" href="contacts.php">&larr; Conversas</a>
            <?= dmAvatar($contact) ?>
            <div class="dm-header-text">
              <h2 class="dm-header-name"><a href="profile-view.php?id=<?= $withId ?>"><?= e($contact['name']) ?></a> <span class="tag-id">#<?= $withId ?></span></h2>
              <?php if ($relation === 'friends'): ?>
                <?= socialPresenceBadge($contactPresence, $withId) ?>
              <?php else: ?>
                <span class="dm-header-meta"><?= e(socialRoleLabel($contact['role'])) ?><?= $relation === 'blocked_by_me' || $relation === 'blocked_me' ? '' : ' · ainda não são amigos' ?></span>
              <?php endif; ?>
            </div>
            <details class="dm-menu">
              <summary class="dm-menu-button" aria-label="Opções da conversa" title="Opções"><?= dmIcon('dots', 22) ?></summary>
              <div class="dm-menu-panel">
                <a class="dm-menu-item" href="profile-view.php?id=<?= $withId ?>">Ver perfil</a>
                <?php if ($relation === 'none' || $relation === 'blocked_me'): ?>
                  <?php if ($relation === 'none'): ?>
                    <?= dmFriendForm('friends', $withId, $chatReturn, 'request', 'Pedir amizade') ?>
                  <?php endif; ?>
                <?php elseif ($relation === 'sent'): ?>
                  <?= dmFriendForm('friends', $withId, $chatReturn, 'cancel', 'Cancelar pedido de amizade') ?>
                <?php elseif ($relation === 'received'): ?>
                  <?= dmFriendForm('friends', $withId, $chatReturn, 'accept', 'Aceitar amizade') ?>
                <?php elseif ($relation === 'friends'): ?>
                  <?= dmFriendForm('friends', $withId, $chatReturn, 'remove', 'Desfazer amizade', 'dm-menu-item', 'Desfazer a amizade com ' . $contact['name'] . '? Vocês deixam de ver o status um do outro. A conversa continua, a menos que você bloqueie.', 'Desfazer amizade', 'Sim, desfazer') ?>
                <?php endif; ?>
                <?php if ($thread && $mode !== 'incoming'): ?>
                  <?= dmFriendForm('conversations', $withId, 'contacts:conversations', 'clear', 'Remover do meu histórico', 'dm-menu-item dm-menu-item-danger', 'Apagar todo o histórico com ' . $contact['name'] . '? Isso vale só para a sua conta e não dá para voltar atrás. Se a outra pessoa mantiver a conversa, ela continua com tudo salvo.', 'Remover do histórico', 'Sim, apagar') ?>
                <?php endif; ?>
                <?php if ($relation === 'blocked_by_me'): ?>
                  <?= dmFriendForm('friends', $withId, $chatReturn, 'unblock', 'Desbloquear') ?>
                <?php else: ?>
                  <?= dmFriendForm('friends', $withId, $chatReturn, 'block', 'Bloquear', 'dm-menu-item dm-menu-item-danger', 'Bloquear ' . $contact['name'] . '? A amizade (ou pedido) será desfeita e esta pessoa não poderá enviar pedidos nem mensagens para você. O histórico continua na sua lista.', 'Bloquear usuário', 'Sim, bloquear') ?>
                <?php endif; ?>
              </div>
            </details>
          </header>

          <?php if ($mode === 'incoming'): ?>
            <div class="dm-notice dm-notice-request">
              <span class="dm-notice-icon"><?= dmIcon('hello', 24) ?></span>
              <div class="dm-notice-text">
                <strong><?= e($contact['name']) ?> quer conversar com você.</strong>
              </div>
              <div class="dm-notice-actions">
                <?= dmFriendForm('conversations', $withId, $chatReturn, 'accept', 'Aceitar', 'button button-primary button-small') ?>
                <?= dmFriendForm('conversations', $withId, $chatReturn, 'decline', 'Recusar', 'button button-small') ?>
                <?= dmFriendForm('friends', $withId, $chatReturn, 'block', 'Bloquear', 'button button-delete button-small', 'Bloquear ' . $contact['name'] . '? Esta pessoa não poderá mais enviar pedidos nem mensagens para você.', 'Bloquear usuário', 'Sim, bloquear') ?>
              </div>
            </div>
          <?php elseif ($mode === 'blocked_by_me'): ?>
            <div class="dm-notice dm-notice-warn">
              <span class="dm-notice-icon"><?= dmIcon('lock', 24) ?></span>
              <div class="dm-notice-text">
                <strong>Você bloqueou esta pessoa.</strong>
              </div>
              <div class="dm-notice-actions">
                <?= dmFriendForm('friends', $withId, $chatReturn, 'unblock', 'Desbloquear', 'button button-small') ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($moreOlder): ?>
            <a class="card-link" href="contacts.php?with=<?= $withId ?>&amp;all=1">Carregar mensagens mais antigas</a>
          <?php endif; ?>

          <ul
            id="message-list"
            class="chat-list dm-chat-list"
            data-action="<?= e($self) ?>"
            data-csrf="<?= e(csrfToken()) ?>"
            data-limit="<?= CHAT_MESSAGE_MAX ?>"
            data-max-lines="<?= CHAT_MESSAGE_MAX_LINES ?>"
            data-dm-with="<?= $withId ?>"
            data-dm-last="<?= $lastId ?>"
            data-dm-mode="<?= e($mode) ?>"
            data-dm-state="<?= e($stateKey) ?>"
            data-dm-poll="contacts.php?with=<?= $withId ?>&amp;poll=1"
            aria-live="polite"
          >
            <?php if (!$conversation): ?>
              <li class="dm-start" data-dm-start><?= $mode === 'first' ? 'Escreva sua primeira mensagem 👋' : 'Nenhuma mensagem ainda. Diga oi 👋' ?></li>
            <?php endif; ?>
            <?php foreach ($conversation as $message): ?>
              <?= dmRenderMessage($message, $me, $people, $self) ?>
            <?php endforeach; ?>
          </ul>

          <?php if (in_array($mode, ['open', 'first', 'awaiting'], true)): ?>
            <form id="reply-form" class="form write-sheet" action="<?= e($self) ?>" method="post"<?= $mode === 'awaiting' ? ' hidden' : '' ?>>
              <?= csrfInput() ?>
              <input type="hidden" name="action" value="reply">
              <label class="visually-hidden" for="reply-text">Mensagem para <?= e($contact['name']) ?></label>
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
              <div class="chat-tools write-tools">
                <div class="emoji-wrap">
                  <button type="button" class="emoji-toggle" data-emoji-target="reply-text" aria-label="Inserir emoji" aria-haspopup="true" aria-expanded="false">😊</button>
                </div>
                <small id="reply-counter" class="field-counter" aria-live="polite">0/<?= CHAT_MESSAGE_MAX ?></small>
                <button type="submit" id="reply-form-submit" class="button button-primary write-send">Enviar</button>
              </div>
            </form>
            <div id="dm-locked" class="write-locked" data-text-awaiting="Aguardando <?= e($contact['name']) ?> aceitar o contato."<?= $mode === 'awaiting' ? '' : ' hidden' ?>>
              <?= dmIcon('hourglass', 22) ?>
              <p>Aguardando <?= e($contact['name']) ?> aceitar o contato.</p>
            </div>
          <?php else: ?>
            <div id="dm-locked" class="write-locked">
              <?= dmIcon('lock', 22) ?>
              <p>
                <?php if ($mode === 'incoming'): ?>
                  Aceite o pedido para responder.
                <?php elseif ($mode === 'blocked_by_me'): ?>
                  Desbloqueie para voltar a conversar.
                <?php else: ?>
                  Não é possível enviar mensagens nesta conversa.
                <?php endif; ?>
              </p>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section id="friends" class="section" data-tab="Amigos">
    <h2 class="section-title">Amigos <span class="dm-count"><?= count($friends) ?></span></h2>
    <?php if (!$friends): ?>
      <div class="soft-empty">
        <span class="soft-empty-art"><?= dmIcon('users', 40) ?></span>
        <h3>Você ainda não tem amigos</h3>
        <div class="soft-empty-actions">
          <a class="button button-primary button-small" href="#add">Adicionar alguém</a>
        </div>
      </div>
    <?php else: ?>
      <div class="convo-search convo-search-wide">
        <?= dmIcon('search', 20) ?>
        <input type="search" class="form-input" placeholder="Buscar amigo" aria-label="Buscar amigo" autocomplete="off" data-filter="#friend-grid" data-filter-empty="#friend-none">
      </div>
      <div class="friends-scroll" data-banner-root>
      <ul id="friend-grid" class="people-grid">
        <?php foreach ($friends as $index => $person): ?>
          <?php $personId = (int) $person['id']; ?>
          <li class="person-card" style="--i: <?= min($index, 12) ?>" data-name="<?= e(mb_strtolower($person['name'])) ?>">
            <span class="person-banner"><?php if (!empty($person['banner_mime'])): ?><img class="person-banner-image" data-banner="../endpoints/image.php?user=<?= $personId ?>&amp;type=banner" alt="" decoding="async"><?php endif; ?></span>
            <a class="person-main" href="profile-view.php?id=<?= $personId ?>">
              <?= socialAvatar($person, 'friend-avatar friend-avatar-lg') ?>
              <span class="friend-text">
                <strong><?= e($person['name']) ?> <span class="tag-id">#<?= $personId ?></span></strong>
                <small><?= e(socialRoleLabel($person['role'])) ?></small>
                <?= socialPresenceBadge($person, $personId) ?>
              </span>
            </a>
            <div class="person-actions">
              <a class="button button-primary button-small" href="contacts.php?with=<?= $personId ?>">Mensagem</a>
              <a class="button button-small" href="profile-view.php?id=<?= $personId ?>">Perfil</a>
              <?= dmFriendForm('friends', $personId, 'contacts:friends', 'remove', 'Desfazer', 'button button-small', 'Desfazer a amizade com ' . $person['name'] . '? Vocês deixam de ver o status um do outro. A conversa continua, a menos que você bloqueie.', 'Desfazer amizade', 'Sim, desfazer') ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      </div>
      <p id="friend-none" class="soft-note" hidden>Nenhum amigo com esse nome.</p>
    <?php endif; ?>

    <?php if ($blockedList): ?>
      <details class="blocked-box">
        <summary>Usuários bloqueados <span class="dm-count"><?= count($blockedList) ?></span></summary>
        <ul class="blocked-list">
          <?php foreach ($blockedList as $person): ?>
            <li class="blocked-item">
              <span class="friend-text"><strong><?= e($person['name']) ?> <span class="tag-id">#<?= (int) $person['id'] ?></span></strong></span>
              <?= dmFriendForm('friends', (int) $person['id'], 'contacts:friends', 'unblock', 'Desbloquear', 'button button-small') ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>
  </section>

  <section id="requests" class="section" data-tab="Pedidos" data-tab-badge="<?= $requestsTotal ?>">
    <h2 class="section-title">Pedidos</h2>

    <?php if (!$contactRequests && !$friendRequestsIn && !$friendRequestsOut): ?>
      <div class="soft-empty">
        <span class="soft-empty-art"><?= dmIcon('inbox', 40) ?></span>
        <h3>Tudo em dia</h3>
      </div>
    <?php endif; ?>

    <?php if ($contactRequests): ?>
      <h3 class="group-title">Pedidos de contato <span class="dm-count"><?= count($contactRequests) ?></span></h3>
      <ul class="request-grid">
        <?php foreach ($contactRequests as $index => $person): ?>
          <?php $personId = (int) $person['id']; ?>
          <li class="request-card" style="--i: <?= min($index, 12) ?>">
            <span class="person-banner"><?php if (!empty($person['banner_mime'])): ?><img class="person-banner-image" data-banner="../endpoints/image.php?user=<?= $personId ?>&amp;type=banner" alt="" decoding="async"><?php endif; ?></span>
            <a class="person-main" href="profile-view.php?id=<?= $personId ?>">
              <?= socialAvatar($person, 'friend-avatar friend-avatar-lg') ?>
              <span class="friend-text">
                <strong><?= e($person['name']) ?> <span class="tag-id">#<?= $personId ?></span></strong>
                <small><?= e(socialRoleLabel($person['role'])) ?> · <?= e(socialShortTime((int) $person['age'])) ?></small>
              </span>
            </a>
            <?php if ($person['preview'] !== null && $person['preview'] !== ''): ?>
              <p class="request-quote"><?= e($person['preview']) ?></p>
            <?php endif; ?>
            <div class="person-actions">
              <?= dmFriendForm('conversations', $personId, 'contacts:requests', 'accept', 'Aceitar', 'button button-primary button-small') ?>
              <?= dmFriendForm('conversations', $personId, 'contacts:requests', 'decline', 'Recusar', 'button button-small') ?>
              <a class="button button-small" href="contacts.php?with=<?= $personId ?>">Abrir</a>
              <?= dmFriendForm('friends', $personId, 'contacts:requests', 'block', 'Bloquear', 'button button-delete button-small', 'Bloquear ' . $person['name'] . '? Esta pessoa não poderá mais enviar pedidos nem mensagens para você.', 'Bloquear usuário', 'Sim, bloquear') ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($friendRequestsIn): ?>
      <h3 class="group-title">Pedidos de amizade <span class="dm-count"><?= count($friendRequestsIn) ?></span></h3>
      <ul class="request-grid">
        <?php foreach ($friendRequestsIn as $index => $person): ?>
          <?php $personId = (int) $person['id']; ?>
          <li class="request-card" style="--i: <?= min($index, 12) ?>">
            <span class="person-banner"><?php if (!empty($person['banner_mime'])): ?><img class="person-banner-image" data-banner="../endpoints/image.php?user=<?= $personId ?>&amp;type=banner" alt="" decoding="async"><?php endif; ?></span>
            <a class="person-main" href="profile-view.php?id=<?= $personId ?>">
              <?= socialAvatar($person, 'friend-avatar friend-avatar-lg') ?>
              <span class="friend-text">
                <strong><?= e($person['name']) ?> <span class="tag-id">#<?= $personId ?></span></strong>
                <small><?= e(socialRoleLabel($person['role'])) ?></small>
              </span>
            </a>
            <div class="person-actions">
              <?= dmFriendForm('friends', $personId, 'contacts:requests', 'accept', 'Aceitar', 'button button-primary button-small') ?>
              <?= dmFriendForm('friends', $personId, 'contacts:requests', 'decline', 'Recusar', 'button button-small') ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($friendRequestsOut): ?>
      <h3 class="group-title">Enviados <span class="dm-count"><?= count($friendRequestsOut) ?></span></h3>
      <ul class="request-grid">
        <?php foreach ($friendRequestsOut as $index => $person): ?>
          <?php $personId = (int) $person['id']; ?>
          <li class="request-card" style="--i: <?= min($index, 12) ?>">
            <span class="person-banner"><?php if (!empty($person['banner_mime'])): ?><img class="person-banner-image" data-banner="../endpoints/image.php?user=<?= $personId ?>&amp;type=banner" alt="" decoding="async"><?php endif; ?></span>
            <a class="person-main" href="profile-view.php?id=<?= $personId ?>">
              <?= socialAvatar($person, 'friend-avatar friend-avatar-lg') ?>
              <span class="friend-text">
                <strong><?= e($person['name']) ?> <span class="tag-id">#<?= $personId ?></span></strong>
                <small>Aguardando resposta</small>
              </span>
            </a>
            <div class="person-actions">
              <?= dmFriendForm('friends', $personId, 'contacts:requests', 'cancel', 'Cancelar pedido', 'button button-small') ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section id="add" class="section" data-tab="Adicionar">
    <h2 class="section-title">Adicionar alguém</h2>
    <div class="add-grid">
      <div class="add-card">
        <h3>Seu código</h3>
        <button type="button" class="tag-chip tag-chip-big" data-copy="<?= e($myTag) ?>" aria-label="Copiar seu código <?= e($myTag) ?>">
          <span class="tag-chip-text" data-copy-label><?= e($myTag) ?></span>
          <?= dmIcon('copy', 22) ?>
        </button>
      </div>

      <form class="add-card add-form" action="../endpoints/conversations.php" method="post" data-tag-form novalidate>
        <?= csrfInput() ?>
        <input type="hidden" name="return" value="contacts:add">
        <h3>Encontrar pelo código</h3>
        <div class="form-group">
          <label class="visually-hidden" for="tag-input">Nome e número da pessoa</label>
          <input id="tag-input" name="tag" type="text" class="form-input tag-input" placeholder="nome#número" maxlength="80" autocomplete="off" autocapitalize="off" spellcheck="false" aria-describedby="tag-error" required>
          <p id="tag-error" class="chat-edit-error" role="alert" hidden></p>
        </div>
        <div class="add-actions">
          <button type="submit" name="action" value="friend_by_tag" class="button button-primary">Pedir amizade</button>
          <button type="submit" name="action" value="message_by_tag" class="button">Mandar mensagem</button>
        </div>
      </form>
    </div>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
