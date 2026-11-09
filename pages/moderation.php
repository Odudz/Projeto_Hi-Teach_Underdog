<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/forum-upload.php';
require_once __DIR__ . '/../includes/live.php';
require_once __DIR__ . '/../includes/ui-helpers.php';

ensureModerator();

const MOD_ACTIONS = ['dismiss', 'delete_content', 'mute', 'mute_keep', 'suspend', 'suspend_keep'];

$category = (string) ($_GET['cat'] ?? '');
if (!isset(RBAC_REPORT_CATEGORIES[$category])) {
    $category = '';
}
$search = trim((string) ($_GET['q'] ?? ''));

/* ---------------------------------------------------------------
 * Ações de Moderação (POST)
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('moderation.php');

    $formAction = (string) ($_POST['form_action'] ?? '');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $backTab = ['resolve_report' => 'mod-reports', 'end_live' => 'mod-lives', 'revoke_sanction' => 'mod-sanctions', 'apply_direct_sanction' => 'mod-sanctions'][$formAction] ?? 'mod-reports';

    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
        setFlash('error', 'Informe o motivo da decisão (5 a 255 caracteres).');
        redirect('moderation.php#' . $backTab);
    }

    $filesToRemove = [];
    try {
        $pdo->beginTransaction();

        // 1. Resolver Denúncia
        if ($formAction === 'resolve_report') {
            $reportId = (int) ($_POST['report_id'] ?? 0);
            $action = (string) ($_POST['action'] ?? '');
            $days = (int) ($_POST['days'] ?? 0) ?: null;

            if (!in_array($action, MOD_ACTIONS, true)) {
                throw new RuntimeException('Ação de moderação inválida.');
            }

            $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = ? AND status = 'open' FOR UPDATE");
            $stmt->execute([$reportId]);
            $report = $stmt->fetch();
            if (!$report) {
                throw new RuntimeException('Denúncia não encontrada ou já atendida.');
            }

            $targetId = (int) $report['target_id'];
            $shouldDeleteContent = in_array($action, ['delete_content', 'mute', 'suspend'], true);
            $shouldSanction = in_array($action, ['mute', 'mute_keep', 'suspend', 'suspend_keep'], true);
            $sanctionType = str_starts_with($action, 'mute') ? 'mute' : 'suspension';

            if ($shouldSanction) {
                if (!$report['target_user_id']) {
                    throw new RuntimeException('O autor deste conteúdo não existe mais.');
                }
                rbacApplySanction($pdo, $user, (int) $report['target_user_id'], $sanctionType, $days, $reason);
            }

            if ($shouldDeleteContent) {
                if ($report['target_type'] === 'post') {
                    $stmt = $pdo->prepare('SELECT id, user_id, topic_id, body FROM forum_posts WHERE id = ? FOR UPDATE');
                    $stmt->execute([$targetId]);
                    $content = $stmt->fetch();
                    $stmt = $pdo->prepare('SELECT stored_name FROM forum_attachments WHERE post_id = ?');
                    $stmt->execute([$targetId]);
                    $filesToRemove = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $table = 'forum_posts';
                    $old = $content ? ['author_id' => (int) $content['user_id'], 'topic_id' => (int) $content['topic_id'], 'body' => mb_substr($content['body'], 0, 2000)] : null;
                } else {
                    $stmt = $pdo->prepare('SELECT id, user_id, title, body FROM forum_topics WHERE id = ? FOR UPDATE');
                    $stmt->execute([$targetId]);
                    $content = $stmt->fetch();
                    $stmt = $pdo->prepare('SELECT stored_name FROM forum_attachments WHERE topic_id = ?');
                    $stmt->execute([$targetId]);
                    $filesToRemove = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $table = 'forum_topics';
                    $old = $content ? ['author_id' => (int) $content['user_id'], 'title' => $content['title'], 'body' => mb_substr($content['body'], 0, 2000)] : null;
                }

                if ($content) {
                    $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$targetId]);
                    rbacAudit(
                        $pdo,
                        (int) $user['id'],
                        $report['target_type'] === 'post' ? 'POST_DELETED' : 'TOPIC_DELETED',
                        $report['target_type'],
                        $targetId,
                        $old,
                        null,
                        $reason
                    );
                }

                $pdo->prepare(
                    "UPDATE reports SET status = 'resolved', handled_by = ?, handled_at = NOW(), resolution = ?
                     WHERE target_type = ? AND target_id = ? AND status = 'open'"
                )->execute([$user['id'], mb_substr($action . ': ' . $reason, 0, 255), $report['target_type'], $targetId]);
            } else {
                $pdo->prepare(
                    'UPDATE reports SET status = ?, handled_by = ?, handled_at = NOW(), resolution = ? WHERE id = ?'
                )->execute([
                    $action === 'dismiss' ? 'dismissed' : 'resolved',
                    $user['id'],
                    mb_substr($action . ': ' . $reason, 0, 255),
                    $reportId,
                ]);
            }

            rbacAudit($pdo, (int) $user['id'], 'REPORT_HANDLED', 'report', $reportId, ['status' => 'open'], ['action' => $action], $reason);
            setFlash('success', 'Denúncia #' . $reportId . ' atendida com sucesso.');

            // 2. Aplicar Sanção Direta
        } elseif ($formAction === 'apply_direct_sanction') {
            $userLookup = trim((string) ($_POST['user_lookup'] ?? ''));
            $sanctionType = (string) ($_POST['sanction_type'] ?? 'mute');
            $days = (int) ($_POST['days'] ?? 0) ?: null;

            if ($userLookup === '') {
                throw new RuntimeException('Informe o ID ou e-mail do usuário a ser sancionado.');
            }

            if (ctype_digit($userLookup)) {
                $stmt = $pdo->prepare('SELECT id, name, role FROM users WHERE id = ? AND activate = 1');
                $stmt->execute([(int) $userLookup]);
            } else {
                $stmt = $pdo->prepare('SELECT id, name, role FROM users WHERE email = ? AND activate = 1');
                $stmt->execute([strtolower($userLookup)]);
            }
            $targetUser = $stmt->fetch();
            if (!$targetUser) {
                throw new RuntimeException('Usuário não encontrado.');
            }

            rbacApplySanction($pdo, $user, (int) $targetUser['id'], $sanctionType, $days, $reason);
            setFlash('success', "Sanção aplicada com sucesso ao usuário {$targetUser['name']} (#{$targetUser['id']}).");

            // 3. Revogar Sanção
        } elseif ($formAction === 'revoke_sanction') {
            rbacRevokeSanction($pdo, $user, (int) ($_POST['sanction_id'] ?? 0), $reason);
            setFlash('success', 'Sanção revogada com sucesso.');

            // 4. Encerrar Live
        } elseif ($formAction === 'end_live') {
            $liveId = (int) ($_POST['live_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT id, teacher_id, title FROM live_classes WHERE id = ? FOR UPDATE');
            $stmt->execute([$liveId]);
            $live = $stmt->fetch();
            if (!$live || !liveEndByStaff($pdo, $liveId)) {
                throw new RuntimeException('Essa live já foi encerrada.');
            }
            rbacAudit(
                $pdo,
                (int) $user['id'],
                'LIVE_ENDED',
                'live',
                $liveId,
                ['teacher_id' => (int) $live['teacher_id'], 'title' => $live['title']],
                null,
                $reason
            );
            setFlash('success', 'Live "' . $live['title'] . '" encerrada.');
        } else {
            throw new RuntimeException('Solicitação inválida.');
        }

        $pdo->commit();
        forumCleanupFiles($filesToRemove);
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        setFlash('error', $e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('moderation.php:failed: ' . $e->getMessage());
        setFlash('error', 'Não foi possível concluir a ação.');
    }
    redirect('moderation.php#' . $backTab);
}

/* ---------------------------------------------------------------
 * Consultas da Moderação
 * ------------------------------------------------------------- */
