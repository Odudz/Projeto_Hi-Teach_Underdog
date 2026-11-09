<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/ui-helpers.php';

ensureAdmin();

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

$pageId = 'admin-users';
$pageTitle = 'Usuários e cargos';
$extraCss = ['tabs', 'staff'];
$extraJs = ['tabs', 'staff'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <header class="staff-header">
    <div>
      <h1 class="staff-title">Gestão de Usuários e Cargos</h1>
      <p class="staff-subtitle">Controle de acessos, papéis de usuários, ativação de contas e auditoria de ações.</p>
    </div>
    <div class="staff-header-actions">
      <a class="button button-small button-secondary" href="moderator-dashboard.php">Painel do Administrador</a>
      <a class="button button-small button-secondary" href="moderation.php">Fila de Moderação</a>
    </div>
  </header>

  <!-- ABA 1: USUÁRIOS -->
  <section id="users-section" class="section" data-tab="Usuários" data-tab-badge="<?= $totalUsers ?>">
    <h2 class="section-title">Contas e Acessos</h2>

    <form class="filter-bar" action="admin-users.php" method="get" role="search" style="align-items: center;">
      <input type="search" name="q" class="form-input" maxlength="100" placeholder="Buscar por nome, e-mail ou CPF" value="<?= e($search) ?>" aria-label="Buscar usuário" style="flex: 1 1 240px; max-width: 320px;">
      
      <select name="role" class="form-input" style="width: auto;">
        <option value="">Todos os cargos</option>
        <?php foreach (RBAC_ROLES as $r): ?>
          <option value="<?= e($r) ?>"<?= $filterRole === $r ? ' selected' : '' ?>><?= e(rbacRoleLabel($r)) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="status" class="form-input" style="width: auto;">
        <option value="">Todos os status</option>
        <option value="active"<?= $filterStatus === 'active' ? ' selected' : '' ?>>Apenas Ativos</option>
        <option value="inactive"<?= $filterStatus === 'inactive' ? ' selected' : '' ?>>Apenas Desativados</option>
      </select>

      <button type="submit" class="button button-primary button-small">Filtrar</button>
      <?php if ($search !== '' || $filterRole !== '' || $filterStatus !== ''): ?>
        <a href="admin-users.php" class="button button-small button-secondary">Limpar</a>
      <?php endif; ?>
    </form>

    <?php if (!$users): ?>
      <div class="empty-state">
        <strong>Nenhum usuário encontrado com os filtros selecionados.</strong>
      </div>
    <?php else: ?>
      <table id="users-table" class="table">
        <thead>
          <tr>
            <th class="table-head">Usuário / E-mail</th>
            <th class="table-head">Contato / CPF</th>
            <th class="table-head">Cargo Atual</th>
            <th class="table-head">Status</th>
            <th class="table-head">Ações Administrativas</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $row):
            $uid = (int) $row['id'];
            $isSelf = $uid === (int) $user['id'];
            $isActive = (int) $row['activate'] === 1;
        ?>
          <tr class="table-row">
            <td class="table-cell">
              <strong><a href="profile-view.php?id=<?= $uid ?>"><?= e($row['name']) ?></a></strong>
              <span class="tag-id">#<?= $uid ?></span>
              <div class="item-note"><?= e($row['email']) ?></div>
            </td>
            <td class="table-cell">
              <div><?= e($row['phone'] ?: 'Sem telefone') ?></div>
              <small class="item-note">CPF: <?= e(formatCpf((string) $row['cpf'])) ?></small>
            </td>
            <td class="table-cell">
              <?= uiRoleBadge((string) $row['role']) ?>
            </td>
            <td class="table-cell">
              <span class="chip <?= $isActive ? 'chip-primary' : 'chip-danger' ?>">
                <?= $isActive ? 'Ativa' : 'Desativada' ?>
              </span>
            </td>
            <td class="table-cell">
              <?php if ($isSelf): ?>
                <span class="chip chip-muted">Sua conta atual</span>
              <?php else: ?>
                <!-- Ação 1: Alterar Cargo -->
                <details class="decision" style="display:inline-block; margin-right: 4px;">
                  <summary class="button button-small button-secondary">Alterar Cargo</summary>
                  <form class="decision-form" action="admin-users.php<?= $queryParameters ? '?' . http_build_query($queryParameters) : '' ?>" method="post">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="change_role">
                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                    <label class="field">
                      <span>Novo Cargo</span>
                      <select name="role" class="form-input">
                        <?php foreach (RBAC_ROLES as $r): ?>
                          <option value="<?= e($r) ?>"<?= $r === $row['role'] ? ' selected' : '' ?>><?= e(rbacRoleLabel($r)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label class="field field-wide">
                      <span>Motivo da Mudança de Cargo *</span>
                      <input type="text" name="reason" class="form-input" minlength="5" maxlength="255" placeholder="Ex.: Promovido para auxílio na moderação" required>
                    </label>
                    <button type="submit" class="button button-primary button-small">Confirmar Cargo</button>
                  </form>
                </details>

                <!-- Ação 2: Ativar / Desativar Conta -->
                <details class="decision" style="display:inline-block;">
                  <summary class="button button-small <?= $isActive ? 'button-danger' : 'button-primary' ?>">
                    <?= $isActive ? 'Desativar' : 'Reativar' ?>
                  </summary>
                  <form class="decision-form" action="admin-users.php<?= $queryParameters ? '?' . http_build_query($queryParameters) : '' ?>" method="post">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                    <label class="field field-wide">
                      <span>Motivo <?= $isActive ? 'da Desativação' : 'da Reativação' ?> *</span>
                      <input type="text" name="reason" class="form-input" minlength="5" maxlength="255" placeholder="Explique o motivo desta ação administrativa" required>
                    </label>
                    <button type="submit" class="button button-small <?= $isActive ? 'button-danger' : 'button-primary' ?>">
                      <?= $isActive ? 'Confirmar Desativação' : 'Confirmar Reativação' ?>
                    </button>
                  </form>
                </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($totalPages > 1): ?>
        <nav class="forum-pager" aria-label="Paginação de usuários" style="margin-top: var(--space-4);">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php
            $pageParams = array_merge($queryParameters, ['page' => $i]);
            $pageUrl = 'admin-users.php?' . http_build_query($pageParams);
            ?>
            <?php if ($i === $page): ?>
              <strong class="forum-page is-active" aria-current="page"><?= $i ?></strong>
            <?php else: ?>
              <a class="forum-page" href="<?= e($pageUrl) ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- ABA 2: LOGS DE AUDITORIA -->
  <section id="audit-section" class="section" data-tab="Histórico de Auditoria">
    <div class="item-head">
      <h2 class="section-title" style="margin:0; border:0; padding:0;">Histórico de Ações da Equipe</h2>
      <form method="get" action="admin-users.php#audit-section" class="inline-form">
        <select name="audit_act" class="form-input" style="width: auto;">
          <option value="">Todas as ações</option>
          <?php foreach ($distinctActions as $act): ?>
            <option value="<?= e($act) ?>"<?= $auditActionFilter === $act ? ' selected' : '' ?>><?= e(uiAuditLabel((string) $act)) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="button button-small button-primary">Filtrar</button>
        <?php if ($auditActionFilter !== ''): ?>
          <a href="admin-users.php#audit-section" class="button button-small button-secondary">Limpar</a>
        <?php endif; ?>
      </form>
    </div>

    <?php if (!$logs): ?>
      <div class="empty-state" style="margin-top: var(--space-3);">
        <strong>Nenhum log registrado para este critério.</strong>
      </div>
    <?php else: ?>
      <table id="audit-table" class="table" style="margin-top: var(--space-3);">
        <thead>
          <tr>
            <th class="table-head">Data/Hora</th>
            <th class="table-head">Responsável</th>
            <th class="table-head">Ação</th>
            <th class="table-head">Alvo</th>
            <th class="table-head">Alteração</th>
            <th class="table-head">Motivo Registrado</th>
            <th class="table-head">IP</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
          <tr class="table-row">
            <td class="table-cell"><?= e(date('d/m/Y H:i', strtotime($log['created_at']))) ?></td>
            <td class="table-cell"><strong><?= e($log['actor_name'] ?? 'Sistema') ?></strong></td>
            <td class="table-cell"><span class="chip chip-muted"><?= e(uiAuditLabel((string) $log['action'])) ?></span></td>
            <td class="table-cell"><?= e($log['target_name'] ?? ($log['target_type'] . ' #' . $log['target_id'])) ?></td>
            <td class="table-cell"><small><?= e(($log['old_value'] ?? '—') . ' → ' . ($log['new_value'] ?? '—')) ?></small></td>
            <td class="table-cell"><?= e($log['reason'] ?? '—') ?></td>
            <td class="table-cell"><small><?= e($log['ip'] !== null ? (string) @inet_ntop($log['ip']) : '—') ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>