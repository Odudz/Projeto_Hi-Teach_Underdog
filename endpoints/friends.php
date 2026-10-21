<?php
// Ações de amizade (pedir, cancelar, aceitar, recusar, desfazer, bloquear, desbloquear).
// Sempre POST + CSRF, sempre volta para uma página fixa do site.
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/social.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../pages/contacts.php');
}
if (!$user) {
    setFlash('error', 'Entre na sua conta para continuar.');
    redirect('../auth/login.php');
}

socialEnsureSchema($pdo);

$target = (int) ($_POST['user_id'] ?? 0);
$return = (string) ($_POST['return'] ?? '');
$back = socialReturnUrl($return, $target);
requireCsrf($back);

$me = (int) $user['id'];
$action = (string) ($_POST['action'] ?? '');

try {
    $result = match ($action) {
        'request' => socialSendRequest($pdo, $me, $target),
        'cancel' => socialCancelRequest($pdo, $me, $target),
        'accept' => socialRespond($pdo, $me, $target, true),
        'decline' => socialRespond($pdo, $me, $target, false),
        'remove' => socialRemoveFriend($pdo, $me, $target),
        'block' => socialBlock($pdo, $me, $target),
        'unblock' => socialUnblock($pdo, $me, $target),
        default => [false, 'Solicitação inválida.'],
    };
} catch (Throwable $error) {
    error_log('friends.php:' . $action . ': ' . $error->getMessage());
    $result = [false, 'Não foi possível concluir a ação. Tente novamente.'];
}

setFlash($result[0] ? 'success' : 'error', $result[1]);

redirect($back);
