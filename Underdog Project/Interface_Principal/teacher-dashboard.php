<?php
include_once 'conexao.php';

if (!$user || $user['role'] !== 'teacher') {
    $gate = $user ? 'teacher' : 'login';
    include_once 'acesso-restrito.php';
}

$pageId = 'teacher-dashboard';
$pageTitle = 'Painel do professor';
include_once 'cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="teacher-videos" class="section">
    <h2 class="section-title">Meus vídeos</h2>
    <form id="video-upload-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="video-title">Título</label>
        <input type="text" id="video-title" name="video-title" class="form-input">
      </div>
      <div class="form-group">
        <label class="form-label" for="video-file">Vídeo</label>
        <input type="file" id="video-file" name="video-file" class="form-input">
      </div>
      <div class="form-group">
        <label class="form-label" for="video-subject">Matéria</label>
        <select id="video-subject" name="video-subject" class="form-input">
          <option value="">Selecione</option>
        </select>
      </div>
      <button type="submit" id="video-upload-form-submit" class="button button-primary">Publicar vídeo</button>
    </form>
    <table id="teacher-video-table" class="table">
      <thead>
        <tr>
          <th class="table-head">Título</th>
          <th class="table-head">Matéria</th>
          <th class="table-head">Ações</th>
        </tr>
      </thead>
      <tbody>
        <tr class="table-row">
          <td class="table-cell">—</td>
          <td class="table-cell">—</td>
          <td class="table-cell">
            <button type="button" id="edit-video" class="button button-secondary">Editar</button>
            <button type="button" id="delete-video" class="button button-secondary">Excluir</button>
          </td>
        </tr>
      </tbody>
    </table>
  </section>

  <section id="teacher-lives" class="section">
    <h2 class="section-title">Minhas transmissões</h2>
    <form id="live-create-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="live-title">Título</label>
        <input type="text" id="live-title" name="live-title" class="form-input">
      </div>
      <div class="form-group">
        <label class="form-label" for="live-start">Início</label>
        <input type="datetime-local" id="live-start" name="live-start" class="form-input">
      </div>
      <div class="form-group">
        <label class="form-label" for="live-url">Link</label>
        <input type="url" id="live-url" name="live-url" class="form-input">
      </div>
      <button type="submit" id="live-create-form-submit" class="button button-primary">Agendar aula ao vivo</button>
    </form>
    <table id="teacher-live-table" class="table">
      <thead>
        <tr>
          <th class="table-head">Título</th>
          <th class="table-head">Início</th>
          <th class="table-head">Ações</th>
        </tr>
      </thead>
      <tbody>
        <tr class="table-row">
          <td class="table-cell">—</td>
          <td class="table-cell">—</td>
          <td class="table-cell">
            <button type="button" id="start-live" class="button button-secondary">Iniciar</button>
            <button type="button" id="edit-live" class="button button-secondary">Editar</button>
          </td>
        </tr>
      </tbody>
    </table>
  </section>

  <section id="teacher-rooms" class="section">
    <h2 class="section-title">Salas virtuais</h2>
    <form id="room-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="room-name">Nome da sala</label>
        <input type="text" id="room-name" name="room-name" class="form-input">
      </div>
      <div class="form-group">
        <label class="form-label" for="room-schedule">Horário limite</label>
        <input type="datetime-local" id="room-schedule" name="room-schedule" class="form-input">
      </div>
      <button type="submit" id="room-form-submit" class="button button-primary">Criar sala</button>
    </form>

    <form id="room-material-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="material-title">Título do material</label>
        <input type="text" id="material-title" name="material-title" class="form-input">
      </div>
      <div class="form-group">
        <label class="form-label" for="material-file">Arquivo</label>
        <input type="file" id="material-file" name="material-file" class="form-input">
      </div>
      <button type="submit" id="room-material-form-submit" class="button button-primary">Publicar material</button>
    </form>

    <form id="room-activity-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="activity-title">Título</label>
        <input type="text" id="activity-title" name="activity-title" class="form-input">
      </div>
      <div class="form-group">
        <label class="form-label" for="activity-statement">Enunciado</label>
        <textarea id="activity-statement" name="activity-statement" class="form-input"></textarea>
      </div>
      <button type="submit" id="room-activity-form-submit" class="button button-primary">Publicar atividade</button>
    </form>
  </section>

  <section id="teacher-answers" class="section">
    <h2 class="section-title">Respostas dos alunos</h2>
    <table id="answer-table" class="table">
      <thead>
        <tr>
          <th class="table-head">Aluno</th>
          <th class="table-head">Atividade</th>
          <th class="table-head">Ações</th>
        </tr>
      </thead>
      <tbody>
        <tr class="table-row">
          <td class="table-cell">—</td>
          <td class="table-cell">—</td>
          <td class="table-cell">
            <button type="button" id="grade-answer" class="button button-secondary">Avaliar</button>
          </td>
        </tr>
      </tbody>
    </table>
  </section>

  <section id="teacher-forums" class="section">
    <h2 class="section-title">Criar fórum</h2>
    <form id="forum-create-form" class="form" action="#" method="post">
      <div class="form-group">
        <label class="form-label" for="forum-title">Título</label>
        <input type="text" id="forum-title" name="forum-title" class="form-input" maxlength="80">
      </div>
      <div class="form-group">
        <label class="form-label" for="forum-subject">Matéria</label>
        <select id="forum-subject" name="forum-subject" class="form-input">
          <option value="">Selecione</option>
        </select>
      </div>
      <button type="submit" id="forum-create-form-submit" class="button button-primary">Criar fórum</button>
    </form>
  </section>
</main>
<?php include_once 'rodape.php'; ?>
