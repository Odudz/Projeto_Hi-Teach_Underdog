<?php
include_once __DIR__ . '/../config/conexao.php';

if (!$user) {
    $gate = 'login';
    $baseUrl = '../';
    include_once __DIR__ . '/../includes/acesso-restrito.php';
}

const AVATAR_MAX_MB = 5;
const BANNER_MAX_MB = 5;
const AVATAR_MAX_PX = 512;
const BANNER_MAX_PX = 1600;
const IMAGE_MAX_SOURCE_PX = 6000;
function processImage(?array $file, int $maxMb, int $maxPx, string &$error): ?array
{
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        $error = 'A imagem passa do limite de envio do servidor (' . ini_get('upload_max_filesize') . ').';
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Falha no envio da imagem.';
        return null;
    }
    if ($file['size'] > $maxMb * 1024 * 1024) {
        $error = "A imagem deve ter no máximo $maxMb MB.";
        return null;
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        $error = 'Use uma imagem JPG, PNG ou WebP.';
        return null;
    }
    [$width, $height] = $info;
    if (max($width, $height) > IMAGE_MAX_SOURCE_PX) {
        $error = 'A imagem é grande demais (máximo de ' . IMAGE_MAX_SOURCE_PX . ' px de largura ou altura).';
        return null;
    }

    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return [
            'bytes' => file_get_contents($file['tmp_name']),
            'mime' => $info['mime'] ?? 'image/jpeg',
        ];
    }

    $source = @imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$source) {
        $error = 'Imagem inválida.';
        return null;
    }

    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $orientation = @exif_read_data($file['tmp_name'])['Orientation'] ?? 1;
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $source = imagerotate($source, $angle, 0);
            [$width, $height] = [imagesx($source), imagesy($source)];
        }
    }

    $scale = min(1, $maxPx / $width, $maxPx / $height);
    $newWidth = max(1, (int) round($width * $scale));
    $newHeight = max(1, (int) round($height * $scale));
    $canvas = imagecreatetruecolor($newWidth, $newHeight);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    ob_start();
    imagejpeg($canvas, null, 85);
    return [
        'bytes' => ob_get_clean(),
        'mime' => 'image/jpeg',
    ];
}
function saveImage(PDO $pdo, int $userId, string $kind, string $bytes, string $mime): void
{
    $stmt = $pdo->prepare("UPDATE users SET {$kind}_data = ?, {$kind}_mime = ? WHERE id = ?");
    $stmt->bindValue(1, $bytes, PDO::PARAM_LOB);
    $stmt->bindValue(2, $mime);
    $stmt->bindValue(3, $userId, PDO::PARAM_INT);
    $stmt->execute();
}

function jsonResponse(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

$imageKinds = [
    'avatar' => [AVATAR_MAX_MB, AVATAR_MAX_PX, 'Foto de perfil atualizada!'],
    'banner' => [BANNER_MAX_MB, BANNER_MAX_PX, 'Banner atualizado!'],
];
$kind = $_POST['action'] ?? '';
$isFetch = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$isImageUpload = $_SERVER['REQUEST_METHOD'] === 'POST' && ($isFetch || isset($imageKinds[$kind]));

if ($isImageUpload) {

    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        jsonResponse(413, ['ok' => false, 'error' => 'A imagem passa do limite do servidor (' . ini_get('post_max_size') . ').']);
    }
    if (!isset($imageKinds[$kind])) {
        jsonResponse(400, ['ok' => false, 'error' => 'Pedido inválido.']);
    }

    [$maxMb, $maxPx, $successMessage] = $imageKinds[$kind];
    $imageError = '';
    $image = processImage($_FILES['image'] ?? null, $maxMb, $maxPx, $imageError);
    if ($image === null) {
        jsonResponse(422, ['ok' => false, 'error' => $imageError ?: 'Nenhuma imagem enviada.']);
    }

    saveImage($pdo, $user['id'], $kind, $image['bytes'], $image['mime']);
    jsonResponse(200, [
        'ok' => true,
        'message' => $successMessage,
        'url' => '../endpoints/image.php?user=' . (int) $user['id'] . '&type=' . $kind . '&v=' . time(),
    ]);
}

