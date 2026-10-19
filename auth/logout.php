<?php
include_once __DIR__ . '/../config/conexao.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Sua sessão expirou para esta ação. Recarregue a página e tente novamente.');
        redirect('../index.php');
    }
    session_destroy();
    session_start();
    session_regenerate_id(true);
    setFlash('success', 'Você saiu da conta.');
}
redirect('../index.php');
