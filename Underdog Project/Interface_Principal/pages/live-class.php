<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/live.php';

$currentUser = liveCurrentUser();
$isTeacher = liveIsTeacher($currentUser);
$old = ['title' => '', 'subject' => ''];

// ---------------------------------------------------------------
// Professor cria uma live
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isTeacher) {
        setFlash('error', 'Somente professores podem criar lives.');
        redirect('live-class.php');
    }
    if (!liveCsrfCheck()) {
        setFlash('error', 'Sessão expirada. Tente novamente.');
        redirect('live-class.php');
    }

    $old = [
        'title' => trim($_POST['title'] ?? ''),
        'subject' => trim($_POST['subject'] ?? ''),
    ];
    $errors = liveValidateInput($old['title'], $old['subject']);

    if (!$errors) {
        $active = liveTeacherActive($pdo, (int) $currentUser['id']);
        if ($active) {
            setFlash('error', 'Você já tem uma live no ar. Volte para ela ou encerre-a antes de criar outra.');
            redirect('live-teacher.php?id=' . (int) $active['id']);
        }

        $newId = liveCreate($pdo, (int) $currentUser['id'], $old['title'], $old['subject']);
        redirect('live-teacher.php?id=' . $newId);
    }

    setFlash('error', implode(' ', $errors));
}

$lives = $currentUser ? liveActiveList($pdo) : [];
$myActive = $isTeacher ? liveTeacherActive($pdo, (int) $currentUser['id']) : null;

$pageId = 'live-class';
$pageTitle = 'Aulas ao vivo';
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">

  <?php if ($isTeacher): ?>
  <section id="live-create-section" class="section">
    <h2 class="section-title">Criar live</h2>

    <?php if ($myActive): ?>
      <div class="gate">
        <h3 class="gate-title">Você já tem uma live no ar</h3>
        <p class="gate-text">“<?= e($myActive['title']) ?>” está em andamento.</p>
        <div class="gate-actions">
          <a id="link-my-live" class="button button-primary" href="live-teacher.php?id=<?= (int) $myActive['id'] ?>">Voltar para a minha live</a>
        </div>
      </div>
    <?php else: ?>
      <form id="live-create-form" class="form" action="live-class.php" method="post">
        <?= liveCsrfField() ?>

        <div class="form-group">
          <label class="form-label" for="live-title">Nome da live</label>
          <input
            type="text"
            id="live-title"
            name="title"
            class="form-input"
            maxlength="<?= LIVE_TITLE_MAX ?>"
            value="<?= e($old['title']) ?>"
            required
          >
        </div>

        <div class="form-group">
          <label class="form-label" for="live-subject">Matéria</label>
          <input
            type="text"
            id="live-subject"
            name="subject"
            class="form-input"
            maxlength="<?= LIVE_SUBJECT_MAX ?>"
            value="<?= e($old['subject']) ?>"
            required
          >
        </div>

        <button type="submit" id="live-create-form-submit" class="button button-primary">Iniciar live</button>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section id="live-player-section" class="section">
    <h2 class="section-title">Ao vivo agora!!</h2>

    <?php if (!$currentUser): ?>
      <div class="gate">
        <h3 class="gate-title">Entre para assistir</h3>
        <p class="gate-text">Faça login para ver e entrar nas lives que estão no ar.</p>
        <div class="gate-actions">
          <a id="link-live-login" class="button button-primary" href="../<?= LIVE_LOGIN_PATH ?>?next=live-class.php">Entrar</a>
          <a id="link-live-register" class="button" href="../<?= LIVE_REGISTER_PATH ?>">Cadastrar</a>
        </div>
      </div>

    <?php elseif (!$lives): ?>
      <p id="live-empty">Nenhuma live no ar agora. Volte em instantes!</p>

    <?php else: ?>
      <ul id="live-list" class="live-list">
        <?php foreach ($lives as $live): ?>
          <li class="live-item">
            <span class="live-item-title"><?= e($live['title']) ?></span>
            <p>Matéria: <?= e($live['subject']) ?> · Professor(a): <?= e($live['teacher_name']) ?></p>
            <a class="button button-primary" href="live-watch.php?id=<?= (int) $live['id'] ?>">Assistir</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

</main>

<?php if ($currentUser && !$isTeacher): ?>
<script>
  // Alunos: atualiza a lista sozinha para novas lives aparecerem
  setTimeout(function () { window.location.reload(); }, 30000);
</script>
<?php endif; ?>

<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
