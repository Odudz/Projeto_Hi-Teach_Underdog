<?php
/**
 * RBAC do Hi Teach — Controle de Acesso e Auditoria
 */

const RBAC_ROLES = ['student', 'teacher', 'moderator', 'admin'];
const RBAC_ROLE_LABELS = [
    'student'   => 'Aluno',
    'teacher'   => 'Professor',
    'moderator' => 'Moderador',
    'admin'     => 'Administrador',
];
const RBAC_REPORT_CATEGORIES = [
    'abuse'         => 'Ofensa ou assédio',
    'spam'          => 'Spam',
    'inappropriate' => 'Conteúdo impróprio',
    'other'         => 'Outro motivo',
];
const RBAC_SANCTION_DAYS = [1, 7, 30, 90];

function rbacRoleLabel(string $role): string
{
    return RBAC_ROLE_LABELS[$role] ?? 'Usuário';
}

function rbacIs(?array $user, string ...$roles): bool
{
    return $user !== null && in_array($user['role'] ?? '', $roles, true);
}

function rbacClientIp(): ?string
{
    $packed = @inet_pton($_SERVER['REMOTE_ADDR'] ?? '');
    return $packed !== false ? $packed : null;
}

function rbacEnsureSchema(PDO $pdo): void
{
    if (!empty($_SESSION['rbac_schema_checked_v3'])) {
        return;
    }
    try {
        $tail = 'ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci';

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                actor_id INT UNSIGNED NULL DEFAULT NULL,
                action VARCHAR(50) NOT NULL,
                target_type VARCHAR(50) NOT NULL,
                target_id INT UNSIGNED NULL DEFAULT NULL,
                old_value TEXT NULL DEFAULT NULL,
                new_value TEXT NULL DEFAULT NULL,
                reason VARCHAR(255) NULL DEFAULT NULL,
                ip VARBINARY(16) NULL DEFAULT NULL,
                user_agent VARCHAR(255) NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_audit_action (action),
                KEY idx_audit_target (target_type, target_id)
            ) $tail"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_sanctions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                type ENUM('mute', 'suspension') NOT NULL,
                reason VARCHAR(255) NOT NULL,
                applied_by INT UNSIGNED NULL DEFAULT NULL,
                expires_at DATETIME NULL DEFAULT NULL,
                revoked_at DATETIME NULL DEFAULT NULL,
                revoked_by INT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_sanction_user (user_id)
            ) $tail"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS reports (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                reporter_id INT UNSIGNED NOT NULL,
                target_type ENUM('post', 'topic') NOT NULL,
                target_id INT UNSIGNED NOT NULL,
                target_user_id INT UNSIGNED NULL DEFAULT NULL,
                category VARCHAR(50) NOT NULL,
                details VARCHAR(255) NULL DEFAULT NULL,
                snapshot TEXT NULL DEFAULT NULL,
                status ENUM('open', 'resolved', 'dismissed') NOT NULL DEFAULT 'open',
                handled_by INT UNSIGNED NULL DEFAULT NULL,
                handled_at DATETIME NULL DEFAULT NULL,
                resolution VARCHAR(255) NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_report_status (status)
            ) $tail"
        );

        $_SESSION['rbac_schema_checked_v3'] = true;
    } catch (Throwable $e) {
        error_log('rbacEnsureSchema: ' . $e->getMessage());
    }
}

function ensureRole(array $roles, string $baseUrl = '../'): void
{
    global $pdo, $user;

    if ($user !== null && in_array($user['role'], $roles, true)) {
        return;
    }

    $gate = $user === null ? 'login' : ($roles === ['admin'] ? 'admin' : 'moderator');
    include __DIR__ . '/acesso-restrito.php';
    exit;
}

function ensureAdmin(string $baseUrl = '../'): void
{
    ensureRole(['admin'], $baseUrl);
}

function ensureModerator(string $baseUrl = '../'): void
{
    ensureRole(['moderator', 'admin'], $baseUrl);
}

function rbacAudit(
    PDO $pdo,
    ?int $actorId,
    string $action,
    string $targetType,
    ?int $targetId,
    ?array $old = null,
    ?array $new = null,
    ?string $reason = null
): void {
    $json = static fn (?array $data): ?string => $data === null
        ? null
        : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try {
        $pdo->prepare(
            'INSERT INTO audit_logs (actor_id, action, target_type, target_id, old_value, new_value, reason, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $actorId,
            $action,
            $targetType,
            $targetId,
            $json($old),
            $json($new),
            $reason !== null ? mb_substr($reason, 0, 255) : null,
            rbacClientIp(),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('rbacAudit: ' . $e->getMessage());
    }
}

function rbacApplySanction(PDO $pdo, array $actor, int $targetId, string $type, ?int $days, string $reason): void
{
    if (!in_array($type, ['mute', 'suspension'], true)) {
        throw new RuntimeException('Tipo de sanção inválido.');
    }
    if ($targetId === (int) $actor['id']) {
        throw new RuntimeException('Você não pode sancionar a si mesmo.');
    }

    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? FOR UPDATE');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target) {
        throw new RuntimeException('Usuário não encontrado.');
    }
    if ($target['role'] === 'admin' || ($target['role'] === 'moderator' && $actor['role'] !== 'admin')) {
        throw new RuntimeException('Você não tem permissão para sancionar este usuário.');
    }

    $pdo->prepare(
        'INSERT INTO user_sanctions (user_id, type, reason, applied_by, expires_at)
         VALUES (?, ?, ?, ?, ' . ($days === null ? 'NULL' : 'DATE_ADD(NOW(), INTERVAL ' . (int) $days . ' DAY)') . ')'
    )->execute([$targetId, $type, $reason, $actor['id']]);

    rbacAudit(
        $pdo, (int) $actor['id'],
        $type === 'suspension' ? 'USER_SUSPENDED' : 'USER_MUTED',
        'user', $targetId, null,
        ['type' => $type, 'days' => $days],
        $reason
    );
}

function rbacRevokeSanction(PDO $pdo, array $actor, int $sanctionId, string $reason): void
{
    $stmt = $pdo->prepare('SELECT id, user_id, type FROM user_sanctions WHERE id = ? AND revoked_at IS NULL FOR UPDATE');
    $stmt->execute([$sanctionId]);
    $sanction = $stmt->fetch();
    if (!$sanction) {
        throw new RuntimeException('Sanção não encontrada ou já revogada.');
    }

    $pdo->prepare('UPDATE user_sanctions SET revoked_at = NOW(), revoked_by = ? WHERE id = ?')
        ->execute([$actor['id'], $sanctionId]);

    rbacAudit(
        $pdo, (int) $actor['id'], 'SANCTION_REVOKED', 'user', (int) $sanction['user_id'],
        ['type' => $sanction['type'], 'sanction_id' => $sanctionId], null, $reason
    );
}

if (!empty($pdo)) {
    rbacEnsureSchema($pdo);
}