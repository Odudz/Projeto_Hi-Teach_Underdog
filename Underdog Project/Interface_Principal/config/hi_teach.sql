CREATE DATABASE IF NOT EXISTS hi_teach CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hi_teach;
/* CASO VOCE QUEIRA CRIAR UM USUÁRIO, POR UMA SENHA E DAR PERMISSÕES, COLOQUE OS VALORES DESEJADOS ABAIXO
 
 CREATE USER IF NOT EXISTS 'EMAIL_TESTE'@'localhost' IDENTIFIED BY 'SENHA_DESEJADA';
 CREATE USER IF NOT EXISTS 'EMAIL_TESTE'@'127.0.0.1' IDENTIFIED BY 'SENHA_DESEJADA';
 GRANT ALL PRIVILEGES ON hi_teach.* TO 'EMAIL_TESTE'@'localhost';
 GRANT ALL PRIVILEGES ON hi_teach.* TO 'EMAIL_TESTE'@'127.0.0.1';
 FLUSH PRIVILEGES; 
 
 */
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
    CONSTRAINT fk_teacher_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE
    SET NULL
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
