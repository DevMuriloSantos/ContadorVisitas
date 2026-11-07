<?php
// Encerra a sessão. Somente POST com token CSRF. Nunca toca no contador.
require __DIR__ . '/includes/auth.php';
iniciar_sessao();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Método não permitido.';
    exit;
}

// Não consome o token: ele aparece na navbar de todas as páginas abertas.
if (!csrf_validar('logout', $_POST['csrf'] ?? null, false)) {
    flash_definir('danger', 'Requisição inválida. Tente novamente.');
    redirecionar('index.php');
}

encerrar_sessao();

// Nova sessão (anônima) apenas para exibir a mensagem de saída.
iniciar_sessao();
flash_definir('success', 'Você saiu da sua conta.');
redirecionar('login.php');
