<?php
include_once 'conexao.php';

if ($user) {
    redirect('profile.php');
}

const PROOF_MAX_MB = 5;
const PROOF_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

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

function validateRegistration(array $data, string $password, string $passwordConfirm): array
{
    $errors = [];

    if (mb_strlen($data['name']) < 2 || mb_strlen($data['name']) > 50) {
        $errors[] = 'Informe um nome com 2 a 50 caracteres.';
    }

    $birth = DateTime::createFromFormat('!Y-m-d', $data['birth_date']);
    $today = new DateTime('today');
    $isRealDate = $birth && $birth->format('Y-m-d') === $data['birth_date'];
    $age = $isRealDate ? $birth->diff($today)->y : 0;
    if (!$isRealDate || $birth > $today || $age < 13 || $age > 120) {
        $errors[] = 'Data de nascimento inválida (idade mínima: 13 anos).';
    }

    if (!isValidCpf($data['cpf'])) {
        $errors[] = 'CPF inválido.';
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 100) {
        $errors[] = 'E-mail inválido.';
    }
    if ($data['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{8,20}$/', $data['phone'])) {
        $errors[] = 'Telefone inválido.';
    }
    if (mb_strlen($data['bio']) > 250) {
        $errors[] = 'A biografia deve ter no máximo 250 caracteres.';
    }
    if ($data['enrollment_number'] !== '' && !preg_match('/^[0-9A-Za-z]{1,10}$/', $data['enrollment_number'])) {
        $errors[] = 'Matrícula inválida.';
    }
    if ($data['role'] === 'teacher' && (mb_strlen($data['specializations']) < 3 || mb_strlen($data['specializations']) > 150)) {
        $errors[] = 'Informe suas especializações (3 a 150 caracteres).';
    }

    if (strlen($password) < 10 || strlen($password) > 72 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $errors[] = 'A senha deve ter de 10 a 72 caracteres, com letras e números.';
    } elseif ($password !== $passwordConfirm) {
        $errors[] = 'As senhas não conferem.';
    }

    return $errors;
}

function storeProof(?array $file, string &$error): ?string
{
    $extension = $file ? strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) : '';

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Envie o comprovante de validação do professor.';
    } elseif ($file['size'] > PROOF_MAX_MB * 1024 * 1024) {
        $error = 'O comprovante deve ter no máximo ' . PROOF_MAX_MB . ' MB.';
    } elseif (!in_array($extension, PROOF_EXTENSIONS, true)) {
        $error = 'O comprovante deve ser PDF, JPG ou PNG.';
    }
    if ($error !== '') {
        return null;
    }

    $folder = __DIR__ . '/storage/private/proofs';
    if (!is_dir($folder)) {
        mkdir($folder, 0755, true);
    }
    $fileName = bin2hex(random_bytes(16)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
    move_uploaded_file($file['tmp_name'], $folder . '/' . $fileName);

    return $fileName;
}

$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    $data = [
        'name' => trim($_POST['name'] ?? ''),
        'birth_date' => trim($_POST['birth_date'] ?? ''),
        'cpf' => preg_replace('/\D/', '', $_POST['cpf'] ?? ''),
        'email' => strtolower(trim($_POST['email'] ?? '')),
        'phone' => trim($_POST['phone'] ?? ''),
        'bio' => trim($_POST['bio'] ?? ''),
        'role' => ($_POST['role'] ?? '') === 'teacher' ? 'teacher' : 'student',
        'enrollment_number' => trim($_POST['enrollment_number'] ?? ''),
        'specializations' => trim($_POST['specializations'] ?? ''),
    ];

    $errors = validateRegistration($data, $_POST['password'] ?? '', $_POST['password_confirm'] ?? '');

    $proofName = null;
    if (!$errors && $data['role'] === 'teacher') {
        $proofError = '';
        $proofName = storeProof($_FILES['validation_proof'] ?? null, $proofError);
        if ($proofError !== '') {
            $errors[] = $proofError;
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $pdo->prepare('INSERT INTO users (name, birth_date, cpf, email, phone, bio, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $data['name'], $data['birth_date'], $data['cpf'], $data['email'],
                    $data['phone'] ?: null, $data['bio'] ?: null,
                    password_hash($_POST['password'], PASSWORD_DEFAULT),
                ]);
            $newUserId = (int) $pdo->lastInsertId();

            if ($data['role'] === 'teacher') {
                $pdo->prepare('INSERT INTO teacher_profiles (user_id, specializations, proof_path) VALUES (?, ?, ?)')
                    ->execute([$newUserId, $data['specializations'], $proofName]);
            } else {
                $pdo->prepare('INSERT INTO student_profiles (user_id, enrollment_number) VALUES (?, ?)')
                    ->execute([$newUserId, $data['enrollment_number'] ?: null]);
            }

            $pdo->commit();
        } catch (PDOException $exception) {
            $pdo->rollBack();
            if ($proofName) {
                @unlink(__DIR__ . '/storage/private/proofs/' . $proofName);
            }
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $errors[] = 'Não foi possível criar a conta com esses dados.';
        }
    }

    if (!$errors) {
        $_SESSION['uid'] = $newUserId;
        setFlash('success', $data['role'] === 'teacher'
            ? 'Conta criada! Seu perfil de professor será liberado após a análise do comprovante.'
            : 'Conta criada com sucesso. Bem-vindo(a) ao Hi Teach!');
        redirect('profile.php');
    }

    setFlash('error', implode(' ', $errors));
}

