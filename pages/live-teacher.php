<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/live.php';

// ---------------------------------------------------------------
// Acesso: logado, professor e dono desta live
// ---------------------------------------------------------------
$currentUser = liveCurrentUser();
if (!$currentUser) {
    redirect('../' . LIVE_LOGIN_PATH . '?next=live-class.php');
}
if (!liveIsTeacher($currentUser)) {
    setFlash('error', 'Somente professores podem transmitir lives.');
    redirect('live-class.php');
}

$liveId = (int) ($_GET['id'] ?? 0);
$live = liveFind($pdo, $liveId);
if (!$live || (int) $live['teacher_id'] !== (int) $currentUser['id']) {
    setFlash('error', 'Live não encontrada.');
    redirect('live-class.php');
}

// ---------------------------------------------------------------
// Ações (POST): ping (sinal de que segue no ar) e end (encerrar)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!liveCsrfCheck()) {
        if ($action === 'ping') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['live' => false]);
            exit;
        }
        setFlash('error', 'Sessão expirada. Tente novamente.');
        redirect('live-teacher.php?id=' . $liveId);
    }

    if ($action === 'ping') {
        liveHeartbeat($pdo, $liveId, (int) $currentUser['id']);
        $fresh = liveFind($pdo, $liveId);
        header('Content-Type: application/json');
        echo json_encode(['live' => $fresh && $fresh['status'] === 'live']);
        exit;
    }

    if ($action === 'end') {
        liveEnd($pdo, $liveId, (int) $currentUser['id']);
        setFlash('success', 'Live encerrada.');
        redirect('live-class.php');
    }
}

if ($live['status'] !== 'live') {
    setFlash('error', 'Essa live já foi encerrada.');
    redirect('live-class.php');
}

// JWT de moderador (professor)
$jwt = gerarJwtJaas($live['room_key'], $currentUser['name'], 'u' . $currentUser['id'], true);

// A cada 30s avisa o servidor que a live segue no ar
$csrfJs = json_encode(liveCsrfToken());
$heartbeatJs = <<<JS
var csrf = {$csrfJs};
function ping() {
  fetch(window.location.href, {
    method: 'POST',
    credentials: 'same-origin',
    body: new URLSearchParams({ action: 'ping', csrf: csrf })
  })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.live === false) { window.location.href = 'live-class.php'; }
    })
    .catch(function () {});
}
ping();
setInterval(ping, 30000);
JS;

$pageId = 'live-class';
$pageTitle = 'Transmitindo: ' . $live['title'];
$baseUrl = '../';
$extraCss = ['live'];
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="live-teacher-section" class="section">
    <h2 class="section-title">Transmitindo: <?= e($live['title']) ?></h2>
    <p>Matéria: <strong><?= e($live['subject']) ?></strong>. Permita o acesso à câmera e ao microfone quando o navegador pedir.</p>

    <?php
    renderJaasPlayer([
        'room' => $live['room_key'],
        'jwt' => $jwt,
        'config' => [
            'startWithAudioMuted' => false,
            'startWithVideoMuted' => false,
        ],
        // Desligar a chamada pelo botão do Jitsi também encerra a live
        'onCloseJs' => "document.getElementById('live-end-form').submit();",
        'extraJs' => $heartbeatJs,
    ]);
    ?>

    <form id="live-end-form" action="live-teacher.php?id=<?= (int) $liveId ?>" method="post">
      <?= liveCsrfField() ?>
      <input type="hidden" name="action" value="end">
      <button type="submit" id="live-end-submit" class="button button-danger">Encerrar live</button>
    </form>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