$byCategory = $pdo->query("SELECT category, COUNT(*) AS n FROM reports WHERE status = 'open' GROUP BY category")->fetchAll(PDO::FETCH_KEY_PAIR);
$openTotal = array_sum($byCategory);

$searchLike = '%' . addcslashes($search, '%_\\\\') . '%';
$stmt = $pdo->prepare(
    "SELECT r.id, r.target_type, r.target_id, r.target_user_id, r.category, r.details, r.snapshot, r.created_at,
            TIMESTAMPDIFF(SECOND, r.created_at, NOW()) AS age_seconds,
            rp.name AS reporter_name,
            au.name AS author_name, au.role AS author_role, (au.avatar_mime IS NOT NULL) AS author_has_avatar,
            TIMESTAMPDIFF(SECOND, au.created_at, NOW()) AS author_age_seconds,
            fp.body AS post_body, ft.title AS topic_title, ft.body AS topic_body, ft.id AS topic_id, ft.is_closed,
            sb.name AS subject_name,
            (SELECT COUNT(*) FROM reports x WHERE x.target_type = r.target_type AND x.target_id = r.target_id) AS total_reports,
            (SELECT COUNT(*) FROM user_sanctions us WHERE us.user_id = r.target_user_id) AS author_sanctions,
            (SELECT COUNT(*) FROM reports y WHERE y.target_user_id = r.target_user_id AND y.status = 'resolved') AS author_confirmed
     FROM reports r
     JOIN users rp ON rp.id = r.reporter_id
     LEFT JOIN users au ON au.id = r.target_user_id
     LEFT JOIN forum_posts fp ON r.target_type = 'post' AND fp.id = r.target_id
     LEFT JOIN forum_topics ft ON ft.id = IF(r.target_type = 'post', fp.topic_id, r.target_id)
     LEFT JOIN subjects sb ON sb.id = ft.subject_id
     WHERE r.status = 'open' 
       AND (? = '' OR r.category = ?)
       AND (? = '' OR au.name LIKE ? OR rp.name LIKE ? OR r.details LIKE ?)
     ORDER BY r.created_at LIMIT 100"
);
$stmt->execute([$category, $category, $search, $searchLike, $searchLike, $searchLike]);
$reports = $stmt->fetchAll();

