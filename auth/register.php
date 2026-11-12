<?php
include_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/recovery.php';

if ($user) {
    redirect('../pages/profile.php');
}

const PROOF_MAX_MB = 5;
const PROOF_MAX_FILES = 5;
const PROOF_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
const PROOF_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];
const BIO_MAX = 250;
const SPECIALIZATION_MAX_ITEMS = 10;
const SPECIALIZATION_MIN_LENGTH = 3;
const SPECIALIZATION_MAX_LENGTH = 80;

const FIELD_ORDER = [
    'name',
    'birth_date',
    'cpf',
    'email',
    'phone',
    'bio',
    'password',
    'password_confirm',
    'recovery_phrase',
    'specializations',
    'validation_proof',
];

// ---------------------------------------------------------------
// Criação Robusta de Pastas com suporte total ao Linux / LAMPP
// ---------------------------------------------------------------
function proofFolder(): string
{
    $root = dirname(__DIR__);
    $paths = [
        $root . DIRECTORY_SEPARATOR . 'storage',
        $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private',
        $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'proofs'
    ];

    $oldUmask = umask(0); // Libera criação com 0777 real no Linux
    foreach ($paths as $path) {
        if (!is_dir($path)) {
            @mkdir($path, 0777, true);
        }
        @chmod($path, 0777);
    }
    umask($oldUmask);

    return end($paths);
}

// ---------------------------------------------------------------
// Garante as tabelas necessárias no MySQL
// ---------------------------------------------------------------
function registerEnsureRequirements(PDO $pdo): void
{
    proofFolder();

    try {
        $tail = 'ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci';

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS teacher_profiles (
                user_id INT UNSIGNED NOT NULL,
                specializations TEXT NULL DEFAULT NULL,
                proof_path VARCHAR(255) NULL DEFAULT NULL,
                status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                reviewed_by INT UNSIGNED NULL DEFAULT NULL,
                reviewed_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id),
                CONSTRAINT fk_tp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) $tail"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS teacher_proofs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_proof_user (user_id),
                CONSTRAINT fk_proofs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) $tail"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS student_profiles (
                user_id INT UNSIGNED NOT NULL,
                enrollment_number VARCHAR(50) NOT NULL,
                PRIMARY KEY (user_id),
                CONSTRAINT fk_sp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) $tail"
        );
    } catch (Throwable $e) {
        error_log('registerEnsureRequirements: ' . $e->getMessage());
    }
}

registerEnsureRequirements($pdo);

