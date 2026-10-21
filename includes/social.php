<?php
// Amizades, bloqueios, presença (online/ocupado/ausente/invisível) e mensagens privadas.
// Todas as regras de negócio ficam aqui; as páginas só chamam estas funções.

const FRIENDS_MAX = 200;
const FRIEND_PENDING_OUT_MAX = 30;
const FRIEND_REQUESTS_PER_HOUR = 20;
const PRESENCE_ONLINE_SECONDS = 150;
const PRESENCE_HEARTBEAT_SECONDS = 60;
const DM_PENDING_OUT_MAX = 30;
const DM_NEW_PER_HOUR = 20;
const TAG_FAILS_MAX = 8;
const TAG_FAILS_WINDOW = 600;

const PRESENCE_STATUSES = [
    'online' => 'Online',
    'busy' => 'Ocupado',
    'away' => 'Ausente',
    'invisible' => 'Invisível',
];

/* ---------------------------------------------------------------- esquema */

/**
 * Cria as tabelas na primeira vez que o site roda com este código, para que o deploy
 * não dependa de lembrar de importar um SQL. O arquivo config/hi_teach.sql tem as mesmas tabelas.
 */
function socialEnsureSchema(PDO $pdo): void
{
    if (!empty($_SESSION['social_schema_v2'])) {
        return;
    }
    try {
        $present = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('friendships', 'user_blocks', 'private_messages', 'user_presence', 'dm_threads')"
        )->fetchColumn();

        if ($present < 5) {
            $hadThreads = (bool) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dm_threads'"
            )->fetchColumn();

            $tail = 'ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci';
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS friendships (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_a INT UNSIGNED NOT NULL,
                    user_b INT UNSIGNED NOT NULL,
                    requested_by INT UNSIGNED NOT NULL,
                    status ENUM('pending', 'accepted') NOT NULL DEFAULT 'pending',
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    responded_at TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_friend_pair (user_a, user_b),
                    KEY idx_friend_b (user_b, status),
                    KEY idx_friend_requester (requested_by, status, created_at),
                    CONSTRAINT fk_friend_a FOREIGN KEY (user_a) REFERENCES users (id) ON DELETE CASCADE,
                    CONSTRAINT fk_friend_b FOREIGN KEY (user_b) REFERENCES users (id) ON DELETE CASCADE,
                    CONSTRAINT fk_friend_req FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE CASCADE
                ) $tail"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS user_blocks (
                    blocker_id INT UNSIGNED NOT NULL,
                    blocked_id INT UNSIGNED NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (blocker_id, blocked_id),
                    KEY idx_block_blocked (blocked_id),
                    CONSTRAINT fk_block_blocker FOREIGN KEY (blocker_id) REFERENCES users (id) ON DELETE CASCADE,
                    CONSTRAINT fk_block_blocked FOREIGN KEY (blocked_id) REFERENCES users (id) ON DELETE CASCADE
                ) $tail"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS private_messages (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    sender_id INT UNSIGNED NOT NULL,
                    recipient_id INT UNSIGNED NOT NULL,
                    body TEXT NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    edited_at TIMESTAMP NULL DEFAULT NULL,
                    read_at TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY idx_pm_pair (sender_id, recipient_id, id),
                    KEY idx_pm_inbox (recipient_id, read_at),
                    CONSTRAINT fk_pm_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE,
                    CONSTRAINT fk_pm_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE
                ) $tail"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS user_presence (
                    user_id INT UNSIGNED NOT NULL,
                    status ENUM('online', 'busy', 'away', 'invisible') NOT NULL DEFAULT 'online',
                    last_seen_at DATETIME NULL DEFAULT NULL,
                    PRIMARY KEY (user_id),
                    CONSTRAINT fk_presence_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
                ) $tail"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS dm_threads (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_a INT UNSIGNED NOT NULL,
                    user_b INT UNSIGNED NOT NULL,
                    initiator_id INT UNSIGNED NOT NULL,
                    status ENUM('pending', 'accepted', 'declined') NOT NULL DEFAULT 'pending',
                    hidden_a TINYINT(1) NOT NULL DEFAULT 0,
                    hidden_b TINYINT(1) NOT NULL DEFAULT 0,
                    cleared_a INT UNSIGNED NOT NULL DEFAULT 0,
                    cleared_b INT UNSIGNED NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    responded_at TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_thread_pair (user_a, user_b),
                    KEY idx_thread_b (user_b, hidden_b),
                    KEY idx_thread_initiator (initiator_id, status, created_at),
                    CONSTRAINT fk_thread_a FOREIGN KEY (user_a) REFERENCES users (id) ON DELETE CASCADE,
                    CONSTRAINT fk_thread_b FOREIGN KEY (user_b) REFERENCES users (id) ON DELETE CASCADE,
                    CONSTRAINT fk_thread_initiator FOREIGN KEY (initiator_id) REFERENCES users (id) ON DELETE CASCADE
                ) $tail"
            );
            if (!$hadThreads) {
                $pdo->exec(
                    "INSERT IGNORE INTO dm_threads (user_a, user_b, initiator_id, status)
                     SELECT LEAST(sender_id, recipient_id), GREATEST(sender_id, recipient_id), LEAST(sender_id, recipient_id), 'accepted'
                     FROM private_messages
                     GROUP BY LEAST(sender_id, recipient_id), GREATEST(sender_id, recipient_id)"
                );
            }
        }
        $_SESSION['social_schema_v2'] = true;
    } catch (Throwable $error) {
        error_log('social.php:schema_failed: ' . $error->getMessage());
    }
}

