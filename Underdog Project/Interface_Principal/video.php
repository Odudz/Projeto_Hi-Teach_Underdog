<?php
include_once 'conexao.php';
$pageId = 'video';
$pageTitle = 'Vídeo';
include_once 'cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="video-player-section" class="section">
    <h2 class="section-title">Título do vídeo</h2>
    <video id="video-player" class="video-player" controls></video>
    <p id="video-teacher" class="video-teacher">Professor: —</p>
    <a id="video-download" class="button button-secondary" href="#">Baixar material</a>
  </section>

  <section id="video-rating" class="section">
    <h2 class="section-title">Avalie a aula</h2>
    <form id="video-rating-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="rating-value">Nota da aula</label>
        <select id="rating-value" name="rating-value" class="form-input">
          <option value="5">5 estrela(s)</option>
          <option value="4">4 estrela(s)</option>
          <option value="3">3 estrela(s)</option>
          <option value="2">2 estrela(s)</option>
          <option value="1">1 estrela(s)</option>
        </select>
      </div>
      <button type="submit" id="video-rating-form-submit" class="button button-primary">Avaliar</button>
    </form>
  </section>

  <section id="video-comments" class="section">
    <h2 class="section-title">Comentários</h2>
    <ul id="video-comment-list" class="comment-list">
      <li class="comment-item">
        <span class="comment-author">Aluno</span>
        <p class="comment-text">Comentário de exemplo.</p>
      </li>
    </ul>
    <form id="video-comment-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="comment-text">Seu comentário</label>
        <textarea id="comment-text" name="comment-text" class="form-input"></textarea>
      </div>
      <button type="submit" id="video-comment-form-submit" class="button button-primary">Comentar</button>
    </form>
  </section>
</main>
<?php include_once 'rodape.php'; ?>
