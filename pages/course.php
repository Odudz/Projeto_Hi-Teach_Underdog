<?php
include_once __DIR__ . '/../config/conexao.php';
$pageId = 'course';
$pageTitle = 'Curso';
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="course-detail" class="section">
    <h2 class="section-title">Título do curso</h2>
    <p id="course-description" class="course-description">Descrição do curso.</p>
    <p id="course-teacher" class="course-teacher">Professor: —</p>
    <p id="course-subject" class="course-subject">Matéria: —</p>
    <button type="button" id="enroll-button" class="button button-primary">
      Matricular-se
    </button>
  </section>

  <section id="course-materials" class="section">
    <h2 class="section-title">Materiais de estudo</h2>
    <ul id="material-list" class="material-list">
      <li class="material-item">
        <a class="material-link" href="video.php">Vídeo de exemplo</a>
      </li>
      <li class="material-item">
        <a class="material-link" id="download-material" href="#">Baixar material</a>
      </li>
    </ul>
  </section>

  <section id="course-links" class="section">
    <h2 class="section-title">Acesso rápido</h2>
    <a class="button button-secondary" href="classroom.php">Sala virtual</a>
    <a class="button button-secondary" href="forum.php">Fórum</a>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
