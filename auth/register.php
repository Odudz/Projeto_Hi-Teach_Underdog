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
const PROOF_FOLDER = __DIR__ . '/../storage/private/proofs';
const BIO_MAX = 250;
const SPECIALIZATION_MAX_ITEMS = 10;
const SPECIALIZATION_MIN_LENGTH = 3;
const SPECIALIZATION_MAX_LENGTH = 80;

const FIELD_ORDER = [
    'name', 'birth_date', 'cpf', 'email', 'phone', 'bio',
    'password', 'password_confirm', 'recovery_phrase', 'specializations', 'validation_proof',
];

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
        $errors['name'] = "O nome pode ter no máximo 50 caracteres (você digitou $nameLength).";
    }

    $birth = DateTime::createFromFormat('!Y-m-d', $data['birth_date']);
    $today = new DateTime('today');
    if ($data['birth_date'] === '') {
        $errors['birth_date'] = 'Informe sua data de nascimento.';
    } elseif (!$birth || $birth->format('Y-m-d') !== $data['birth_date']) {
        $errors['birth_date'] = 'Essa data de nascimento não é válida. Use o seletor de data.';
    } elseif ($birth > $today) {
        $errors['birth_date'] = 'A data de nascimento não pode estar no futuro.';
    } else {
        $age = $birth->diff($today)->y;
        if ($age < 13) {
            $errors['birth_date'] = "Você precisa ter pelo menos 13 anos para criar uma conta (idade informada: $age).";
        } elseif ($age > 120) {
            $errors['birth_date'] = 'Confira o ano da data de nascimento: a idade informada passa de 120 anos.';
        }
    }

    $cpfLength = strlen($data['cpf']);
    if ($cpfLength === 0) {
        $errors['cpf'] = 'Informe seu CPF.';
    } elseif ($cpfLength !== 11) {
        $errors['cpf'] = "O CPF precisa ter 11 números (você digitou $cpfLength).";
    } elseif (!isValidCpf($data['cpf'])) {
        $errors['cpf'] = 'Esse CPF não é válido. Confira os números digitados.';
    }

    if ($data['email'] === '') {
        $errors['email'] = 'Informe seu e-mail.';
    } elseif (strlen($data['email']) > 100) {
        $errors['email'] = 'O e-mail pode ter no máximo 100 caracteres.';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Esse e-mail não parece válido. Use o formato nome@exemplo.com.';
    }

    if ($data['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{8,20}$/', $data['phone'])) {
        $errors['phone'] = 'O telefone deve ter de 8 a 20 caracteres, usando só números, espaços, + ( ) e -.';
    }

    $bioLength = mb_strlen($data['bio']);
    if ($bioLength > BIO_MAX) {
        $errors['bio'] = 'A biografia pode ter no máximo ' . BIO_MAX . " caracteres (você digitou $bioLength).";
    }

    $passwordLength = strlen($password);
    if ($password === '') {
        $errors['password'] = 'Crie uma senha.';
    } elseif ($passwordLength > 72) {
        $errors['password'] = 'A senha pode ter no máximo 72 caracteres.';
    } else {
        $missing = [];
        if ($passwordLength < 10) {
            $missing[] = "pelo menos 10 caracteres (a sua tem $passwordLength)";
        }
        if (!preg_match('/[A-Za-z]/', $password)) {
            $missing[] = 'pelo menos uma letra';
        }
        if (!preg_match('/\d/', $password)) {
            $missing[] = 'pelo menos um número';
        }
        if ($missing) {
            $errors['password'] = 'A senha ainda precisa de ' . joinWithAnd($missing) . '.';
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
        $errors['recovery_phrase'] = 'A frase recuperadora não pode ser igual à senha. Escolha outra frase, que você consiga lembrar.';
    }

    return $errors;
}

