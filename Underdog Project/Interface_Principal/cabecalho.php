<?php
$pageId = $pageId ?? 'page';
$pageTitle = $pageTitle ?? 'Hi Teach';
$extraCss = $extraCss ?? [];
$openModal = $openModal ?? '';
$currentFile = basename($_SERVER['SCRIPT_NAME']);

$navItems = [
    ['index.php', 'Início'],
    ['subjects.php', 'Matérias'],
    ['live-class.php', 'Aulas ao vivo'],
    ['forum.php', 'Fóruns'],
    ['messages.php', 'Mensagens'],
];
if ($user && $user['role'] === 'teacher') {
    $navItems[] = ['teacher-dashboard.php', 'Painel do professor'];
}
if ($user && $user['role'] === 'admin') {
    $navItems[] = ['moderator-dashboard.php', 'Administração'];
}
if (!$user) {
    $navItems[] = ['login.php', 'Entrar'];
}

$headerInitials = '';
if ($user) {
    $nameParts = array_slice(preg_split('/\s+/', trim($user['name'])) ?: [], 0, 2);
    $headerInitials = mb_strtoupper(implode('', array_map(fn ($part) => mb_substr($part, 0, 1), $nameParts)));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | Hi Teach</title>
<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/themes.css">
<link rel="stylesheet" href="css/layout.css">
<link rel="stylesheet" href="css/components.css">
<link rel="stylesheet" href="css/modal.css">
<?php foreach ($extraCss as $sheet): ?>
<link rel="stylesheet" href="css/<?= e($sheet) ?>.css">
<?php endforeach; ?>
<link rel="stylesheet" href="css/settings.css">
<link rel="stylesheet" href="css/animations.css">
<script src="js/theme.js"></script>
</head>
<body id="page-<?= e($pageId) ?>" class="page page-<?= e($pageId) ?>"<?= $openModal !== '' ? ' data-open-modal="' . e($openModal) . '"' : '' ?>>
<header id="site-header" class="site-header">
  <a id="site-logo" class="site-logo" href="index.php">Hi Teach</a>
  <nav id="main-nav" class="main-nav">
    <ul class="nav-list">
      <?php foreach ($navItems as $index => [$href, $label]): ?>
        <li class="nav-item" style="--i: <?= $index ?>"><a class="nav-link" id="nav-<?= e(basename($href, '.php')) ?>" href="<?= e($href) ?>"<?= $currentFile === $href ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </nav>
  <div id="header-actions" class="header-actions">
    <?php if ($user): ?>
      <a id="header-avatar" class="header-avatar" href="profile.php" aria-label="Meu perfil" title="Meu perfil"<?= $currentFile === 'profile.php' ? ' aria-current="page"' : '' ?>>
        <?php if ($user['avatar_mime']): ?>
          <img class="header-avatar-image" src="image.php?user=<?= (int) $user['id'] ?>&amp;type=avatar" alt="">
        <?php else: ?>
          <span class="header-avatar-initials"><?= e($headerInitials) ?></span>
        <?php endif; ?>
      </a>
    <?php endif; ?>
    <button type="button" id="settings-button" class="settings-button" aria-label="Abrir opções" aria-haspopup="dialog" aria-expanded="false" data-modal-open="settings-modal">
      <svg class="settings-icon" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </button>
  </div>
</header>
<div id="flash-area" class="flash-area">
  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="alert alert-<?= e($_SESSION['flash'][0]) ?>" role="alert"><?= e($_SESSION['flash'][1]) ?></div>
    <?php unset($_SESSION['flash']); ?>
  <?php endif; ?>
</div>
