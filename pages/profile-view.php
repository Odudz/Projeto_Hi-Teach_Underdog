<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/social.php';

$profileId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT id, name, bio, role, avatar_mime, banner_mime, created_at
     FROM users
     WHERE id = ? AND activate = 1'
);
$stmt->execute([$profileId]);
$profile = $stmt->fetch();

if (!$profile) {
    setFlash('error', 'Perfil não encontrado.');
    redirect('forum.php');
}

$roleLabels = [
    'student' => 'Aluno',
    'teacher' => 'Professor',
    'admin' => 'Administrador',
];
$roleLabel = $roleLabels[$profile['role']] ?? 'Usuário';

$initials = mb_strtoupper(
    implode(
        '',
        array_map(
            fn ($part) => mb_substr($part, 0, 1),
            array_slice(preg_split('/\s+/', trim($profile['name'])) ?: [], 0, 2)
        )
    )
);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM forum_topics WHERE user_id = ?');
$stmt->execute([$profileId]);
$forumTopicsCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM forum_posts WHERE user_id = ?');
$stmt->execute([$profileId]);
$forumPostsCount = (int) $stmt->fetchColumn();

if ($user && (int) $user['id'] === (int) $profile['id']) {
    redirect('profile.php');
}

// Fóruns (tópicos) abertos em que a pessoa criou ou participou.
$stmt = $pdo->prepare(
    'SELECT t.id, t.title, s.name AS subject, (t.user_id = :owner1) AS is_owner,
            (SELECT COUNT(*) FROM forum_posts p WHERE p.topic_id = t.id AND p.user_id = :owner2) AS posts_by_user,
            (SELECT COUNT(*) FROM forum_posts p WHERE p.topic_id = t.id) AS posts_total,
            GREATEST(t.created_at, COALESCE((SELECT MAX(p.created_at) FROM forum_posts p WHERE p.topic_id = t.id), t.created_at)) AS last_activity
     FROM forum_topics t
     JOIN subjects s ON s.id = t.subject_id
     WHERE t.is_closed = 0
       AND (t.user_id = :owner3 OR EXISTS (SELECT 1 FROM forum_posts p WHERE p.topic_id = t.id AND p.user_id = :owner4))
     ORDER BY last_activity DESC
     LIMIT 13'
);
$stmt->execute(['owner1' => $profileId, 'owner2' => $profileId, 'owner3' => $profileId, 'owner4' => $profileId]);
$activeTopics = $stmt->fetchAll();
$hasMoreTopics = count($activeTopics) > 12;
$activeTopics = array_slice($activeTopics, 0, 12);

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM forum_topics t
     WHERE t.is_closed = 1
       AND (t.user_id = :owner1 OR EXISTS (SELECT 1 FROM forum_posts p WHERE p.topic_id = t.id AND p.user_id = :owner2))'
);
$stmt->execute(['owner1' => $profileId, 'owner2' => $profileId]);
$closedTopicsCount = (int) $stmt->fetchColumn();

// Amizade e presença: só quem é amigo vê o status e o "visto por último".
$relation = 'none';
$presenceRow = null;
if ($user) {
    socialEnsureSchema($pdo);
    $relation = socialRelation($pdo, (int) $user['id'], $profileId);
    if ($relation === 'friends') {
        $stmt = $pdo->prepare(
            'SELECT status AS presence_status, TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) AS seen_age FROM user_presence WHERE user_id = ?'
        );
        $stmt->execute([$profileId]);
        $presenceRow = $stmt->fetch() ?: [];
    }
}
$viewReturn = 'view:' . $profileId;

