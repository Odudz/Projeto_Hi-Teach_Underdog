<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/live.php';

// ---------------------------------------------------------------
// Acesso: precisa estar logado
// ---------------------------------------------------------------
$currentUser = liveCurrentUser();
if (!$currentUser) {
    setFlash('error', 'Faça login para assistir às lives.');
    redirect('../' . LIVE_LOGIN_PATH . '?next=live-class.php');
}

$liveId = (int) ($_GET['id'] ?? 0);
$live = liveFind($pdo, $liveId);
if (!$live) {
    setFlash('error', 'Live não encontrada.');
    redirect('live-class.php');
}

// Consulta rápida usada pelo JavaScript para saber se a live ainda está no ar
if (isset($_GET['check'])) {
    header('Content-Type: application/json');
    echo json_encode(['online' => (bool) $live['is_online']]);
    exit;
}

// O professor dono da live transmite por outra página
if ((int) $live['teacher_id'] === (int) $currentUser['id']) {
    redirect('live-teacher.php?id=' . $liveId);
}

if (!$live['is_online']) {
    setFlash('error', 'Essa live não está mais no ar.');
    redirect('live-class.php');
}

// JWT SEM moderador (aluno)
$jwt = gerarJwtJaas($live['room_key'], $currentUser['name'], 'u' . $currentUser['id'], false);

// A cada 15s confere se a live continua no ar; se acabou, volta para a lista
$checkUrlJs = json_encode('live-watch.php?id=' . $liveId . '&check=1');
$watchJs = <<<JS
var checkUrl = {$checkUrlJs};
setInterval(function () {
  fetch(checkUrl, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.online === false) {
        api.dispose();
        warning.textContent = 'A live foi encerrada. Voltando para a lista...';
        warning.hidden = false;
        setTimeout(function () { window.location.href = 'live-class.php'; }, 3000);
      }
    })
    .catch(function () {});
}, 15000);
JS;

$pageId = 'live-class';
$pageTitle = 'Assistindo: ' . $live['title'];
$baseUrl = '../';
$extraCss = ['live'];
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="live-watch-section" class="section">
    <h2 class="section-title"><?= e($live['title']) ?></h2>
    <p>Matéria: <strong><?= e($live['subject']) ?></strong> · Professor(a): <?= e($live['teacher_name']) ?></p>

    <?php
    renderJaasPlayer([
        'room' => $live['room_key'],
        'jwt' => $jwt,
        'config' => [
            'startWithAudioMuted' => true,   // aluno entra mutado
            'startWithVideoMuted' => true,   // e sem câmera
            'toolbarButtons' => ['chat', 'raisehand', 'fullscreen', 'hangup'],
        ],
        'exitUrl' => 'live-class.php',
        'extraJs' => $watchJs,
    ]);
    ?>

    <a id="link-back-lives" class="button" href="live-class.php">Voltar para as lives</a>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