/* --------------------------------------------------------------- presença */

function socialTouchPresence(PDO $pdo, int $userId): void
{
    // Invisível não atualiza "visto por último": senão o horário entregaria que a pessoa está aqui.
    $pdo->prepare(
        "INSERT INTO user_presence (user_id, status, last_seen_at) VALUES (?, 'online', NOW())
         ON DUPLICATE KEY UPDATE last_seen_at = IF(status = 'invisible', last_seen_at, NOW())"
    )->execute([$userId]);
}

function socialSetStatus(PDO $pdo, int $userId, string $status): bool
{
    if (!isset(PRESENCE_STATUSES[$status])) {
        return false;
    }
    $pdo->prepare(
        'INSERT INTO user_presence (user_id, status, last_seen_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE status = VALUES(status),
             last_seen_at = IF(VALUES(status) = \'invisible\', last_seen_at, NOW())'
    )->execute([$userId, $status]);
    return true;
}

function socialOwnStatus(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare('SELECT status FROM user_presence WHERE user_id = ?');
    $stmt->execute([$userId]);
    $status = $stmt->fetchColumn();
    return is_string($status) && isset(PRESENCE_STATUSES[$status]) ? $status : 'online';
}

/**
 * Como os amigos enxergam alguém. $row precisa de presence_status, seen_age (segundos desde o último ping).
 * Retorna key (online|busy|away|offline), label e o texto "visto por último".
 */
function socialPresenceView(array $row): array
{
    $status = $row['presence_status'] ?? null;
    $age = isset($row['seen_age']) && $row['seen_age'] !== null ? (int) $row['seen_age'] : null;

    $active = $age !== null && $age <= PRESENCE_ONLINE_SECONDS && $status !== 'invisible' && $status !== null;
    if ($active) {
        $labels = ['online' => 'Online', 'busy' => 'Ocupado', 'away' => 'Ausente'];
        return ['key' => $status, 'label' => $labels[$status] ?? 'Online', 'seen' => ''];
    }

    return ['key' => 'offline', 'label' => 'Offline', 'seen' => socialLastSeenText($age)];
}

function socialLastSeenText(?int $age): string
{
    if ($age === null) {
        return 'Ainda não ficou online';
    }
    // Usa a idade em segundos (calculada no banco) para não depender de fuso horário.
    $moment = time() - $age;
    $time = date('H:i', $moment);
    $day = date('Y-m-d', $moment);
    if ($day === date('Y-m-d')) {
        return "Visto por último hoje às $time";
    }
    if ($day === date('Y-m-d', strtotime('-1 day'))) {
        return "Visto por último ontem às $time";
    }
    return 'Visto por último em ' . date('d/m/Y', $moment) . " às $time";
}

function socialPresenceBadge(array $row, int $userId, bool $withSeen = true): string
{
    $view = socialPresenceView($row);
    return '<span class="presence presence-' . e($view['key']) . '" data-presence-user="' . $userId . '">'
        . '<span class="presence-dot" aria-hidden="true"></span>'
        . '<span class="presence-label">' . e($view['label']) . '</span>'
        . ($withSeen ? '<small class="presence-seen">' . e($view['seen']) . '</small>' : '')
        . '</span>';
}

function socialStatusPicker(string $current, string $returnTo): string
{
    $icons = ['online' => 'Online', 'busy' => 'Ocupado', 'away' => 'Ausente', 'invisible' => 'Invisível'];
    ob_start();
    ?>
    <form class="status-picker" method="post" action="<?= e(socialBase()) ?>endpoints/presence.php" data-status-picker role="group" aria-label="Meu status">
      <?= csrfInput() ?>
      <input type="hidden" name="return" value="<?= e($returnTo) ?>">
      <?php foreach ($icons as $key => $label): ?>
        <button type="submit" name="status" value="<?= e($key) ?>" class="status-option status-<?= e($key) ?>" aria-pressed="<?= $current === $key ? 'true' : 'false' ?>" data-status="<?= e($key) ?>">
          <span class="presence-dot" aria-hidden="true"></span><?= e($label) ?>
        </button>
      <?php endforeach; ?>
    </form>
    <?php
    return (string) ob_get_clean();
}

function socialBase(): string
{
    global $baseUrl;
    return $baseUrl ?? '';
}

/* ---------------------------------------------------------------- relações */

function socialPair(int $a, int $b): array
{
    return $a < $b ? [$a, $b] : [$b, $a];
}

function socialIsBlockedEitherWay(PDO $pdo, int $a, int $b): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM user_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1'
    );
    $stmt->execute([$a, $b, $b, $a]);
    return (bool) $stmt->fetchColumn();
}

