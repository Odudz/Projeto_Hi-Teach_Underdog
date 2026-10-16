<?php
/**
 * Módulo de lives (aulas ao vivo) do Hi Teach.
 *
 * Aqui ficam as regras de negócio e o acesso ao banco.
 * Este arquivo NÃO imprime HTML: as páginas (pages/live-*.php) só chamam estas funções.
 *
 * Depende de: $pdo e $user (config/conexao.php).
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/jaas/jaas_jwt.php';
require_once __DIR__ . '/jaas/jaas_player.php';

// ---------------------------------------------------------------
// AJUSTES DO MÓDULO (mexa só aqui)
// ---------------------------------------------------------------

const LIVE_TITLE_MAX = 80;      // tamanho máximo do nome da live
const LIVE_SUBJECT_MAX = 60;    // tamanho máximo da matéria

// Se o professor ficar esse tempo (em segundos) sem dar sinal, a live some da lista.
const LIVE_STALE_SECONDS = 180;

// Caminhos (a partir da raiz do site) das telas de login e cadastro.
// Ajuste para a pasta onde estão o seu login.php e register.php.
const LIVE_LOGIN_PATH = 'auth/login.php';
const LIVE_REGISTER_PATH = 'auth/register.php';

// ---------------------------------------------------------------
// USUÁRIO E PERMISSÕES
// ---------------------------------------------------------------

/**
 * Usuário logado (o mesmo $user do config/conexao.php: id, name, role...) ou null.
 */
function liveCurrentUser(): ?array
{
    global $user;
    return $user ?: null;
}

/**
 * Só professor cria e transmite lives. É a ÚNICA função que decide isso.
 * No Hi Teach o papel 'teacher' só é dado depois que o administrador analisa o comprovante.
 */
function liveIsTeacher(?array $user): bool
{
    return $user !== null && ($user['role'] ?? '') === 'teacher';
}

// ---------------------------------------------------------------
// CSRF (protege os formulários de criar e encerrar live)
// ---------------------------------------------------------------

function liveCsrfToken(): string
{
    if (empty($_SESSION['live_csrf'])) {
        $_SESSION['live_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['live_csrf'];
}

function liveCsrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(liveCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function liveCsrfCheck(): bool
{
    $sent = (string) ($_POST['csrf'] ?? '');
    return $sent !== '' && hash_equals($_SESSION['live_csrf'] ?? '', $sent);
}

// ---------------------------------------------------------------
// VALIDAÇÃO
// ---------------------------------------------------------------

/**
 * @return string[] lista de erros (vazia = tudo certo)
 */
function liveValidateInput(string $title, string $subject): array
{
    $errors = [];

    $titleLength = mb_strlen($title);
    if ($titleLength < 3 || $titleLength > LIVE_TITLE_MAX) {
        $errors[] = 'O nome da live deve ter de 3 a ' . LIVE_TITLE_MAX . ' caracteres.';
    }

    $subjectLength = mb_strlen($subject);
    if ($subjectLength < 2 || $subjectLength > LIVE_SUBJECT_MAX) {
        $errors[] = 'Informe a matéria (de 2 a ' . LIVE_SUBJECT_MAX . ' caracteres).';
    }

    return $errors;
}

// ---------------------------------------------------------------
// BANCO DE DADOS
// ---------------------------------------------------------------

/**
 * Trecho SQL que significa "no ar": status 'live' e professor deu sinal há pouco.
 * (o tempo vem de uma constante inteira, por isso é seguro concatenar)
 */
function liveOnlineSql(string $alias = 'l'): string
{
    return "($alias.status = 'live' AND $alias.last_seen_at >= NOW() - INTERVAL " . (int) LIVE_STALE_SECONDS . ' SECOND)';
}

/**
 * Cria a live e devolve o id. A chave da sala é aleatória.
 */
function liveCreate(PDO $pdo, int $teacherId, string $title, string $subject): int
{
    // Encerra lives antigas "penduradas" deste professor (navegador fechado, etc.)
    $pdo->prepare("UPDATE live_classes SET status = 'ended', ended_at = NOW() WHERE teacher_id = ? AND status = 'live'")
        ->execute([$teacherId]);

    $roomKey = bin2hex(random_bytes(12));

    $pdo->prepare('INSERT INTO live_classes (teacher_id, title, subject, room_key) VALUES (?, ?, ?, ?)')
        ->execute([$teacherId, $title, $subject, $roomKey]);

    return (int) $pdo->lastInsertId();
}

/**
 * Busca uma live (com o nome do professor e o campo is_online).
 */
function liveFind(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT l.*, u.name AS teacher_name, ' . liveOnlineSql('l') . ' AS is_online
           FROM live_classes l
           JOIN users u ON u.id = l.teacher_id
          WHERE l.id = ?'
    );
    $stmt->execute([$id]);
    $live = $stmt->fetch();

    return $live ?: null;
}

/**
 * Lives no ar agora (para a aba de lives dos alunos).
 */
function liveActiveList(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT l.id, l.title, l.subject, l.started_at, u.name AS teacher_name
           FROM live_classes l
           JOIN users u ON u.id = l.teacher_id
          WHERE ' . liveOnlineSql('l') . '
          ORDER BY l.started_at DESC'
    );

    return $stmt->fetchAll();
}

/**
 * Live no ar deste professor (ou null).
 */
function liveTeacherActive(PDO $pdo, int $teacherId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT l.id, l.title
           FROM live_classes l
          WHERE l.teacher_id = ? AND ' . liveOnlineSql('l') . '
          LIMIT 1'
    );
    $stmt->execute([$teacherId]);
    $live = $stmt->fetch();

    return $live ?: null;
}

/**
 * Encerra a live (só o dono consegue).
 */
function liveEnd(PDO $pdo, int $id, int $teacherId): void
{
    $pdo->prepare("UPDATE live_classes SET status = 'ended', ended_at = NOW() WHERE id = ? AND teacher_id = ? AND status = 'live'")
        ->execute([$id, $teacherId]);
}

/**
 * "Ainda estou no ar": renova o sinal do professor.
 */
function liveHeartbeat(PDO $pdo, int $id, int $teacherId): void
{
    $pdo->prepare("UPDATE live_classes SET last_seen_at = NOW() WHERE id = ? AND teacher_id = ? AND status = 'live'")
        ->execute([$id, $teacherId]);
}
