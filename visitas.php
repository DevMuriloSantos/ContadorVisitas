<?php
// Página restrita. Apenas CONSULTA o contador; nunca incrementa.
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/contador.php';

iniciar_sessao();
exige_login();

$total = ler_visitas();
if ($total === false) {
    error_log('visitas.php: ler_visitas() falhou');
}

$titulo = 'Controle de visitas';
require __DIR__ . '/includes/header.php';
?>
<div class="card shadow-sm cartao-form text-center">
    <div class="card-body p-4">
        <h1 class="h5 text-muted mb-3">Total de logins registrados</h1>
        <?php if ($total === false): ?>
            <p class="text-danger mb-0">O total de visitas está indisponível no momento.</p>
        <?php else: ?>
            <div class="valor-visitas"><?= e((string) $total) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