function socialAreFriends(PDO $pdo, int $a, int $b): bool
{
    if ($a === $b) {
        return false;
    }
    [$low, $high] = socialPair($a, $b);
    $stmt = $pdo->prepare("SELECT 1 FROM friendships WHERE user_a = ? AND user_b = ? AND status = 'accepted'");
    $stmt->execute([$low, $high]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Estado entre $me e $other: self | friends | sent | received | blocked_by_me | blocked_me | none
 * (blocked_me existe só para esconder opções; a interface nunca diz à pessoa que foi bloqueada.)
 */
function socialRelation(PDO $pdo, int $me, int $other): string
{
    if ($me === $other) {
        return 'self';
    }
    $stmt = $pdo->prepare('SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?');
    $stmt->execute([$me, $other]);
    if ($stmt->fetchColumn()) {
        return 'blocked_by_me';
    }
    $stmt->execute([$other, $me]);
    if ($stmt->fetchColumn()) {
        return 'blocked_me';
    }
    [$low, $high] = socialPair($me, $other);
    $stmt = $pdo->prepare('SELECT status, requested_by FROM friendships WHERE user_a = ? AND user_b = ?');
    $stmt->execute([$low, $high]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'none';
    }
    if ($row['status'] === 'accepted') {
        return 'friends';
    }
    return (int) $row['requested_by'] === $me ? 'sent' : 'received';
}

/** @return array{0: bool, 1: string} [sucesso, mensagem] */
function socialSendRequest(PDO $pdo, int $me, int $target): array
{
    $generic = [false, 'Não foi possível enviar o pedido de amizade.'];

    if ($me === $target) {
        return [false, 'Você não pode adicionar a si mesmo.'];
    }
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND activate = 1');
    $stmt->execute([$target]);
    if (!$stmt->fetchColumn()) {
        return $generic;
    }
    if (socialIsBlockedEitherWay($pdo, $me, $target)) {
        return $generic;
    }

    $relation = socialRelation($pdo, $me, $target);
    if ($relation === 'friends') {
        return [false, 'Vocês já são amigos.'];
    }
    if ($relation === 'sent') {
        return [false, 'Você já enviou um pedido para esta pessoa.'];
    }
    if ($relation === 'received') {
        // Os dois quiseram ao mesmo tempo: vira amizade.
        return socialRespond($pdo, $me, $target, true);
    }

    if (socialCountFriends($pdo, $me) >= FRIENDS_MAX) {
        return [false, 'Você chegou ao limite de ' . FRIENDS_MAX . ' amigos.'];
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM friendships WHERE requested_by = ? AND status = 'pending'");
    $stmt->execute([$me]);
    if ((int) $stmt->fetchColumn() >= FRIEND_PENDING_OUT_MAX) {
        return [false, 'Você já tem ' . FRIEND_PENDING_OUT_MAX . ' pedidos pendentes. Espere alguns serem respondidos.'];
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM friendships WHERE requested_by = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
    $stmt->execute([$me]);
    if ((int) $stmt->fetchColumn() >= FRIEND_REQUESTS_PER_HOUR) {
        return [false, 'Muitos pedidos em pouco tempo. Tente novamente mais tarde.'];
    }

    [$low, $high] = socialPair($me, $target);
    try {
        $pdo->prepare("INSERT INTO friendships (user_a, user_b, requested_by, status) VALUES (?, ?, ?, 'pending')")
            ->execute([$low, $high, $me]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            return [false, 'Já existe um pedido entre vocês.'];
        }
        throw $error;
    }
    return [true, 'Pedido de amizade enviado!'];
}

function socialRespond(PDO $pdo, int $me, int $other, bool $accept): array
{
    [$low, $high] = socialPair($me, $other);

    if ($accept) {
        if (socialIsBlockedEitherWay($pdo, $me, $other)) {
            return [false, 'Não foi possível aceitar este pedido.'];
        }
        if (socialCountFriends($pdo, $me) >= FRIENDS_MAX) {
            return [false, 'Você chegou ao limite de ' . FRIENDS_MAX . ' amigos.'];
        }
        $stmt = $pdo->prepare(
            "UPDATE friendships SET status = 'accepted', responded_at = NOW()
             WHERE user_a = ? AND user_b = ? AND status = 'pending' AND requested_by = ?"
        );
        $stmt->execute([$low, $high, $other]);
        if ($stmt->rowCount() > 0) {
            $pdo->prepare("UPDATE dm_threads SET status = 'accepted', responded_at = NOW() WHERE user_a = ? AND user_b = ? AND status <> 'accepted'")
                ->execute([$low, $high]);
            return [true, 'Pedido aceito. Vocês agora são amigos!'];
        }
        return [false, 'Esse pedido não existe mais.'];
    }

    $stmt = $pdo->prepare("DELETE FROM friendships WHERE user_a = ? AND user_b = ? AND status = 'pending' AND requested_by = ?");
    $stmt->execute([$low, $high, $other]);
    return $stmt->rowCount() > 0 ? [true, 'Pedido recusado.'] : [false, 'Esse pedido não existe mais.'];
}

function socialCancelRequest(PDO $pdo, int $me, int $other): array
{
    [$low, $high] = socialPair($me, $other);
    $stmt = $pdo->prepare("DELETE FROM friendships WHERE user_a = ? AND user_b = ? AND status = 'pending' AND requested_by = ?");
    $stmt->execute([$low, $high, $me]);
    return $stmt->rowCount() > 0 ? [true, 'Pedido cancelado.'] : [false, 'Esse pedido não existe mais.'];
}

function socialRemoveFriend(PDO $pdo, int $me, int $other): array
{
    [$low, $high] = socialPair($me, $other);
    $stmt = $pdo->prepare("DELETE FROM friendships WHERE user_a = ? AND user_b = ? AND status = 'accepted'");
    $stmt->execute([$low, $high]);
    return $stmt->rowCount() > 0 ? [true, 'Amizade desfeita.'] : [false, 'Vocês não são amigos.'];
}

function socialBlock(PDO $pdo, int $me, int $other): array
{
    if ($me === $other) {
        return [false, 'Você não pode bloquear a si mesmo.'];
    }
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND activate = 1');
    $stmt->execute([$other]);
    if (!$stmt->fetchColumn()) {
        return [false, 'Usuário não encontrado.'];
    }
    [$low, $high] = socialPair($me, $other);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT IGNORE INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?)')->execute([$me, $other]);
        $pdo->prepare('DELETE FROM friendships WHERE user_a = ? AND user_b = ?')->execute([$low, $high]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    return [true, 'Usuário bloqueado. Ele não poderá enviar pedidos nem mensagens para você.'];
}

function socialUnblock(PDO $pdo, int $me, int $other): array
{
    $stmt = $pdo->prepare('DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?');
    $stmt->execute([$me, $other]);
    return $stmt->rowCount() > 0 ? [true, 'Usuário desbloqueado.'] : [false, 'Esse usuário não estava bloqueado.'];
}

function socialCountFriends(PDO $pdo, int $me): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM friendships WHERE status = 'accepted' AND (user_a = ? OR user_b = ?)");
    $stmt->execute([$me, $me]);
    return (int) $stmt->fetchColumn();
}

/* ----------------------------------------------------------------- listas */

/**
 * Amigos com presença, não lidas e última mensagem. Só amigos ativos e sem bloqueio.
 */
function socialFriends(PDO $pdo, int $me): array
{
    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.role, u.avatar_mime, u.banner_mime,
                p.status AS presence_status,
                TIMESTAMPDIFF(SECOND, p.last_seen_at, NOW()) AS seen_age,
                (SELECT COUNT(*) FROM private_messages m
                  WHERE m.sender_id = u.id AND m.recipient_id = :me1 AND m.read_at IS NULL) AS unread,
                (SELECT MAX(m.id) FROM private_messages m
                  WHERE (m.sender_id = u.id AND m.recipient_id = :me2) OR (m.sender_id = :me3 AND m.recipient_id = u.id)) AS last_message_id
         FROM friendships f
         JOIN users u ON u.id = IF(f.user_a = :me4, f.user_b, f.user_a) AND u.activate = 1
         LEFT JOIN user_presence p ON p.user_id = u.id
         WHERE f.status = 'accepted' AND (f.user_a = :me5 OR f.user_b = :me6)
           AND NOT EXISTS (
                SELECT 1 FROM user_blocks b
                 WHERE (b.blocker_id = :me7 AND b.blocked_id = u.id) OR (b.blocker_id = u.id AND b.blocked_id = :me8))
         ORDER BY u.name"
    );
    $stmt->execute([
        'me1' => $me, 'me2' => $me, 'me3' => $me, 'me4' => $me,
        'me5' => $me, 'me6' => $me, 'me7' => $me, 'me8' => $me,
    ]);
    return $stmt->fetchAll();
}

/** Pedidos recebidos ($incoming = true) ou enviados. */
function socialRequests(PDO $pdo, int $me, bool $incoming): array
{
    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.role, u.avatar_mime, u.banner_mime, f.created_at
         FROM friendships f
         JOIN users u ON u.id = IF(f.user_a = :me1, f.user_b, f.user_a) AND u.activate = 1
         WHERE f.status = 'pending' AND (f.user_a = :me2 OR f.user_b = :me3)
           AND f.requested_by " . ($incoming ? '<>' : '=') . " :me4
           AND NOT EXISTS (
                SELECT 1 FROM user_blocks b
                 WHERE (b.blocker_id = :me5 AND b.blocked_id = u.id) OR (b.blocker_id = u.id AND b.blocked_id = :me6))
         ORDER BY f.created_at DESC"
    );
    $stmt->execute(['me1' => $me, 'me2' => $me, 'me3' => $me, 'me4' => $me, 'me5' => $me, 'me6' => $me]);
    return $stmt->fetchAll();
}

function socialBlockedUsers(PDO $pdo, int $me): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name FROM user_blocks b JOIN users u ON u.id = b.blocked_id AND u.activate = 1
         WHERE b.blocker_id = ? ORDER BY u.name'
    );
    $stmt->execute([$me]);
    return $stmt->fetchAll();
}

