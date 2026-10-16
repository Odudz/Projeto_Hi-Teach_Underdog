<?php
// ---------------------------------------------------------------
// CONFIGURAÇÃO DO JaaS (Jitsi as a Service - 8x8)
// ---------------------------------------------------------------

// Seu App ID (o "magic cookie" que aparece na URL do script). Não é segredo.
define('JAAS_APP_ID', 'vpaas-magic-cookie-e8dae5b0d49f4d64bfca1fb32efe8a6c');

// Key ID da SUA chave de API (painel JaaS -> API Keys).
// Formato: vpaas-magic-cookie-e8dae5b0d49f4d64bfca1fb32efe8a6c/xxxxxx
// ATENÇÃO: o "4796e3-SAMPLE_APP" do exemplo NÃO serve, crie a sua chave.
define('JAAS_KEY_ID', 'vpaas-magic-cookie-e8dae5b0d49f4d64bfca1fb32efe8a6c/f14859');

// Caminho do arquivo da chave privada baixado do painel.
// Guarde FORA da pasta htdocs, para ninguém conseguir baixar pelo navegador.
// Exemplo no XAMPP/Windows: C:/xampp/segredos/jaas_privatekey.pk
define('JAAS_PRIVATE_KEY_PATH', 'C:/xampp/SEGREDOS/JAS_PRIVATE_KEY_LIVE.pk');

// Validade do token em segundos (14400 = 4 horas)
define('JAAS_JWT_VALIDADE', 14400);
