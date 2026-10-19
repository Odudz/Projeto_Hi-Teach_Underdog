USE hi_teach;

SET @has_old := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_active');
SET @sql := IF(@has_old > 0,
    'ALTER TABLE users CHANGE is_active activate TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT ''activate: nada a fazer (coluna is_active nao existe)''');
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

SET @has_hash := (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'recovery_phrase_hash');
SET @sql := IF(@has_hash = 0,
    'ALTER TABLE users ADD COLUMN recovery_phrase_hash VARCHAR(255) DEFAULT NULL AFTER activate',
    'SELECT ''recovery_phrase_hash: ja existe''');
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

ALTER TABLE teacher_profiles MODIFY specializations TEXT NOT NULL;

CREATE TABLE IF NOT EXISTS teacher_proofs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(60) NOT NULL,
    original_name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_proofs_user (user_id),
    CONSTRAINT fk_proofs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO teacher_proofs (user_id, file_name, original_name)
SELECT tp.user_id, tp.proof_path, tp.proof_path
FROM teacher_profiles tp
WHERE NOT EXISTS (SELECT 1 FROM teacher_proofs p WHERE p.user_id = tp.user_id);

UPDATE student_profiles
SET enrollment_number = CAST(user_id AS CHAR)
WHERE enrollment_number IS NULL OR enrollment_number = '';

SET @has_edited := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'forum_posts' AND COLUMN_NAME = 'edited_at');
SET @sql := IF(@has_edited = 0,
    'ALTER TABLE forum_posts ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL AFTER created_at',
    'SELECT ''edited_at: ja existe''');
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;