/** Números do balão vermelho do cabeçalho. */
function socialBadgeCounts(PDO $pdo, int $me): array
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM friendships f
         JOIN users u ON u.id = f.requested_by AND u.activate = 1
         WHERE f.status = 'pending' AND f.requested_by <> ? AND (f.user_a = ? OR f.user_b = ?)
           AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE b.blocker_id = ? AND b.blocked_id = f.requested_by)"
    );
    $stmt->execute([$me, $me, $me, $me]);
    $friendRequests = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM dm_threads t
         JOIN users u ON u.id = t.initiator_id AND u.activate = 1
         WHERE t.status = 'pending' AND t.initiator_id <> ? AND (t.user_a = ? OR t.user_b = ?)
           AND IF(t.user_a = ?, t.hidden_a, t.hidden_b) = 0
           AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = t.initiator_id)
                                                          OR (b.blocker_id = t.initiator_id AND b.blocked_id = ?))"
    );
    $stmt->execute([$me, $me, $me, $me, $me, $me]);
    $contactRequests = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM private_messages m
         JOIN users u ON u.id = m.sender_id AND u.activate = 1
         JOIN dm_threads t ON t.user_a = LEAST(m.sender_id, m.recipient_id) AND t.user_b = GREATEST(m.sender_id, m.recipient_id)
         WHERE m.recipient_id = ? AND m.read_at IS NULL AND t.status = 'accepted'
           AND m.id > IF(t.user_a = ?, t.cleared_a, t.cleared_b)
           AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = m.recipient_id AND b.blocked_id = m.sender_id)
                                                          OR (b.blocker_id = m.sender_id AND b.blocked_id = m.recipient_id))"
    );
    $stmt->execute([$me, $me]);
    $messages = (int) $stmt->fetchColumn();

    $requests = $friendRequests + $contactRequests;
    return [
        'requests' => $requests,
        'friend_requests' => $friendRequests,
        'contact_requests' => $contactRequests,
        'messages' => $messages,
        'total' => $requests + $messages,
    ];
}