function respondJson(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function iniBytes(string $key): int
{
    $value = trim((string) ini_get($key));
    if ($value === '') {
        return 0;
    }
    $bytes = (int) $value;
    return match (strtolower($value[strlen($value) - 1])) {
        'g' => $bytes * 1024 * 1024 * 1024,
        'm' => $bytes * 1024 * 1024,
        'k' => $bytes * 1024,
        default => $bytes,
    };
}

function joinWithAnd(array $items): string
{
    $last = array_pop($items);
    return $items ? implode(', ', $items) . ' e ' . $last : (string) $last;
}

function isValidCpf(string $cpf): bool
{
    if (!preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }
    for ($position = 9; $position < 11; $position++) {
        $sum = 0;
        for ($i = 0; $i < $position; $i++) {
            $sum += (int) $cpf[$i] * (($position + 1) - $i);
        }
        if ((int) $cpf[$position] !== ((10 * $sum) % 11) % 10) {
            return false;
        }
    }
    return true;
}

function validateRegistration(array $data, string $password, string $passwordConfirm, string $recoveryPhrase): array
{
    $errors = [];

    $nameLength = mb_strlen($data['name']);
    if ($nameLength === 0) {
        $errors['name'] = 'Informe seu nome.';
    } elseif ($nameLength < 2) {
        $errors['name'] = 'O nome precisa ter pelo menos 2 caracteres.';
    } elseif ($nameLength > 50) {
        $errors['name'] = "O nome pode ter no máximo 50 caracteres.";
    }

    $birth = DateTime::createFromFormat('!Y-m-d', $data['birth_date']);
    $today = new DateTime('today');
    if ($data['birth_date'] === '') {
        $errors['birth_date'] = 'Informe sua data de nascimento.';
    } elseif (!$birth || $birth->format('Y-m-d') !== $data['birth_date']) {
        $errors['birth_date'] = 'Data de nascimento inválida.';
    } elseif ($birth > $today) {
        $errors['birth_date'] = 'A data de nascimento não pode estar no futuro.';
    } else {
        $age = $birth->diff($today)->y;
        if ($age < 13) {
            $errors['birth_date'] = "Você precisa ter pelo menos 13 anos para criar uma conta.";
        } elseif ($age > 120) {
            $errors['birth_date'] = 'Confira o ano da data de nascimento.';
        }
    }

    $cpfLength = strlen($data['cpf']);
    if ($cpfLength === 0) {
        $errors['cpf'] = 'Informe seu CPF.';
    } elseif ($cpfLength !== 11) {
        $errors['cpf'] = "O CPF precisa ter 11 números.";
    } elseif (!isValidCpf($data['cpf'])) {
        $errors['cpf'] = 'Esse CPF não é válido.';
    }

    if ($data['email'] === '') {
        $errors['email'] = 'Informe seu e-mail.';
    } elseif (strlen($data['email']) > 100) {
        $errors['email'] = 'O e-mail pode ter no máximo 100 caracteres.';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Esse e-mail não parece válido.';
    }

    if ($data['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{8,20}$/', $data['phone'])) {
        $errors['phone'] = 'O telefone deve ter de 8 a 20 caracteres.';
    }

    $bioLength = mb_strlen($data['bio']);
    if ($bioLength > BIO_MAX) {
        $errors['bio'] = 'A biografia pode ter no máximo ' . BIO_MAX . ' caracteres.';
    }

    $passwordLength = strlen($password);
    if ($password === '') {
        $errors['password'] = 'Crie uma senha.';
    } elseif ($passwordLength > 72) {
        $errors['password'] = 'A senha pode ter no máximo 72 caracteres.';
    } else {
        $missing = [];
        if ($passwordLength < 10) {
            $missing[] = "pelo menos 10 caracteres";
        }
        if (!preg_match('/[A-Za-z]/', $password)) {
            $missing[] = 'pelo menos uma letra';
        }
        if (!preg_match('/\d/', $password)) {
            $missing[] = 'pelo menos um número';
        }
        if ($missing) {
            $errors['password'] = 'A senha precisa de ' . joinWithAnd($missing) . '.';
        }
    }

    if ($passwordConfirm === '') {
        $errors['password_confirm'] = 'Repita a senha para confirmar.';
    } elseif ($password !== $passwordConfirm) {
        $errors['password_confirm'] = 'A confirmação não é igual à senha digitada.';
    }

    if ($message = validateRecoveryPhrase($recoveryPhrase)) {
        $errors['recovery_phrase'] = $message;
    } elseif ($password !== '' && normalizeRecoveryPhrase($recoveryPhrase) === normalizeRecoveryPhrase($password)) {
        $errors['recovery_phrase'] = 'A frase recuperadora não pode ser igual à senha.';
    }

    return $errors;
}

function collectSpecializations(array $post): array
{
    $items = $post['specializations'] ?? [];
    if (!is_array($items)) {
        $items = preg_split('/[\r\n;]+/', (string) $items) ?: [];
    }
    if (!empty($post['specialization_input']) && is_string($post['specialization_input'])) {
        $items[] = $post['specialization_input'];
    }

    $result = [];
    foreach ($items as $item) {
        if (!is_string($item)) {
            continue;
        }
        $item = trim(preg_replace('/\s+/u', ' ', $item) ?? '');
        $key = mb_strtolower($item);
        if ($item !== '' && !isset($result[$key])) {
            $result[$key] = $item;
        }
    }
    return array_values($result);
}

function validateSpecializations(array $items): ?string
{
    if (!$items) {
        return 'Adicione pelo menos uma especialização.';
    }
    if (count($items) > SPECIALIZATION_MAX_ITEMS) {
        return 'Você pode adicionar no máximo ' . SPECIALIZATION_MAX_ITEMS . ' especializações.';
    }
    foreach ($items as $item) {
        $length = mb_strlen($item);
        if ($length < SPECIALIZATION_MIN_LENGTH) {
            return "A especialização “{$item}” é muito curta.";
        }
        if ($length > SPECIALIZATION_MAX_LENGTH) {
            return 'A especialização passa de ' . SPECIALIZATION_MAX_LENGTH . ' caracteres.';
        }
    }
    return null;
}

function normalizeUploads(?array $files): array
{
    if (!$files || !isset($files['name'])) {
        return [];
    }
    $errors = (array) ($files['error'] ?? []);
    $tmpNames = (array) ($files['tmp_name'] ?? []);
    $sizes = (array) ($files['size'] ?? []);

    $list = [];
    foreach ((array) $files['name'] as $i => $name) {
        $error = (int) ($errors[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $list[] = [
            'name' => (string) $name,
            'tmp_name' => (string) ($tmpNames[$i] ?? ''),
            'error' => $error,
            'size' => (int) ($sizes[$i] ?? 0),
        ];
    }
    return $list;
}

function describeUploadError(int $code, string $label): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "$label passa do limite de upload do servidor.",
        UPLOAD_ERR_PARTIAL => "$label foi enviado só em parte. Adicione-o novamente.",
        default => "Não foi possível receber $label. Tente novamente.",
    };
}

function validateProofs(array $files): array
{
    if (!$files) {
        return ['Envie pelo menos um comprovante ou currículo (PDF, JPG ou PNG).', []];
    }
    if (count($files) > PROOF_MAX_FILES) {
        return ['Envie no máximo ' . PROOF_MAX_FILES . ' arquivos.', []];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $issues = [];
    foreach ($files as $index => $file) {
        $label = '“' . $file['name'] . '”';
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = describeUploadError($file['error'], $label);
        } elseif ($file['size'] <= 0) {
            $message = "$label está vazio.";
        } elseif ($file['size'] > PROOF_MAX_MB * 1024 * 1024) {
            $message = "$label tem mais de " . PROOF_MAX_MB . " MB.";
        } elseif (!in_array($extension, PROOF_EXTENSIONS, true)) {
            $message = "$label não é PDF, JPG ou PNG.";
        } elseif (!in_array($finfo->file($file['tmp_name']), PROOF_MIME_TYPES, true)) {
            $message = "$label não parece ser um arquivo válido.";
        } else {
            continue;
        }
        $issues[] = ['index' => $index, 'message' => $message];
    }

    return $issues ? [implode(' ', array_column($issues, 'message')), $issues] : [null, []];
}

function removeProofFiles(array $fileNames): void
{
    $folder = proofFolder();
    foreach ($fileNames as $fileName) {
        @unlink($folder . DIRECTORY_SEPARATOR . basename((string) $fileName));
    }
}

function storeProofs(array $files): array
{
    $folder = proofFolder();
    if (!is_dir($folder) || !is_writable($folder)) {
        // Tenta liberar permissão em tempo de execução
        @chmod($folder, 0777);
    }

    $stored = [];
    try {
        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $fileName = bin2hex(random_bytes(16)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
            $dest = $folder . DIRECTORY_SEPARATOR . $fileName;

            $saved = false;
            // 1. move_uploaded_file padrão
            if (is_uploaded_file($file['tmp_name'])) {
                $saved = @move_uploaded_file($file['tmp_name'], $dest);
            }
            // 2. copy direto
            if (!$saved) {
                $saved = @copy($file['tmp_name'], $dest);
            }
            // 3. stream binário via file_put_contents
            if (!$saved && is_readable($file['tmp_name'])) {
                $content = @file_get_contents($file['tmp_name']);
                if ($content !== false) {
                    $saved = (@file_put_contents($dest, $content) !== false);
                }
            }

            if (!$saved) {
                $err = error_get_last()['message'] ?? 'Permissão negada ao gravar arquivo.';
                throw new RuntimeException("Falha ao salvar '{$file['name']}'. Detalhe: $err");
            }

            $original = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $file['name']) ?? '');
            $stored[] = [
                'file_name' => $fileName,
                'original_name' => mb_substr($original !== '' ? $original : $fileName, 0, 120),
            ];
        }
    } catch (Throwable $exception) {
        removeProofFiles(array_column($stored, 'file_name'));
        throw $exception;
    }
    return $stored;
}

