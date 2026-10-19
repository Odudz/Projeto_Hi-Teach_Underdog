<?php
/**
 * Frase recuperadora: regras, hash e limite de tentativas.
 *
 * Usada por auth/register.php (cria a frase) e auth/recover.php (reativa a conta).
 * Não imprime HTML. Depende de $pdo (config/conexao.php) só nas funções de limite de tentativas.
 *
 * Regras de propósito simples, para a pessoa conseguir lembrar:
 *   - pelo menos 3 palavras (cada palavra com 2 ou mais caracteres)
 *   - no máximo 100 caracteres
 * Maiúsculas/minúsculas e espaços repetidos não fazem diferença na hora de recuperar.
 */

const RECOVERY_MIN_WORDS = 3;
const RECOVERY_MIN_WORD_LENGTH = 2;
const RECOVERY_MAX_LENGTH = 100;
const RECOVERY_EXAMPLE = 'Eu jogo bola';

// Tentativas erradas permitidas dentro da janela, antes de bloquear por um tempo
const RECOVERY_WINDOW_SECONDS = 900;      // 15 minutos
const RECOVERY_MAX_FAILS_PER_EMAIL = 5;
const RECOVERY_MAX_FAILS_PER_IP = 15;

/** Tira espaços sobrando e ignora maiúsculas/minúsculas. */
function normalizeRecoveryPhrase(string $phrase): string
{
    $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? '';
    return mb_strtolower(trim($phrase), 'UTF-8');
}

/** Palavras que contam para o mínimo (precisam ter letra ou número e 2+ caracteres). */
function recoveryPhraseWords(string $phrase): array
{
    $words = [];
    foreach (explode(' ', normalizeRecoveryPhrase($phrase)) as $word) {
        if (mb_strlen($word, 'UTF-8') >= RECOVERY_MIN_WORD_LENGTH && preg_match('/[\p{L}\p{N}]/u', $word)) {
            $words[] = $word;
        }
    }
    return $words;
}

/** Retorna a mensagem de erro explicando o que falta, ou null se a frase serve. */
function validateRecoveryPhrase(string $phrase): ?string
{
    $normalized = normalizeRecoveryPhrase($phrase);
    if ($normalized === '') {
        return 'Escreva sua frase recuperadora. Ela serve para reativar a conta se ela for desativada. Exemplo: “' . RECOVERY_EXAMPLE . '”.';
    }

    $length = mb_strlen($normalized, 'UTF-8');
    if ($length > RECOVERY_MAX_LENGTH) {
        return 'A frase pode ter no máximo ' . RECOVERY_MAX_LENGTH . " caracteres (a sua tem $length). Escolha algo mais curto, fácil de lembrar.";
    }

    $count = count(recoveryPhraseWords($phrase));
    if ($count < RECOVERY_MIN_WORDS) {
        $has = $count === 1 ? 'só 1 palavra' : "só $count palavras";
        if ($count === 0) {
            $has = 'nenhuma palavra válida';
        }
        return "Sua frase tem $has. Use pelo menos " . RECOVERY_MIN_WORDS . ' palavras, por exemplo: “' . RECOVERY_EXAMPLE . '”.';
    }

    return null;
}

/** O sha256 antes do bcrypt evita o corte de 72 bytes do bcrypt em frases com acento. */
function hashRecoveryPhrase(string $phrase): string
{
    return password_hash(hash('sha256', normalizeRecoveryPhrase($phrase)), PASSWORD_DEFAULT);
}

function verifyRecoveryPhrase(string $phrase, ?string $hash): bool
{
    if ($hash === null || $hash === '') {
        return false;
    }
    return password_verify(hash('sha256', normalizeRecoveryPhrase($phrase)), $hash);
}

/** Gasta o mesmo tempo de uma verificação real (evita descobrir e-mails pelo tempo da resposta). */
function recoveryDummyVerify(): void
{
    static $dummy = null;
    $dummy ??= password_hash('hi-teach-dummy', PASSWORD_DEFAULT);
    password_verify('hi-teach-dummy-2', $dummy);
}

// ---------------------------------------------------------------
// Limite de tentativas (usa a tabela login_attempts)
// ---------------------------------------------------------------

function recoveryClientIp(): string
{
    $packed = @inet_pton($_SERVER['REMOTE_ADDR'] ?? '');
    return $packed !== false ? $packed : "\0\0\0\0";
}

/** O prefixo separa estas tentativas das de login comum na mesma tabela. */
function recoveryEmailKey(string $email): string
{
    return hash('sha256', 'recover:' . strtolower(trim($email)));
}

function recoveryIsBlocked(PDO $pdo, string $emailKey): bool
{
    $since = date('Y-m-d H:i:s', time() - RECOVERY_WINDOW_SECONDS);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email_hash = ? AND attempted_at >= ?');
    $stmt->execute([$emailKey, $since]);
    if ((int) $stmt->fetchColumn() >= RECOVERY_MAX_FAILS_PER_EMAIL) {
        return true;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?');
    $stmt->execute([recoveryClientIp(), $since]);
    return (int) $stmt->fetchColumn() >= RECOVERY_MAX_FAILS_PER_IP;
}

function recoveryRegisterFailure(PDO $pdo, string $emailKey): void
{
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO login_attempts (ip, email_hash, attempted_at) VALUES (?, ?, ?)')
        ->execute([recoveryClientIp(), $emailKey, $now]);
    // Limpeza: registros com mais de um dia não servem para nada
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
        ->execute([date('Y-m-d H:i:s', time() - 86400)]);
}

function recoveryClearFailures(PDO $pdo, string $emailKey): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE email_hash = ?')->execute([$emailKey]);
}

function recoveryWaitMinutes(): int
{
    return (int) ceil(RECOVERY_WINDOW_SECONDS / 60);
}
