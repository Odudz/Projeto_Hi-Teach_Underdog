<?php
include_once 'conexao.php';
$pageId = 'forum';
$pageTitle = 'Fóruns';
include_once 'cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="forum-filter" class="section">
    <h2 class="section-title">Filtrar por matéria</h2>

    <form id="forum-filter-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="filter-subject">Matéria</label>
        <select id="filter-subject" name="filter-subject" class="form-input">
          <option value="all">Todas</option>
        </select>
      </div>

      <button type="submit" id="forum-filter-form-submit" class="button button-primary">Filtrar</button>
    </form>
  </section>

  <section id="forum-list" class="section">
    <h2 class="section-title">Fóruns</h2>

    <article class="card forum-card">
      <h3 class="card-title">Título do fórum</h3>
      <p class="card-text">Matéria: — | Criado por: — | Data: —</p>
      <a class="card-link" href="forum-topic.php">Entrar</a>
    </article>
  </section>
</main>
<?php include_once 'rodape.php'; ?>
