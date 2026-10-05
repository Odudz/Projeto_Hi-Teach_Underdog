<?php
include_once 'conexao.php';
$pageId = 'forum-topic';
$pageTitle = 'Tópico do fórum';
include_once 'cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="topic-header" class="section">
    <h2 class="section-title">Título do fórum</h2>
    <p id="topic-info" class="topic-info">Matéria — Criado por —</p>
  </section>

  <section id="topic-messages" class="section">
    <h2 class="section-title">Mensagens</h2>
    <ul id="message-list" class="message-list">
      <li class="message-item">
        <span class="message-author">Autor</span>
        <p class="message-text">Mensagem.</p>
      </li>
    </ul>
  </section>

  <section id="topic-reply" class="section">
    <h2 class="section-title">Responder</h2>
    <form id="reply-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="reply-text">Mensagem</label>
        <textarea id="reply-text" name="reply-text" class="form-input"></textarea>
      </div>
      <button type="submit" id="reply-form-submit" class="button button-primary">Enviar</button>
    </form>
  </section>
</main>
<?php include_once 'rodape.php'; ?>
