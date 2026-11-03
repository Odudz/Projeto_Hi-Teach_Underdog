<?php
require_once __DIR__ . '/../includes/courses.php';
$subjects = $pdo->query('SELECT * FROM subjects ORDER BY name')->fetchAll();
$subjectId = (int) ($_GET['subject_id'] ?? 0);
$stmt = $pdo->prepare('SELECT c.*,s.name AS subject_name,u.name AS teacher_name FROM courses c JOIN subjects s ON s.id=c.subject_id JOIN users u ON u.id=c.teacher_id AND u.activate=1 WHERE (c.is_published=1 OR c.teacher_id=? OR ?=1) AND (?=0 OR c.subject_id=?) ORDER BY c.created_at DESC,c.id DESC');
$stmt->execute([$user['id'] ?? 0, ($user['role'] ?? '') === 'admin' ? 1 : 0,$subjectId,$subjectId]);
$courses = $stmt->fetchAll();
$pageId = 'subjects';
$pageTitle = 'Matérias';
$baseUrl = '../';
$extraCss = ['forum', 'courses'];
include __DIR__ . '/../includes/cabecalho.php';?>
<main id="main-content" class="main-content courses-content">
<section class="section forum-hero">
  <span class="forum-hero-deco forum-hero-deco-a" aria-hidden="true"></span>
  <span class="forum-hero-deco forum-hero-deco-b" aria-hidden="true"></span>
  <span class="forum-hero-deco forum-hero-deco-c" aria-hidden="true"></span>
  <h1 class="forum-hero-title">Seu espaço de aprendizado</h1>
  <p class="forum-hero-lead">Explore as matérias, encontre seu próximo curso e continue aprendendo no seu ritmo.</p>
  <ul class="forum-stats" aria-label="Resumo das matérias e cursos">
    <li class="forum-stat"><strong><?= count($subjects) ?></strong><span>matérias</span></li>
    <li class="forum-stat"><strong><?= count($courses) ?></strong><span>cursos nesta seleção</span></li>
  </ul>
  <div class="forum-hero-tools"><a class="button button-primary" href="#course-list">Explorar cursos</a><?php if ($user && in_array($user['role'], ['teacher','admin'], true)): ?><a class="button button-secondary" href="#course-create">Criar curso</a><?php endif; ?></div>
</section>
<section id="subject-list" class="section forum-shelf"><div class="courses-section-heading"><h2 class="section-title">Matérias <span class="dm-count"><?= count($subjects) ?></span></h2><a class="forum-filter<?= $subjectId === 0 ? ' is-active' : '' ?>" href="subjects.php"<?= $subjectId === 0 ? ' aria-current="page"' : '' ?>>Todas as matérias</a></div>
<div class="forum-grid subject-grid">
<?php foreach ($subjects as $index => $subject): ?>
<article class="forum-tile subject-card<?= $subjectId === (int)$subject['id'] ? ' is-selected' : '' ?>" style="--i: <?= min($index, 8) ?>">
  <div class="forum-tile-top"><span class="course-subject-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6c-3-3-7-3-10-2v15c3-1 7-1 10 2 3-3 7-3 10-2V4c-3-1-7-1-10 2Z"/><path d="M12 6v15"/></svg></span><?php if ($subjectId === (int)$subject['id']): ?><span class="forum-chip">Selecionada</span><?php endif; ?></div>
  <h3 class="forum-tile-title"><a class="forum-tile-link" href="subjects.php?subject_id=<?= (int)$subject['id'] ?>#course-list"<?= $subjectId === (int)$subject['id'] ? ' aria-current="page"' : '' ?>><?= e($subject['name']) ?></a></h3>
  <p class="forum-tile-desc"><?= e($subject['description']) ?></p>
  <div class="forum-tile-foot"><span>Ver cursos →</span><a class="course-secondary-link" href="forum.php?subject=<?= (int)$subject['id'] ?>">Fóruns</a></div>
</article>
<?php endforeach; ?></div></section>
<section id="course-list" class="section forum-shelf"><h2 class="section-title">Cursos <span class="dm-count"><?= count($courses) ?></span></h2>
<?php if (!$courses): ?>
<div class="forum-empty"><p>Nenhum curso disponível nesta matéria.</p><a class="button button-secondary" href="subjects.php#course-list">Ver todos os cursos</a></div>
<?php endif; ?>
<div class="forum-grid">
<?php foreach ($courses as $course): ?>
<article class="forum-tile course-card">
  <div class="forum-tile-top"><span class="forum-chip forum-chip-subject"><?= e($course['subject_name']) ?></span><?php if (!$course['is_published']): ?><span class="forum-chip">Rascunho</span><?php endif; ?><?php if ($user && courseEnrolled($pdo, (int)$course['id'], (int)$user['id'])): ?><span class="forum-chip forum-chip-status">Matriculado</span><?php endif; ?></div>
  <h3 class="forum-tile-title"><a class="forum-tile-link" href="course.php?id=<?= (int)$course['id'] ?>"><?= e($course['title']) ?></a></h3>
  <p class="forum-tile-desc"><?= e($course['description']) ?></p>
  <div class="forum-tile-foot"><span class="forum-tile-author"><span class="tile-avatar tile-avatar-initials" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($course['teacher_name'],0,1))) ?></span><span>Professor: <?= e($course['teacher_name']) ?></span></span><span>Abrir curso →</span></div>
</article>
<?php endforeach; ?></div></section>
<?php if ($user && in_array($user['role'], ['teacher','admin'], true)): ?>
<section id="course-create" class="section course-form-panel"><h2 class="section-title">Criar curso</h2>
<form class="form" method="post" action="../endpoints/course.php">
<?= csrfInput() ?><input type="hidden" name="action" value="create">
<label>Título<input class="form-input" name="title" maxlength="100" required></label>
<label>Descrição<textarea class="form-input" name="description" maxlength="500"></textarea></label>
<label>Matéria<select class="form-input" name="subject_id" required><?php foreach ($subjects as $subject): ?><option value="<?= (int) $subject['id'] ?>"><?= e($subject['name']) ?></option><?php endforeach; ?></select></label>
<label><input type="checkbox" name="is_published" checked> Publicado</label><button class="button button-primary">Criar curso</button>
</form></section>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/rodape.php'; ?>
