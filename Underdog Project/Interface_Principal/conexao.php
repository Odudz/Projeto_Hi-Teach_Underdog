<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$dbHost = '127.0.0.1';
$dbName = 'hi_teach';
$dbUser = 'hi_teach_app';
$dbPass = 'admin_pucpr';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $error) {
    die('Erro ao conectar no banco: ' . $error->getMessage());
}

function e($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [$type, $message];
}

$user = null;
if (!empty($_SESSION['uid'])) {
    $stmt = $pdo->prepare(
        'SELECT id, name, email, phone, bio, birth_date, role, avatar_mime, banner_mime, created_at
         FROM users WHERE id = ? AND is_active = 1'
    );
    $stmt->execute([$_SESSION['uid']]);
    $user = $stmt->fetch() ?: null;

    if ($user === null) {
        unset($_SESSION['uid']);
    }
}