$attachmentsByReport = [];
if ($reports) {
    $postIds = [];
    $topicIds = [];
    foreach ($reports as $row) {
        $row['target_type'] === 'post' ? $postIds[] = (int) $row['target_id'] : $topicIds[] = (int) $row['target_id'];
    }
    $clauses = [];
    $params = [];
    if ($postIds) {
        $clauses[] = 'post_id IN (' . implode(',', array_fill(0, count($postIds), '?')) . ')';
        array_push($params, ...$postIds);
    }
    if ($topicIds) {
        $clauses[] = '(post_id IS NULL AND topic_id IN (' . implode(',', array_fill(0, count($topicIds), '?')) . '))';
        array_push($params, ...$topicIds);
    }
    if ($clauses) {
        $stmt = $pdo->prepare('SELECT id, topic_id, post_id, original_name, mime_type FROM forum_attachments WHERE ' . implode(' OR ', $clauses) . ' ORDER BY id');
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $attachment) {
            $key = $attachment['post_id'] !== null ? 'post:' . $attachment['post_id'] : 'topic:' . $attachment['topic_id'];
            $attachmentsByReport[$key][] = $attachment;
        }
    }
}

$lives = liveStaffList($pdo);
$recentLives = liveStaffRecent($pdo, 10);

$sanctions = $pdo->query(
    "SELECT s.id, s.type, s.reason, s.created_at, s.expires_at,
            IF(s.expires_at IS NULL, NULL, TIMESTAMPDIFF(SECOND, NOW(), s.expires_at)) AS remaining_seconds,
            u.id AS user_id, u.name AS user_name, u.role AS user_role, (u.avatar_mime IS NOT NULL) AS user_has_avatar,
            a.name AS applied_name
     FROM user_sanctions s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN users a ON a.id = s.applied_by
     WHERE s.revoked_at IS NULL AND (s.expires_at IS NULL OR s.expires_at > NOW())
     ORDER BY s.created_at DESC LIMIT 100"
)->fetchAll();

$history = $pdo->query(
    "SELECT r.id, r.target_type, r.category, r.status, r.resolution, r.handled_at,
            TIMESTAMPDIFF(SECOND, r.handled_at, NOW()) AS handled_seconds,
            au.name AS author_name, h.name AS handler_name, rp.name AS reporter_name
     FROM reports r
     JOIN users rp ON rp.id = r.reporter_id
     LEFT JOIN users au ON au.id = r.target_user_id
     LEFT JOIN users h ON h.id = r.handled_by
     WHERE r.status <> 'open'
     ORDER BY r.handled_at DESC, r.id DESC LIMIT 50"
)->fetchAll();

