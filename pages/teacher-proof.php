<?php
include_once __DIR__ . '/../config/conexao.php';

if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    exit('Acesso negado.');
}

$proofId = (int) ($_GET['id'] ?? 0);
if ($proofId > 0) {
    // Um comprovante específico (o professor pode enviar vários)
    $stmt = $pdo->prepare('SELECT file_name FROM teacher_proofs WHERE id = ?');
    $stmt->execute([$proofId]);
} else {
    // Compatibilidade: primeiro comprovante do professor
    $stmt = $pdo->prepare('SELECT proof_path FROM teacher_profiles WHERE user_id = ?');
    $stmt->execute([(int) ($_GET['user'] ?? 0)]);
}
$fileName = $stmt->fetchColumn();

$path = __DIR__ . '/../storage/private/proofs/' . basename((string) $fileName);
if (!$fileName || !is_file($path)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$mimeTypes = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
$extension = pathinfo($path, PATHINFO_EXTENSION);

header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="comprovante.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
