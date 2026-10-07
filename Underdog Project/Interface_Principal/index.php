<?php
include_once __DIR__ . '/config/conexao.php';
$firstName = '';
if ($user) {
    $parts = preg_split('/\s+/', trim($user['name']));
    $firstName = $parts[0] ?? '';
}
$pageId = 'index';
$pageTitle = 'Início';
$baseUrl = '';
$extraCss = ['home'];
include_once __DIR__ . '/includes/cabecalho.php';
?>
<main id="main-content" class="main-content home-main">
  <section id="hero" class="hero home-hero">
    <div class="home-hero-copy">
      <?php if ($user): ?>
        <h1 class="hero-title">Olá, <?= e($firstName) ?>.</br> Continue de onde parou.</h1>
        <p class="hero-text">Abra suas matérias, entre numa aula ao vivo ou veja o que está sendo discutido nos fóruns.</p>
        <div class="home-actions">
          <a id="cta-subjects" class="button button-primary" href="pages/subjects.php">Ver matérias</a>
          <a id="cta-live" class="button button-secondary" href="pages/live-class.php">Aulas ao vivo</a>
          <?php if ($user['role'] === 'teacher'): ?>
            <a id="cta-teacher" class="button button-secondary" href="pages/teacher-dashboard.php">Painel do professor</a>
          <?php elseif ($user['role'] === 'admin'): ?>
            <a id="cta-admin" class="button button-secondary" href="pages/moderator-dashboard.php">Administração</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <h1 class="hero-title">Estude a distância com a estrutura da sala de aula</h1>
        <p class="hero-text">Videoaulas, materiais, aulas ao vivo e fóruns por matéria, reunidos em um só lugar para você estudar no seu ritmo.</p>
        <div class="home-actions">
          <a id="cta-register" class="button button-primary" href="auth/register.php">Criar conta</a>
          <a id="cta-login" class="button button-secondary" href="auth/login.php">Entrar</a>
        </div>
      <?php endif; ?>
    </div>

    <div class="home-window" aria-hidden="true">
      <div class="home-window-bar">
        <span>Aula de exemplo</span>
        <span class="home-live">Ao vivo</span>
      </div>
      <div class="home-video"><span class="home-play"></span></div>
      <div class="home-chat">
        <p><strong>Professor:</strong> Bem-vindos à aula de hoje!</p>
        <p><strong>Ana:</strong> Posso tirar uma dúvida?</p>
        <p><strong>Professor:</strong> Claro, manda no chat.</p>
      </div>
    </div>
  </section>

  <section id="features" class="section features">
    <h2 class="section-title">O que você encontra</h2>

    <a class="card feature-card" href="pages/subjects.php" style="text-decoration: none;">
      <h3 class="card-title">Videoaulas</h3>
      <p class="card-text">Assista quando quiser.</p>
    </a>

    <a class="card feature-card" href="pages/live-class.php" style="text-decoration: none;">
      <h3 class="card-title">Aulas ao vivo</h3>
      <p class="card-text">Participe e comente em tempo real.</p>
    </a>

    <a class="card feature-card" href="pages/forum.php" style="text-decoration: none;">
      <h3 class="card-title">Fóruns</h3>
      <p class="card-text">Discuta cada matéria.</p>
    </a>

    <a class="card feature-card" href="pages/classroom.php" style="text-decoration: none;">
      <h3 class="card-title">Salas virtuais</h3>
      <p class="card-text">Materiais e atividades do professor.</p>
    </a>
  </section>

  <section id="subjects-preview" class="section">
    <h2 class="section-title">Matérias</h2>

    <a class="card subject-card" href="pages/subjects.php" style="text-decoration: none;">
      <h3 class="card-title">Engenharia de Requisitos</h3>
      <p class="card-text">Levantamento e análise de requisitos.</p>
    </a>

    <a class="card subject-card" href="pages/subjects.php" style="text-decoration: none;">
      <h3 class="card-title">Desenvolvimento Web</h3>
      <p class="card-text">HTML, CSS, JavaScript e mais.</p>
    </a>

    <a class="card subject-card" href="pages/subjects.php" style="text-decoration: none;">
      <h3 class="card-title">Banco de Dados</h3>
      <p class="card-text">Modelagem e SQL.</p>
    </a>
  </section>

</main>
<?php include_once __DIR__ . '/includes/rodape.php'; ?>
