<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/rbac.php';

const REPORTS_PER_HOUR = 10;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
if (!$user) {
    setFlash('error', 'Entre na sua conta para enviar denúncias.');
    redirect('../auth/login.php');
}

$type = (string) ($_POST['target_type'] ?? '');
$targetId = (int) ($_POST['target_id'] ?? 0);
$category = (string) ($_POST['category'] ?? '');
$details = trim((string) ($_POST['details'] ?? ''));
$back = '../pages/forum.php';

$content = null;
if ($type === 'post' && $targetId > 0) {
    $stmt = $pdo->prepare('SELECT user_id, topic_id, body AS text FROM forum_posts WHERE id = ?');
    $stmt->execute([$targetId]);
    $content = $stmt->fetch();
    if ($content) {
        $back = '../pages/forum-topic.php?id=' . (int) $content['topic_id'] . '#post-' . $targetId;
    }
} elseif ($type === 'topic' && $targetId > 0) {
    $stmt = $pdo->prepare('SELECT user_id, id AS topic_id, CONCAT(title, " — ", body) AS text FROM forum_topics WHERE id = ?');
    $stmt->execute([$targetId]);
    $content = $stmt->fetch();
    if ($content) {
        $back = '../pages/forum-topic.php?id=' . $targetId;
    }
}

requireCsrf($back);

if (!$content) {
    setFlash('error', 'Conteúdo não encontrado.');
} elseif (!isset(RBAC_REPORT_CATEGORIES[$category])) {
    setFlash('error', 'Selecione um motivo válido para a denúncia.');
} elseif (mb_strlen($details) > 255) {
    setFlash('error', 'O detalhe da denúncia pode ter no máximo 255 caracteres.');
} elseif ((int) $content['user_id'] === (int) $user['id']) {
    setFlash('error', 'Você não pode denunciar sua própria postagem.');
} else {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND created_at >= NOW() - INTERVAL 1 HOUR');
    $stmt->execute([$user['id']]);

    if ((int) $stmt->fetchColumn() >= REPORTS_PER_HOUR) {
        setFlash('error', 'Você enviou muitas denúncias recentemente. Aguarde antes de enviar outra.');
    } else {
        try {
            $pdo->prepare(
                'INSERT INTO reports (reporter_id, target_type, target_id, target_user_id, category, details, snapshot)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $user['id'], $type, $targetId, $content['user_id'], $category,
                $details !== '' ? $details : null, mb_substr((string) $content['text'], 0, 500),
            ]);
            setFlash('success', 'Denúncia enviada com sucesso. Nossa equipe de moderação irá avaliar.');
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                setFlash('error', 'Você já denunciou este conteúdo anteriormente.');
            } else {
                error_log('report.php: ' . $e->getMessage());
                setFlash('error', 'Não foi possível enviar a denúncia.');
            }
        }
    }
}
redirect($back);