<?php
require_once __DIR__ . '/../includes/course-content.php';
$course = courseFind($pdo,(int)($_GET['id'] ?? 0));
if (!$course) {
    courseAbort(404,'Curso não encontrado.');
}
if (!courseCanWatch($pdo,$user,$course)) {
    courseAbort($user ? 403 : 401,'Matricule-se no curso para acessar a sala.');
}
$manager = courseManager($user,$course);
courseContentActions($pdo,$course,$user);
$stmt = $pdo->prepare('SELECT * FROM course_materials WHERE course_id=? ORDER BY id DESC');
$stmt->execute([$course['id']]);
$materials = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT * FROM course_activities WHERE course_id=? ORDER BY id');
$stmt->execute([$course['id']]);
$activities = $stmt->fetchAll();
$editingActivity = null;
foreach ($activities as $item) {
    if ($manager && (int)($_GET['edit_activity'] ?? 0) === (int)$item['id']) {
        $editingActivity=$item;
    }
}
$pageId='classroom';
$pageTitle=$course['title'];
$baseUrl='../';
include __DIR__ . '/../includes/cabecalho.php';?>
<main id="main-content" class="main-content" data-course-id="<?= (int)$course['id'] ?>">
<section class="section"><a href="course.php?id=<?= (int)$course['id'] ?>">Voltar ao curso</a><h2 class="section-title">Sala: <?= e($course['title']) ?></h2>
<p>Professor: <?= e($course['teacher_name']) ?></p></section>
<section class="section"><h2 class="section-title">Materiais</h2>
<?php if (!$materials): ?><p>Nenhum material publicado.</p><?php endif; ?>
<?php foreach ($materials as $material): ?>
<article class="card"><h3><?= e($material['title']) ?></h3><a href="../endpoints/material.php?id=<?= (int)$material['id'] ?>">Baixar <?= e($material['original_name']) ?></a>
<?php if ($manager): ?>
<form method="post"><?= csrfInput() ?><input type="hidden" name="action" value="delete_material"><input type="hidden" name="material_id" value="<?= (int)$material['id'] ?>"><button class="button button-secondary" data-confirm="Excluir material?">Excluir material</button></form>
<?php endif; ?></article>
<?php endforeach; ?>
<?php if ($manager): ?>
<form class="form" method="post" enctype="multipart/form-data"><?= csrfInput() ?><input type="hidden" name="action" value="material"><label>Título<input class="form-input" name="title" maxlength="100" required></label><label>Documento ou imagem (até 10 MB)<input class="form-input" type="file" name="material" required></label><button class="button button-primary">Publicar material</button></form>
<?php endif; ?>
</section>
<section class="section"><h2 class="section-title">Atividades</h2>
<?php if (!$activities): ?><p>Nenhuma atividade publicada.</p><?php endif; ?>
<?php foreach ($activities as $activity): ?>
<article class="card"><h3><?= e($activity['title']) ?></h3>
<p>Prazo: <?= e($activity['deadline'] ?? 'Sem prazo') ?></p><a href="../activity.php?id=<?= (int)$activity['id'] ?>">Abrir atividade e respostas</a>
<?php if ($manager): ?><a href="classroom.php?id=<?= (int)$course['id'] ?>&amp;edit_activity=<?= (int)$activity['id'] ?>#activity-editor">Editar atividade</a><form method="post"><?= csrfInput() ?><input type="hidden" name="action" value="delete_activity"><input type="hidden" name="activity_id" value="<?= (int)$activity['id'] ?>"><button class="button button-secondary" data-confirm="Excluir atividade e respostas?">Excluir atividade</button></form>
<?php endif; ?></article>
<?php endforeach; ?>
<?php if ($manager): ?>
<form id="activity-editor" class="form" method="post"><?= csrfInput() ?><input type="hidden" name="action" value="activity"><input type="hidden" name="activity_id" value="<?= (int)($editingActivity['id'] ?? 0) ?>"><label>Título<input class="form-input" name="title" maxlength="100" value="<?= e($editingActivity['title'] ?? '') ?>" required></label><label>Enunciado<textarea class="form-input" name="statement" maxlength="10000" required><?= e($editingActivity['statement'] ?? '') ?></textarea></label><label>Prazo opcional<input class="form-input" type="datetime-local" name="deadline" value="<?= !empty($editingActivity['deadline']) ? e(date('Y-m-d\TH:i',strtotime($editingActivity['deadline']))) : '' ?>"></label><button class="button button-primary">Salvar atividade</button></form>
<?php endif; ?>
</section></main>
<?php include __DIR__ . '/../includes/rodape.php'; ?>