$handledWeek = (int) $pdo->query("SELECT COUNT(*) FROM reports WHERE status <> 'open' AND handled_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
$oldestOpen = $reports ? (int) $reports[0]['age_seconds'] : null;

$isAdmin = $user['role'] === 'admin';

$canSanction = static function (array $report) use ($user): bool {
    if (!$report['target_user_id'] || (int) $report['target_user_id'] === (int) $user['id']) {
        return false;
    }
    $role = (string) $report['author_role'];
    return $role !== 'admin' && !($role === 'moderator' && $user['role'] !== 'admin');
};

$pageId = 'moderation';
$pageTitle = 'Fila de Moderação';
$extraCss = ['tabs', 'staff'];
$extraJs = ['tabs', 'staff'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
    <header class="staff-header">
        <div>
            <h1 class="staff-title">Fila de Moderação</h1>
            <p class="staff-subtitle">Atenda denúncias da comunidade, aplique sanções disciplinares e monitore
                transmissões.</p>
        </div>
        <div class="staff-header-actions">
            <?php if ($isAdmin): ?>
                <a class="button button-small button-secondary" href="moderator-dashboard.php">Painel do Administrador</a>
                <a class="button button-small button-secondary" href="admin-users.php">Usuários e cargos</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="staff-summary">
        <?= uiStat('Denúncias Abertas', $openTotal, '#mod-reports', $oldestOpen !== null ? 'a mais antiga: ' . uiAgo($oldestOpen) : 'fila zerada', $openTotal > 0) ?>
        <?= uiStat('Lives no Ar', count($lives), '#mod-lives', $lives ? 'em transmissão agora' : 'nenhuma no momento') ?>
        <?= uiStat('Sanções Ativas', count($sanctions), '#mod-sanctions', 'usuários silenciados ou suspensos') ?>
        <?= uiStat('Atendidas (7 dias)', $handledWeek, '#mod-history', 'resolvidas ou descartadas') ?>
    </div>

    <!-- ABA 1: FILA DE DENÚNCIAS -->
    <section id="mod-reports" class="section" data-tab="Denúncias" data-tab-badge="<?= $openTotal ?>">
        <h2 class="section-title">Fila de Denúncias</h2>

        <nav class="filter-bar" aria-label="Filtrar por motivo">
            <a class="filter-chip<?= $category === '' ? ' is-active' : '' ?>" href="moderation.php#mod-reports">Todas
                <span><?= $openTotal ?></span></a>
            <?php foreach (RBAC_REPORT_CATEGORIES as $value => $label): ?>
                <a class="filter-chip<?= $category === $value ? ' is-active' : '' ?>"
                    href="moderation.php?cat=<?= e($value) ?>#mod-reports"><?= e($label) ?>
                    <span><?= (int) ($byCategory[$value] ?? 0) ?></span></a>
            <?php endforeach; ?>
        </nav>

        <form class="filter-bar" method="get" action="moderation.php#mod-reports">
            <?php if ($category !== ''): ?><input type="hidden" name="cat" value="<?= e($category) ?>"><?php endif; ?>
            <input type="search" name="q" class="form-input" value="<?= e($search) ?>"
                placeholder="Buscar por autor, denunciante ou detalhe" aria-label="Buscar denúncias"
                style="flex: 1 1 240px; max-width: 320px;">
            <button type="submit" class="button button-small button-primary">Buscar</button>
            <?php if ($search !== '' || $category !== ''): ?>
                <a class="button button-small button-secondary" href="moderation.php#mod-reports">Limpar filtros</a>
            <?php endif; ?>
        </form>

        <?php if (!$reports): ?>
            <div class="empty-state" id="mod-reports-empty">
                <strong><?= $category === '' ? 'Nenhuma denúncia aberta no momento.' : 'Nenhuma denúncia aberta para esta categoria.' ?></strong>
                <span>Quando alguém denunciar uma mensagem ou fórum, ela aparecerá aqui para análise da equipe.</span>
            </div>
        <?php else: ?>
            <div class="item-list" id="mod-reports-list">
                <?php foreach ($reports as $report):
                    $isPost = $report['target_type'] === 'post';
                    $current = $isPost ? $report['post_body'] : ($report['topic_title'] !== null ? $report['topic_title'] . ' — ' . $report['topic_body'] : null);
                    $removed = $current === null;
                    $edited = !$removed && mb_substr($current, 0, 500) !== $report['snapshot'];
                    $text = $removed ? $report['snapshot'] : $current;
                    $long = mb_strlen($text) > 320;
                    $attachments = $attachmentsByReport[($isPost ? 'post:' : 'topic:') . $report['target_id']] ?? [];
                    $sanctionable = $canSanction($report);
                ?>
                    <article class="item-card" id="report-<?= (int) $report['id'] ?>">
                        <header class="item-head">
                            <div class="chip-row">
                                <span class="chip chip-muted"><?= $isPost ? 'Mensagem' : 'Fórum' ?></span>
                                <span
                                    class="chip <?= $report['category'] === 'abuse' ? 'chip-danger' : 'chip-primary' ?>"><?= e(RBAC_REPORT_CATEGORIES[$report['category']] ?? 'Outro motivo') ?></span>
                                <?php if ((int) $report['total_reports'] > 1): ?>
                                    <span class="chip chip-accent"><?= (int) $report['total_reports'] ?> denúncias</span>
                                <?php endif; ?>
                                <?php if ($removed): ?><span class="chip chip-muted">conteúdo já removido</span><?php endif; ?>
                                <?php if ($edited): ?><span class="chip chip-accent">editado após denúncia</span><?php endif; ?>
                            </div>
                            <time class="item-time"
                                title="<?= e(date('d/m/Y H:i', strtotime($report['created_at']))) ?>"><?= e(uiAgo((int) $report['age_seconds'])) ?></time>
                        </header>

                        <div class="item-grid">
                            <div class="item-main">
                                <?php if ($report['topic_title'] !== null): ?>
                                    <p class="item-context">
                                        No fórum
                                        <strong><?= e($report['topic_title']) ?></strong><?= $report['subject_name'] ? ' · Matéria: ' . e($report['subject_name']) : '' ?>
                                    </p>
                                <?php endif; ?>

                                <blockquote class="quote">
                                    <?php if ($long): ?>
                                        <?= nl2br(e(mb_substr($text, 0, 320))) ?>…
                                        <details class="more">
                                            <summary>Ler conteúdo completo</summary><?= nl2br(e(mb_substr($text, 0, 4000))) ?>
                                        </details>
                                    <?php else: ?>
                                        <?= nl2br(e($text)) ?>
                                    <?php endif; ?>
                                </blockquote>

                                <?php if ($attachments): ?>
                                    <div class="thumb-row" aria-label="Anexos">
                                        <?php foreach ($attachments as $attachment): ?>
                                            <?= uiAttachment('forum-attachment.php?id=' . (int) $attachment['id'], (string) $attachment['original_name'], (string) $attachment['mime_type']) ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <p class="item-reason">
                                    <strong>Denúncia de <?= e($report['reporter_name']) ?>:</strong>
                                    <?= $report['details'] ? e($report['details']) : '<em>(Sem observações adicionais)</em>' ?>
                                </p>
                            </div>

                            <aside class="item-side" aria-label="Autor do conteúdo denunciado">
                                <?php if ($report['author_name'] === null): ?>
                                    <p class="item-note">O autor não existe mais.</p>
                                <?php else: ?>
                                    <div class="person">
                                        <?= uiAvatar((int) $report['target_user_id'], (string) $report['author_name'], (bool) $report['author_has_avatar']) ?>
                                        <div>
                                            <strong><?= e($report['author_name']) ?></strong> <span
                                                class="tag-id">#<?= (int) $report['target_user_id'] ?></span>
                                            <div><?= uiRoleBadge((string) $report['author_role']) ?></div>
                                        </div>
                                    </div>
                                    <ul class="fact-list">
                                        <li>Membro <?= e(uiAgo((int) $report['author_age_seconds'])) ?></li>
                                        <li class="<?= (int) $report['author_sanctions'] > 0 ? 'is-warn' : '' ?>">
                                            <?= (int) $report['author_sanctions'] ?> sanção(ões) anterior(es)</li>
                                        <li class="<?= (int) $report['author_confirmed'] > 0 ? 'is-warn' : '' ?>">
                                            <?= (int) $report['author_confirmed'] ?> denúncia(s) confirmada(s)</li>
                                    </ul>
                                <?php endif; ?>
                            </aside>
                        </div>

                        <footer class="item-actions">
                            <?php if ($report['topic_id']): ?>
                                <a class="button button-small button-secondary"
                                    href="forum-topic.php?id=<?= (int) $report['topic_id'] ?><?= $isPost ? '#post-' . (int) $report['target_id'] : '' ?>">Abrir
                                    no fórum</a>
                            <?php endif; ?>

                            <details class="decision">
                                <summary class="button button-small button-primary">Tomar decisão</summary>
                                <form class="decision-form"
                                    action="moderation.php<?= $category !== '' ? '?cat=' . e($category) : '' ?>" method="post">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="form_action" value="resolve_report">
                                    <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">

                                    <label class="field">
                                        <span>Decisão</span>
                                        <select name="action" class="form-input" data-action-select>
                                            <option value="dismiss">Descartar (denúncia improcedente)</option>
                                            <option value="delete_content" <?= $removed ? ' disabled' : '' ?>>Excluir apenas o
                                                conteúdo</option>
                                            <option value="mute" <?= $sanctionable ? '' : ' disabled' ?>>Silenciar autor e
                                                excluir conteúdo</option>
                                            <option value="mute_keep" <?= $sanctionable ? '' : ' disabled' ?>>Silenciar autor
                                                (manter conteúdo)</option>
                                            <option value="suspend" <?= $sanctionable ? '' : ' disabled' ?>>Suspender autor e
                                                excluir conteúdo</option>
                                            <option value="suspend_keep" <?= $sanctionable ? '' : ' disabled' ?>>Suspender autor
                                                (manter conteúdo)</option>
                                        </select>
                                    </label>

                                    <label class="field" data-days-field hidden>
                                        <span>Duração da Sanção</span>
                                        <select name="days" class="form-input">
                                            <?php foreach (RBAC_SANCTION_DAYS as $d): ?>
                                                <option value="<?= $d ?>" <?= $d === 7 ? ' selected' : '' ?>><?= $d ?>
                                                    dia<?= $d > 1 ? 's' : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>

                                    <label class="field field-wide">
                                        <span>Motivo da decisão * <small>(fica registrado no histórico)</small></span>
                                        <input type="text" name="reason" class="form-input" minlength="5" maxlength="255"
                                            required placeholder="Explique sucintamente o motivo desta ação">
                                    </label>

                                    <?php if (!$sanctionable && $report['author_name'] !== null): ?>
                                        <p class="item-note field-wide">Aviso: Este autor possui cargo protegido e não pode ser
                                            sancionado por esta conta.</p>
                                    <?php endif; ?>
                                    <button type="submit" class="button button-primary button-small">Aplicar Decisão</button>
                                </form>
                            </details>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ABA 2: LIVES NO AR -->
    <section id="mod-lives" class="section" data-tab="Lives no ar" data-tab-badge="<?= count($lives) ?>">
        <h2 class="section-title">Lives no Ar</h2>
        <?php if (!$lives): ?>
            <div class="empty-state" id="mod-lives-empty">
                <strong>Nenhuma live no ar neste momento.</strong>
                <span>Quando um professor iniciar uma transmissão ao vivo, você poderá acompanhá-la e fiscalizá-la
                    daqui.</span>
            </div>
        <?php else: ?>
            <div class="item-list" id="mod-lives-list">
                <?php foreach ($lives as $live): $unstable = (int) $live['signal_seconds'] > 75; ?>
                    <article class="item-card">
                        <header class="item-head">
                            <div class="chip-row">
                                <span class="chip chip-danger">Ao vivo</span>
                                <span class="chip chip-primary"><?= e($live['subject']) ?></span>
                                <span
                                    class="chip <?= $unstable ? 'chip-danger' : 'chip-muted' ?>"><?= $unstable ? 'sinal instável' : 'sinal conectado' ?></span>
                            </div>
                            <time class="item-time">no ar há <?= e(uiDuration((int) $live['elapsed_seconds'])) ?></time>
                        </header>
                        <div class="person">
                            <?= uiAvatar((int) $live['teacher_id'], (string) $live['teacher_name'], (bool) $live['teacher_has_avatar']) ?>
                            <div>
                                <strong><?= e($live['title']) ?></strong>
                                <div class="item-note">Professor(a): <?= e($live['teacher_name']) ?></div>
                            </div>
                        </div>
                        <footer class="item-actions">
                            <a class="button button-small button-secondary"
                                href="live-watch.php?id=<?= (int) $live['id'] ?>">Assistir como Moderador</a>
                            <details class="decision">
                                <summary class="button button-small button-danger">Encerrar Live</summary>
                                <form class="decision-form" action="moderation.php" method="post"
                                    data-confirm="Encerrar esta transmissão imediatamente?">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="form_action" value="end_live">
                                    <input type="hidden" name="live_id" value="<?= (int) $live['id'] ?>">
                                    <label class="field field-wide">
                                        <span>Motivo do encerramento forçado *</span>
                                        <input type="text" name="reason" class="form-input" minlength="5" maxlength="255"
                                            required placeholder="Ex.: Violação das diretrizes de conduta">
                                    </label>
                                    <button type="submit" class="button button-danger button-small">Encerrar Agora</button>
                                </form>
                            </details>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($recentLives): ?>
            <h3 class="subsection-title" style="margin-top:var(--space-4);">Últimas lives finalizadas</h3>
            <table class="table" id="mod-lives-recent">
                <thead>
                    <tr>
                        <th class="table-head">Título da Live</th>
                        <th class="table-head">Professor(a)</th>
                        <th class="table-head">Duração</th>
                        <th class="table-head">Finalizada</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLives as $live): ?>
                        <tr class="table-row">
                            <td class="table-cell"><strong><?= e($live['title']) ?></strong> <small
                                    class="item-note">(<?= e($live['subject']) ?>)</small></td>
                            <td class="table-cell"><?= e($live['teacher_name']) ?></td>
                            <td class="table-cell"><?= e(uiDuration((int) $live['duration_seconds'])) ?></td>
                            <td class="table-cell">
                                <?= e(uiAgo((int) $live['age_seconds'])) ?><?= $live['status'] === 'live' ? ' <small class="item-note">(interrompida)</small>' : '' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <!-- ABA 3: SANÇÕES ATIVAS -->
    <section id="mod-sanctions" class="section" data-tab="Sanções ativas" data-tab-badge="<?= count($sanctions) ?>">
        <div class="item-head">
            <h2 class="section-title" style="margin:0; border:0; padding:0;">Sanções Ativas</h2>
            <details class="decision">
                <summary class="button button-small button-primary">Aplicar Sanção Direta</summary>
                <form class="decision-form" action="moderation.php" method="post" style="margin-top:var(--space-2);">
                    <?= csrfInput() ?>
                    <input type="hidden" name="form_action" value="apply_direct_sanction">
                    <label class="field">
                        <span>Usuário (ID ou E-mail) *</span>
                        <input type="text" name="user_lookup" class="form-input"
                            placeholder="Ex.: 42 ou usuario@email.com" required>
                    </label>
                    <label class="field">
                        <span>Tipo de Sanção</span>
                        <select name="sanction_type" class="form-input">
                            <option value="mute">Silenciar (impede mensagens e fóruns)</option>
                            <option value="suspension">Suspender (bloqueia o login)</option>
                        </select>
                    </label>
                    <label class="field">
                        <span>Duração</span>
                        <select name="days" class="form-input">
                            <?php foreach (RBAC_SANCTION_DAYS as $d): ?>
                                <option value="<?= $d ?>" <?= $d === 7 ? ' selected' : '' ?>><?= $d ?>
                                    dia<?= $d > 1 ? 's' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field field-wide">
                        <span>Motivo da Sanção *</span>
                        <input type="text" name="reason" class="form-input" minlength="5" maxlength="255"
                            placeholder="Ex.: Spam recorrente ou desrespeito em mensagens" required>
                    </label>
                    <button type="submit" class="button button-danger button-small">Aplicar Sanção Imediata</button>
                </form>
            </details>
        </div>

        <?php if (!$sanctions): ?>
            <div class="empty-state" id="mod-sanctions-empty" style="margin-top: var(--space-3);">
                <strong>Nenhum usuário está sancionado no momento.</strong>
                <span>Silenciamentos e suspensões ativos aparecem aqui até expirarem ou serem revogados.</span>
            </div>
        <?php else: ?>
            <div class="item-list" id="mod-sanctions-list" style="margin-top: var(--space-3);">
                <?php foreach ($sanctions as $sanction): $remaining = $sanction['remaining_seconds'] === null ? null : (int) $sanction['remaining_seconds']; ?>
                    <article class="item-card">
                        <header class="item-head">
                            <div class="chip-row">
                                <span
                                    class="chip <?= $sanction['type'] === 'suspension' ? 'chip-danger' : 'chip-accent' ?>"><?= $sanction['type'] === 'suspension' ? 'Suspensão de Conta' : 'Silenciado no Fórum' ?></span>
                                <span class="chip chip-muted"><?= e(uiRemaining($remaining)) ?></span>
                            </div>
                            <time
                                class="item-time"><?= $sanction['expires_at'] ? 'até ' . e(date('d/m/Y H:i', strtotime($sanction['expires_at']))) : 'tempo indeterminado' ?></time>
                        </header>
                        <div class="person">
                            <?= uiAvatar((int) $sanction['user_id'], (string) $sanction['user_name'], (bool) $sanction['user_has_avatar']) ?>
                            <div>
                                <strong><?= e($sanction['user_name']) ?></strong> <span
                                    class="tag-id">#<?= (int) $sanction['user_id'] ?></span>
                                <?= uiRoleBadge((string) $sanction['user_role']) ?>
                                <div class="item-note">Motivo: "<?= e($sanction['reason']) ?>" · Por:
                                    <?= e($sanction['applied_name'] ?? 'Sistema') ?></div>
                            </div>
                        </div>
                        <footer class="item-actions">
                            <details class="decision">
                                <summary class="button button-small button-secondary">Revogar Sanção</summary>
                                <form class="decision-form" action="moderation.php" method="post">
                                    <?= csrfInput() ?>
                                    <input type="hidden" name="form_action" value="revoke_sanction">
                                    <input type="hidden" name="sanction_id" value="<?= (int) $sanction['id'] ?>">
                                    <label class="field field-wide">
                                        <span>Motivo da revogação *</span>
                                        <input type="text" name="reason" class="form-input" minlength="5" maxlength="255"
                                            required placeholder="Por que a punição está sendo retirada?">
                                    </label>
                                    <button type="submit" class="button button-primary button-small">Confirmar
                                        Revogação</button>
                                </form>
                            </details>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ABA 4: HISTÓRICO -->
    <section id="mod-history" class="section" data-tab="Histórico">
        <h2 class="section-title">Últimas 50 denúncias atendidas</h2>
        <?php if (!$history): ?>
            <div class="empty-state" id="mod-history-empty">
                <strong>Nenhuma denúncia no histórico ainda.</strong>
                <span>As resoluções de denúncias da equipe ficam arquivadas aqui.</span>
            </div>
        <?php else: ?>
            <table class="table" id="mod-history-table">
                <thead>
                    <tr>
                        <th class="table-head">Quando</th>
                        <th class="table-head">Conteúdo / Alvo</th>
                        <th class="table-head">Motivo</th>
                        <th class="table-head">Decisão</th>
                        <th class="table-head">Atendido por</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $row): ?>
                        <tr class="table-row">
                            <td class="table-cell"><?= $row['handled_at'] ? e(uiAgo((int) $row['handled_seconds'])) : '—' ?>
                            </td>
                            <td class="table-cell"><?= e($row['author_name'] ?? '—') ?> <small
                                    class="item-note">(<?= $row['target_type'] === 'post' ? 'mensagem' : 'fórum' ?>)</small>
                            </td>
                            <td class="table-cell"><?= e(RBAC_REPORT_CATEGORIES[$row['category']] ?? '—') ?></td>
                            <td class="table-cell">
                                <span
                                    class="chip <?= $row['status'] === 'resolved' ? 'chip-primary' : 'chip-muted' ?>"><?= $row['status'] === 'resolved' ? 'Resolvida' : 'Descartada' ?></span>
                                <div class="item-note"><?= e($row['resolution'] ?? '') ?></div>
                            </td>
                            <td class="table-cell"><?= e($row['handler_name'] ?? 'Sistema') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
<script>
    document.addEventListener('change', function(ev) {
        if (ev.target && ev.target.matches('[data-action-select]')) {
            var form = ev.target.closest('form');
            var days = form ? form.querySelector('[data-days-field]') : null;
            if (days) {
                var val = ev.target.value;
                days.hidden = !(val.indexOf('mute') !== -1 || val.indexOf('suspend') !== -1);
            }
        }
    });
</script>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
