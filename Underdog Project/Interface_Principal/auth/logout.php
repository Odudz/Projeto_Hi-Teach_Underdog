<?php
include_once __DIR__ . '/../config/conexao.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_destroy();
    session_start();
    setFlash('success', 'Você saiu da conta.');
}
redirect('../index.php');