function socialRoleLabel(string $role): string
{
    return ['student' => 'Aluno', 'teacher' => 'Professor', 'admin' => 'Administrador'][$role] ?? 'Usuário';
}

function socialInitials(string $name): string
{
    return mb_strtoupper(mb_substr(trim($name), 0, 1));
}

/** Avatar pequeno (imagem ou inicial) que leva ao perfil. */
function socialAvatar(array $person, string $class = 'friend-avatar'): string
{
    $id = (int) $person['id'];
    $html = '<span class="' . e($class) . '" aria-hidden="true">';
    if (!empty($person['avatar_mime'])) {
        $html .= '<img class="friend-avatar-image" src="' . e(socialBase()) . 'endpoints/image.php?user=' . $id . '&amp;type=avatar" alt="" loading="lazy">';
    } else {
        $html .= '<span class="friend-avatar-initials">' . e(socialInitials($person['name'])) . '</span>';
    }
    return $html . '</span>';
}

/**
 * Cada $return vira um destino fixo (nunca uma URL vinda do usuário).
 * 'profile' | 'contacts' | 'contacts:requests|friends|add|conversations' | 'view:ID' | 'chat:ID'
 */
function socialReturnUrl(string $return, int $fallbackId = 0): string
{
    if ($return === 'profile') {
        return '../pages/profile.php';
    }
    if ($return === 'contacts' || $return === 'messages') {
        return '../pages/contacts.php';
    }
    if (preg_match('/^contacts:(requests|friends|add|conversations)$/', $return, $match)) {
        return '../pages/contacts.php#' . $match[1];
    }
    if (preg_match('/^view:(\d+)$/', $return, $match)) {
        return '../pages/profile-view.php?id=' . (int) $match[1];
    }
    if (preg_match('/^(?:chat|messages):(\d+)$/', $return, $match)) {
        return '../pages/contacts.php?with=' . (int) $match[1];
    }
    return $fallbackId > 0 ? '../pages/profile-view.php?id=' . $fallbackId : '../pages/contacts.php';
}

