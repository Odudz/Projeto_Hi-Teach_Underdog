<?php
/**
 * Reativa uma conta desativada usando e-mail + frase recuperadora.
 * Recebe o formulário do modal "Recuperar conta" (auth/login.php).
 *
 * Por segurança a resposta de erro é sempre a mesma, exista a conta ou não,
 * e há um limite de tentativas erradas (ver includes/recovery.php).
 */
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/recovery.php';

$wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

function recoverRespond(bool $wantsJson, int $status, array $payload): never
{
    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
    setFlash($payload['ok'] ? 'success' : 'error', $payload['message']);
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.php');
}

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$phrase = (string) ($_POST['recovery_phrase'] ?? '');

$fieldErrors = [];
if ($email === '') {
    $fieldErrors['email'] = 'Informe o e-mail da conta que você quer reativar.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fieldErrors['email'] = 'Esse e-mail não parece válido. Use o formato nome@exemplo.com.';
}
if (trim($phrase) === '') {
    $fieldErrors['recovery_phrase'] = 'Escreva a frase recuperadora que você criou no cadastro.';
}
if ($fieldErrors) {
    recoverRespond($wantsJson, 422, [
        'ok' => false,
        'errors' => $fieldErrors,
        'message' => implode(' ', $fieldErrors),
    ]);
}

$key = recoveryEmailKey($email);
if (recoveryIsBlocked($pdo, $key)) {
    recoverRespond($wantsJson, 429, [
        'ok' => false,
        'blocked' => true,
        'message' => 'Muitas tentativas erradas. Por segurança, aguarde ' . recoveryWaitMinutes()
            . ' minutos para tentar de novo, ou fale com o suporte.',
    ]);
}

$stmt = $pdo->prepare('SELECT id, activate, recovery_phrase_hash FROM users WHERE email = ?');
$stmt->execute([$email]);
$account = $stmt->fetch();

$hash = $account['recovery_phrase_hash'] ?? null;
if (!$hash) {
    recoveryDummyVerify();
}
// A frase só reativa contas desativadas
$matches = $account && (int) $account['activate'] === 0 && verifyRecoveryPhrase($phrase, $hash);

if (!$matches) {
    recoveryRegisterFailure($pdo, $key);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email_hash = ? AND attempted_at >= ?');
    $stmt->execute([$key, date('Y-m-d H:i:s', time() - RECOVERY_WINDOW_SECONDS)]);
    $left = max(0, RECOVERY_MAX_FAILS_PER_EMAIL - (int) $stmt->fetchColumn());

    recoverRespond($wantsJson, 422, [
        'ok' => false,
        'message' => 'Não encontramos uma conta desativada com esse e-mail e essa frase. '
            . 'Confira os dois (maiúsculas e espaços a mais não atrapalham). A frase só funciona para contas desativadas. '
            . ($left > 0
                ? "Você ainda tem $left " . ($left === 1 ? 'tentativa' : 'tentativas') . ' antes de um bloqueio temporário.'
                : 'Você atingiu o limite de tentativas; aguarde ' . recoveryWaitMinutes() . ' minutos ou fale com o suporte.'),
        'left' => $left,
    ]);
}

$pdo->prepare('UPDATE users SET activate = 1 WHERE id = ?')->execute([(int) $account['id']]);
recoveryClearFailures($pdo, $key);

recoverRespond($wantsJson, 200, [
    'ok' => true,
    'message' => 'Conta reativada! Agora é só entrar com seu e-mail e sua senha.',
]);
