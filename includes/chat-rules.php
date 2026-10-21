<?php
// Regras compartilhadas pelas mensagens do fórum e pelas mensagens privadas.
// Qualquer mudança aqui vale para os dois lugares.

const CHAT_MESSAGE_MAX = 280;
const CHAT_MESSAGE_MAX_LINES = 5;
const CHAT_EDIT_SECONDS = 180;
const CHAT_DELETE_SECONDS = 300;

function chatJson(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function chatNormalize(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2064}\x{2800}\x{3164}\x{115F}\x{1160}\x{FEFF}\x{00AD}]/u', '', $text) ?? $text;
    $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/ ?\n ?/', "\n", $text) ?? $text;
    $text = preg_replace('/\n{2,}/', "\n", $text) ?? $text;
    return trim($text);
}

function chatContainsLink(string $text): bool
{
    $flat = mb_strtolower(preg_replace('/[\s\x{200B}-\x{200F}]+/u', '', $text) ?? $text);
    if (preg_match('~(https?:|ftp:|www\.|://|mailto:|wa\.me|bit\.ly|t\.me|discord\.gg|tinyurl)~u', $flat)) {
        return true;
    }
    if (preg_match('~[a-z0-9._%+\-]+@[a-z0-9\-]+\.[a-z]{2,}~u', $flat)) {
        return true;
    }
    if (preg_match('~[a-z0-9\-]+\.(?:com|net|org|info|biz|xyz|site|online|shop|store|click|vip|icu|br|ru|cn)(?![a-z0-9])~u', $flat)) {
        return true;
    }
    $dotted = mb_strtolower(preg_replace('/\s*(\[\s*(dot|ponto)\s*\]|\(\s*(dot|ponto)\s*\))\s*/iu', '.', $text) ?? $text);
    return (bool) preg_match('~[a-z0-9\-]+\.(?:com|net|org|info|biz|xyz|top|site|online|club|shop|store|link|click|live|app|dev|io|ly|me|co|tv|cc|gg|gl|ws|vip|icu|cyou|ru|cn|tk|ml|ga|cf|gq|pw|br|pt|uk|fr)(?![a-z0-9])~iu', $dotted);
}

function chatValidate(string $text): ?string
{
    $length = mb_strlen($text);
    if ($length === 0) {
        return 'A mensagem não pode ficar vazia.';
    }
    if ($length > CHAT_MESSAGE_MAX) {
        return 'A mensagem pode ter no máximo ' . CHAT_MESSAGE_MAX . " caracteres (você digitou $length).";
    }
    if (substr_count($text, "\n") + 1 > CHAT_MESSAGE_MAX_LINES) {
        return 'A mensagem pode ter no máximo ' . CHAT_MESSAGE_MAX_LINES . ' linhas.';
    }
    if (chatContainsLink($text)) {
        return 'Links e endereços de sites não são permitidos nas mensagens.';
    }
    return null;
}
