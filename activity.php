<?php
require_once __DIR__ . '/includes/courses.php';
$stmt = $pdo->prepare('SELECT * FROM course_activities WHERE id=?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$activity = $stmt->fetch();
if (!$activity) {
    http_response_code(404);
    exit('Atividade não encontrada.');
}
$course = courseFind($pdo,(int)$activity['course_id']);
if (!$course || !courseCanWatch($pdo,$user,$course)) {
    http_response_code($user ? 403 : 401);
    exit('Acesso restrito ao curso.');
}
$manager = courseManager($user,$course);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit;
    }
    try {
        if (($_POST['action'] ?? '') === 'answer') {
            if ($manager || $user['role'] !== 'student') {
                http_response_code(403);
                exit;
            }
            if ($activity['deadline'] && strtotime($activity['deadline']) < time()) {
                throw new InvalidArgumentException('O prazo desta atividade terminou.');
            }
            $answer = courseText('answer',10000);
            $pdo->prepare('INSERT INTO activity_submissions (activity_id,student_id,answer) VALUES (?,?,?) ON DUPLICATE KEY UPDATE answer=VALUES(answer),grade=NULL,feedback=\'\'')->execute([$activity['id'],$user['id'],$answer]);
        } elseif (($_POST['action'] ?? '') === 'grade' && $manager) {
            $grade = filter_var($_POST['grade'] ?? '',FILTER_VALIDATE_FLOAT);
            if ($grade === false || $grade < 0 || $grade > 10) {
                throw new InvalidArgumentException('A nota deve estar entre 0 e 10.');
            }
            $pdo->prepare('UPDATE activity_submissions SET grade=?,feedback=? WHERE activity_id=? AND student_id=?')->execute([$grade,courseText('feedback',500,false),$activity['id'],(int)($_POST['student_id'] ?? 0)]);
        } else {
            http_response_code(403);
            exit;
        }
        setFlash('success','Resposta salva.');
    } catch (InvalidArgumentException $error) {
        setFlash('error',$error->getMessage());
    }
    redirect('activity.php?id=' . (int)$activity['id']);
}
$stmt = $pdo->prepare('SELECT a.*,u.name AS student_name FROM activity_submissions a JOIN users u ON u.id=a.student_id WHERE a.activity_id=? AND (?=1 OR a.student_id=?) ORDER BY u.name');
$stmt->execute([$activity['id'],$manager ? 1 : 0,$user['id']]);
$answers = $stmt->fetchAll();
$pageId='activity';
$pageTitle=$activity['title'];
$baseUrl='';
include __DIR__ . '/includes/cabecalho.php';?>
<main id="main-content" class="main-content" data-course-id="<?= (int)$course['id'] ?>"><section class="section"><a href="pages/classroom.php?id=<?= (int)$course['id'] ?>">Voltar à sala</a><h2 class="section-title"><?= e($activity['title']) ?></h2>
<p><?= nl2br(e($activity['statement'])) ?></p>
<p>Prazo: <?= e($activity['deadline'] ?? 'Sem prazo') ?></p></section>
<?php if (!$manager): ?>
<section class="section"><h2 class="section-title">Sua resposta</h2>
<form class="form" method="post"><?= csrfInput() ?><input type="hidden" name="action" value="answer"><label>Resposta<textarea class="form-input" name="answer" maxlength="10000" required><?= e($answers[0]['answer'] ?? '') ?></textarea></label><button class="button button-primary">Salvar resposta</button></form></section>
<?php endif; ?>
<section class="section"><h2 class="section-title">Respostas e avaliação</h2><?php foreach ($answers as $answer): ?>
<article class="card"><h3><?= e($answer['student_name']) ?></h3>
<p><?= nl2br(e($answer['answer'])) ?></p>
<p>Nota: <?= e($answer['grade'] ?? 'Aguardando avaliação') ?></p>
<p><?= e($answer['feedback']) ?></p>
<?php if ($manager): ?>
<form class="form" method="post"><?= csrfInput() ?><input type="hidden" name="action" value="grade"><input type="hidden" name="student_id" value="<?= (int)$answer['student_id'] ?>"><label>Nota (0 a 10)<input class="form-input" name="grade" type="number" min="0" max="10" step="0.01" value="<?= e($answer['grade']) ?>" required></label><label>Comentário<textarea class="form-input" name="feedback" maxlength="500"><?= e($answer['feedback']) ?></textarea></label><button class="button button-primary">Avaliar resposta</button></form>
<?php endif; ?></article>
<?php endforeach; ?></section></main>
<?php include __DIR__ . '/includes/rodape.php'; ?>