$wantsJson = ($_GET['ajax'] ?? '') === '1' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$old = [];
$fieldErrors = [];
$fileIssues = [];
$generalError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    $data = [
        'name' => trim($_POST['name'] ?? ''),
        'birth_date' => trim($_POST['birth_date'] ?? ''),
        'cpf' => preg_replace('/\D/', '', $_POST['cpf'] ?? ''),
        'email' => strtolower(trim($_POST['email'] ?? '')),
        'phone' => trim($_POST['phone'] ?? ''),
        'bio' => trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['bio'] ?? ''))),
        'role' => ($_POST['role'] ?? '') === 'teacher' ? 'teacher' : 'student',
    ];
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $recoveryPhrase = (string) ($_POST['recovery_phrase'] ?? '');

    $fieldErrors = validateRegistration($data, $password, $passwordConfirm, $recoveryPhrase);

    $specializations = [];
    $uploads = [];
    if ($data['role'] === 'teacher') {
        $specializations = collectSpecializations($_POST);
        if ($message = validateSpecializations($specializations)) {
            $fieldErrors['specializations'] = $message;
        }

        $uploads = normalizeUploads($_FILES['validation_proof'] ?? null);
        [$proofMessage, $fileIssues] = validateProofs($uploads);
        if ($proofMessage) {
            $fieldErrors['validation_proof'] = $proofMessage;
        }
    }

    // Verifica se e-mail ou CPF já existem
    $checkEmail = !isset($fieldErrors['email']);
    $checkCpf = !isset($fieldErrors['cpf']);
    if ($checkEmail || $checkCpf) {
        $stmt = $pdo->prepare('SELECT email, cpf, activate FROM users WHERE email = ? OR cpf = ?');
        $stmt->execute([$data['email'], $data['cpf']]);
        foreach ($stmt->fetchAll() as $row) {
            if ($checkEmail && $row['email'] === $data['email']) {
                $fieldErrors['email'] = 'Já existe uma conta com esse e-mail.';
            }
            if ($checkCpf && $row['cpf'] === $data['cpf']) {
                $fieldErrors['cpf'] = 'Esse CPF já está cadastrado em outra conta.';
            }
        }
    }

    if (!$fieldErrors) {
        $storedProofs = [];
        try {
            if ($data['role'] === 'teacher') {
                $storedProofs = storeProofs($uploads);
            }

            $pdo->beginTransaction();

            // O usuário inicia como 'student' (permissão comum) até aprovação
            $stmtUser = $pdo->prepare(
                'INSERT INTO users (name, birth_date, cpf, email, phone, bio, password_hash, recovery_phrase_hash, role) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmtUser->execute([
                $data['name'],
                $data['birth_date'],
                $data['cpf'],
                $data['email'],
                $data['phone'] ?: null,
                $data['bio'] ?: null,
                password_hash($password, PASSWORD_DEFAULT),
                hashRecoveryPhrase($recoveryPhrase),
                'student',
            ]);
            $newUserId = (int) $pdo->lastInsertId();

            if ($data['role'] === 'teacher') {
                // Cadastra perfil com status PENDENTE
                $pdo->prepare(
                    "INSERT INTO teacher_profiles (user_id, specializations, proof_path, status) 
                     VALUES (?, ?, ?, 'pending')"
                )->execute([
                    $newUserId,
                    json_encode($specializations, JSON_UNESCAPED_UNICODE),
                    $storedProofs[0]['file_name'] ?? null,
                ]);

                $insertProof = $pdo->prepare('INSERT INTO teacher_proofs (user_id, file_name, original_name) VALUES (?, ?, ?)');
                foreach ($storedProofs as $proof) {
                    $insertProof->execute([$newUserId, $proof['file_name'], $proof['original_name']]);
                }
            } else {
                $pdo->prepare('INSERT INTO student_profiles (user_id, enrollment_number) VALUES (?, ?)')
                    ->execute([$newUserId, (string) $newUserId]);
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            removeProofFiles(array_column($storedProofs, 'file_name'));
            error_log('register.php error: ' . $exception->getMessage());
            $generalError = 'Erro ao salvar cadastro: ' . $exception->getMessage();
        }
    }

    if (!$fieldErrors && !$generalError) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $newUserId;
        setFlash('success', $data['role'] === 'teacher'
            ? 'Conta criada! Seu perfil de professor foi enviado para análise da administração. Enquanto isso, você já pode navegar normalmente.'
            : 'Conta criada com sucesso. Bem-vindo(a) ao Hi Teach!');

        if ($wantsJson) {
            respondJson(200, ['ok' => true, 'redirect' => '../pages/profile.php']);
        }
        redirect('../pages/profile.php');
    }

    uksort($fieldErrors, static fn($a, $b) => array_search($a, FIELD_ORDER, true) <=> array_search($b, FIELD_ORDER, true));

    if ($wantsJson) {
        respondJson(422, ['ok' => false, 'errors' => $fieldErrors, 'files' => $fileIssues, 'message' => $generalError]);
    }

    setFlash('error', $generalError !== '' ? $generalError : implode(' ', array_values($fieldErrors)));
}

