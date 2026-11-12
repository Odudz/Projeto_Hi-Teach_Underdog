/* ------------------------------------------------------------------
   CORRECAO: Unknown column 'sub.submitted_at'
   Rode no phpMyAdmin (aba SQL) com o banco do projeto selecionado.
   Pode ser executado varias vezes: so altera o que estiver faltando.
   Se a tabela nao existir, ele apenas avisa e nao faz nada.
   ------------------------------------------------------------------ */

-- 1. activity_submissions.submitted_at ------------------------------
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_submissions');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_submissions' AND COLUMN_NAME = 'submitted_at');
SET @has_created := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_submissions' AND COLUMN_NAME = 'created_at');
SET @needs_backfill := (@tbl > 0 AND @col = 0 AND @has_created > 0);

SET @sql := IF(@tbl = 0,
    'SELECT ''activity_submissions NAO EXISTE: crie a tabela primeiro (veja includes/courses.php)''',
    IF(@col = 0,
        'ALTER TABLE activity_submissions ADD COLUMN submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'SELECT ''submitted_at: ja existe'''));
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- Se existia created_at, copia a data antiga para o novo campo
SET @sql := IF(@needs_backfill,
    'UPDATE activity_submissions SET submitted_at = created_at',
    'SELECT ''backfill: nao necessario''');
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- 2. activity_submissions.grade -------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_submissions' AND COLUMN_NAME = 'grade');
SET @sql := IF(@tbl = 0,
    'SELECT ''grade: tabela nao existe''',
    IF(@col = 0,
        'ALTER TABLE activity_submissions ADD COLUMN grade DECIMAL(4,1) NULL DEFAULT NULL',
        'SELECT ''grade: ja existe'''));
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- 3. courses.updated_at (usado no ORDER BY da lista de cursos) -------
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'updated_at');
SET @sql := IF(@tbl = 0,
    'SELECT ''courses: tabela nao existe''',
    IF(@col = 0,
        'ALTER TABLE courses ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'SELECT ''courses.updated_at: ja existe'''));
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- 4. courses.is_published --------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'is_published');
SET @sql := IF(@tbl = 0,
    'SELECT ''courses: tabela nao existe''',
    IF(@col = 0,
        'ALTER TABLE courses ADD COLUMN is_published TINYINT(1) NOT NULL DEFAULT 0',
        'SELECT ''courses.is_published: ja existe'''));
PREPARE step FROM @sql; EXECUTE step; DEALLOCATE PREPARE step;

-- Conferencia final
SHOW COLUMNS FROM activity_submissions;