$modalError = '';
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $bio = trim($_POST['bio'] ?? '');

    $errors = [];

    if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
        $errors[] = 'Informe um nome com 2 a 50 caracteres.';
    }
    if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{8,20}$/', $phone)) {
        $errors[] = 'Telefone inválido.';
    }
    if (mb_strlen($bio) > 250) {
        $errors[] = 'A biografia deve ter no máximo 250 caracteres.';
    }

    if ($errors) {
        $modalError = implode(' ', $errors);
        $old = ['name' => $name, 'phone' => $phone, 'bio' => $bio];
        $openModal = 'edit-profile-modal';
    } else {
        $pdo->prepare('UPDATE users SET name = ?, phone = ?, bio = ? WHERE id = ?')
            ->execute([$name, $phone ?: null, $bio ?: null, $user['id']]);

        setFlash('success', 'Perfil atualizado!');
        redirect('profile.php');
    }
}

$pageId = 'profile';
$pageTitle = 'Perfil';
$extraCss = ['profile', 'image-editor'];
$extraJs = ['image-editor'];
$baseUrl = '../';

$roleLabels = ['student' => 'Aluno', 'teacher' => 'Professor', 'admin' => 'Administrador'];
$roleLabel = $roleLabels[$user['role']] ?? 'Aluno';

$stmt = $pdo->prepare('SELECT status FROM teacher_profiles WHERE user_id = ?');
$stmt->execute([$user['id']]);
$teacherStatus = $stmt->fetchColumn();
if ($user['role'] === 'student' && $teacherStatus === 'pending') {
    $roleLabel = 'Aluno · professor em análise';
} elseif ($user['role'] === 'student' && $teacherStatus === 'rejected') {
    $roleLabel = 'Aluno · cadastro de professor recusado';
}

$initials = mb_strtoupper(implode('', array_map(fn ($part) => mb_substr($part, 0, 1), array_slice(preg_split('/\s+/', trim($user['name'])) ?: [], 0, 2))));
$memberSince = date('d/m/Y', strtotime($user['created_at']));

ob_start();
?>
<div id="edit-profile-modal" class="modal-overlay">
  <section
    id="edit-profile-dialog"
    class="modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="edit-profile-title"
  >
    <header class="modal-header">
      <h2 id="edit-profile-title" class="modal-title">Editar perfil</h2>
      <button
        type="button"
        class="modal-close"
        data-modal-close
        aria-label="Fechar"
      >×</button>
    </header>

    <form
      id="profile-form"
      class="form"
      action="profile.php"
      method="post"
    >
      <?php if ($modalError !== ''): ?>
        <div id="profile-form-error" class="alert alert-error" role="alert">
          <?= e($modalError) ?>
        </div>
      <?php endif; ?>

      <div class="form-group">
        <label class="form-label" for="name">Nome</label>
        <input
          type="text"
          id="name"
          name="name"
          class="form-input"
          maxlength="50"
          value="<?= e($old['name'] ?? $user['name']) ?>"
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
          value="<?= e($old['phone'] ?? $user['phone']) ?>"
        >
      </div>

      <div class="form-group">
        <label class="form-label" for="bio">Biografia</label>
        <textarea
          id="bio"
          name="bio"
          class="form-input"
          maxlength="250"
        ><?= e($old['bio'] ?? $user['bio']) ?></textarea>
      </div>

      <button
        type="submit"
        id="profile-form-submit"
        class="button button-primary"
      >
        Salvar alterações
      </button>
    </form>
  </section>
</div>
<?php
$modals = ob_get_clean();

$imageEditors = [
    'avatar' => ['title' => 'Foto de perfil', 'shape' => 'circle', 'width' => 512, 'height' => 512],
    'banner' => ['title' => 'Banner', 'shape' => 'rect', 'width' => 1600, 'height' => 533],
];
ob_start();
foreach ($imageEditors as $kind => $editor):
?>
<div
  id="<?= $kind ?>-modal"
  class="modal-overlay"
  data-image-editor="<?= $kind ?>"
  data-shape="<?= $editor['shape'] ?>"
  data-out-width="<?= $editor['width'] ?>"
  data-out-height="<?= $editor['height'] ?>"
  data-endpoint="profile.php"
