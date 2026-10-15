CREATE DATABASE IF NOT EXISTS hi_teach CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hi_teach;

/* CASO VOCE QUEIRA CRIAR UM USUÁRIO, POR UMA SENHA E DAR PERMISSÕES, COLOQUE OS VALORES DESEJADOS ABAIXO
 
 CREATE USER IF NOT EXISTS 'EMAIL_TESTE'@'localhost' IDENTIFIED BY 'SENHA_DESEJADA';
 CREATE USER IF NOT EXISTS 'EMAIL_TESTE'@'127.0.0.1' IDENTIFIED BY 'SENHA_DESEJADA';
 GRANT ALL PRIVILEGES ON hi_teach.* TO 'EMAIL_TESTE'@'localhost';
 GRANT ALL PRIVILEGES ON hi_teach.* TO 'EMAIL_TESTE'@'127.0.0.1';
 FLUSH PRIVILEGES; 
 
 */

-- ===================================================
-- 1. TABELAS DO SISTEMA PRINCIPAL (USUÁRIOS / LOGS)
-- ===================================================

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    birth_date DATE NOT NULL,
    cpf CHAR(11) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    bio VARCHAR(250) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student', 'teacher', 'admin') NOT NULL DEFAULT 'student',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    avatar_data MEDIUMBLOB DEFAULT NULL,
    avatar_mime VARCHAR(30) DEFAULT NULL,
    banner_data MEDIUMBLOB DEFAULT NULL,
    banner_mime VARCHAR(30) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_cpf (cpf)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_profiles (
    user_id INT UNSIGNED NOT NULL,
    enrollment_number VARCHAR(10) DEFAULT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_student_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_profiles (
    user_id INT UNSIGNED NOT NULL,
    specializations VARCHAR(150) NOT NULL,
    proof_path VARCHAR(60) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (user_id),
    KEY fk_teacher_reviewer (reviewed_by),
    CONSTRAINT fk_teacher_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_teacher_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED DEFAULT NULL,
    action VARCHAR(50) NOT NULL,
    details VARCHAR(255) NOT NULL DEFAULT '',
    ip VARBINARY(16) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id, created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip VARBINARY(16) NOT NULL,
    email_hash CHAR(64) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attempts_ip (ip, attempted_at),
    KEY idx_attempts_email (email_hash, attempted_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ===================================================
-- 2. TABELAS DO FÓRUM
-- ===================================================

CREATE TABLE IF NOT EXISTS subjects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(60) NOT NULL,
    description VARCHAR(250) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_subjects_name (name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forum_topics (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    body TEXT NOT NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_topics_subject (subject_id, created_at),
    KEY idx_topics_user (user_id),
    CONSTRAINT fk_topics_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE CASCADE,
    CONSTRAINT fk_topics_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forum_posts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    topic_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_posts_topic (topic_id, created_at),
    KEY idx_posts_user (user_id),
    CONSTRAINT fk_posts_topic FOREIGN KEY (topic_id) REFERENCES forum_topics (id) ON DELETE CASCADE,
    CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forum_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    topic_id INT UNSIGNED NOT NULL,
    post_id INT UNSIGNED DEFAULT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(100) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attachment_topic (topic_id),
    KEY idx_attachment_post (post_id),
    KEY idx_attachment_topic_post (topic_id, post_id),
    CONSTRAINT fk_attachment_topic FOREIGN KEY (topic_id) REFERENCES forum_topics (id) ON DELETE CASCADE,
    CONSTRAINT fk_attachment_post FOREIGN KEY (post_id) REFERENCES forum_posts (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Inserção de dados iniciais padrão
INSERT IGNORE INTO subjects (name, description) VALUES
    ('Engenharia de Requisitos', 'Levantamento e análise de requisitos.'),
    ('Desenvolvimento Web', 'HTML, CSS, JavaScript e mais.'),
    ('Banco de Dados', 'Modelagem e SQL.');
