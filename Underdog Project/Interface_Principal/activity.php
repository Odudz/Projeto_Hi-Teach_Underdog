<?php
include_once 'conexao.php';
$pageId = 'activity';
$pageTitle = 'Atividade';
include_once 'cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="activity-detail" class="section">
    <h2 class="section-title">Título da atividade</h2>
    <p id="activity-statement" class="activity-statement">Enunciado.</p>
    <p id="activity-deadline" class="activity-deadline">Prazo: —</p>
  </section>

  <section id="activity-answer" class="section">
    <h2 class="section-title">Sua resposta</h2>
    <form id="answer-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="answer-text">Resposta</label>
        <textarea id="answer-text" name="answer-text" class="form-input"></textarea>
      </div>

      <div class="form-group">
        <label class="form-label" for="answer-file">Anexo</label>
        <input type="file" id="answer-file" name="answer-file" class="form-input">
      </div>

      <button type="submit" id="answer-form-submit" class="button button-primary">Enviar resposta</button>
    </form>
  </section>

  <section id="activity-rating" class="section">
    <h2 class="section-title">Avalie a atividade</h2>
    <form id="activity-rating-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="rating-value">Nota da atividade</label>
        <select id="rating-value" name="rating-value" class="form-input">
          <option value="5">5 estrela(s)</option>
          <option value="4">4 estrela(s)</option>
          <option value="3">3 estrela(s)</option>
          <option value="2">2 estrela(s)</option>
          <option value="1">1 estrela(s)</option>
        </select>
      </div>

      <button type="submit" id="activity-rating-form-submit" class="button button-primary">Avaliar</button>
    </form>
  </section>
</main>
<?php include_once 'rodape.php'; ?>
