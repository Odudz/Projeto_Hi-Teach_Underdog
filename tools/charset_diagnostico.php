<?php
// DIAGNÓSTICO E CORREÇÃO DE EMOJIS ("?" no lugar dos emojis).
// Só administradores logados conseguem abrir. Funciona na hospedagem e no computador.
include_once __DIR__ . '/../config/conexao.php';

if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    die('Acesso negado. Entre com uma conta de administrador.');
}

function dbName(PDO $pdo): string
{
    return (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
}

function badColumns(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME <> 'utf8mb4'
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    $stmt->execute([dbName($pdo)]);
    return $stmt->fetchAll();
}

function badTables(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
           AND (TABLE_COLLATION IS NULL OR TABLE_COLLATION NOT LIKE 'utf8mb4%')
         ORDER BY TABLE_NAME"
    );
    $stmt->execute([dbName($pdo)]);
    return $stmt->fetchAll();
}

$report = [];
$fixed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? null)) {
    $db = dbName($pdo);
    try {
        $pdo->exec('ALTER DATABASE `' . str_replace('`', '', $db) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $report[] = ['ok', "Banco padronizado em utf8mb4."];
    } catch (Throwable $error) {
        $report[] = ['warn', 'Não foi possível alterar o padrão do banco (a hospedagem pode bloquear): ' . $error->getMessage()];
    }
    // Converte toda tabela com padrão errado OU com alguma coluna de texto fora de utf8mb4.
    $toFix = array_unique(array_merge(
        array_column(badTables($pdo), 'TABLE_NAME'),
        array_column(badColumns($pdo), 'TABLE_NAME')
    ));
    foreach ($toFix as $tableName) {
        $name = str_replace('`', '', $tableName);
        try {
            $pdo->exec("ALTER TABLE `$name` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $report[] = ['ok', "Tabela $name convertida."];
        } catch (Throwable $error) {
            $report[] = ['error', "Tabela $name falhou: " . $error->getMessage()];
        }
    }
    $fixed = true;
}

$columns = badColumns($pdo);
$tables = badTables($pdo);
$variables = $pdo->query("SHOW VARIABLES WHERE Variable_name IN ('character_set_client','character_set_connection','character_set_results','character_set_database','sql_mode')")->fetchAll(PDO::FETCH_KEY_PAIR);

// Teste real: grava e lê um emoji em uma tabela temporária (não mexe nos seus dados).
$roundTrip = null;
try {
    $pdo->exec('CREATE TEMPORARY TABLE emoji_test (t TEXT) DEFAULT CHARSET=utf8mb4');
    $sample = "😊👍🚀";
    $pdo->prepare('INSERT INTO emoji_test (t) VALUES (?)')->execute([$sample]);
    $roundTrip = $pdo->query('SELECT t FROM emoji_test')->fetchColumn() === $sample;
} catch (Throwable $error) {
    $roundTrip = null;
}

$forumOk = true;
$bodyColumns = $pdo->prepare(
    "SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND ((TABLE_NAME = 'forum_posts' AND COLUMN_NAME = 'body')
        OR (TABLE_NAME = 'forum_topics' AND COLUMN_NAME IN ('title','body'))
        OR (TABLE_NAME = 'private_messages' AND COLUMN_NAME = 'body')
        OR (TABLE_NAME = 'users' AND COLUMN_NAME IN ('name','bio')))"
);
$bodyColumns->execute([dbName($pdo)]);
$bodyColumns = $bodyColumns->fetchAll();
foreach ($bodyColumns as $column) {
    if ($column['CHARACTER_SET_NAME'] !== 'utf8mb4') {
        $forumOk = false;
    }
}
?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnóstico de emojis</title>
<style>body{font-family:system-ui,sans-serif;max-width:820px;margin:30px auto;padding:0 16px;line-height:1.5}
.ok{color:#1b8a3a}.error{color:#c62828}.warn{color:#b26a00}table{border-collapse:collapse;width:100%}td,th{border:1px solid #bbb;padding:4px 8px;text-align:left}
button{padding:10px 18px;font-size:16px;cursor:pointer}</style></head><body>
<h1>Diagnóstico de emojis</h1>
<?php foreach ($report as [$type, $text]): ?><p class="<?= e($type) ?>"><?= e($text) ?></p><?php endforeach; ?>

<h2>Resultado</h2>
<p class="<?= $forumOk && !$columns && !$tables ? 'ok' : 'error' ?>"><b>
<?= $forumOk && !$columns && !$tables ? 'Tudo certo: o banco aceita emojis.' : 'Problema encontrado: colunas fora de utf8mb4 trocam emojis por "?".' ?></b></p>
<p>Teste de gravação de emoji na conexão atual: <b class="<?= $roundTrip ? 'ok' : 'error' ?>"><?= $roundTrip === null ? 'não foi possível testar' : ($roundTrip ? 'passou 😊' : 'FALHOU (a conexão perde emojis)') ?></b></p>

<h3>Conexão</h3>
<table><?php foreach ($variables as $name => $value): ?><tr><td><?= e($name) ?></td><td><?= e($value) ?></td></tr><?php endforeach; ?></table>

<?php if ($tables || $columns): ?>
<h3>Tabelas fora de utf8mb4</h3>
<table><tr><th>Tabela</th><th>Collation</th></tr><?php foreach ($tables as $row): ?><tr><td><?= e($row['TABLE_NAME']) ?></td><td><?= e($row['TABLE_COLLATION']) ?></td></tr><?php endforeach; ?></table>
<h3>Colunas de texto fora de utf8mb4</h3>
<table><tr><th>Tabela</th><th>Coluna</th><th>Charset</th></tr><?php foreach ($columns as $row): ?><tr><td><?= e($row['TABLE_NAME']) ?></td><td><?= e($row['COLUMN_NAME']) ?></td><td><?= e($row['CHARACTER_SET_NAME']) ?></td></tr><?php endforeach; ?></table>
<form method="post" style="margin-top:20px">
  <?= csrfInput() ?>
  <p>Converter tudo para utf8mb4 não apaga dados. Mensagens que já viraram "?" não voltam, só as novas ficam corretas.</p>
  <button type="submit">Corrigir agora</button>
</form>
<?php endif; ?>
<p style="margin-top:30px"><small>Depois de corrigir, você pode apagar a pasta <code>tools/</code> da hospedagem.</small></p>
</body></html>
