<?php
require_once __DIR__ . '/../includes/courses.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if (!$user) {
    http_response_code(401);
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit;
}
$id = (int) ($_POST['course_id'] ?? 0);
$action = $_POST['action'] ?? '';
$course = $id ? courseFind($pdo, $id) : null;
$back = $id ? '../pages/course.php?id=' . $id : '../pages/subjects.php';
if ($action !== 'create' && !$course) {
    http_response_code(404);
    exit;
}
try {
    if (in_array($action, ['enroll', 'leave'], true)) {
        if ($user['role'] !== 'student' || !$course['is_published']) {
            http_response_code(403);
            exit;
        }
        $sql = $action === 'enroll'
            ? 'INSERT IGNORE INTO course_enrollments (course_id,student_id) VALUES (?,?)'
            : 'DELETE FROM course_enrollments WHERE course_id=? AND student_id=?';
        $pdo->prepare($sql)->execute([$id, $user['id']]);
    } else {
        if (($action === 'create' && !in_array($user['role'], ['teacher', 'admin'], true)) ||
            ($action !== 'create' && !courseManager($user, $course))) {
            http_response_code(403);
            exit;
        }
        if (in_array($action, ['create', 'update'], true)) {
            $title = courseText('title', 100);
            $description = courseText('description', 500, false);
            $subject = (int) ($_POST['subject_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT 1 FROM subjects WHERE id=?');
            $stmt->execute([$subject]);
            if (!$stmt->fetchColumn()) {
                throw new InvalidArgumentException('Selecione uma matéria válida.');
            }
            $published = isset($_POST['is_published']) ? 1 : 0;
            if ($action === 'create') {
                $pdo->prepare('INSERT INTO courses (subject_id,teacher_id,title,description,is_published) VALUES (?,?,?,?,?)')->execute([$subject,$user['id'],$title,$description,$published]);
                $back = '../pages/course.php?id=' . $pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE courses SET subject_id=?,title=?,description=?,is_published=? WHERE id=?')->execute([$subject,$title,$description,$published,$id]);
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare('SELECT stored_name FROM course_materials WHERE course_id=?');
            $stmt->execute([$id]);
            $materialFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare('DELETE FROM courses WHERE id=?')->execute([$id]);
            foreach ($materialFiles as $name) {
                if (preg_match('/^[a-f0-9]{32}\.[a-z0-9]+$/D',$name)) {
                    $file = __DIR__ . '/../storage/private/materials/' . $name;
                    if (is_file($file) && !unlink($file)) {
                        error_log('course.php: material cleanup failed');
                    }
                }
            }
            $back = '../pages/subjects.php';
        } elseif (in_array($action, ['save_lesson', 'delete_lesson'], true)) {
            $lessonId = (int) ($_POST['lesson_id'] ?? 0);
            if ($lessonId) {
                $stmt = $pdo->prepare('SELECT id FROM lessons WHERE id=? AND course_id=?');
                $stmt->execute([$lessonId,$id]);
                if (!$stmt->fetchColumn()) {
                    http_response_code(404);
                    exit;
                }
            }
            if ($action === 'delete_lesson') {
                $pdo->prepare('DELETE FROM lessons WHERE id=? AND course_id=?')->execute([$lessonId,$id]);
            } else {
                $title = courseText('title', 100);
                $description = courseText('description', 500, false);
                $videoId = youtubeVideoId(courseText('youtube_url', 500));
                $order = filter_var($_POST['display_order'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>1000000]]);
                if (!$videoId || $order === false) {
                    throw new InvalidArgumentException('Informe uma URL válida do YouTube e uma ordem entre 0 e 1000000.');
                }
                $published = isset($_POST['is_published']) ? 1 : 0;
                if ($lessonId) {
                    $pdo->prepare('UPDATE lessons SET title=?,description=?,youtube_video_id=?,display_order=?,is_published=? WHERE id=? AND course_id=?')->execute([$title,$description,$videoId,$order,$published,$lessonId,$id]);
                } else {
                    $pdo->prepare('INSERT INTO lessons (course_id,teacher_id,title,description,youtube_video_id,display_order,is_published) VALUES (?,?,?,?,?,?,?)')->execute([$id,$user['id'],$title,$description,$videoId,$order,$published]);
                }
            }
        } else {
            throw new InvalidArgumentException('Ação inválida.');
        }
    }
    setFlash('success', 'Alteração salva.');
} catch (InvalidArgumentException $error) {
    setFlash('error', $error->getMessage());
} catch (Throwable $error) {
    error_log('course.php: ' . $error->getMessage());
    setFlash('error', 'Não foi possível salvar. Tente novamente.');
}
redirect($back);