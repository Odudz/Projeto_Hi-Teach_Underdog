<?php
// PÁGINA DE DIAGNÓSTICO - só funciona no próprio computador (localhost).
// Apague este arquivo antes de publicar o site.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    die('Acesso negado.');
}

require_once __DIR__ . '/../includes/jaas/jaas_jwt.php';

function linha($ok, $texto) {
    $cor = $ok ? '#1b8a3a' : '#c62828';
    $icone = $ok ? 'OK' : 'ERRO';
    echo "<p style='color:$cor'><b>[$icone]</b> " . htmlspecialchars($texto) . "</p>";
}

echo "<!DOCTYPE html><html lang='pt-br'><meta charset='UTF-8'><body style='font-family:Arial;max-width:800px;margin:30px auto'>";
echo "<h1>Diagnóstico JaaS</h1>";

// 1. Key ID
$kid = JAAS_KEY_ID;
linha(strpos($kid, 'COLOQUE_AQUI') === false, "Key ID foi preenchido: $kid");
linha(strpos($kid, JAAS_APP_ID . '/') === 0 && strlen($kid) > strlen(JAAS_APP_ID) + 1,
      "Key ID começa com o App ID seguido de barra e um código");

// 2. Chave privada
$caminho = JAAS_PRIVATE_KEY_PATH;
$legivel = is_readable($caminho);
linha($legivel, "Arquivo da chave encontrado em: $caminho");

$chave = $legivel ? openssl_pkey_get_private(file_get_contents($caminho)) : false;
linha((bool)$chave, "PHP consegue ler a chave privada");

// 3. Relógio
$agora = time();
echo "<p><b>Hora do servidor:</b> " . date('d/m/Y H:i:s', $agora) . " (" . date_default_timezone_get() . ")"
   . "<br><b>Hora UTC:</b> " . gmdate('d/m/Y H:i:s', $agora) . "<br>"
   . "Compare com a hora UTC real (ex.: pesquise \"hora UTC\"). Se diferir mais de 1-2 minutos, o JaaS rejeita o token.</p>";

// 4. Gera e verifica o token
if ($chave && strpos($kid, 'COLOQUE_AQUI') === false) {
    $jwt = gerarJwtJaas('sala-diagnostico', 'Teste', 'teste-1', true);
    [$h, $p, $s] = explode('.', $jwt);

    $detalhes = openssl_pkey_get_details($chave);
    $assinaturaBin = base64_decode(strtr($s, '-_', '+/'));
    $valida = openssl_verify("$h.$p", $assinaturaBin, $detalhes['key'], OPENSSL_ALGO_SHA256) === 1;
    linha($valida, "Assinatura do token confere com a chave privada");

    $decod = fn($x) => json_decode(base64_decode(strtr($x, '-_', '+/')), true);
    echo "<h3>Cabeçalho do token</h3><pre>" . htmlspecialchars(json_encode($decod($h), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "</pre>";
    echo "<h3>Conteúdo do token</h3><pre>" . htmlspecialchars(json_encode($decod($p), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "</pre>";
}

echo "<hr><p>Se tudo estiver [OK] aqui e ainda aparecer \"not allowed to join\", o problema está na combinação Key ID + chave privada (a chave baixada não pertence a esse Key ID) ou na chave no painel do JaaS (confira se está ativa).</p>";
echo "</body></html>";
