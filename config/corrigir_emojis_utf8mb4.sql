/* CORREÇÃO DOS EMOJIS ("?")
   Rode no phpMyAdmin (aba SQL) com o banco da hospedagem selecionado.
   Converte as tabelas para utf8mb4, que é o único charset do MySQL que guarda emojis.
   Não apaga dados. Mensagens que já viraram "?" não voltam; as novas ficam certas.
   (Alternativa sem phpMyAdmin: entre como administrador e abra /tools/charset_diagnostico.php) */

ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE student_profiles CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE teacher_profiles CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE teacher_proofs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE audit_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE login_attempts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE subjects CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE forum_topics CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE forum_posts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE forum_attachments CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE live_classes CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

/* Confira o resultado: nenhuma linha deve aparecer. */
SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME <> 'utf8mb4';
