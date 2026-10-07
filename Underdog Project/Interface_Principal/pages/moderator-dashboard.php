<?php
include_once __DIR__ . '/../config/conexao.php';

if (!$user || $user['role'] !== 'admin') {
    $gate = $user ? 'admin' : 'login';
    $baseUrl = '../';
    include_once __DIR__ . '/../includes/acesso-restrito.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['forum_topic_id'])) {
        $pdo->prepare('DELETE FROM forum_topics WHERE id = ?')->execute([(int) $_POST['forum_topic_id']]);
        setFlash('success', 'Tópico excluído.');
        redirect('moderator-dashboard.php#mod-forums');
    }

    $teacherId = (int) ($_POST['user_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';

    if (!$teacherId || $teacherId === (int) $user['id'] || !in_array($decision, ['approve', 'reject'], true)) {
        setFlash('error', 'Solicitação inválida.');
        redirect('moderator-dashboard.php');
    }

    $stmt = $pdo->prepare("UPDATE teacher_profiles SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$decision === 'approve' ? 'approved' : 'rejected', $user['id'], $teacherId]);

    if ($stmt->rowCount() === 0) {
        setFlash('error', 'Essa solicitação já foi analisada.');
    } else {
        if ($decision === 'approve') {
            $pdo->prepare("UPDATE users SET role = 'teacher' WHERE id = ? AND role = 'student'")->execute([$teacherId]);
        }
        setFlash('success', $decision === 'approve' ? 'Professor aprovado.' : 'Solicitação recusada.');
    }
    redirect('moderator-dashboard.php');
}

$pending = $pdo->query(
    "SELECT u.id AS user_id, u.name, u.email, t.specializations
     FROM teacher_profiles t JOIN users u ON u.id = t.user_id
     WHERE t.status = 'pending' ORDER BY u.created_at"
)->fetchAll();

$forumTopics = $pdo->query(
    'SELECT t.id, t.title, u.name AS author
     FROM forum_topics t JOIN users u ON u.id = t.user_id
     ORDER BY t.created_at DESC LIMIT 20'
)->fetchAll();

$pageId = 'moderator-dashboard';
$pageTitle = 'Painel do moderador';
$baseUrl = '../';
include_once __DIR__ . '/../includes/cabecalho.php';
?>
<main id="main-content" class="main-content">
  <section id="pending-teachers" class="section">
    <h2 class="section-title">Professores aguardando aprovação</h2>
    <?php if (!$pending): ?>
      <p id="pending-empty" class="form-note">Nenhuma solicitação pendente.</p>
    <?php else: ?>
      <table id="pending-teachers-table" class="table">
        <thead>
          <tr>
            <th class="table-head">Nome</th>
            <th class="table-head">E-mail</th>
            <th class="table-head">Especializações</th>
            <th class="table-head">Comprovante</th>
            <th class="table-head">Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($pending as $teacher): ?>
          <tr class="table-row">
            <td class="table-cell"><?= e($teacher['name']) ?></td>
            <td class="table-cell"><?= e($teacher['email']) ?></td>
            <td class="table-cell"><?= e($teacher['specializations']) ?></td>
            <td class="table-cell"><a class="card-link" href="../endpoints/teacher-proof.php?user=<?= (int) $teacher['user_id'] ?>" target="_blank" rel="noopener noreferrer">Ver comprovante</a></td>
            <td class="table-cell">
              <form class="inline-form" action="moderator-dashboard.php" method="post">
                <input type="hidden" name="user_id" value="<?= (int) $teacher['user_id'] ?>">
                <button type="submit" name="decision" value="approve" class="button button-primary">Aprovar</button>
                <button type="submit" name="decision" value="reject" class="button button-danger">Recusar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <?php
  $moderationSections = [
      ['videos', 'Vídeos'],
      ['lives', 'Transmissões ao vivo'],
      ['rooms', 'Salas virtuais'],
      ['materials', 'Materiais'],
      ['activities', 'Atividades'],
      ['forums', 'Fóruns e mensagens'],
      ['comments', 'Comentários'],
  ];
  foreach ($moderationSections as [$sectionId, $sectionTitle]):
  ?>
  <?php if ($sectionId === 'forums'): ?>
    <section id="mod-forums" class="section">
      <h2 class="section-title"><?= $sectionTitle ?></h2>
      <?php if (!$forumTopics): ?>
        <p id="mod-forums-empty" class="form-note">Nenhum tópico criado ainda.</p>
      <?php else: ?>
        <table id="mod-forums-table" class="table">
          <thead>
            <tr>
              <th class="table-head">Título</th>
              <th class="table-head">Autor</th>
              <th class="table-head">Ações</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($forumTopics as $topic): ?>
            <tr class="table-row">
              <td class="table-cell"><?= e($topic['title']) ?></td>
              <td class="table-cell"><?= e($topic['author']) ?></td>
              <td class="table-cell">
                <a class="card-link" href="forum-topic.php?id=<?= (int) $topic['id'] ?>">Revisar</a>
                <form class="inline-form" action="moderator-dashboard.php" method="post">
                  <input type="hidden" name="forum_topic_id" value="<?= (int) $topic['id'] ?>">
                  <button type="submit" class="button button-danger"
                          onclick="return confirm('Excluir este tópico e todas as mensagens?');">Excluir</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  <?php continue; endif; ?>
    <section id="mod-<?= $sectionId ?>" class="section">
      <h2 class="section-title"><?= $sectionTitle ?></h2>
      <table id="mod-<?= $sectionId ?>-table" class="table">
        <thead>
          <tr>
            <th class="table-head">Título</th>
            <th class="table-head">Autor</th>
            <th class="table-head">Ações</th>
          </tr>
        </thead>
        <tbody>
          <tr class="table-row">
            <td class="table-cell">—</td>
            <td class="table-cell">—</td>
            <td class="table-cell">
              <button type="button" id="review-mod-<?= $sectionId ?>" class="button button-secondary">Revisar</button>
              <button type="button" id="edit-mod-<?= $sectionId ?>" class="button button-secondary">Editar</button>
              <button type="button" id="delete-mod-<?= $sectionId ?>" class="button button-danger">Excluir</button>
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  <?php endforeach; ?>
</main>
<?php include_once __DIR__ . '/../includes/rodape.php'; ?>
