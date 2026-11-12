<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/ui-helpers.php';
require_once __DIR__ . '/../includes/ui-icons.php';

ensureAdmin();
rbacEnsureSchema($pdo);

const USERS_PER_PAGE = 25;

$search = trim((string) ($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 100);
$filterRole = (string) ($_GET['role'] ?? '');
$filterStatus = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));

$queryParameters = array_filter([
    'q' => $search !== '' ? $search : null,
    'role' => in_array($filterRole, RBAC_ROLES, true) ? $filterRole : null,
    'status' => in_array($filterStatus, ['active', 'inactive'], true) ? $filterStatus : null,
    'page' => $page > 1 ? $page : null,
]);
$backUrl = 'admin-users.php' . ($queryParameters ? '?' . http_build_query($queryParameters) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf($backUrl);

    $action = (string) ($_POST['action'] ?? 'change_role');
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? ''));

    if ($targetId <= 0) {
        setFlash('error', 'Usuário inválido.');
        redirect($backUrl);
    }

    if ($targetId === (int) $user['id']) {
        setFlash('error', 'Você não pode alterar seu próprio status ou cargo por segurança.');
        redirect($backUrl);
    }

    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
        setFlash('error', 'Informe o motivo da ação administrativa (5 a 255 caracteres).');
        redirect($backUrl);
    }

    // 1. Alterar Cargo
    if ($action === 'change_role') {
        $newRole = (string) ($_POST['role'] ?? '');
        if (!in_array($newRole, RBAC_ROLES, true)) {
            setFlash('error', 'Cargo selecionado inválido.');
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('SELECT id, name, role FROM users WHERE id = ? FOR UPDATE');
                $stmt->execute([$targetId]);
                $target = $stmt->fetch();

                if (!$target) {
                    throw new RuntimeException('Usuário não encontrado.');
                }
                if ($target['role'] === $newRole) {
                    throw new RuntimeException($target['name'] . ' já possui o cargo ' . rbacRoleLabel($newRole) . '.');
                }

                if ($target['role'] === 'admin') {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND activate = 1 AND id <> ? FOR UPDATE");
                    $stmt->execute([$targetId]);
                    if (!$stmt->fetchAll()) {
                        throw new RuntimeException('Não é possível rebaixar o único administrador ativo do sistema.');
                    }
                }

                $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);
                rbacAudit(
                    $pdo, (int) $user['id'], 'ROLE_CHANGED', 'user', $targetId,
                    ['role' => $target['role']], ['role' => $newRole], $reason
                );
                $pdo->commit();

                setFlash('success', "Cargo de {$target['name']} alterado para " . rbacRoleLabel($newRole) . '.');
            } catch (RuntimeException $e) {
                $pdo->rollBack();
                setFlash('error', $e->getMessage());
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('admin-users.php:role_change_failed: ' . $e->getMessage());
                setFlash('error', 'Não foi possível alterar o cargo.');
            }
        }

    // 2. Ativar / Desativar Conta
    } elseif ($action === 'toggle_active') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id, name, role, activate FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$targetId]);
            $target = $stmt->fetch();

            if (!$target) {
                throw new RuntimeException('Usuário não encontrado.');
            }

            if ((int) $target['activate'] === 1 && $target['role'] === 'admin') {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' AND activate = 1 AND id <> ? FOR UPDATE");
                $stmt->execute([$targetId]);
                if (!$stmt->fetchAll()) {
                    throw new RuntimeException('Não é possível desativar o último administrador ativo.');
                }
            }

            $newActivate = (int) $target['activate'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE users SET activate = ? WHERE id = ?')->execute([$newActivate, $targetId]);

            $auditAction = $newActivate === 1 ? 'USER_ACTIVATED' : 'USER_DEACTIVATED';
            rbacAudit(
                $pdo, (int) $user['id'], $auditAction, 'user', $targetId,
                ['activate' => (int) $target['activate']], ['activate' => $newActivate], $reason
            );

            $pdo->commit();
            setFlash('success', $newActivate === 1
                ? "A conta de {$target['name']} foi reativada com sucesso."
                : "A conta de {$target['name']} foi desativada.");
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            setFlash('error', $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('admin-users.php:toggle_active_failed: ' . $e->getMessage());
            setFlash('error', 'Não foi possível alterar o status da conta.');
        }
    }

    redirect($backUrl);
}

