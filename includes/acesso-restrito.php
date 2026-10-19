<?php
$pageId = 'gate';
$baseUrl = $baseUrl ?? '';
$currentFile = basename($_SERVER['SCRIPT_NAME']);
$nextTarget = in_array($currentFile, ['index.php', 'activity.php'], true) ? $currentFile : 'pages/' . $currentFile;

if ($gate === 'login') {
    http_response_code(401);
    $pageTitle = 'Ops! Você precisa entrar';
    $text = 'Crie uma conta ou entre na sua para acessar esta página. É rapidinho!';
    $buttons = [
        ['Entrar', $baseUrl . 'auth/login.php?next=' . urlencode($nextTarget), 'button-primary'],
        ['Criar conta', $baseUrl . 'auth/register.php', 'button-secondary'],
    ];
} elseif ($gate === 'teacher') {
    http_response_code(403);

    $stmt = $pdo->prepare('SELECT status FROM teacher_profiles WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $isPending = $stmt->fetchColumn() === 'pending';

    $pageTitle = 'Área dos professores';
    $text = $isPending
        ? 'Seu cadastro de professor está em análise. Assim que for aprovado, este painel será liberado.'
        : 'Esta área é exclusiva para professores aprovados.';
    $buttons = [['Voltar ao perfil', $baseUrl . 'pages/profile.php', 'button-primary']];
} else {
    http_response_code(403);
    $pageTitle = 'Área restrita';
    $text = 'Esta área é exclusiva para administradores.';
    $buttons = [['Voltar ao perfil', $baseUrl . 'pages/profile.php', 'button-primary']];
}

include_once __DIR__ . '/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="gate" class="gate">
    <svg class="gate-art" viewBox="0 0 240 200" role="img" aria-label="Cadeado sorridente entre nuvens">
      <ellipse class="art-cloud" cx="46" cy="160" rx="36" ry="14"/>
      <ellipse class="art-cloud" cx="72" cy="148" rx="26" ry="18"/>
      <ellipse class="art-cloud" cx="196" cy="166" rx="34" ry="13"/>
      <ellipse class="art-cloud" cx="176" cy="154" rx="24" ry="16"/>
      <g class="art-lock">
        <path class="art-shackle" d="M88 96 V72 a32 32 0 0 1 64 0 V96"/>
        <rect class="art-body" x="66" y="92" width="108" height="86" rx="22"/>
        <circle class="art-eye" cx="105" cy="128" r="6"/>
        <circle class="art-eye" cx="135" cy="128" r="6"/>
        <ellipse class="art-cheek" cx="92" cy="143" rx="8" ry="5"/>
        <ellipse class="art-cheek" cx="148" cy="143" rx="8" ry="5"/>
        <path class="art-mouth" d="M112 145 q8 9 16 0"/>
      </g>
      <path class="art-star art-star-one" d="M206 38 l4 10 10 4 -10 4 -4 10 -4 -10 -10 -4 10 -4z"/>
      <path class="art-star art-star-two" d="M34 62 l3 7 7 3 -7 3 -3 7 -3 -7 -7 -3 7 -3z"/>
    </svg>
    <h1 class="gate-title"><?= e($pageTitle) ?></h1>
    <p class="gate-text"><?= e($text) ?></p>
    <div class="gate-actions">
      <?php foreach ($buttons as [$label, $href, $class]): ?>
        <a class="button <?= e($class) ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </section>
</main>
<?php
include_once __DIR__ . '/rodape.php';
exit;