function socialTag(array $person): string
{
    return (string) $person['name'] . '#' . (int) $person['id'];
}

function socialParseTag(string $input): ?array
{
    $input = trim(preg_replace('/\s+/u', ' ', $input) ?? $input);
    $input = ltrim($input, '@');
    $position = mb_strrpos($input, '#');
    if ($position === false) {
        return null;
    }
    $name = trim(mb_substr($input, 0, $position));
    $digits = trim(mb_substr($input, $position + 1));
    if ($name === '' || $digits === '' || !ctype_digit($digits) || strlen($digits) > 10) {
        return null;
    }
    return ['name' => $name, 'id' => (int) $digits];
}

function socialTagThrottled(): bool
{
    $now = time();
    $recent = array_values(array_filter(
        (array) ($_SESSION['tag_fails'] ?? []),
        fn ($moment) => $now - (int) $moment < TAG_FAILS_WINDOW
    ));
    $_SESSION['tag_fails'] = $recent;
    return count($recent) >= TAG_FAILS_MAX;
}

function socialTagFail(): void
{
    $_SESSION['tag_fails'][] = time();
}

function socialFindByTag(PDO $pdo, string $input, int $me): array
{
    if (socialTagThrottled()) {
        return [null, 'Muitas tentativas seguidas. Aguarde alguns minutos e tente de novo.'];
    }
    $tag = socialParseTag($input);
    if ($tag === null) {
        return [null, 'Use o formato nome#número.'];
    }
    $stmt = $pdo->prepare('SELECT id, name, role, avatar_mime FROM users WHERE id = ? AND name = ? AND activate = 1');
    $stmt->execute([$tag['id'], $tag['name']]);
    $person = $stmt->fetch();
    if (!$person) {
        socialTagFail();
        return [null, 'Não encontramos ninguém com esse nome e número.'];
    }
    if ((int) $person['id'] === $me) {
        return [null, 'Esse é o seu próprio código.'];
    }
    if (socialIsBlockedEitherWay($pdo, $me, (int) $person['id'])) {
        return [null, 'Não encontramos ninguém com esse nome e número.'];
    }
    return [$person, null];
}

function socialThread(PDO $pdo, int $me, int $other): ?array
{
    [$low, $high] = socialPair($me, $other);
    $stmt = $pdo->prepare('SELECT * FROM dm_threads WHERE user_a = ? AND user_b = ?');
    $stmt->execute([$low, $high]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $mine = $me === (int) $row['user_a'] ? 'a' : 'b';
    $row['id'] = (int) $row['id'];
    $row['mine'] = $mine;
    $row['initiator_id'] = (int) $row['initiator_id'];
    $row['hidden_me'] = (int) $row['hidden_' . $mine];
    $row['cleared_me'] = (int) $row['cleared_' . $mine];
    return $row;
}

function socialDmState(PDO $pdo, int $me, int $other): array
{
    $relation = socialRelation($pdo, $me, $other);
    $thread = socialThread($pdo, $me, $other);
    if ($relation === 'blocked_by_me') {
        $mode = 'blocked_by_me';
    } elseif ($relation === 'blocked_me') {
        $mode = 'blocked';
    } elseif ($relation === 'friends') {
        $mode = 'open';
    } elseif ($thread === null) {
        $mode = 'first';
    } elseif ($thread['status'] === 'accepted') {
        $mode = 'open';
    } elseif ($thread['initiator_id'] === $me) {
        $mode = 'awaiting';
    } else {
        $mode = 'incoming';
    }
    return ['relation' => $relation, 'thread' => $thread, 'mode' => $mode];
}

function socialThreadMaxMessageId(PDO $pdo, int $a, int $b): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(id), 0) FROM private_messages
         WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)'
    );
    $stmt->execute([$a, $b, $b, $a]);
    return (int) $stmt->fetchColumn();
}

