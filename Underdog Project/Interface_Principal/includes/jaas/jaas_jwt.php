<?php
require_once __DIR__ . '/jaas_config.php';

function jaas_b64url(string $dados): string {
    return rtrim(strtr(base64_encode($dados), '+/', '-_'), '=');
}

/**
 * Gera o JWT assinado (RS256) que o JaaS exige.
 *
 * @param string $sala       chave_sala do banco (sem o prefixo do App ID)
 * @param string $nome       nome exibido na chamada
 * @param string $id         identificador único do usuário
 * @param bool   $moderador  true = professor, false = aluno
 */
function gerarJwtJaas(string $sala, string $nome, string $id, bool $moderador, string $email = ''): string {
    if (!is_readable(JAAS_PRIVATE_KEY_PATH)) {
        die("Chave privada do JaaS não encontrada. Confira JAAS_PRIVATE_KEY_PATH em jaas_config.php.");
    }

    $chave = openssl_pkey_get_private(file_get_contents(JAAS_PRIVATE_KEY_PATH));
    if (!$chave) {
        die("Não foi possível ler a chave privada. Verifique se o arquivo está completo e se a extensão openssl está ativa no PHP.");
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
            // Recursos premium; ative conforme o seu plano
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
        die("Falha ao assinar o JWT.");
    }

    return $dados . '.' . jaas_b64url($assinatura);
}
