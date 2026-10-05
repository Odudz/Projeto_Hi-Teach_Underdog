<?php
include_once __DIR__ . '/../config/conexao.php';

if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    exit('Acesso negado.');
}

$stmt = $pdo->prepare('SELECT proof_path FROM teacher_profiles WHERE user_id = ?');
$stmt->execute([(int) ($_GET['user'] ?? 0)]);
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
readfile($path);