>
  <section
    class="modal modal-editor"
    role="dialog"
    aria-modal="true"
    aria-labelledby="<?= $kind ?>-modal-title"
  >
    <header class="modal-header">
      <h2 id="<?= $kind ?>-modal-title" class="modal-title"><?= e($editor['title']) ?></h2>
      <button type="button" class="modal-close" data-modal-close aria-label="Fechar">×</button>
    </header>

    <div class="editor-body">
      <div class="alert alert-error" role="alert" data-editor-error hidden></div>

      <div class="editor-picker" data-editor-picker>
        <div class="editor-drop" data-editor-drop>
          <svg class="editor-drop-icon" viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
          <p class="editor-drop-title">Arraste uma imagem aqui</p>
          <p class="editor-drop-text">ou escolha um arquivo do seu dispositivo (JPG, PNG ou WebP)</p>
          <button type="button" class="button button-primary" data-editor-choose>Escolher imagem</button>
        </div>
        <input type="file" accept="image/jpeg,image/png,image/webp" data-editor-file hidden>
      </div>

      <div class="editor-workspace" data-editor-workspace hidden>
        <div class="editor-stage" data-editor-stage>
          <canvas
            class="editor-canvas"
            tabindex="0"
            data-editor-canvas
            aria-label="Área de edição: arraste para mover a imagem. Setas movem, mais e menos dão zoom."
          ></canvas>
        </div>
        <p class="editor-hint">Arraste para posicionar. Use a roda do mouse, o controle ou dois dedos para dar zoom.</p>

        <div class="editor-tools">
          <div class="editor-zoom">
            <button type="button" class="editor-round" data-editor-zoom-out aria-label="Diminuir zoom">−</button>
            <input type="range" class="editor-range" min="1" max="5" step="0.01" value="1" data-editor-zoom aria-label="Zoom">
            <button type="button" class="editor-round" data-editor-zoom-in aria-label="Aumentar zoom">+</button>
          </div>

          <div class="editor-buttons">
            <button type="button" class="button button-small" data-editor-rotate="-1">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
              Girar
            </button>
            <button type="button" class="button button-small" data-editor-rotate="1">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
              Girar
            </button>
            <button type="button" class="button button-small" data-editor-flip>
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v18"/><path d="M8 7L3 17h5z"/><path d="M16 7l5 10h-5z"/></svg>
              Espelhar
            </button>
            <button type="button" class="button button-small" data-editor-reset>Redefinir</button>
          </div>

          <details class="editor-adjust">
            <summary>Ajustes de cor</summary>
            <div class="editor-adjust-list">
              <?php foreach (['brightness' => ['Brilho', 50, 150], 'contrast' => ['Contraste', 50, 150], 'saturation' => ['Saturação', 0, 200]] as $key => [$label, $min, $max]): ?>
                <label class="editor-adjust-row">
                  <span class="editor-adjust-label"><?= $label ?></span>
                  <input type="range" class="editor-range" min="<?= $min ?>" max="<?= $max ?>" step="1" value="100" data-editor-adjust="<?= $key ?>">
                  <output class="editor-adjust-value" data-editor-adjust-value="<?= $key ?>">100%</output>
                </label>
              <?php endforeach; ?>
            </div>
          </details>
        </div>

        <div class="editor-footer">
          <button type="button" class="button" data-editor-change>Trocar imagem</button>
          <button type="button" class="button button-primary" data-editor-save>Salvar</button>
        </div>
      </div>
    </div>
  </section>
