<?php
include_once 'conexao.php';
$pageId = 'live-class';
$pageTitle = 'Aulas ao vivo';
include_once 'cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="live-player-section" class="section">
    <h2 class="section-title">Aula ao vivo</h2>
    <div id="live-player" class="live-player"></div>
    <p id="live-title" class="live-title">Título da aula</p>
  </section>

  <section id="live-chat" class="section">
    <h2 class="section-title">Chat da aula</h2>
    <ul id="live-comment-list" class="comment-list"></ul>
    <form id="live-comment-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="live-comment">Mensagem</label>
        <input type="text" id="live-comment" name="live-comment" class="form-input">
      </div>
      <button type="submit" id="live-comment-form-submit" class="button button-primary">Enviar</button>
    </form>
  </section>

  <section id="live-schedule" class="section">
    <h2 class="section-title">Próximas aulas</h2>
    <ul id="live-list" class="live-list">
      <li class="live-item">
        <span class="live-item-title">Título</span>
        <time class="live-item-date">dd/mm/aaaa hh:mm</time>
        <span class="live-item-teacher">Professor</span>
      </li>
    </ul>
  </section>
</main>
<?php include_once 'rodape.php'; ?>
