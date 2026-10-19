<?php
include_once __DIR__ . '/../config/conexao.php';
$pageId = 'classroom';
$pageTitle = 'Sala virtual';
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="classroom-header" class="section">
    <h2 class="section-title">Nome da sala</h2>
    <p id="classroom-teacher" class="classroom-teacher">Professor: —</p>
  </section>

  <section id="classroom-materials" class="section">
    <h2 class="section-title">Materiais</h2>
    <ul id="classroom-material-list" class="material-list"></ul>
  </section>

  <section id="classroom-activities" class="section">
    <h2 class="section-title">Atividades</h2>

    <article class="card activity-card">
      <h3 class="card-title">Título da atividade</h3>
      <p class="card-text">Prazo: —</p>
      <a class="card-link" href="../activity.php">Abrir</a>
    </article>

    <ul id="activity-list" class="activity-list"></ul>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
