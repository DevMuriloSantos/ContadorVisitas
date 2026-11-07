<?php
/**
 * Topo compartilhado. Antes de incluir, a página define:
 *   $titulo (string)            título da página
 *   $flash  (array|null)        opcional; se ausente, a mensagem pendente é lida aqui
 */
require_once __DIR__ . '/auth.php';
iniciar_sessao();

$titulo = $titulo ?? 'ContadorVisitas';
$flash  = array_key_exists('flash', get_defined_vars()) ? $flash : flash_obter();
$usuario = usuario_atual();
$tiposAlerta = ['success', 'danger', 'warning', 'info'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> - ContadorVisitas</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/estilo.css">
</head>
<body class="d-flex flex-column min-vh-100 bg-light">
<nav class="navbar navbar-expand-md navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="index.php">TechNews</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menu"
                aria-controls="menu" aria-expanded="false" aria-label="Alternar menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="menu">
            <ul class="navbar-nav me-auto mb-2 mb-md-0">
                <li class="nav-item"><a class="nav-link" href="index.php">Início</a></li>
                <?php if ($usuario): ?>
                    <li class="nav-item"><a class="nav-link" href="visitas.php">Controle de visitas</a></li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav align-items-md-center gap-md-2 pb-3 pb-md-0">
                <?php if ($usuario): ?>
                    <li class="nav-item"><span class="navbar-text text-light">Olá, <?= e($usuario['nome']) ?></span></li>
                    <li class="nav-item">
                        <form action="logout.php" method="post" class="d-inline">
                            <?= csrf_campo('logout') ?>
                            <button type="submit" class="btn btn-outline-light btn-sm">Sair</button>
                        </form>
                    </li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="login.php">Entrar</a></li>
                    <li class="nav-item"><a class="btn btn-primary btn-sm" href="cadastro.php">Cadastrar-se</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<main class="container flex-grow-1 py-4">
<?php if ($flash): ?>
    <?php $tipo = in_array($flash['tipo'], $tiposAlerta, true) ? $flash['tipo'] : 'info'; ?>
    <div class="alert alert-<?= e($tipo) ?>" role="alert">
        <?php if (count($flash['mensagens']) === 1): ?>
            <?= e($flash['mensagens'][0]) ?>
        <?php else: ?>
            <ul class="mb-0">
                <?php foreach ($flash['mensagens'] as $mensagem): ?>
                    <li><?= e($mensagem) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>