function socialDmSend(PDO $pdo, int $me, int $other, string $body): array
{
    if ($me === $other) {
        return [false, 'Você não pode enviar mensagens para si mesmo.', 403];
    }
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND activate = 1');
    $stmt->execute([$other]);
    if (!$stmt->fetchColumn()) {
        return [false, 'Essa conversa não existe.', 404];
    }

    [$low, $high] = socialPair($me, $other);

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $state = socialDmState($pdo, $me, $other);
        $mode = $state['mode'];
        $thread = $state['thread'];

        if ($mode === 'blocked_by_me' || $mode === 'blocked') {
            return [false, 'Não é possível enviar mensagens nesta conversa.', 403];
        }
        if ($mode === 'awaiting') {
            return [false, 'Você poderá continuar a conversa quando essa pessoa aceitar o seu contato.', 403];
        }
        if ($mode === 'incoming') {
            return [false, 'Aceite o pedido de contato para poder responder.', 403];
        }

        if ($mode === 'first') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM dm_threads WHERE initiator_id = ? AND status <> 'accepted'");
            $stmt->execute([$me]);
            if ((int) $stmt->fetchColumn() >= DM_PENDING_OUT_MAX) {
                return [false, 'Você já tem ' . DM_PENDING_OUT_MAX . ' pedidos de contato aguardando resposta.', 429];
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM dm_threads WHERE initiator_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
            $stmt->execute([$me]);
            if ((int) $stmt->fetchColumn() >= DM_NEW_PER_HOUR) {
                return [false, 'Muitos contatos novos em pouco tempo. Tente novamente mais tarde.', 429];
            }
        }

        $pdo->beginTransaction();
        try {
            if ($mode === 'first') {
                $pdo->prepare("INSERT INTO dm_threads (user_a, user_b, initiator_id, status) VALUES (?, ?, ?, 'pending')")
                    ->execute([$low, $high, $me]);
            } elseif ($thread === null) {
                $pdo->prepare("INSERT INTO dm_threads (user_a, user_b, initiator_id, status) VALUES (?, ?, ?, 'accepted')")
                    ->execute([$low, $high, $me]);
            } else {
                $pdo->prepare(
                    "UPDATE dm_threads SET hidden_a = 0, hidden_b = 0, status = 'accepted'
                     WHERE id = ? AND (hidden_a = 1 OR hidden_b = 1 OR status <> 'accepted')"
                )->execute([$thread['id']]);
            }
            $pdo->prepare('INSERT INTO private_messages (sender_id, recipient_id, body) VALUES (?, ?, ?)')
                ->execute([$me, $other, $body]);
            $messageId = (int) $pdo->lastInsertId();
            $pdo->commit();
            return [true, $messageId, $mode === 'first' ? 'request' : 'sent'];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof PDOException && $error->getCode() === '23000' && $attempt === 0) {
                continue;
            }
            throw $error;
        }
    }
    return [false, 'Não foi possível enviar sua mensagem.', 500];
}

function socialThreadRespond(PDO $pdo, int $me, int $other, bool $accept): array
{
    $thread = socialThread($pdo, $me, $other);
    if (!$thread || $thread['initiator_id'] === $me || $thread['status'] === 'accepted') {
        return [false, 'Esse pedido não existe mais.'];
    }
    if (socialIsBlockedEitherWay($pdo, $me, $other)) {
        return [false, 'Não foi possível concluir a ação.'];
    }
    $mine = $thread['mine'];

    if ($accept) {
        $pdo->prepare("UPDATE dm_threads SET status = 'accepted', responded_at = NOW(), hidden_$mine = 0 WHERE id = ?")
            ->execute([$thread['id']]);
        return [true, 'Contato aceito. Vocês já podem conversar!'];
    }

    $max = socialThreadMaxMessageId($pdo, $me, $other);
    $pdo->prepare("UPDATE dm_threads SET status = 'declined', responded_at = NOW(), hidden_$mine = 1, cleared_$mine = ? WHERE id = ?")
        ->execute([$max, $thread['id']]);
    socialThreadPurge($pdo, $me, $other);
    return [true, 'Pedido de contato recusado.'];
}

function socialThreadClear(PDO $pdo, int $me, int $other): array
{
    $thread = socialThread($pdo, $me, $other);
    if (!$thread) {
        return [false, 'Essa conversa não existe mais.'];
    }
    $mine = $thread['mine'];
    $max = socialThreadMaxMessageId($pdo, $me, $other);
    $pdo->prepare("UPDATE dm_threads SET hidden_$mine = 1, cleared_$mine = ? WHERE id = ?")
        ->execute([$max, $thread['id']]);
    socialThreadPurge($pdo, $me, $other);
    return [true, 'Histórico removido da sua conta. A outra pessoa não foi afetada.'];
}

function socialThreadPurge(PDO $pdo, int $a, int $b): void
{
    $thread = socialThread($pdo, $a, $b);
    if (!$thread) {
        return;
    }
    $cut = min((int) $thread['cleared_a'], (int) $thread['cleared_b']);
    if ($cut > 0) {
        $pdo->prepare(
            'DELETE FROM private_messages
             WHERE ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)) AND id <= ?'
        )->execute([$a, $b, $b, $a, $cut]);
    }
    if ((int) $thread['hidden_a'] === 1 && (int) $thread['hidden_b'] === 1 && $thread['status'] !== 'declined') {
        $pdo->prepare('DELETE FROM dm_threads WHERE id = ?')->execute([$thread['id']]);
    }
}