/** Junta as especializações enviadas (lista + texto pendente), sem repetidas. */
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
        return 'Adicione pelo menos uma especialização (digite e pressione Enter).';
    }
    if (count($items) > SPECIALIZATION_MAX_ITEMS) {
        return 'Você pode adicionar no máximo ' . SPECIALIZATION_MAX_ITEMS . ' especializações (você adicionou ' . count($items) . ').';
    }
    foreach ($items as $item) {
        $length = mb_strlen($item);
        if ($length < SPECIALIZATION_MIN_LENGTH) {
            return "A especialização “{$item}” é muito curta (mínimo de " . SPECIALIZATION_MIN_LENGTH . ' caracteres).';
        }
        if ($length > SPECIALIZATION_MAX_LENGTH) {
            return 'A especialização “' . mb_substr($item, 0, 30) . '…” passa de ' . SPECIALIZATION_MAX_LENGTH . " caracteres (tem $length).";
        }
    }
    return null;
}

/** Transforma $_FILES (um ou vários arquivos) em uma lista simples. */
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
        default => "Não foi possível receber $label. Adicione-o novamente.",
    };
}

/** Retorna [mensagem do campo|null, [['index' => n, 'message' => '...'], ...]]. */
function validateProofs(array $files): array
{
    if (!$files) {
        return ['Envie pelo menos um comprovante (PDF, JPG ou PNG).', []];
    }
    if (count($files) > PROOF_MAX_FILES) {
        return ['Envie no máximo ' . PROOF_MAX_FILES . ' comprovantes (você enviou ' . count($files) . ').', []];
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
            $message = sprintf('%s tem %s MB; o limite é %d MB por arquivo.', $label, number_format($file['size'] / 1048576, 1, ',', ''), PROOF_MAX_MB);
        } elseif (!in_array($extension, PROOF_EXTENSIONS, true)) {
            $message = "$label não é PDF, JPG ou PNG.";
        } elseif (!in_array($finfo->file($file['tmp_name']), PROOF_MIME_TYPES, true)) {
            $message = "$label não parece ser um PDF, JPG ou PNG de verdade.";
        } else {
            continue;
        }
        $issues[] = ['index' => $index, 'message' => $message];
    }

    return $issues ? [implode(' ', array_column($issues, 'message')), $issues] : [null, []];
}

function removeProofFiles(array $fileNames): void
{
    foreach ($fileNames as $fileName) {
        @unlink(PROOF_FOLDER . '/' . basename((string) $fileName));
    }
}

