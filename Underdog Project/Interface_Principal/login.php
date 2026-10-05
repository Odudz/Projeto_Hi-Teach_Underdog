<?php
include_once 'conexao.php';

if ($user) {
    redirect('profile.php');
}

$next = $_POST['next'] ?? $_GET['next'] ?? '';
if (!preg_match('/^[a-z-]+\.php$/', $next) || in_array($next, ['login.php', 'register.php'], true) || !is_file(__DIR__ . '/' . $next)) {
    $next = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([$email]);
    $account = $stmt->fetch();

    if ($account && password_verify($password, $account['password_hash'])) {
        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$account['id']]);
        $_SESSION['uid'] = $account['id'];
        redirect($next !== '' ? $next : 'profile.php');
    }

    setFlash('error', 'E-mail ou senha inválidos.');
}

$pageId = 'login';
$pageTitle = 'Entrar';
include_once 'cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="login-section" class="section">
    <h2 class="section-title">Entrar</h2>
    <form id="login-form" class="form" action="login.php" method="post">
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="form-group">
        <label class="form-label" for="email">E-mail</label>
        <input
          type="email"
          id="email"
          name="email"
          class="form-input"
          maxlength="100"
          autocomplete="username"
          required
        >
      </div>
      <div class="form-group">
        <label class="form-label" for="password">Senha</label>
        <input
          type="password"
          id="password"
          name="password"
          class="form-input"
          maxlength="1024"
          autocomplete="current-password"
          required
        >
      </div>
      <button
        type="submit"
        id="login-form-submit"
        class="button button-primary"
      >Entrar</button>
    </form>
    <p class="form-note">Não tem conta? <a id="link-register" href="register.php">Cadastre-se</a></p>
  </section>
</main>
<?php include_once 'rodape.php'; ?>
