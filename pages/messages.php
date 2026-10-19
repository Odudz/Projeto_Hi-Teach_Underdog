<?php
include_once __DIR__ . '/../config/conexao.php';
$pageId = 'messages';
$pageTitle = 'Mensagens';
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="contact-list-section" class="section">
    <h2 class="section-title">Conversas</h2>
    <ul id="contact-list" class="contact-list">
      <li class="contact-item">Contato</li>
    </ul>
  </section>

  <section id="conversation" class="section">
    <h2 class="section-title">Conversa</h2>
    <ul id="chat-message-list" class="message-list"></ul>

    <form id="chat-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="chat-text">Mensagem</label>
        <input type="text" id="chat-text" name="chat-text" class="form-input">
      </div>
      <button type="submit" id="chat-form-submit" class="button button-primary">Enviar</button>
    </form>
  </section>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