/** Salva os comprovantes (já validados). Retorna [['file_name', 'original_name'], ...]. */
function storeProofs(array $files): array
{
    if (!is_dir(PROOF_FOLDER) && !mkdir(PROOF_FOLDER, 0755, true) && !is_dir(PROOF_FOLDER)) {
        throw new RuntimeException('Não foi possível preparar a pasta de comprovantes.');
    }

    $stored = [];
    try {
        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $fileName = bin2hex(random_bytes(16)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
            if (!move_uploaded_file($file['tmp_name'], PROOF_FOLDER . '/' . $fileName)) {
                throw new RuntimeException('Não foi possível salvar um dos comprovantes.');
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

$wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$old = [];
$fieldErrors = [];
$fileIssues = [];
$generalError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postMax = iniBytes('post_max_size');
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($postMax > 0 && $contentLength > $postMax) {
        // O PHP descarta tudo quando o envio passa do limite do servidor
        $limitMb = number_format($postMax / 1048576, 0, ',', '');
        $fieldErrors['validation_proof'] = "O envio passou do limite de $limitMb MB aceito pelo servidor. Remova ou reduza os comprovantes e tente de novo.";
    } else {
        $old = $_POST;

        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'birth_date' => trim($_POST['birth_date'] ?? ''),
            'cpf' => preg_replace('/\D/', '', $_POST['cpf'] ?? ''),
            'email' => strtolower(trim($_POST['email'] ?? '')),
            'phone' => trim($_POST['phone'] ?? ''),
            // Quebras de linha contam como 1 caractere, igual ao navegador
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

        // E-mail e CPF já cadastrados
        $checkEmail = !isset($fieldErrors['email']);
        $checkCpf = !isset($fieldErrors['cpf']);
        if ($checkEmail || $checkCpf) {
            $stmt = $pdo->prepare('SELECT email, cpf, activate FROM users WHERE email = ? OR cpf = ?');
            $stmt->execute([$data['email'], $data['cpf']]);
            foreach ($stmt->fetchAll() as $row) {
                $disabled = (int) $row['activate'] === 0;
                if ($checkEmail && $row['email'] === $data['email']) {
                    $fieldErrors['email'] = $disabled
                        ? 'Já existe uma conta com esse e-mail, mas ela está desativada. Em “Entrar” use “Recuperar conta” para reativá-la com a sua frase recuperadora.'
                        : 'Já existe uma conta com esse e-mail. Tente entrar ou use outro e-mail.';
                }
                if ($checkCpf && $row['cpf'] === $data['cpf']) {
                    $fieldErrors['cpf'] = $disabled
                        ? 'Esse CPF pertence a uma conta desativada. Em “Entrar” use “Recuperar conta” para reativá-la com a sua frase recuperadora.'
                        : 'Esse CPF já está vinculado a uma conta. Tente entrar na sua conta.';
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

                $pdo->prepare('INSERT INTO users (name, birth_date, cpf, email, phone, bio, password_hash, recovery_phrase_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([
                        $data['name'], $data['birth_date'], $data['cpf'], $data['email'],
                        $data['phone'] ?: null, $data['bio'] ?: null,
                        password_hash($password, PASSWORD_DEFAULT),
                        hashRecoveryPhrase($recoveryPhrase),
                    ]);
                $newUserId = (int) $pdo->lastInsertId();

                if ($data['role'] === 'teacher') {
                    $pdo->prepare('INSERT INTO teacher_profiles (user_id, specializations, proof_path) VALUES (?, ?, ?)')
                        ->execute([
                            $newUserId,
                            json_encode($specializations, JSON_UNESCAPED_UNICODE),
                            $storedProofs[0]['file_name'],
                        ]);
                    $insertProof = $pdo->prepare('INSERT INTO teacher_proofs (user_id, file_name, original_name) VALUES (?, ?, ?)');
                    foreach ($storedProofs as $proof) {
                        $insertProof->execute([$newUserId, $proof['file_name'], $proof['original_name']]);
                    }
                } else {
                    // A matrícula é o próprio ID, gerado pelo sistema
                    $pdo->prepare('INSERT INTO student_profiles (user_id, enrollment_number) VALUES (?, ?)')
                        ->execute([$newUserId, (string) $newUserId]);
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                removeProofFiles(array_column($storedProofs, 'file_name'));

                if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                    $fieldErrors['email'] = 'Esse e-mail ou CPF acabou de ser cadastrado em outra conta. Revise os dados e tente de novo.';
                } else {
                    error_log('register.php: ' . $exception->getMessage());
                    $generalError = 'Não foi possível concluir o cadastro agora. Tente novamente em alguns instantes.';
                }
            }
        }

        if (!$fieldErrors && !$generalError) {
            session_regenerate_id(true);
            $_SESSION['uid'] = $newUserId;
            setFlash('success', $data['role'] === 'teacher'
                ? 'Conta criada! Seu perfil de professor será liberado após a análise dos comprovantes.'
                : 'Conta criada com sucesso. Bem-vindo(a) ao Hi Teach!');

            if ($wantsJson) {
                respondJson(200, ['ok' => true, 'redirect' => '../pages/profile.php']);
            }
            redirect('../pages/profile.php');
        }
    }

    uksort($fieldErrors, static fn ($a, $b) => array_search($a, FIELD_ORDER, true) <=> array_search($b, FIELD_ORDER, true));

    if ($wantsJson) {
        respondJson(422, ['ok' => false, 'errors' => $fieldErrors, 'files' => $fileIssues, 'message' => $generalError]);
    }

    setFlash('error', $generalError !== ''
        ? $generalError
        : "Corrija os campos abaixo:\n• " . implode("\n• ", array_values($fieldErrors)));
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
$invalid = static fn (string $field): string => isset($fieldErrors[$field]) ? ' aria-invalid="true"' : '';

$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="register-section" class="section">
    <h2 class="section-title">Criar conta</h2>

    <form
      id="register-form"
      class="form"
      action="register.php"
      method="post"
      enctype="multipart/form-data"
      novalidate
    >
      <div class="form-group" data-field="name">
        <label class="form-label" for="name">Nome</label>
        <input
          type="text"
          id="name"
          name="name"
          class="form-input"
          maxlength="50"
          value="<?= e($old['name'] ?? '') ?>"
          autocomplete="name"
          aria-describedby="error-name"<?= $invalid('name') ?>
          required
        >
        <?= $fieldError('name') ?>
      </div>

      <div class="form-group" data-field="birth_date">
        <label class="form-label" for="birth-date">Data de nascimento</label>
        <input
          type="date"
          id="birth-date"
          name="birth_date"
          class="form-input"
          value="<?= e($old['birth_date'] ?? '') ?>"
          aria-describedby="error-birth_date"<?= $invalid('birth_date') ?>
          required
        >
        <?= $fieldError('birth_date') ?>
      </div>

      <div class="form-group" data-field="cpf">
        <label class="form-label" for="cpf">CPF</label>
        <input
          type="text"
          id="cpf"
          name="cpf"
          class="form-input"
          maxlength="14"
          inputmode="numeric"
          placeholder="000.000.000-00"
          value="<?= e($old['cpf'] ?? '') ?>"
          aria-describedby="error-cpf"<?= $invalid('cpf') ?>
          required
        >
        <?= $fieldError('cpf') ?>
      </div>

      <div class="form-group" data-field="email">
        <label class="form-label" for="email">E-mail</label>
        <input
          type="email"
          id="email"
          name="email"
          class="form-input"
          maxlength="100"
          value="<?= e($old['email'] ?? '') ?>"
          autocomplete="email"
          aria-describedby="error-email"<?= $invalid('email') ?>
          required
        >
        <?= $fieldError('email') ?>
      </div>

      <div class="form-group" data-field="phone">
        <label class="form-label" for="phone">Telefone</label>
        <input
          type="tel"
          id="phone"
          name="phone"
          class="form-input"
          maxlength="20"
          value="<?= e($old['phone'] ?? '') ?>"
          autocomplete="tel"
          aria-describedby="error-phone"<?= $invalid('phone') ?>
        >
        <?= $fieldError('phone') ?>
      </div>

      <div class="form-group" data-field="bio">
        <label class="form-label" for="bio">Biografia</label>
        <textarea
          id="bio"
          name="bio"
          class="form-input form-textarea-fixed"
          maxlength="<?= BIO_MAX ?>"
          aria-describedby="bio-counter error-bio"<?= $invalid('bio') ?>
        ><?= e($old['bio'] ?? '') ?></textarea>
        <div class="field-meta">
          <small class="form-note">Opcional. Conte um pouco sobre você.</small>
          <small id="bio-counter" class="field-counter" aria-live="polite"><?= mb_strlen(str_replace("\r\n", "\n", (string) ($old['bio'] ?? ''))) ?>/<?= BIO_MAX ?></small>
        </div>
        <?= $fieldError('bio') ?>
      </div>

      <div class="form-group" data-field="password">
        <label class="form-label" for="password">Senha</label>
        <input
          type="password"
          id="password"
          name="password"
          class="form-input"
          maxlength="72"
          autocomplete="new-password"
          aria-describedby="password-hint error-password"<?= $invalid('password') ?>
          required
        >
        <small id="password-hint" class="form-note">Mínimo de 10 caracteres, com letras e números.</small>
        <?= $fieldError('password') ?>
      </div>

      <div class="form-group" data-field="password_confirm">
        <label class="form-label" for="password-confirm">Confirmar senha</label>
        <input
          type="password"
          id="password-confirm"
          name="password_confirm"
          class="form-input"
          maxlength="72"
          autocomplete="new-password"
          aria-describedby="error-password_confirm"<?= $invalid('password_confirm') ?>
          required
        >
        <?= $fieldError('password_confirm') ?>
      </div>

      <div class="form-group" data-field="recovery_phrase">
        <label class="form-label" for="recovery-phrase">Frase recuperadora</label>
        <input
          type="text"
          id="recovery-phrase"
          name="recovery_phrase"
          class="form-input"
          maxlength="<?= RECOVERY_MAX_LENGTH ?>"
          placeholder="Ex.: <?= e(RECOVERY_EXAMPLE) ?>"
          value="<?= e($old['recovery_phrase'] ?? '') ?>"
          autocomplete="off"
          autocapitalize="off"
          spellcheck="false"
          aria-describedby="recovery-hint error-recovery_phrase"<?= $invalid('recovery_phrase') ?>
          required
        >
        <div class="field-meta">
          <small id="recovery-hint" class="form-note">
            Se um dia sua conta for desativada, é com esta frase que você a reativa. Use pelo menos <?= RECOVERY_MIN_WORDS ?> palavras que você lembre fácil, como “<?= e(RECOVERY_EXAMPLE) ?>”. Maiúsculas e espaços a mais não importam.
          </small>
          <small id="recovery-counter" class="field-counter" aria-live="polite">0/<?= RECOVERY_MIN_WORDS ?> palavras</small>
        </div>
        <?= $fieldError('recovery_phrase') ?>
      </div>

      <div class="form-group">
        <label class="form-label" for="role">Perfil</label>
        <select id="role" name="role" class="form-input">
          <option value="student"<?= $isTeacher ? '' : ' selected' ?>>Aluno</option>
          <option value="teacher"<?= $isTeacher ? ' selected' : '' ?>>Professor</option>
        </select>
      </div>

      <fieldset id="teacher-fields" class="fieldset">
        <legend>Dados do professor</legend>

        <div class="form-group" data-field="specializations">
          <label class="form-label" for="specialization-input">Especializações</label>
          <ul id="specialization-list" class="tag-list" aria-label="Especializações adicionadas"></ul>
          <div class="tag-entry">
            <input
              type="text"
              id="specialization-input"
              name="specialization_input"
              class="form-input"
              maxlength="<?= SPECIALIZATION_MAX_LENGTH ?>"
              placeholder="Ex.: Formado em Matemática pela USP"
              autocomplete="off"
              aria-describedby="specialization-hint error-specializations"<?= $invalid('specializations') ?>
            >
            <button type="button" id="specialization-add" class="button tag-add">Adicionar</button>
          </div>
          <div class="field-meta">
            <small id="specialization-hint" class="form-note">Digite e pressione Enter para criar cada especialização.</small>
            <small id="specialization-counter" class="field-counter" aria-live="polite"><?= count($oldSpecializations) ?>/<?= SPECIALIZATION_MAX_ITEMS ?></small>
          </div>
          <div id="specialization-seed" hidden>
            <?php foreach ($oldSpecializations as $item): ?>
              <input type="hidden" name="specializations[]" value="<?= e($item) ?>">
            <?php endforeach; ?>
          </div>
          <?= $fieldError('specializations') ?>
        </div>

        <div class="form-group" data-field="validation_proof">
          <span class="form-label" id="proof-label">Comprovantes de validação</span>
          <div
            id="proof-drop"
            class="dropzone"
            role="group"
            tabindex="-1"
            aria-labelledby="proof-label"
            aria-describedby="proof-hint error-validation_proof"<?= $invalid('validation_proof') ?>
          >
            <svg class="dropzone-icon" viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 12 15 15"/></svg>
            <p class="dropzone-title">Arraste seus comprovantes aqui</p>
            <p class="dropzone-text">ou escolha os arquivos do seu dispositivo (PDF, JPG ou PNG)</p>
            <button type="button" id="proof-choose" class="button button-primary">Escolher arquivos</button>
          </div>
          <input
            type="file"
            id="validation-proof"
            name="validation_proof[]"
            accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
            multiple
            hidden
          >
          <ul id="proof-list" class="file-list" aria-label="Comprovantes selecionados"></ul>
          <div class="field-meta">
            <small id="proof-hint" class="form-note">PDF, JPG ou PNG. Até <?= PROOF_MAX_FILES ?> arquivos de <?= PROOF_MAX_MB ?> MB cada.</small>
            <small id="proof-counter" class="field-counter" aria-live="polite">0/<?= PROOF_MAX_FILES ?></small>
          </div>
          <?= $fieldError('validation_proof') ?>
        </div>

        <p class="form-note">
          Seu perfil de professor só é liberado depois que um administrador analisar os comprovantes.
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