$pageId = 'profile-view';
$pageTitle = 'Perfil de ' . $profile['name'];
$extraCss = ['profile', 'social'];
$extraJs = ['forum'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="profile-header" class="profile-header">
    <div id="profile-banner" class="profile-banner">
      <?php if ($profile['banner_mime']): ?>
        <img
          id="profile-banner-image"
          class="profile-banner-image"
          src="../endpoints/image.php?user=<?= (int) $profile['id'] ?>&amp;type=banner"
          alt=""
        >
      <?php endif; ?>
    </div>

    <div class="profile-identity">
      <div id="profile-avatar" class="profile-avatar">
        <?php if ($profile['avatar_mime']): ?>
          <img
            id="profile-avatar-image"
            class="profile-avatar-image"
            src="../endpoints/image.php?user=<?= (int) $profile['id'] ?>&amp;type=avatar"
            alt="Foto de perfil de <?= e($profile['name']) ?>"
          >
        <?php else: ?>
          <span id="profile-avatar-initials" class="profile-avatar-initials"><?= e($initials) ?></span>
        <?php endif; ?>
      </div>

      <div class="profile-summary">
        <h1 id="profile-name" class="profile-name"><?= e($profile['name']) ?></h1>
        <span id="profile-role" class="profile-role"><?= e($roleLabel) ?></span>
        <span id="profile-id" class="profile-id" title="Código para adicionar esta pessoa"><?= e(socialTag($profile)) ?></span>
        <?php if ($presenceRow !== null): ?>
          <p class="profile-presence"><?= socialPresenceBadge($presenceRow, $profileId) ?></p>
        <?php endif; ?>

        <?php if (!empty($profile['bio'])): ?>
          <p id="profile-bio" class="profile-bio"><?= nl2br(e($profile['bio'])) ?></p>
        <?php else: ?>
          <p id="profile-bio" class="profile-bio">Este usuário ainda não adicionou uma biografia.</p>
        <?php endif; ?>
      </div>

      <div class="profile-actions">
        <a class="button button-secondary" href="forum.php">&larr; Voltar aos fóruns</a>
      </div>
    </div>
  </section>

  <section id="profile-stats" class="profile-stats">
    <div class="stat-card">
      <span class="stat-value"><?= $forumTopicsCount ?></span>
      <span class="stat-label">Fóruns criados</span>
    </div>
    <div class="stat-card">
      <span class="stat-value"><?= $forumPostsCount ?></span>
      <span class="stat-label">Mensagens em fóruns</span>
    </div>
    <div class="stat-card">
      <span class="stat-value"><?= e(date('d/m/Y', strtotime($profile['created_at']))) ?></span>
      <span class="stat-label">Membro desde</span>
    </div>
  </section>

  <section id="profile-friendship" class="section">
    <h2 class="section-title">Amizade</h2>
    <div class="friend-card">
      <div class="friend-card-text">
        <?php if (!$user): ?>
          <h3 class="friend-card-title">Quer adicionar <?= e($profile['name']) ?>?</h3>
          <p class="form-note">Entre na sua conta para enviar um pedido de amizade.</p>
        <?php elseif ($relation === 'friends'): ?>
          <h3 class="friend-card-title">Vocês são amigos</h3>
          <p class="form-note">Você pode trocar mensagens privadas e ver o status e o "visto por último" um do outro.</p>
        <?php elseif ($relation === 'sent'): ?>
          <h3 class="friend-card-title">Pedido enviado</h3>
          <p class="form-note">Aguardando <?= e($profile['name']) ?> responder.</p>
        <?php elseif ($relation === 'received'): ?>
          <h3 class="friend-card-title"><?= e($profile['name']) ?> quer ser seu amigo</h3>
          <p class="form-note">Aceite para trocar mensagens e ver o status um do outro.</p>
        <?php elseif ($relation === 'blocked_by_me'): ?>
          <h3 class="friend-card-title">Você bloqueou este usuário</h3>
          <p class="form-note">Ele não pode enviar pedidos nem mensagens para você.</p>
        <?php else: ?>
          <h3 class="friend-card-title">Adicionar <?= e($profile['name']) ?> como amigo</h3>
          <p class="form-note">Amigos trocam mensagens privadas e veem o status e o "visto por último" um do outro.</p>
        <?php endif; ?>
      </div>

      <?php if (!$user): ?>
        <div class="friend-card-actions">
          <a class="button button-primary" href="../auth/login.php?next=pages/forum.php">Entrar</a>
        </div>
      <?php else: ?>
        <div class="friend-card-actions">
          <?php
          $friendAction = function (string $action, string $label, string $class = '', string $confirm = '', string $title = '', string $yes = '') use ($profileId, $viewReturn): void {
              ?>
              <form method="post" action="../endpoints/friends.php"<?= $confirm !== '' ? ' data-confirm="' . e($confirm) . '" data-confirm-title="' . e($title) . '" data-confirm-yes="' . e($yes) . '"' : '' ?>>
                <?= csrfInput() ?>
                <input type="hidden" name="user_id" value="<?= $profileId ?>">
                <input type="hidden" name="return" value="<?= e($viewReturn) ?>">
                <button type="submit" name="action" value="<?= e($action) ?>" class="button <?= e($class) ?>"><?= e($label) ?></button>
              </form>
              <?php
          };

          if ($relation === 'none' || $relation === 'blocked_me') {
              $friendAction('request', 'Enviar pedido de amizade', 'button-primary');
              if ($relation === 'none') {
                  ?>
                  <a class="button" href="contacts.php?with=<?= $profileId ?>">Enviar mensagem</a>
                  <?php
              }
          } elseif ($relation === 'sent') {
              $friendAction('cancel', 'Cancelar pedido');
              ?>
              <a class="button" href="contacts.php?with=<?= $profileId ?>">Enviar mensagem</a>
              <?php
          } elseif ($relation === 'received') {
              $friendAction('accept', 'Aceitar', 'button-primary');
              $friendAction('decline', 'Recusar');
              ?>
              <a class="button" href="contacts.php?with=<?= $profileId ?>">Enviar mensagem</a>
              <?php
          } elseif ($relation === 'friends') {
              ?>
              <a class="button button-primary" href="contacts.php?with=<?= $profileId ?>">Enviar mensagem</a>
              <?php
              $friendAction('remove', 'Desfazer amizade', '', 'Desfazer a amizade com ' . $profile['name'] . '? Vocês deixam de ver o status um do outro e de trocar mensagens.', 'Desfazer amizade', 'Sim, desfazer');
          }

          if ($relation === 'blocked_by_me') {
              $friendAction('unblock', 'Desbloquear');
          } else {
              $friendAction('block', 'Bloquear', 'button-delete', 'Bloquear ' . $profile['name'] . '? A amizade (ou pedido) será desfeita e esta pessoa não poderá enviar pedidos nem mensagens para você.', 'Bloquear usuário', 'Sim, bloquear');
          }
          ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section id="profile-forums" class="section">
    <h2 class="section-title">Fóruns ativos</h2>
    <?php if (!$activeTopics): ?>
      <p class="social-empty">
        <?= e($profile['name']) ?> não tem fóruns abertos no momento<?= $closedTopicsCount > 0 ? ' (' . $closedTopicsCount . ' já encerrado' . ($closedTopicsCount > 1 ? 's' : '') . ')' : '' ?>.
      </p>
    <?php else: ?>
      <ul id="profile-forum-list" class="topic-list">
        <?php foreach ($activeTopics as $topic): ?>
          <li class="topic-item">
            <a class="topic-item-title" href="forum-topic.php?id=<?= (int) $topic['id'] ?>"><?= e($topic['title']) ?></a>
            <span class="topic-item-meta">
              <span class="topic-pill"><?= e($topic['subject']) ?></span>
              <?php if ((int) $topic['is_owner'] === 1): ?>
                <span class="topic-pill topic-pill-owner">Criou este fórum</span>
              <?php endif; ?>
              <?php if ((int) $topic['posts_by_user'] > 0): ?>
                <span class="topic-pill"><?= (int) $topic['posts_by_user'] ?> <?= (int) $topic['posts_by_user'] === 1 ? 'mensagem' : 'mensagens' ?> neste fórum</span>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="form-note">
        <?= $hasMoreTopics ? 'Mostrando os 12 com atividade mais recente. ' : '' ?>
        <?= $closedTopicsCount > 0 ? $closedTopicsCount . ' fórum' . ($closedTopicsCount > 1 ? 's' : '') . ' encerrado' . ($closedTopicsCount > 1 ? 's' : '') . ' não ' . ($closedTopicsCount > 1 ? 'aparecem' : 'aparece') . ' aqui.' : '' ?>
      </p>
    <?php endif; ?>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
