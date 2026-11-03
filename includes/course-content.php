<?php
require_once __DIR__ . '/courses.php';
require_once __DIR__ . '/forum-upload.php';
const COURSE_MATERIAL_DIR = __DIR__ . '/../storage/private/materials';
function courseMaterialUpload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new InvalidArgumentException('Selecione um arquivo válido.');
    }
    $size = filesize($file['tmp_name']);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = forumAllowedMimeTypes();
    // Arquivos compactados e executáveis não são aceitos como materiais.
    unset($allowed['application/zip']);
    if (!$size || $size > FORUM_MAX_FILE_SIZE || !isset($allowed[$mime])) {
        throw new InvalidArgumentException('Envie um documento ou imagem de até 10 MB.');
    }
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!is_dir(COURSE_MATERIAL_DIR) && !mkdir(COURSE_MATERIAL_DIR,0755,true)) {
        throw new RuntimeException('Material storage unavailable.');
    }
    if (!move_uploaded_file($file['tmp_name'],COURSE_MATERIAL_DIR . '/' . $name)) {
        throw new RuntimeException('Material write failed.');
    }
    return [$name,forumSafeOriginalName($file['name']),$mime,$size];
}
function courseContentActions(PDO $pdo, array $course, array $account): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        courseAbort(403,'Sessão expirada.');
    }
    if (!courseManager($account,$course)) {
        courseAbort(403,'Você não pode gerenciar este curso.');
    }
    $back = 'classroom.php?id=' . (int) $course['id'];
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'material') {
            $title = courseText('title',100);
            $upload = courseMaterialUpload($_FILES['material'] ?? []);
            try {
                $pdo->prepare('INSERT INTO course_materials (course_id,creator_id,title,stored_name,original_name,mime_type,file_size) VALUES (?,?,?,?,?,?,?)')->execute([$course['id'],$account['id'],$title,...$upload]);
            } catch (Throwable $error) {
                unlink(COURSE_MATERIAL_DIR . '/' . $upload[0]);
                throw $error;
            }
        } elseif ($action === 'delete_material') {
            $stmt = $pdo->prepare('SELECT stored_name FROM course_materials WHERE id=? AND course_id=?');
            $stmt->execute([(int)($_POST['material_id'] ?? 0),$course['id']]);
            $name = $stmt->fetchColumn();
            $pdo->prepare('DELETE FROM course_materials WHERE id=? AND course_id=?')->execute([(int)($_POST['material_id'] ?? 0),$course['id']]);
            if ($name && preg_match('/^[a-f0-9]{32}\.[a-z0-9]+$/D',$name) && is_file(COURSE_MATERIAL_DIR . '/' . $name)) {
                unlink(COURSE_MATERIAL_DIR . '/' . $name);
            }
        } elseif ($action === 'activity') {
            $title = courseText('title',100);
            $statement = courseText('statement',10000);
            $deadline = courseText('deadline',16,false);
            $date = $deadline === '' ? null : DateTime::createFromFormat('!Y-m-d\TH:i',$deadline);
            if ($deadline !== '' && (!$date || $date->format('Y-m-d\TH:i') !== $deadline)) {
                throw new InvalidArgumentException('Prazo inválido.');
            }
            $activityId = (int)($_POST['activity_id'] ?? 0);
            if ($activityId) {
                $pdo->prepare('UPDATE course_activities SET title=?,statement=?,deadline=? WHERE id=? AND course_id=?')->execute([$title,$statement,$date ? $date->format('Y-m-d H:i:s') : null,$activityId,$course['id']]);
            } else {
                $pdo->prepare('INSERT INTO course_activities (course_id,title,statement,deadline) VALUES (?,?,?,?)')->execute([$course['id'],$title,$statement,$date ? $date->format('Y-m-d H:i:s') : null]);
            }
        } elseif ($action === 'delete_activity') {
            $pdo->prepare('DELETE FROM course_activities WHERE id=? AND course_id=?')->execute([(int)($_POST['activity_id'] ?? 0),$course['id']]);
        } else {
            throw new InvalidArgumentException('Ação inválida.');
        }
        setFlash('success','Alteração salva.');
    } catch (InvalidArgumentException $error) {
        setFlash('error',$error->getMessage());
    } catch (Throwable $error) {
        error_log('course-content: ' . $error->getMessage());
        setFlash('error','Não foi possível salvar o conteúdo.');
    }
    redirect($back);
}