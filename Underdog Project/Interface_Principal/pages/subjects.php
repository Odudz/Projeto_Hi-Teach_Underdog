<?php
include_once __DIR__ . '/../config/conexao.php';
$pageId = 'subjects';
$pageTitle = 'Matérias';
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="subject-list" class="section">
    <h2 class="section-title">Matérias</h2>

    <article class="card subject-card">
      <h3 class="card-title">Nome da matéria</h3>
      <p class="card-text">Descrição da matéria.</p>
      <a class="card-link" href="course.php">Ver cursos</a>
    </article>

    <a class="card-link" href="forum.php">Fórum da matéria</a>
  </section>

  <section id="course-list" class="section">
    <h2 class="section-title">Cursos</h2>

    <article class="card course-card">
      <h3 class="card-title">Título do curso</h3>
      <p class="card-text">Descrição do curso. Professor: —</p>
      <a class="card-link" href="course.php">Abrir curso</a>
    </article>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