</div>
<?php
endforeach;
$modals .= ob_get_clean();
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="profile-header" class="profile-header">
    <div id="profile-banner" class="profile-banner">
      <?php if ($user['banner_mime']): ?>
        <img
          id="profile-banner-image"
          class="profile-banner-image"
          src="../endpoints/image.php?user=<?= (int) $user['id'] ?>&amp;type=banner"
          alt=""
        >
      <?php endif; ?>
      <button
        type="button"
        id="banner-edit-button"
        class="profile-banner-edit"
        aria-label="Trocar banner"
        aria-haspopup="dialog"
        data-modal-open="banner-modal"
      ><span aria-hidden="true">+</span></button>
    </div>

    <div class="profile-identity">
      <div id="profile-avatar" class="profile-avatar">
        <?php if ($user['avatar_mime']): ?>
          <img
            id="profile-avatar-image"
            class="profile-avatar-image"
            src="../endpoints/image.php?user=<?= (int) $user['id'] ?>&amp;type=avatar"
            alt="Foto de perfil de <?= e($user['name']) ?>"
          >
        <?php else: ?>
          <span id="profile-avatar-initials" class="profile-avatar-initials"><?= e($initials) ?></span>
        <?php endif; ?>
        <button
          type="button"
          id="avatar-edit-button"
          class="profile-avatar-edit"
          aria-label="Trocar foto de perfil"
          aria-haspopup="dialog"
          data-modal-open="avatar-modal"
        ><span aria-hidden="true">+</span></button>
      </div>

      <div class="profile-summary">
        <h1 id="profile-name" class="profile-name"><?= e($user['name']) ?></h1>
        <span id="profile-role" class="profile-role"><?= e($roleLabel) ?></span>
        <p id="profile-bio" class="profile-bio"><?= e($user['bio'] ?: 'Conte um pouco sobre você.') ?></p>
      </div>

      <div class="profile-actions">
        <button
          type="button"
          id="edit-profile-button"
          class="button button-primary"
          aria-haspopup="dialog"
          data-modal-open="edit-profile-modal"
        >
          Editar perfil
        </button>
        <form id="logout-form" action="../auth/logout.php" method="post">
          <button type="submit" id="logout-button" class="button button-danger">Sair</button>
        </form>
      </div>
    </div>
  </section>

  <section id="profile-stats" class="profile-stats">
    <div class="stat-card">
      <span id="stat-courses" class="stat-value">0</span>
      <span class="stat-label">Cursos</span>
    </div>
    <div class="stat-card">
      <span id="stat-lessons" class="stat-value">0</span>
      <span class="stat-label">Aulas assistidas</span>
    </div>
    <div class="stat-card">
      <span id="stat-posts" class="stat-value">0</span>
      <span class="stat-label">Mensagens em fóruns</span>
    </div>
  </section>

  <section id="profile-about" class="section">
    <h2 class="section-title">Sobre</h2>
    <dl id="profile-data" class="profile-data">
      <dt>E-mail</dt>
      <dd id="profile-email"><?= e($user['email']) ?></dd>
      <dt>Telefone</dt>
      <dd id="profile-phone"><?= e($user['phone'] ?: '—') ?></dd>
      <dt>Membro desde</dt>
      <dd id="profile-member-since"><?= e($memberSince) ?></dd>
    </dl>
  </section>

  <section id="profile-badges" class="section">
    <h2 class="section-title">Conquistas</h2>
    <ul id="badge-list" class="badge-list">
      <li class="badge-item">
        <span class="badge-icon">★</span>
        <span class="badge-name">Primeira aula</span>
      </li>
      <li class="badge-item">
        <span class="badge-icon">▶</span>
        <span class="badge-name">10 vídeos assistidos</span>
      </li>
      <li class="badge-item">
        <span class="badge-icon">✉</span>
        <span class="badge-name">Voz no fórum</span>
      </li>
      <li class="badge-item">
        <span class="badge-icon">✎</span>
        <span class="badge-name">Atividade entregue</span>
      </li>
    </ul>
  </section>

  <?php if (in_array($user['role'], ['teacher', 'admin'], true)): ?>
    <section id="profile-links" class="section">
      <h2 class="section-title">Painéis</h2>
      <div class="profile-actions">
        <?php if ($user['role'] === 'teacher'): ?>
          <a id="link-teacher-dashboard" class="button button-secondary" href="teacher-dashboard.php">
            Painel do professor
          </a>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin'): ?>
          <a id="link-moderator-dashboard" class="button button-secondary" href="moderator-dashboard.php">
            Administração
          </a>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