// ---------------------------------------------------------------
// Consultas com Filtros
// ---------------------------------------------------------------
$whereClauses = [];
$params = [];

if ($search !== '') {
    $like = '%' . addcslashes($search, '%_\\\\') . '%';
    $whereClauses[] = '(name LIKE ? OR email LIKE ? OR cpf LIKE ?)';
    array_push($params, $like, $like, $like);
}

if ($filterRole !== '' && in_array($filterRole, RBAC_ROLES, true)) {
    $whereClauses[] = 'role = ?';
    $params[] = $filterRole;
}

if ($filterStatus === 'active') {
    $whereClauses[] = 'activate = 1';
} elseif ($filterStatus === 'inactive') {
    $whereClauses[] = 'activate = 0';
}

$whereSql = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users $whereSql");
$countStmt->execute($params);
$totalUsers = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalUsers / USERS_PER_PAGE));
$page = min($page, $totalPages);
$offset = ($page - 1) * USERS_PER_PAGE;

$listStmt = $pdo->prepare(
    "SELECT id, name, email, phone, cpf, role, activate, created_at, last_login_at 
     FROM users 
     $whereSql 
     ORDER BY id DESC 
     LIMIT " . USERS_PER_PAGE . " OFFSET $offset"
);
$listStmt->execute($params);
$users = $listStmt->fetchAll();

// Histórico de auditoria com filtro
$auditActionFilter = (string) ($_GET['audit_act'] ?? '');
$auditParams = [];
$auditWhere = '';
if ($auditActionFilter !== '') {
    $auditWhere = 'WHERE l.action = ?';
    $auditParams[] = $auditActionFilter;
}

$stmtLogs = $pdo->prepare(
    "SELECT l.id, l.action, l.target_type, l.target_id, l.old_value, l.new_value, l.reason, l.ip, l.created_at,
            a.name AS actor_name, t.name AS target_name
     FROM audit_logs l
     LEFT JOIN users a ON a.id = l.actor_id
     LEFT JOIN users t ON l.target_type = 'user' AND t.id = l.target_id
     $auditWhere
     ORDER BY l.id DESC LIMIT 50"
);
$stmtLogs->execute($auditParams);
$logs = $stmtLogs->fetchAll();

$distinctActions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

function formatCpf(string $cpf): string
{
    $clean = preg_replace('/\D/', '', $cpf);
    if (strlen($clean) === 11) {
        return substr($clean, 0, 3) . '.***.***-' . substr($clean, 9, 2);
    }
    return $cpf ?: '—';
}

/** Resumo legível de uma alteração registrada na auditoria (ex.: "Cargo: Aluno → Professor"). */
function apDiff(?string $old, ?string $new): string
{
    $labels = ['role' => 'Cargo', 'activate' => 'Conta'];
    $before = json_decode((string) $old, true);
    $after = json_decode((string) $new, true);

    if (!is_array($before) && !is_array($after)) {
        return ($old ?? '—') . ' → ' . ($new ?? '—');
    }

    $before = is_array($before) ? $before : [];
    $after = is_array($after) ? $after : [];
    $lines = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
        $fmt = function ($value) use ($key): string {
            if ($value === null) {
                return '—';
            }
            if ($key === 'role') {
                return rbacRoleLabel((string) $value);
            }
            if ($key === 'activate') {
                return (int) $value === 1 ? 'Ativa' : 'Desativada';
            }
            return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        };
        $lines[] = ($labels[$key] ?? (string) $key) . ': ' . $fmt($before[$key] ?? null) . ' → ' . $fmt($after[$key] ?? null);
    }
    return implode(' · ', $lines);
}

// Totais por cargo para o topo do painel
$roleCounts = [];
$inactiveCount = 0;
try {
    foreach ($pdo->query('SELECT role, COUNT(*) AS n FROM users GROUP BY role')->fetchAll() as $r) {
        $roleCounts[(string) $r['role']] = (int) $r['n'];
    }
    $inactiveCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE activate = 0')->fetchColumn();
} catch (Throwable $e) {
}
$allUsersCount = array_sum($roleCounts);

$formQuery = $queryParameters ? '?' . http_build_query($queryParameters) : '';
$hasFilters = $search !== '' || $filterRole !== '' || $filterStatus !== '';

// Janela de páginas: 1 … atual-2..atual+2 … última
$pageWindow = [];
for ($i = 1; $i <= $totalPages; $i++) {
    if ($i === 1 || $i === $totalPages || abs($i - $page) <= 2) {
        $pageWindow[] = $i;
    }
}

