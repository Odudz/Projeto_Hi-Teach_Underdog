<?php
// Batimento de presença (a cada ~60 s) e troca de status.
// Responde JSON para o fetch do site e faz redirect quando o formulário é enviado sem JavaScript.
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/social.php';

$isFetch = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

function presenceJson(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../pages/profile.php');
}
if (!$user) {
    if ($isFetch) {
        presenceJson(401, ['ok' => false, 'error' => 'Entre na sua conta para continuar.']);
    }
    redirect('../auth/login.php');
}

socialEnsureSchema($pdo);
$me = (int) $user['id'];
$back = socialReturnUrl((string) ($_POST['return'] ?? 'profile'));
requireCsrf($back, $isFetch);

try {
    $status = $_POST['status'] ?? null;
    if (is_string($status) && $status !== '') {
        if (!socialSetStatus($pdo, $me, $status)) {
            if ($isFetch) {
                presenceJson(422, ['ok' => false, 'error' => 'Status inválido.']);
            }
            setFlash('error', 'Status inválido.');
            redirect($back);
        }
        if (!$isFetch) {
            setFlash('success', 'Status atualizado: ' . PRESENCE_STATUSES[$status] . '.');
        }
    } else {
        socialTouchPresence($pdo, $me);
    }

    if (!$isFetch) {
        redirect($back);
    }

    $friends = [];
    foreach (socialFriends($pdo, $me) as $friend) {
        $view = socialPresenceView($friend);
        $friends[] = ['id' => (int) $friend['id'], 'key' => $view['key'], 'label' => $view['label'], 'seen' => $view['seen']];
    }
    $badge = socialBadgeCounts($pdo, $me);
    presenceJson(200, [
        'ok' => true,
        'status' => socialOwnStatus($pdo, $me),
        'badge' => $badge,
        'friends' => $friends,
    ]);
} catch (Throwable $error) {
    error_log('presence.php: ' . $error->getMessage());
    if ($isFetch) {
        presenceJson(500, ['ok' => false, 'error' => 'Não foi possível atualizar agora.']);
    }
    setFlash('error', 'Não foi possível atualizar o status.');
    redirect($back);
}
