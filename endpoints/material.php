<?php
require_once __DIR__ . '/../includes/course-content.php';
$stmt = $pdo->prepare('SELECT * FROM course_materials WHERE id=?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$material = $stmt->fetch();
if (!$material) {
    http_response_code(404);
    exit;
}
$course = courseFind($pdo,(int)$material['course_id']);
if (!$course || !courseCanWatch($pdo,$user,$course)) {
    http_response_code($user ? 403 : 401);
    exit;
}
if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]+$/D',$material['stored_name'])) {
    http_response_code(404);
    exit;
}
$file = COURSE_MATERIAL_DIR . '/' . $material['stored_name'];
if (!is_file($file)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $material['mime_type']);
header('Content-Length: ' . filesize($file));
header("Content-Disposition: attachment; filename=material; filename*=UTF-8''" . rawurlencode($material['original_name']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($file);