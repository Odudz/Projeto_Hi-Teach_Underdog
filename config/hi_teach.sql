-- ============================================================
-- Hi Teach — SQL Completo (27 tabelas + migrações + dados iniciais)
-- Compatível com MySQL 5.7 / 8.0 e MariaDB 10.4+ (LAMPP / XAMPP / InfinityFree)
-- ============================================================

-- Em localhost, se ainda não criou o banco, descomente as 2 linhas abaixo:
-- CREATE DATABASE IF NOT EXISTS `hi_teach` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `hi_teach`;

/* CASO VOCÊ QUEIRA CRIAR UM USUÁRIO DE BANCO LOCAL, COLOQUE OS VALORES ABAIXO:
CREATE USER IF NOT EXISTS 'EMAIL_TESTE'@'localhost' IDENTIFIED BY 'SENHA_DESEJADA';
CREATE USER IF NOT EXISTS 'EMAIL_TESTE'@'127.0.0.1' IDENTIFIED BY 'SENHA_DESEJADA';
GRANT ALL PRIVILEGES ON hi_teach.* TO 'EMAIL_TESTE'@'localhost';
GRANT ALL PRIVILEGES ON hi_teach.* TO 'EMAIL_TESTE'@'127.0.0.1';
FLUSH PRIVILEGES;
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. USUÁRIOS, SEGURANÇA E PERFIS
-- ============================================================

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `birth_date` DATE NOT NULL,
    `cpf` CHAR(11) NOT NULL,
    `email` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `bio` TEXT DEFAULT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('student', 'teacher', 'moderator', 'admin') NOT NULL DEFAULT 'student',
    `activate` TINYINT(1) NOT NULL DEFAULT 1,
    `deactivated_by` ENUM('user', 'admin') DEFAULT NULL,
    `recovery_phrase_hash` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_at` TIMESTAMP NULL DEFAULT NULL,
    `avatar_data` MEDIUMBLOB DEFAULT NULL,
    `avatar_mime` VARCHAR(100) DEFAULT NULL,
    `banner_data` MEDIUMBLOB DEFAULT NULL,
    `banner_mime` VARCHAR(100) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    UNIQUE KEY `uq_users_cpf` (`cpf`),
    KEY `idx_users_role_active` (`role`, `activate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip` VARBINARY(16) NOT NULL,
    `email_hash` CHAR(64) NOT NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attempts_ip` (`ip`, `attempted_at`),
    KEY `idx_attempts_email` (`email_hash`, `attempted_at`),
    KEY `idx_attempts_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_profiles` (
    `user_id` INT UNSIGNED NOT NULL,
    `enrollment_number` VARCHAR(50) NOT NULL,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_student_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teacher_profiles` (
    `user_id` INT UNSIGNED NOT NULL,
    `specializations` TEXT DEFAULT NULL,
    `proof_path` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `reviewed_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    KEY `idx_tp_status` (`status`),
    KEY `fk_teacher_reviewer` (`reviewed_by`),
    CONSTRAINT `fk_teacher_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_teacher_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teacher_proofs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_proofs_user` (`user_id`),
    CONSTRAINT `fk_proofs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. MATÉRIAS, CURSOS, AULAS, SALA VIRTUAL E AVALIAÇÕES
-- ============================================================

CREATE TABLE IF NOT EXISTS `subjects` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_subjects_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subject_enrollments` (
    `subject_id` INT UNSIGNED NOT NULL,
    `student_id` INT UNSIGNED NOT NULL,
    `enrolled_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`subject_id`, `student_id`),
    KEY `idx_se_student` (`student_id`),
    CONSTRAINT `fk_se_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_se_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `courses` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_id` INT UNSIGNED NOT NULL,
    `teacher_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(120) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `is_published` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_courses_subject` (`subject_id`),
    KEY `idx_courses_teacher` (`teacher_id`),
    CONSTRAINT `fk_courses_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_courses_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lessons` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_id` INT UNSIGNED NOT NULL,
    `teacher_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(120) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `youtube_video_id` VARCHAR(32) NOT NULL,
    `display_order` INT NOT NULL DEFAULT 0,
    `is_published` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lessons_course` (`course_id`, `display_order`),
    KEY `idx_lessons_teacher` (`teacher_id`),
    CONSTRAINT `fk_lessons_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lessons_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lesson_progress` (
    `lesson_id` INT UNSIGNED NOT NULL,
    `student_id` INT UNSIGNED NOT NULL,
    `completed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`lesson_id`, `student_id`),
    KEY `idx_lp_student` (`student_id`),
    CONSTRAINT `fk_lp_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `lessons` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lp_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `course_enrollments` (
    `course_id` INT UNSIGNED NOT NULL,
    `student_id` INT UNSIGNED NOT NULL,
    `enrolled_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`course_id`, `student_id`),
    KEY `idx_ce_student` (`student_id`),
    CONSTRAINT `fk_ce_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ce_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `course_materials` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_id` INT UNSIGNED NOT NULL,
    `creator_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(120) NOT NULL,
    `stored_name` VARCHAR(64) NOT NULL,
    `original_name` VARCHAR(180) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `file_size` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_materials_course` (`course_id`),
    KEY `idx_materials_creator` (`creator_id`),
    CONSTRAINT `fk_materials_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_materials_creator` FOREIGN KEY (`creator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `course_activities` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `course_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(120) NOT NULL,
    `statement` TEXT NOT NULL,
    `deadline` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_activities_course` (`course_id`),
    CONSTRAINT `fk_activities_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_submissions` (
    `activity_id` INT UNSIGNED NOT NULL,
    `student_id` INT UNSIGNED NOT NULL,
    `answer` TEXT NOT NULL,
    `grade` DECIMAL(4,2) NULL DEFAULT NULL,
    `feedback` VARCHAR(500) DEFAULT NULL,
    `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`activity_id`, `student_id`),
    KEY `idx_sub_student` (`student_id`),
    CONSTRAINT `fk_sub_activity` FOREIGN KEY (`activity_id`) REFERENCES `course_activities` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sub_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `course_ratings` (
    `target_type` ENUM('teacher', 'lesson', 'activity') NOT NULL,
    `target_id` INT UNSIGNED NOT NULL,
    `student_id` INT UNSIGNED NOT NULL,
    `stars` TINYINT UNSIGNED NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`target_type`, `target_id`, `student_id`),
    KEY `idx_ratings_target` (`target_type`, `target_id`),
    KEY `idx_ratings_student` (`student_id`),
    CONSTRAINT `fk_ratings_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. FÓRUNS E ANEXOS
-- ============================================================

CREATE TABLE IF NOT EXISTS `forum_topics` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(120) NOT NULL,
    `body` TEXT NOT NULL,
    `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_topics_subject` (`subject_id`, `created_at`),
    KEY `idx_topics_user` (`user_id`),
    CONSTRAINT `fk_topics_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_topics_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_posts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `topic_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `body` TEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `edited_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_posts_topic` (`topic_id`, `created_at`),
    KEY `idx_posts_user` (`user_id`),
    CONSTRAINT `fk_posts_topic` FOREIGN KEY (`topic_id`) REFERENCES `forum_topics` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forum_attachments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `topic_id` INT UNSIGNED NOT NULL,
    `post_id` INT UNSIGNED DEFAULT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_name` VARCHAR(100) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `file_size` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attachment_topic` (`topic_id`),
    KEY `idx_attachment_post` (`post_id`),
    KEY `idx_attachment_topic_post` (`topic_id`, `post_id`),
    CONSTRAINT `fk_attachment_topic` FOREIGN KEY (`topic_id`) REFERENCES `forum_topics` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_attachment_post` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. AULAS AO VIVO (JaaS)
-- ============================================================

CREATE TABLE IF NOT EXISTS `live_classes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `teacher_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(80) NOT NULL,
    `subject` VARCHAR(60) NOT NULL,
    `room_key` VARCHAR(64) NOT NULL,
    `status` ENUM('live', 'ended') NOT NULL DEFAULT 'live',
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_live_room_key` (`room_key`),
    KEY `idx_live_status` (`status`, `last_seen_at`),
    KEY `idx_live_teacher` (`teacher_id`, `status`),
    CONSTRAINT `fk_live_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. SOCIAL, AMIZADES, PRESENÇA E MENSAGENS PRIVADAS
-- ============================================================

CREATE TABLE IF NOT EXISTS `friendships` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_a` INT UNSIGNED NOT NULL,
    `user_b` INT UNSIGNED NOT NULL,
    `requested_by` INT UNSIGNED NOT NULL,
    `status` ENUM('pending', 'accepted') NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `responded_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_friend_pair` (`user_a`, `user_b`),
    KEY `idx_friend_b` (`user_b`, `status`),
    KEY `idx_friend_requester` (`requested_by`, `status`, `created_at`),
    CONSTRAINT `fk_friend_a` FOREIGN KEY (`user_a`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_friend_b` FOREIGN KEY (`user_b`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_friend_req` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_blocks` (
    `blocker_id` INT UNSIGNED NOT NULL,
    `blocked_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`blocker_id`, `blocked_id`),
    KEY `idx_block_blocked` (`blocked_id`),
    CONSTRAINT `fk_block_blocker` FOREIGN KEY (`blocker_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_block_blocked` FOREIGN KEY (`blocked_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `private_messages` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sender_id` INT UNSIGNED NOT NULL,
    `recipient_id` INT UNSIGNED NOT NULL,
    `body` TEXT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `edited_at` TIMESTAMP NULL DEFAULT NULL,
    `read_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_pm_pair` (`sender_id`, `recipient_id`, `id`),
    KEY `idx_pm_inbox` (`recipient_id`, `read_at`),
    CONSTRAINT `fk_pm_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pm_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_presence` (
    `user_id` INT UNSIGNED NOT NULL,
    `status` ENUM('online', 'busy', 'away', 'invisible') NOT NULL DEFAULT 'online',
    `last_seen_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_presence_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dm_threads` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_a` INT UNSIGNED NOT NULL,
    `user_b` INT UNSIGNED NOT NULL,
    `initiator_id` INT UNSIGNED NOT NULL,
    `status` ENUM('pending', 'accepted', 'declined') NOT NULL DEFAULT 'pending',
    `hidden_a` TINYINT(1) NOT NULL DEFAULT 0,
    `hidden_b` TINYINT(1) NOT NULL DEFAULT 0,
    `cleared_a` INT UNSIGNED NOT NULL DEFAULT 0,
    `cleared_b` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `responded_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_thread_pair` (`user_a`, `user_b`),
    KEY `idx_thread_b` (`user_b`, `hidden_b`),
    KEY `idx_thread_initiator` (`initiator_id`, `status`, `created_at`),
    CONSTRAINT `fk_thread_a` FOREIGN KEY (`user_a`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_thread_b` FOREIGN KEY (`user_b`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_thread_initiator` FOREIGN KEY (`initiator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. ADMINISTRAÇÃO, MODERAÇÃO, SANÇÕES E AUDITORIA
-- ============================================================

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_id` INT UNSIGNED NULL DEFAULT NULL,
    `action` VARCHAR(50) NOT NULL,
    `target_type` VARCHAR(50) NOT NULL,
    `target_id` INT UNSIGNED NULL DEFAULT NULL,
    `old_value` TEXT NULL DEFAULT NULL,
    `new_value` TEXT NULL DEFAULT NULL,
    `reason` VARCHAR(255) NULL DEFAULT NULL,
    `ip` VARBINARY(16) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_action` (`action`),
    KEY `idx_audit_target` (`target_type`, `target_id`),
    KEY `idx_audit_actor` (`actor_id`),
    CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_sanctions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `type` ENUM('mute', 'suspension') NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `applied_by` INT UNSIGNED NULL DEFAULT NULL,
    `expires_at` DATETIME NULL DEFAULT NULL,
    `revoked_at` DATETIME NULL DEFAULT NULL,
    `revoked_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sanction_user` (`user_id`),
    KEY `idx_sanction_active` (`user_id`, `revoked_at`, `expires_at`),
    CONSTRAINT `fk_sanction_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sanction_applied` FOREIGN KEY (`applied_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_sanction_revoked` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reports` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reporter_id` INT UNSIGNED NOT NULL,
    `target_type` ENUM('post', 'topic') NOT NULL,
    `target_id` INT UNSIGNED NOT NULL,
    `target_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `category` VARCHAR(50) NOT NULL,
    `details` VARCHAR(255) NULL DEFAULT NULL,
    `snapshot` TEXT NULL DEFAULT NULL,
    `status` ENUM('open', 'resolved', 'dismissed') NOT NULL DEFAULT 'open',
    `handled_by` INT UNSIGNED NULL DEFAULT NULL,
    `handled_at` DATETIME NULL DEFAULT NULL,
    `resolution` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_report_once` (`reporter_id`, `target_type`, `target_id`),
    KEY `idx_report_status` (`status`),
    KEY `idx_report_target` (`target_type`, `target_id`),
    CONSTRAINT `fk_report_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_report_target_user` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_report_handler` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- 7. MIGRAÇÕES SEGURAS (PARA BANCOS JÁ EXISTENTES)
-- ============================================================

-- Renomeia is_active -> activate se existir de versão antiga
SET @has_old := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_active');
SET @sql := IF(@has_old > 0,
    'ALTER TABLE users CHANGE is_active activate TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT ''activate: ok''');
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- Garante recovery_phrase_hash
SET @has_hash := (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'recovery_phrase_hash');
SET @sql := IF(@has_hash = 0,
    'ALTER TABLE users ADD COLUMN recovery_phrase_hash VARCHAR(255) DEFAULT NULL AFTER activate',
    'SELECT ''recovery_phrase_hash: ok''');
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- Garante deactivated_by (bloqueia recuperação por frase quando desativado por admin)
SET @has_deact := (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deactivated_by');
SET @sql := IF(@has_deact = 0,
    'ALTER TABLE users ADD COLUMN deactivated_by ENUM(\'user\', \'admin\') DEFAULT NULL AFTER activate',
    'SELECT ''deactivated_by: ok''');
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- Retrocompatibilidade: marca contas já desativadas pelo admin usando audit_logs
UPDATE `users` u
SET u.`deactivated_by` = IF(
    IFNULL((
        SELECT a.`action`
        FROM `audit_logs` a
        WHERE a.`target_type` = 'user'
          AND a.`target_id` = u.`id`
          AND a.`action` IN ('USER_DEACTIVATED', 'USER_ACTIVATED')
        ORDER BY a.`id` DESC
        LIMIT 1
    ), '') = 'USER_DEACTIVATED',
    'admin',
    'user'
)
WHERE u.`activate` = 0
  AND u.`deactivated_by` IS NULL;

-- Garante cargo 'moderator' em users.role
SET @has_mod := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'
                   AND COLUMN_TYPE LIKE '%''moderator''%');
SET @sql := IF(@has_mod = 0,
    'ALTER TABLE users MODIFY role ENUM(\'student\', \'teacher\', \'moderator\', \'admin\') NOT NULL DEFAULT \'student\'',
    'SELECT ''role_moderator: ok''');
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- Garante subjects.description
SET @has_sub_desc := (SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'description');
SET @sql := IF(@has_sub_desc = 0,
    'ALTER TABLE subjects ADD COLUMN description VARCHAR(255) DEFAULT NULL AFTER name',
    'SELECT ''subjects_description: ok''');
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- Garante forum_posts.edited_at
SET @has_edited := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts' AND COLUMN_NAME = 'edited_at');
SET @sql := IF(@has_edited = 0,
    'ALTER TABLE forum_posts ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL AFTER created_at',
    'SELECT ''edited_at: ok''');
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- Garante chave única uq_report_once em reports (se reports já existia antes)
SET @has_uq_rep := (SELECT COUNT(*) FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports' AND INDEX_NAME = 'uq_report_once');
SET @sql := IF(@has_uq_rep = 0,
    'ALTER TABLE reports ADD UNIQUE KEY uq_report_once (reporter_id, target_type, target_id)',
    'SELECT ''uq_report_once: ok''');
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- Migra comprovantes e matrículas antigas com segurança
INSERT INTO `teacher_proofs` (`user_id`, `file_name`, `original_name`)
SELECT tp.`user_id`, tp.`proof_path`, tp.`proof_path`
FROM `teacher_profiles` tp
WHERE tp.`proof_path` IS NOT NULL
  AND tp.`proof_path` <> ''
  AND NOT EXISTS (SELECT 1 FROM `teacher_proofs` p WHERE p.`user_id` = tp.`user_id`);

UPDATE `student_profiles`
SET `enrollment_number` = CAST(`user_id` AS CHAR)
WHERE `enrollment_number` IS NULL OR `enrollment_number` = '';

-- ============================================================
-- 8. DADOS INICIAIS (MATÉRIAS PADRÃO)
-- ============================================================

INSERT IGNORE INTO `subjects` (`name`, `description`) VALUES
    ('Engenharia de Requisitos', 'Levantamento e análise de requisitos.'),
    ('Desenvolvimento Web', 'HTML, CSS, JavaScript e mais.'),
    ('Banco de Dados', 'Modelagem e SQL.');

-- ============================================================
-- 9. PROMOVER PRIMEIRO ADMINISTRADOR (OPCIONAL / PÓS-CADASTRO)
-- ============================================================
-- Cadastre sua conta em auth/register.php e depois rode no SQL:
-- UPDATE `users` SET `role` = 'admin' WHERE `email` = 'seu_email@exemplo.com';