$pageId = 'admin-users';
$pageTitle = 'Usuários e cargos';
$extraCss = ['ui-kit', 'admin-panels'];
$extraJs = ['ui-kit', 'staff'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content ap-main">

    <!-- ====================== HERO ====================== -->
    <header class="ap-hero ap-hero--admin">
        <div class="ap-hero-text">
            <div class="ap-hero-top"><?= uiRoleBadge('admin') ?></div>
            <h1 class="ap-title">Usuários e cargos</h1>
            <p class="ap-subtitle">Controle de acessos, papéis de usuários, ativação de contas e auditoria das ações da equipe.</p>
        </div>
        <div class="ap-hero-actions">
            <a class="button button-small" href="moderator-dashboard.php"><?= uiIcon('overview', 16) ?>Painel da administração</a>
            <a class="button button-small" href="moderation.php"><?= uiIcon('shield', 16) ?>Fila de moderação</a>
        </div>
    </header>

    <div class="ap-shell">

        <nav class="ap-nav" data-ap-nav aria-label="Seções do painel">
            <?= apNavButton('users-section', 'users', 'Usuários', 0) ?>
            <?= apNavButton('audit-section', 'audit', 'Auditoria', 0) ?>
            <span class="ap-nav-sep">Atalhos</span>
            <?= apNavLink('moderator-dashboard.php', 'overview', 'Painel da administração') ?>
            <?= apNavLink('moderation.php', 'shield', 'Fila de moderação') ?>
        </nav>

        <div class="ap-content">

            <!-- ====================== USUÁRIOS ====================== -->
            <section id="users-section" class="ap-panel" data-ap-panel>
                <div class="ap-panel-head">
                    <div>
                        <h2 class="ap-panel-title">Contas e acessos <span class="chip"><?= $totalUsers ?></span></h2>
                        <p class="ap-panel-sub">Clique em um usuário para alterar o cargo ou ativar/desativar a conta. Toda ação exige um motivo e fica registrada.</p>
                    </div>
                </div>

                <div class="ap-stats ap-stats--compact">
                    <?= apStat('users', $allUsersCount, 'Total de contas') ?>
                    <?= apStat('shield', $roleCounts['admin'] ?? 0, 'Administradores') ?>
                    <?= apStat('shield', $roleCounts['moderator'] ?? 0, 'Moderadores') ?>
                    <?= apStat('teacher', $roleCounts['teacher'] ?? 0, 'Professores') ?>
                    <?= apStat('book', $roleCounts['student'] ?? 0, 'Alunos') ?>
                    <?= apStat('lock', $inactiveCount, 'Desativadas', '', null, $inactiveCount > 0) ?>
                </div>

                <form class="ap-filter" action="admin-users.php" method="get" role="search">
                    <label class="ap-search">
                        <?= uiIcon('search', 18) ?><span class="ui-sr">Buscar usuário</span>
                        <input type="search" name="q" class="form-input" maxlength="100" placeholder="Nome, e-mail ou CPF" value="<?= e($search) ?>">
                    </label>
                    <select name="role" class="form-input" aria-label="Filtrar por cargo">
                        <option value="">Todos os cargos</option>
                        <?php foreach (RBAC_ROLES as $r): ?>
                            <option value="<?= e($r) ?>"<?= $filterRole === $r ? ' selected' : '' ?>><?= e(rbacRoleLabel($r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" class="form-input" aria-label="Filtrar por status">
                        <option value="">Todos os status</option>
                        <option value="active"<?= $filterStatus === 'active' ? ' selected' : '' ?>>Apenas ativos</option>
                        <option value="inactive"<?= $filterStatus === 'inactive' ? ' selected' : '' ?>>Apenas desativados</option>
                    </select>
                    <button type="submit" class="button button-primary button-small">Filtrar</button>
                    <?php if ($hasFilters): ?><a href="admin-users.php" class="button button-small">Limpar</a><?php endif; ?>
                </form>

                <?php if (!$users): ?>
                    <div class="ui-empty"><strong>Nenhum usuário encontrado com os filtros selecionados.</strong></div>
                <?php else: ?>
                    <ul class="ap-list" id="users-table">
                        <?php foreach ($users as $row):
                            $uid = (int) $row['id'];
                            $isSelf = $uid === (int) $user['id'];
                            $isActive = (int) $row['activate'] === 1;
                            $summary = '<span class="avatar">' . e(uiInitials((string) $row['name'])) . '</span>';
                        ?>
                            <li class="ap-user">
                                <?php if ($isSelf): ?>
                                    <div class="ap-user-static">
                                        <?= $summary ?>
                                        <span class="ap-user-id">
                                            <strong class="ui-ellipsis"><?= e($row['name']) ?></strong>
                                            <small class="ui-ellipsis"><?= e($row['email']) ?> · #<?= $uid ?></small>
                                        </span>
                                        <span class="ap-user-chips"><?= uiRoleBadge((string) $row['role']) ?><span class="chip chip-muted">Sua conta atual</span></span>
                                    </div>
                                <?php else: ?>
                                    <details class="ui-details ap-user-details">
                                        <summary>
                                            <?= $summary ?>
                                            <span class="ap-user-id">
                                                <strong class="ui-ellipsis" title="<?= e($row['name']) ?>"><?= e($row['name']) ?></strong>
                                                <small class="ui-ellipsis" title="<?= e($row['email']) ?>"><?= e($row['email']) ?> · #<?= $uid ?></small>
                                            </span>
                                            <span class="ap-user-chips">
                                                <?= uiRoleBadge((string) $row['role']) ?>
                                                <span class="chip <?= $isActive ? 'chip-primary' : 'chip-danger' ?>"><?= $isActive ? 'Ativa' : 'Desativada' ?></span>
                                            </span>
                                            <span class="ap-user-manage"><span>Gerenciar</span><span class="ui-chevron" aria-hidden="true"></span></span>
                                        </summary>
                                        <div class="ui-details-body"><div class="ui-details-inner ap-manage-grid">

                                            <div class="ap-manage-box">
                                                <h4>Dados da conta</h4>
                                                <dl class="ap-facts">
                                                    <div><dt>Perfil:</dt><dd><a href="profile-view.php?id=<?= $uid ?>">Abrir perfil</a></dd></div>
                                                    <div><dt>Telefone:</dt><dd><?= e($row['phone'] ?: 'Sem telefone') ?></dd></div>
                                                    <div><dt>CPF:</dt><dd><?= e(formatCpf((string) $row['cpf'])) ?></dd></div>
                                                    <div><dt>Cadastro:</dt><dd><?= e(date('d/m/Y H:i', strtotime((string) $row['created_at']))) ?></dd></div>
                                                    <div><dt>Último acesso:</dt><dd><?= !empty($row['last_login_at']) ? e(date('d/m/Y H:i', strtotime((string) $row['last_login_at']))) : 'Nunca' ?></dd></div>
                                                </dl>
                                            </div>

                                            <form class="ap-manage-box ui-form" action="admin-users.php<?= e($formQuery) ?>" method="post">
                                                <h4>Alterar cargo</h4>
                                                <?= csrfInput() ?>
                                                <input type="hidden" name="action" value="change_role">
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <label class="ui-field">
                                                    <span class="ui-field-top"><span class="ui-field-label">Novo cargo</span></span>
                                                    <select name="role" class="form-input">
                                                        <?php foreach (RBAC_ROLES as $r): ?>
                                                            <option value="<?= e($r) ?>"<?= $r === $row['role'] ? ' selected' : '' ?>><?= e(rbacRoleLabel($r)) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label class="ui-field">
                                                    <span class="ui-field-top"><span class="ui-field-label">Motivo *</span></span>
                                                    <input type="text" name="reason" class="form-input" minlength="5" maxlength="255" placeholder="Ex.: Promovido para auxiliar na moderação" required autocomplete="off">
                                                </label>
                                                <div class="ui-actions">
                                                    <button type="submit" class="button button-primary button-small"
                                                        data-confirm="O cargo de <?= e(mb_strimwidth((string) $row['name'], 0, 50, '…')) ?> será alterado e a ação ficará registrada na auditoria."
                                                        data-confirm-title="Confirmar mudança de cargo?" data-confirm-yes="Alterar cargo">Confirmar cargo</button>
                                                </div>
                                            </form>

                                            <form class="ap-manage-box ui-form" action="admin-users.php<?= e($formQuery) ?>" method="post">
                                                <h4><?= $isActive ? 'Desativar conta' : 'Reativar conta' ?></h4>
                                                <?= csrfInput() ?>
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <label class="ui-field">
                                                    <span class="ui-field-top"><span class="ui-field-label">Motivo <?= $isActive ? 'da desativação' : 'da reativação' ?> *</span></span>
                                                    <input type="text" name="reason" class="form-input" minlength="5" maxlength="255" placeholder="Explique o motivo desta ação" required autocomplete="off">
                                                </label>
                                                <div class="ui-actions">
                                                    <button type="submit" class="button button-small <?= $isActive ? 'button-delete' : 'button-primary' ?>"
                                                        data-confirm="<?= $isActive ? e(mb_strimwidth((string) $row['name'], 0, 50, '…')) . ' perderá o acesso à plataforma.' : e(mb_strimwidth((string) $row['name'], 0, 50, '…')) . ' voltará a ter acesso à plataforma.' ?>"
                                                        data-confirm-title="<?= $isActive ? 'Desativar conta?' : 'Reativar conta?' ?>"
                                                        data-confirm-yes="<?= $isActive ? 'Desativar' : 'Reativar' ?>"
                                                        <?= $isActive ? 'data-confirm-tone="danger"' : '' ?>><?= $isActive ? 'Confirmar desativação' : 'Confirmar reativação' ?></button>
                                                </div>
                                            </form>

                                        </div></div>
                                    </details>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($totalPages > 1): ?>
                        <nav class="ap-pager" aria-label="Paginação de usuários">
                            <?php $previous = 0; foreach ($pageWindow as $i):
                                if ($previous && $i - $previous > 1): ?><span class="ap-page" aria-hidden="true">…</span><?php endif;
                                $previous = $i;
                                $pageUrl = 'admin-users.php?' . http_build_query(array_merge($queryParameters, ['page' => $i])) . '#users-section';
                                if ($i === $page): ?>
                                    <strong class="ap-page is-active" aria-current="page"><?= $i ?></strong>
                                <?php else: ?>
                                    <a class="ap-page" href="<?= e($pageUrl) ?>"><?= $i ?></a>
                                <?php endif;
                            endforeach; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <!-- ====================== AUDITORIA ====================== -->
            <section id="audit-section" class="ap-panel" data-ap-panel>
                <div class="ap-panel-head">
                    <div>
                        <h2 class="ap-panel-title">Histórico de auditoria</h2>
                        <p class="ap-panel-sub">As 50 ações administrativas mais recentes.</p>
                    </div>
                </div>

                <form method="get" action="admin-users.php#audit-section" class="ap-filter">
                    <select name="audit_act" class="form-input" aria-label="Filtrar por ação">
                        <option value="">Todas as ações</option>
                        <?php foreach ($distinctActions as $act): ?>
                            <option value="<?= e($act) ?>"<?= $auditActionFilter === $act ? ' selected' : '' ?>><?= e(uiAuditLabel((string) $act)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button button-small button-primary">Filtrar</button>
                    <?php if ($auditActionFilter !== ''): ?><a href="admin-users.php#audit-section" class="button button-small">Limpar</a><?php endif; ?>
                </form>

                <?php if (!$logs): ?>
                    <div class="ui-empty"><strong>Nenhum registro para este critério.</strong></div>
                <?php else: ?>
                    <ul class="ap-list" id="audit-table">
                        <?php foreach ($logs as $log): ?>
                            <li class="ap-log">
                                <div class="ap-log-time">
                                    <?= e(date('d/m/Y', strtotime((string) $log['created_at']))) ?>
                                    <small><?= e(date('H:i', strtotime((string) $log['created_at']))) ?></small>
                                </div>
                                <div class="ap-log-body">
                                    <div class="ap-log-head">
                                        <strong><?= e($log['actor_name'] ?? 'Sistema') ?></strong>
                                        <span class="chip chip-muted"><?= e(uiAuditLabel((string) $log['action'])) ?></span>
                                        <span class="ap-muted">alvo: <?= e($log['target_name'] ?? ($log['target_type'] . ' #' . $log['target_id'])) ?></span>
                                    </div>
                                    <div class="ap-log-diff"><?= e(apDiff($log['old_value'] ?? null, $log['new_value'] ?? null)) ?></div>
                                    <?php if (!empty($log['reason'])): ?><p class="ap-log-reason"><strong>Motivo:</strong> <?= e($log['reason']) ?></p><?php endif; ?>
                                    <?php if ($log['ip'] !== null): ?><span class="ap-log-ip">IP <?= e((string) @inet_ntop($log['ip'])) ?></span><?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

        </div>
    </div>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
