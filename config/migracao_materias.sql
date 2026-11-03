
-- Estrutura adicional para cursos, aulas, materiais e atividades.
-- Cria as tabelas de cursos que ainda não existirem, preservando os dados.
CREATE TABLE IF NOT EXISTS courses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    subject_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    title VARCHAR(100) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_courses_subject(subject_id,is_published),
    KEY idx_courses_teacher(teacher_id,created_at),
    CONSTRAINT fk_course_subject FOREIGN KEY(subject_id) REFERENCES subjects(id),
    CONSTRAINT fk_course_teacher FOREIGN KEY(teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS lessons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    title VARCHAR(100) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    video_path VARCHAR(120) NULL,
    video_mime VARCHAR(60) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_lessons_course(course_id,is_published,created_at),
    KEY idx_lessons_teacher(teacher_id,created_at),
    CONSTRAINT fk_lesson_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_lesson_teacher FOREIGN KEY(teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS course_enrollments (
    course_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    enrolled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(course_id,student_id),
    KEY idx_enrollments_student(student_id,enrolled_at),
    CONSTRAINT fk_enrollment_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_enrollment_student FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Materiais, atividades, respostas e progresso vinculados aos cursos.
CREATE TABLE IF NOT EXISTS course_materials (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    creator_id INT UNSIGNED NOT NULL,
    title VARCHAR(100) NOT NULL,
    stored_name VARCHAR(64) NOT NULL,
    original_name VARCHAR(180) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_material_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_material_creator FOREIGN KEY(creator_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS course_activities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    title VARCHAR(100) NOT NULL,
    statement TEXT NOT NULL,
    deadline DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS activity_submissions (
    activity_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    answer TEXT NOT NULL,
    grade DECIMAL(4,2) NULL,
    feedback VARCHAR(500) NOT NULL DEFAULT '',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY(activity_id,student_id),
    CONSTRAINT fk_submission_activity FOREIGN KEY(activity_id) REFERENCES course_activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_submission_student FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS lesson_progress (
    lesson_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(lesson_id,student_id),
    CONSTRAINT fk_progress_lesson FOREIGN KEY(lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
    CONSTRAINT fk_progress_student FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE lessons ADD COLUMN IF NOT EXISTS youtube_video_id CHAR(11) NULL;
ALTER TABLE lessons ADD COLUMN IF NOT EXISTS display_order INT UNSIGNED NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_lesson_order ON lessons (course_id, display_order, id);
