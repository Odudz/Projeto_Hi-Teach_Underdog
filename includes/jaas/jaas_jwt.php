<?php
require_once __DIR__ . '/jaas_config.php';

function jaas_fail(string $logMessage): never {
    error_log('jaas_jwt.php: ' . $logMessage);
    http_response_code(500);
    exit('Não foi possível iniciar a aula ao vivo agora. Tente novamente mais tarde ou fale com o suporte.');
}

function jaas_b64url(string $dados): string {
    return rtrim(strtr(base64_encode($dados), '+/', '-_'), '=');
}

function gerarJwtJaas(string $sala, string $nome, string $id, bool $moderador, string $email = ''): string {
    if (!is_readable(JAAS_PRIVATE_KEY_PATH)) {
        jaas_fail('Chave privada do JaaS não encontrada em ' . JAAS_PRIVATE_KEY_PATH);
    }
    $chave = openssl_pkey_get_private(file_get_contents(JAAS_PRIVATE_KEY_PATH));
    if (!$chave) {
        jaas_fail('Não foi possível ler a chave privada do JaaS (arquivo incompleto ou openssl desativado)');
    }
    $agora = time();

    $header = [
        'alg' => 'RS256',
        'typ' => 'JWT',
        'kid' => JAAS_KEY_ID,
    ];
    $payload = [
        'aud'  => 'jitsi',
        'iss'  => 'chat',
        'iat'  => $agora,
        'nbf'  => $agora - 5,
        'exp'  => $agora + JAAS_JWT_VALIDADE,
        'sub'  => JAAS_APP_ID,
        'room' => $sala,
        'context' => [
            'user' => [
                'id'        => $id,
                'name'      => $nome,
                'email'     => $email,
                'avatar'    => '',
                'moderator' => $moderador,
            ],
            'features' => [
                'livestreaming'  => false,
                'recording'      => false,
                'transcription'  => false,
                'outbound-call'  => false,
            ],
        ],
    ];
    $dados = jaas_b64url(json_encode($header, JSON_UNESCAPED_SLASHES))
           . '.'
           . jaas_b64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if (!openssl_sign($dados, $assinatura, $chave, OPENSSL_ALGO_SHA256)) {
        jaas_fail('Falha ao assinar o JWT do JaaS');
    }
    return $dados . '.' . jaas_b64url($assinatura);
}
