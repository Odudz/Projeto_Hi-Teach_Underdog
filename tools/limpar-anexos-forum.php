<?php
// Limpeza one-shot dos anexos do fórum (uploads/forum).
// Apenas administradores. Não toca em currículos de professores (storage/private/proofs).
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/courses.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/ui-helpers.php';
require_once __DIR__ . '/../includes/forum-upload.php';
require_once __DIR__ . '/../includes/ui-icons.php';

ensureAdmin('../');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf('limpar-anexos-forum.php');

    if (($_POST['confirm'] ?? '') === 'sim') {
        $rows = $pdo->query('SELECT stored_name FROM forum_attachments')->fetchAll(PDO::FETCH_COLUMN);
        $removidos = 0;
        $falhas = 0;

        foreach ($rows as $name) {
            // Só aceita o padrão gerado pelo sistema (32 hex + extensão) e apenas dentro de uploads/forum.
            if (!is_string($name) || !preg_match('/^[a-f0-9]{32}\.[a-z0-9]+$/D', $name)) {
                $falhas++;
                continue;
            }
            $path = FORUM_UPLOAD_DIR . '/' . $name;
            if (is_file($path)) {
                if (@unlink($path)) {
                    $removidos++;
                } else {
                    $falhas++;
                }
            }
        }

        $pdo->exec('DELETE FROM forum_attachments');
        setFlash('success', "Anexos do fórum removidos: {$removidos} arquivo(s). Falhas: {$falhas}.");
    } else {
        setFlash('error', 'Confirmação não marcada. Nada foi apagado.');
    }
    redirect('limpar-anexos-forum.php');
}

$total = (int) $pdo->query('SELECT COUNT(*) FROM forum_attachments')->fetchColumn();

$pageId = 'limpar-anexos-forum';
$pageTitle = 'Limpeza de anexos do fórum';
$extraCss = ['ui-kit', 'admin-panels'];
$extraJs = ['ui-kit'];
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content ap-main">
    <header class="ap-hero ap-hero--admin">
        <div class="ap-hero-text">
            <div class="ap-hero-top"><?= uiRoleBadge('admin') ?></div>
            <h1 class="ap-title">Limpeza de anexos do fórum</h1>
            <p class="ap-subtitle">Remove os arquivos enviados nos fóruns. Os comprovantes de professores não são afetados.</p>
        </div>
        <div class="ap-hero-actions">
            <a class="button button-small" href="../pages/moderator-dashboard.php"><?= uiIcon('overview', 16) ?>Voltar ao painel</a>
        </div>
    </header>

    <section class="ui-panel ui-panel-danger" style="display:grid; gap: var(--space-3);">
        <p><strong><?= $total ?></strong> anexo(s) registrado(s) no fórum.</p>
        <?php if ($total === 0): ?>
            <p class="ui-note">Não há anexos para remover. Você já pode apagar este arquivo do servidor.</p>
        <?php else: ?>
            <form class="ui-form" method="post" action="limpar-anexos-forum.php">
                <?= csrfInput() ?>
                <label class="ui-check">
                    <input type="checkbox" name="confirm" value="sim" required>
                    <span>Entendo que os arquivos serão apagados permanentemente.</span>
                </label>
                <div class="ui-actions">
                    <button type="submit" class="button button-delete"
                        data-confirm="Todos os anexos do fórum serão apagados. Essa ação não pode ser desfeita."
                        data-confirm-title="Apagar todos os anexos?" data-confirm-yes="Apagar anexos" data-confirm-tone="danger">Apagar todos os anexos</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
