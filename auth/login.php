<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/recovery.php';

if ($user) {
    redirect('../pages/profile.php');
}

$next = $_POST['next'] ?? $_GET['next'] ?? '';
$next = str_replace('\\', '/', $next);
if (preg_match('/^[a-z-]+\.php$/', $next) && is_file(__DIR__ . '/../pages/' . $next)) {
    $nextRedirect = '../pages/' . $next;
} elseif (preg_match('/^pages\/[a-z-]+\.php$/', $next) && is_file(__DIR__ . '/../' . $next)) {
    $nextRedirect = '../' . $next;
} elseif (in_array($next, ['index.php', 'activity.php'], true) && is_file(__DIR__ . '/../' . $next)) {
    $nextRedirect = '../' . $next;
} else {
    $next = '';
    $nextRedirect = '';
}

const LOGIN_WINDOW_SECONDS = 900;
const LOGIN_MAX_FAILS_PER_EMAIL = 5;
const LOGIN_MAX_FAILS_PER_IP = 20;

function loginEmailKey(string $email): string
{
    return hash('sha256', 'login:' . $email);
}

function loginIsBlocked(PDO $pdo, string $emailKey): bool
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_SECONDS);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email_hash = ? AND attempted_at >= ?');
    $stmt->execute([$emailKey, $since]);
    if ((int) $stmt->fetchColumn() >= LOGIN_MAX_FAILS_PER_EMAIL) {
        return true;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?');
    $stmt->execute([recoveryClientIp(), $since]);
    return (int) $stmt->fetchColumn() >= LOGIN_MAX_FAILS_PER_IP;
}

$email = '';
$recoverEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $emailKey = loginEmailKey($email);

    if (loginIsBlocked($pdo, $emailKey)) {
        setFlash('error', 'Muitas tentativas de login erradas. Por segurança, aguarde ' . recoveryWaitMinutes() . ' minutos e tente de novo.');
    } else {
        $stmt = $pdo->prepare('SELECT id, password_hash, activate FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $account = $stmt->fetch();

        $passwordOk = $account && password_verify($password, $account['password_hash']);

        if ($passwordOk && (int) $account['activate'] === 1) {
            $pdo->prepare('DELETE FROM login_attempts WHERE email_hash = ?')->execute([$emailKey]);
            $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$account['id']]);
            session_regenerate_id(true);
            $_SESSION['uid'] = $account['id'];
            redirect($nextRedirect !== '' ? $nextRedirect : '../pages/profile.php');
        }

        if ($passwordOk) {
            setFlash('error', 'Sua conta está desativada. Use a frase recuperadora que você criou no cadastro para reativá-la.');
            $openModal = 'recover-modal';
            $recoverEmail = $email;
        } else {
            recoveryRegisterFailure($pdo, $emailKey);
            setFlash('error', 'E-mail ou senha inválidos. Confira os dados; se a conta foi excluída, use “Recuperar conta”.');
        }
    }
}

$pageId = 'login';
$pageTitle = 'Entrar';
$baseUrl = '../';
$extraCss = ['login'];
$extraJs = ['login'];

$supportMailBase = 'mailto:' . SUPPORT_EMAIL . '?subject=' . rawurlencode('Recuperar minha conta no Hi Teach');

ob_start();
?>
<div id="recover-modal" class="modal-overlay">
  <section
    id="recover-dialog"
    class="modal modal-recover"
    role="dialog"
    aria-modal="true"
    aria-labelledby="recover-title"
  >
    <header class="modal-header">
      <h2 id="recover-title" class="modal-title">Recuperar conta</h2>
      <button type="button" class="modal-close" data-modal-close aria-label="Fechar">×</button>
    </header>

    <div id="recover-form-view" class="recover-body">
      <div class="recover-hero">
        <span class="recover-badge" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
        </span>
        <p class="recover-intro">
          Excluiu a conta sem querer ou ela foi invadida? Informe o e-mail dela e a <strong>frase recuperadora</strong> que você criou no cadastro para reativá-la.
        </p>
      </div>

      <form id="recover-form" class="form" action="recover.php" method="post" novalidate>
        <div id="recover-error" class="recover-error" role="alert" hidden></div>

        <div class="form-group">
          <label class="form-label" for="recover-email">E-mail da conta</label>
          <input
            type="email"
            id="recover-email"
            name="email"
            class="form-input"
            maxlength="100"
            autocomplete="email"
            value="<?= e($recoverEmail) ?>"
            aria-describedby="recover-email-error"
            required
          >
          <p id="recover-email-error" class="recover-field-error" hidden></p>
        </div>

        <div class="form-group">
          <label class="form-label" for="recover-phrase">Frase recuperadora</label>
          <input
            type="text"
            id="recover-phrase"
            name="recovery_phrase"
            class="form-input"
            maxlength="<?= RECOVERY_MAX_LENGTH ?>"
            placeholder="Ex.: <?= e(RECOVERY_EXAMPLE) ?>"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            aria-describedby="recover-phrase-hint recover-phrase-error"
            required
          >
          <small id="recover-phrase-hint" class="form-note">Maiúsculas e espaços a mais não atrapalham.</small>
          <p id="recover-phrase-error" class="recover-field-error" hidden></p>
        </div>

        <button type="submit" id="recover-submit" class="button button-primary">Reativar minha conta</button>
      </form>

      <div class="recover-support">
        <p class="recover-support-title">Não lembra da frase?</p>
        <p class="recover-support-text">Sem problema: fale com o suporte. Vamos confirmar seus dados e reativar a conta para você.</p>
        <a
          id="recover-support-link"
          class="button"
          href="<?= e($supportMailBase) ?>"
          data-mail-base="<?= e($supportMailBase) ?>"
        >Falar com o suporte</a>
      </div>
    </div>

    <div id="recover-success" class="recover-body recover-done" hidden>
      <span class="recover-badge recover-badge-ok" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      </span>
      <h3 class="recover-done-title">Conta reativada!</h3>
      <p class="recover-intro">Pronto. Agora é só entrar com seu e-mail e sua senha de sempre.</p>
      <button type="button" id="recover-done" class="button button-primary" data-modal-close>Voltar para o login</button>
    </div>
  </section>
</div>
<?php
$modals = ob_get_clean();

include_once __DIR__ . '/../includes/cabecalho.php';
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
          value="<?= e($email) ?>"
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
    <p class="form-note">
      Excluiu sua conta ou ela foi desativada?
      <button type="button" id="open-recover" class="link-button" aria-haspopup="dialog" data-modal-open="recover-modal">Recuperar conta</button>
    </p>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
