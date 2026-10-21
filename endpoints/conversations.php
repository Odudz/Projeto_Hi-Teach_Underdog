<?php
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

$action = (string) ($_POST['action'] ?? '');
$return = (string) ($_POST['return'] ?? 'contacts');
$target = (int) ($_POST['user_id'] ?? 0);
$back = socialReturnUrl($return, $target);
requireCsrf($back);

$me = (int) $user['id'];

try {
    if ($action === 'friend_by_tag' || $action === 'message_by_tag') {
        $back = socialReturnUrl('contacts:add');
        [$person, $problem] = socialFindByTag($pdo, (string) ($_POST['tag'] ?? ''), $me);
        if ($person === null) {
            setFlash('error', (string) $problem);
            redirect($back);
        }
        if ($action === 'message_by_tag') {
            redirect(socialReturnUrl('chat:' . (int) $person['id']));
        }
        $result = socialSendRequest($pdo, $me, (int) $person['id']);
        if ($result[0]) {
            $result[1] = 'Pedido de amizade enviado para ' . $person['name'] . '#' . (int) $person['id'] . '!';
        }
    } else {
        $result = match ($action) {
            'accept' => socialThreadRespond($pdo, $me, $target, true),
            'decline' => socialThreadRespond($pdo, $me, $target, false),
            'clear' => socialThreadClear($pdo, $me, $target),
            default => [false, 'Solicitação inválida.'],
        };
        if ($result[0] && ($action === 'decline' || $action === 'clear') && str_starts_with($return, 'chat:')) {
            $back = socialReturnUrl('contacts:conversations');
        }
    }
} catch (Throwable $error) {
    error_log('conversations.php:' . $action . ': ' . $error->getMessage());
    $result = [false, 'Não foi possível concluir a ação. Tente novamente.'];
}

setFlash($result[0] ? 'success' : 'error', $result[1]);
redirect($back);