function socialThreadPruneEmptyPending(PDO $pdo, int $a, int $b): void
{
    $thread = socialThread($pdo, $a, $b);
    if (!$thread || $thread['status'] !== 'pending') {
        return;
    }
    if (socialThreadMaxMessageId($pdo, $a, $b) === 0) {
        $pdo->prepare('DELETE FROM dm_threads WHERE id = ?')->execute([$thread['id']]);
    }
}

function socialShortTime(int $age): string
{
    $moment = time() - $age;
    if (date('Y-m-d', $moment) === date('Y-m-d')) {
        return date('H:i', $moment);
    }
    if (date('Y-m-d', $moment) === date('Y-m-d', strtotime('-1 day'))) {
        return 'Ontem';
    }
    return date('d/m', $moment);
}

function socialPreview(string $body, int $max = 54): string
{
    $flat = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);
    return mb_strlen($flat) > $max ? rtrim(mb_substr($flat, 0, $max - 1)) . '…' : $flat;
}

function socialPresenceDot(array $row, int $userId): string
{
    $view = socialPresenceView($row);
    return '<span class="avatar-presence"><span class="presence presence-' . e($view['key']) . '" data-presence-user="' . $userId . '">'
        . '<span class="presence-dot" aria-hidden="true"></span>'
        . '<span class="presence-label visually-hidden">' . e($view['label']) . '</span>'
        . '</span></span>';
}

function socialConversations(PDO $pdo, int $me): array
{
    $stmt = $pdo->prepare(
        "SELECT t.id AS thread_id, t.status AS thread_status, t.initiator_id,
                u.id, u.name, u.role, u.avatar_mime, u.banner_mime,
                p.status AS presence_status,
                TIMESTAMPDIFF(SECOND, p.last_seen_at, NOW()) AS seen_age,
                EXISTS (SELECT 1 FROM friendships f WHERE f.user_a = t.user_a AND f.user_b = t.user_b AND f.status = 'accepted') AS is_friend,
                EXISTS (SELECT 1 FROM user_blocks b WHERE b.blocker_id = ? AND b.blocked_id = u.id) AS blocked_by_me,
                EXISTS (SELECT 1 FROM user_blocks b WHERE b.blocker_id = u.id AND b.blocked_id = ?) AS blocked_me,
                (SELECT COUNT(*) FROM private_messages m
                  WHERE m.sender_id = u.id AND m.recipient_id = ? AND m.read_at IS NULL
                    AND m.id > IF(t.user_a = ?, t.cleared_a, t.cleared_b)) AS unread,
                (SELECT MAX(m.id) FROM private_messages m
                  WHERE ((m.sender_id = u.id AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = u.id))
                    AND m.id > IF(t.user_a = ?, t.cleared_a, t.cleared_b)) AS last_message_id
         FROM dm_threads t
         JOIN users u ON u.id = IF(t.user_a = ?, t.user_b, t.user_a) AND u.activate = 1
         LEFT JOIN user_presence p ON p.user_id = u.id
         WHERE (t.user_a = ? OR t.user_b = ?)
           AND IF(t.user_a = ?, t.hidden_a, t.hidden_b) = 0
           AND (t.status = 'accepted' OR t.initiator_id = ?)
         ORDER BY COALESCE(last_message_id, 0) DESC, t.id DESC"
    );
    $stmt->execute(array_fill(0, 12, $me));
    $rows = $stmt->fetchAll();

    $ids = array_values(array_filter(array_map(fn ($row) => (int) $row['last_message_id'], $rows)));
    $last = [];
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, sender_id, body, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM private_messages WHERE id IN ($marks)"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $message) {
            $last[(int) $message['id']] = $message;
        }
    }
    foreach ($rows as &$row) {
        $row['last'] = $last[(int) $row['last_message_id']] ?? null;
    }
    unset($row);
    return $rows;
}

function socialContactRequests(PDO $pdo, int $me): array
{
    $stmt = $pdo->prepare(
        "SELECT t.id AS thread_id, t.status AS thread_status, u.id, u.name, u.role, u.avatar_mime, u.banner_mime,
                TIMESTAMPDIFF(SECOND, t.created_at, NOW()) AS age,
                (SELECT m.body FROM private_messages m
                  WHERE m.sender_id = u.id AND m.recipient_id = ?
                    AND m.id > IF(t.user_a = ?, t.cleared_a, t.cleared_b)
                  ORDER BY m.id DESC LIMIT 1) AS preview
         FROM dm_threads t
         JOIN users u ON u.id = t.initiator_id AND u.activate = 1
         WHERE t.status = 'pending' AND t.initiator_id <> ? AND (t.user_a = ? OR t.user_b = ?)
           AND IF(t.user_a = ?, t.hidden_a, t.hidden_b) = 0
           AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = u.id)
                                                          OR (b.blocker_id = u.id AND b.blocked_id = ?))
         ORDER BY t.created_at DESC"
    );
    $stmt->execute(array_fill(0, 8, $me));
    return $stmt->fetchAll();
}