$pageId = 'register';
$pageTitle = 'Cadastro';
$isTeacher = ($old['role'] ?? '') === 'teacher';
include_once 'cabecalho.php';
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
    >
      <div class="form-group">
        <label class="form-label" for="name">Nome</label>
        <input
          type="text"
          id="name"
          name="name"
          class="form-input"
          maxlength="50"
          value="<?= e($old['name'] ?? '') ?>"
          autocomplete="name"
          required
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="birth-date">Data de nascimento</label>
        <input
          type="date"
          id="birth-date"
          name="birth_date"
          class="form-input"
          value="<?= e($old['birth_date'] ?? '') ?>"
          required
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="cpf">CPF</label>
        <input
          type="text"
          id="cpf"
          name="cpf"
          class="form-input"
          maxlength="14"
          inputmode="numeric"
          value="<?= e($old['cpf'] ?? '') ?>"
          required
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="email">E-mail</label>
        <input
          type="email"
          id="email"
          name="email"
          class="form-input"
          maxlength="100"
          value="<?= e($old['email'] ?? '') ?>"
          autocomplete="email"
          required
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="phone">Telefone</label>
        <input
          type="tel"
          id="phone"
          name="phone"
          class="form-input"
          maxlength="20"
          value="<?= e($old['phone'] ?? '') ?>"
          autocomplete="tel"
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="bio">Biografia</label>
        <textarea id="bio" name="bio" class="form-input" maxlength="250"><?= e($old['bio'] ?? '') ?></textarea>
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Senha</label>
        <input
          type="password"
          id="password"
          name="password"
          class="form-input"
          minlength="10"
          maxlength="72"
          autocomplete="new-password"
          required
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="password-confirm">Confirmar senha</label>
        <input
          type="password"
          id="password-confirm"
          name="password_confirm"
          class="form-input"
          minlength="10"
          maxlength="72"
          autocomplete="new-password"
          required
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="role">Perfil</label>
        <select id="role" name="role" class="form-input">
          <option value="student"<?= $isTeacher ? '' : ' selected' ?>>Aluno</option>
          <option value="teacher"<?= $isTeacher ? ' selected' : '' ?>>Professor</option>
        </select>
      </div>

      <fieldset id="student-fields" class="fieldset">
        <legend>Dados do aluno</legend>
        <div class="form-group">
          <label class="form-label" for="enrollment-number">Matrícula</label>
          <input
            type="text"
            id="enrollment-number"
            name="enrollment_number"
            class="form-input"
            maxlength="10"
            value="<?= e($old['enrollment_number'] ?? '') ?>"
          >
        </div>
      </fieldset>

      <fieldset id="teacher-fields" class="fieldset">
        <legend>Dados do professor</legend>
        <div class="form-group">
          <label class="form-label" for="specializations">Especializações</label>
          <input
            type="text"
            id="specializations"
            name="specializations"
            class="form-input"
            maxlength="150"
            value="<?= e($old['specializations'] ?? '') ?>"
          >
        </div>
        <div class="form-group">
          <label class="form-label" for="validation-proof">Comprovante de validação (PDF, JPG ou PNG, até 5 MB)</label>
          <input
            type="file"
            id="validation-proof"
            name="validation_proof"
            class="form-input"
            accept=".pdf,.jpg,.jpeg,.png"
          >
        </div>
        <p class="form-note">
          Seu perfil de professor só é liberado depois que um administrador analisar o comprovante.
        </p>
      </fieldset>

      <button type="submit" id="register-form-submit" class="button button-primary">Cadastrar</button>
    </form>

    <p class="form-note">
      Já tem conta? <a id="link-login" href="login.php">Entrar</a>
    </p>
  </section>
</main>
<?php include_once 'rodape.php'; ?>