$pageId = 'register';
$pageTitle = 'Cadastro';
$extraCss = ['register'];
$extraJs = ['register'];
$isTeacher = ($old['role'] ?? '') === 'teacher';
$oldSpecializations = $old ? collectSpecializations($old) : [];

$fieldError = static function (string $field) use ($fieldErrors): string {
    $message = $fieldErrors[$field] ?? '';
    return '<p class="field-error" id="error-' . $field . '" data-error-for="' . $field . '"'
        . ($message === '' ? ' hidden' : '') . '>' . e($message) . '</p>';
};
$invalid = static fn(string $field): string => isset($fieldErrors[$field]) ? ' aria-invalid="true"' : '';

$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
    <section id="register-section" class="section">
        <h2 class="section-title">Criar conta</h2>

        <form id="register-form" class="form" action="register.php" method="post" enctype="multipart/form-data"
            novalidate>
            <div class="form-group" data-field="name">
                <label class="form-label" for="name">Nome</label>
                <input type="text" id="name" name="name" class="form-input" maxlength="50"
                    value="<?= e($old['name'] ?? '') ?>" autocomplete="name" aria-describedby="error-name"
                    <?= $invalid('name') ?> required>
                <?= $fieldError('name') ?>
            </div>

            <div class="form-group" data-field="birth_date">
                <label class="form-label" for="birth-date">Data de nascimento</label>
                <input type="date" id="birth-date" name="birth_date" class="form-input"
                    value="<?= e($old['birth_date'] ?? '') ?>" aria-describedby="error-birth_date"
                    <?= $invalid('birth_date') ?> required>
                <?= $fieldError('birth_date') ?>
            </div>

            <div class="form-group" data-field="cpf">
                <label class="form-label" for="cpf">CPF</label>
                <input type="text" id="cpf" name="cpf" class="form-input" maxlength="14" inputmode="numeric"
                    placeholder="000.000.000-00" value="<?= e($old['cpf'] ?? '') ?>" aria-describedby="error-cpf"
                    <?= $invalid('cpf') ?> required>
                <?= $fieldError('cpf') ?>
            </div>

            <div class="form-group" data-field="email">
                <label class="form-label" for="email">E-mail</label>
                <input type="email" id="email" name="email" class="form-input" maxlength="100"
                    value="<?= e($old['email'] ?? '') ?>" autocomplete="email" aria-describedby="error-email"
                    <?= $invalid('email') ?> required>
                <?= $fieldError('email') ?>
            </div>

            <div class="form-group" data-field="phone">
                <label class="form-label" for="phone">Telefone</label>
                <input type="tel" id="phone" name="phone" class="form-input" maxlength="20"
                    value="<?= e($old['phone'] ?? '') ?>" autocomplete="tel" aria-describedby="error-phone"
                    <?= $invalid('phone') ?>>
                <?= $fieldError('phone') ?>
            </div>

            <div class="form-group" data-field="bio">
                <label class="form-label" for="bio">Biografia</label>
                <textarea id="bio" name="bio" class="form-input form-textarea-fixed" maxlength="<?= BIO_MAX ?>"
                    aria-describedby="bio-counter error-bio"
                    <?= $invalid('bio') ?>><?= e($old['bio'] ?? '') ?></textarea>
                <div class="field-meta">
                    <small class="form-note">Opcional. Conte um pouco sobre você.</small>
                    <small id="bio-counter" class="field-counter"
                        aria-live="polite"><?= mb_strlen(str_replace("\r\n", "\n", (string) ($old['bio'] ?? ''))) ?>/<?= BIO_MAX ?></small>
                </div>
                <?= $fieldError('bio') ?>
            </div>

            <div class="form-group" data-field="password">
                <label class="form-label" for="password">Senha</label>
                <input type="password" id="password" name="password" class="form-input" maxlength="72"
                    autocomplete="new-password" aria-describedby="password-hint error-password"
                    <?= $invalid('password') ?> required>
                <small id="password-hint" class="form-note">Mínimo de 10 caracteres, com letras e números.</small>
                <?= $fieldError('password') ?>
            </div>

            <div class="form-group" data-field="password_confirm">
                <label class="form-label" for="password-confirm">Confirmar senha</label>
                <input type="password" id="password-confirm" name="password_confirm" class="form-input" maxlength="72"
                    autocomplete="new-password" aria-describedby="error-password_confirm"
                    <?= $invalid('password_confirm') ?> required>
                <?= $fieldError('password_confirm') ?>
            </div>

            <div class="form-group" data-field="recovery_phrase">
                <label class="form-label" for="recovery-phrase">Frase recuperadora</label>
                <input type="text" id="recovery-phrase" name="recovery_phrase" class="form-input"
                    maxlength="<?= RECOVERY_MAX_LENGTH ?>" placeholder="Ex.: <?= e(RECOVERY_EXAMPLE) ?>"
                    value="<?= e($old['recovery_phrase'] ?? '') ?>" autocomplete="off" autocapitalize="off"
                    spellcheck="false" aria-describedby="recovery-hint error-recovery_phrase"
                    <?= $invalid('recovery_phrase') ?> required>
                <div class="field-meta">
                    <small id="recovery-hint" class="form-note">
                        Se um dia sua conta for desativada, é com esta frase que você a reativa.
                    </small>
                    <small id="recovery-counter" class="field-counter" aria-live="polite">0/<?= RECOVERY_MIN_WORDS ?>
                        palavras</small>
                </div>
                <?= $fieldError('recovery_phrase') ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="role">Perfil</label>
                <select id="role" name="role" class="form-input">
                    <option value="student" <?= $isTeacher ? '' : ' selected' ?>>Aluno</option>
                    <option value="teacher" <?= $isTeacher ? ' selected' : '' ?>>Professor</option>
                </select>
            </div>

            <fieldset id="teacher-fields" class="fieldset">
                <legend>Dados do professor</legend>

                <div class="form-group" data-field="specializations">
                    <label class="form-label" for="specialization-input">Especializações</label>
                    <ul id="specialization-list" class="tag-list" aria-label="Especializações adicionadas"></ul>
                    <div class="tag-entry">
                        <input type="text" id="specialization-input" name="specialization_input" class="form-input"
                            maxlength="<?= SPECIALIZATION_MAX_LENGTH ?>"
                            placeholder="Ex.: Formado em Matemática pela USP" autocomplete="off"
                            aria-describedby="specialization-hint error-specializations"
                            <?= $invalid('specializations') ?>>
                        <button type="button" id="specialization-add" class="button tag-add">Adicionar</button>
                    </div>
                    <div class="field-meta">
                        <small id="specialization-hint" class="form-note">Digite e pressione Enter para adicionar cada
                            especialização.</small>
                        <small id="specialization-counter" class="field-counter"
                            aria-live="polite"><?= count($oldSpecializations) ?>/<?= SPECIALIZATION_MAX_ITEMS ?></small>
                    </div>
                    <div id="specialization-seed" hidden>
                        <?php foreach ($oldSpecializations as $item): ?>
                            <input type="hidden" name="specializations[]" value="<?= e($item) ?>">
                        <?php endforeach; ?>
                    </div>
                    <?= $fieldError('specializations') ?>
                </div>

                <div class="form-group" data-field="validation_proof">
                    <span class="form-label" id="proof-label">Comprovantes de validação / Currículo</span>
                    <div id="proof-drop" class="dropzone" role="group" tabindex="-1" aria-labelledby="proof-label"
                        aria-describedby="proof-hint error-validation_proof" <?= $invalid('validation_proof') ?>>
                        <svg class="dropzone-icon" viewBox="0 0 24 24" width="44" height="44" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            aria-hidden="true">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                            <polyline points="14 2 14 8 20 8" />
                            <line x1="12" y1="18" x2="12" y2="12" />
                            <polyline points="9 15 12 12 15 15" />
                        </svg>
                        <p class="dropzone-title">Arraste seu currículo ou comprovante aqui</p>
                        <p class="dropzone-text">ou escolha os arquivos do seu dispositivo (PDF, JPG ou PNG)</p>
                        <button type="button" id="proof-choose" class="button button-primary">Escolher arquivos</button>
                    </div>
                    <input type="file" id="validation-proof" name="validation_proof[]"
                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" multiple hidden>
                    <ul id="proof-list" class="file-list" aria-label="Comprovantes selecionados"></ul>
                    <div class="field-meta">
                        <small id="proof-hint" class="form-note">PDF, JPG ou PNG. Até <?= PROOF_MAX_FILES ?> arquivos de
                            <?= PROOF_MAX_MB ?> MB cada.</small>
                        <small id="proof-counter" class="field-counter"
                            aria-live="polite">0/<?= PROOF_MAX_FILES ?></small>
                    </div>
                    <?= $fieldError('validation_proof') ?>
                </div>

                <p class="form-note">
                    Seu perfil de professor será analisado por um administrador antes da liberação das ferramentas de
                    docência. Enquanto isso, você poderá navegar normalmente.
                </p>
            </fieldset>

            <button type="submit" id="register-form-submit" class="button button-primary">Cadastrar</button>
        </form>

        <p class="form-note">
            Já tem conta? <a id="link-login" href="login.php">Entrar</a>
        </p>
    </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
