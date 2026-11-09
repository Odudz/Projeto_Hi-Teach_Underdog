<?php
$serverName = $_SERVER['SERVER_NAME'] ?? '';
$httpHost = $_SERVER['HTTP_HOST'] ?? '';
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

$isLocal = in_array($remoteAddr, ['127.0.0.1', '::1'], true)
    || str_contains($serverName, 'localhost')
    || str_contains($httpHost, 'localhost')
    || str_contains($serverName, '127.0.0.1')
    || str_contains($httpHost, '127.0.0.1');

$debug = getenv('HT_DEBUG') === '1' || $isLocal;
error_reporting(E_ALL);
// Ativa display_errors no seu computador para nunca mais dar tela branca misteriosa
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

date_default_timezone_set('America/Sao_Paulo');

mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if ($isLocal) {
    $dbHost = getenv('HT_DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('HT_DB_NAME') ?: 'hi_teach';
    $dbUser = getenv('HT_DB_USER') ?: 'root';
    $dbPass = getenv('HT_DB_PASS') !== false ? getenv('HT_DB_PASS') : '';
} else {
    $dbHost = getenv('HT_DB_HOST') ?: 'sql213.infinityfree.com';
    $dbName = getenv('HT_DB_NAME') ?: 'if0_43131727_hi_teach';
    $dbUser = getenv('HT_DB_USER') ?: 'if0_43131727';
    $dbPass = getenv('HT_DB_PASS') !== false ? getenv('HT_DB_PASS') : '6ZE8alv4Iy1fYH';
}

const SUPPORT_EMAIL = 'suporte@seudominio.com.br';

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);
} catch (PDOException $error) {
    if ($isLocal && ($error->getCode() == 1049 || str_contains($error->getMessage(), 'Unknown database'))) {
        try {
            $pdoRoot = new PDO("mysql:host=$dbHost;charset=utf8mb4", $dbUser, $dbPass);
            $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        } catch (Throwable $e) {
            error_log('conexao.php auto-db: ' . $e->getMessage());
            http_response_code(500);
            die("Não foi possível conectar ao banco de dados local '$dbName'. Crie o banco de dados no phpMyAdmin do XAMPP.");
        }
    } else {
        error_log('conexao.php: ' . $error->getMessage());
        http_response_code(500);
        die('Não foi possível conectar ao banco de dados no momento: ' . $error->getMessage());
    }
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

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function validateCsrfToken(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function requireCsrf(string $redirectTo, bool $json = false): void
{
    if (validateCsrfToken($_POST['csrf_token'] ?? null)) {
        return;
    }
    if ($json) {
        http_response_code(419);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Sua sessão expirou. Recarregue a página e tente de novo.']);
        exit;
    }
    setFlash('error', 'Sua sessão expirou para esta ação. Recarregue a página e tente novamente.');
    redirect($redirectTo);
}

$user = null;
if (!empty($_SESSION['uid'])) {
    $stmt = $pdo->prepare(
        'SELECT id, name, email, phone, bio, birth_date, role, avatar_mime, banner_mime, created_at,
                (recovery_phrase_hash IS NOT NULL) AS has_recovery_phrase
         FROM users WHERE id = ? AND activate = 1'
    );
    $stmt->execute([$_SESSION['uid']]);
    $user = $stmt->fetch() ?: null;

    if ($user === null) {
        unset($_SESSION['uid']);
    }
}
