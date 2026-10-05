<?php
include_once __DIR__ . '/config/conexao.php';
$pageId = 'index';
$pageTitle = 'Início';
$baseUrl = '';
include_once __DIR__ . '/includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="hero" class="hero">
    <h1 class="hero-title">Estude a distância com a estrutura da sala de aula</h1>
    <p class="hero-text">Videoaulas, materiais, aulas ao vivo e fóruns por matéria.</p>
    <?php if (!$user): ?>
        <a id="cta-register" class="button button-primary" href="auth/register.php">Criar conta</a>
        <a id="cta-login" class="button button-secondary" href="auth/login.php">Entrar</a>
    <?php endif; ?>
  </section>

  <section id="features" class="section features">
    <h2 class="section-title">O que você encontra</h2>

    <article class="card feature-card">
      <h3 class="card-title">Videoaulas</h3>
      <p class="card-text">Assista quando quiser.</p>
      <a class="card-link" href="pages/subjects.php">Ver mais</a>
    </article>

    <article class="card feature-card">
      <h3 class="card-title">Aulas ao vivo</h3>
      <p class="card-text">Participe e comente em tempo real.</p>
      <a class="card-link" href="pages/live-class.php">Ver mais</a>
    </article>

    <article class="card feature-card">
      <h3 class="card-title">Fóruns</h3>
      <p class="card-text">Discuta cada matéria.</p>
      <a class="card-link" href="pages/forum.php">Ver mais</a>
    </article>

    <article class="card feature-card">
      <h3 class="card-title">Salas virtuais</h3>
      <p class="card-text">Materiais e atividades do professor.</p>
      <a class="card-link" href="pages/classroom.php">Ver mais</a>
    </article>
  </section>

  <section id="subjects-preview" class="section">
    <h2 class="section-title">Matérias</h2>

    <article class="card subject-card">
      <h3 class="card-title">Engenharia de Requisitos</h3>
      <p class="card-text">Levantamento e análise de requisitos.</p>
      <a class="card-link" href="pages/subjects.php">Ver mais</a>
    </article>

    <article class="card subject-card">
      <h3 class="card-title">Desenvolvimento Web</h3>
      <p class="card-text">HTML, CSS, JavaScript e mais.</p>
      <a class="card-link" href="pages/subjects.php">Ver mais</a>
    </article>

    <article class="card subject-card">
      <h3 class="card-title">Banco de Dados</h3>
      <p class="card-text">Modelagem e SQL.</p>
      <a class="card-link" href="pages/subjects.php">Ver mais</a>
    </article>
  </section>
</main>
<?php include_once __DIR__ . '/includes/rodape.php'; ?>
