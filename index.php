<?php
// Página inicial (pública): notícias de tecnologia via RSS. NÃO mexe no contador de logins.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rss.php';

iniciar_sessao();

// Uma falha no RSS nunca derruba a página: sem notícias, mostramos uma mensagem amigável.
try {
    $resultado = noticias_obter();
} catch (Throwable $erro) {
    error_log('index.php: erro ao obter notícias: ' . $erro->getMessage());
    $resultado = ['noticias' => [], 'fonte' => null, 'atualizado_em' => null, 'desatualizado' => false];
}

$noticias = $resultado['noticias'];

$titulo = 'Notícias de tecnologia';
require __DIR__ . '/includes/header.php';
?>
<section class="hero-tech rounded-4 p-4 p-md-5 mb-4 text-white">
    <h1 class="display-6 fw-bold mb-2">Tecnologia em um só lugar</h1>
    <p class="lead mb-3">As principais notícias do mundo da tecnologia, reunidas de fontes confiáveis.</p>
    <?php if ($resultado['atualizado_em']): ?>
        <p class="small mb-0 text-white-50">
            Atualizado em <time datetime="<?= e(noticias_data_iso($resultado['atualizado_em'])) ?>"><?= e(noticias_formatar_data($resultado['atualizado_em'])) ?></time>
            &middot; Fonte: <?= e($resultado['fonte']) ?>
            <?php if ($resultado['desatualizado']): ?>
                <span class="badge text-bg-warning ms-1">versão anterior</span>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</section>

<?php if (!$noticias): ?>
    <div class="alert alert-info" role="status">
        As notícias estão temporariamente indisponíveis. Tente novamente em alguns minutos.
    </div>
<?php else: ?>
    <?php if ($resultado['desatualizado']): ?>
        <div class="alert alert-warning" role="status">
            Não foi possível buscar notícias novas agora. Exibindo a última atualização disponível.
        </div>
    <?php endif; ?>

    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
        <?php foreach ($noticias as $n): ?>
            <div class="col">
                <article class="card h-100 shadow-sm card-noticia">
                    <img src="<?= e($n['imagem'] ?? NOTICIAS_PLACEHOLDER) ?>" class="card-img-top card-noticia-img"
                         alt="" loading="lazy" referrerpolicy="no-referrer">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                            <span class="badge text-bg-primary"><?= e($n['fonte']) ?></span>
                            <?php if ($n['data']): ?>
                                <time class="small text-muted" datetime="<?= e(noticias_data_iso($n['data'])) ?>"><?= e(noticias_formatar_data($n['data'])) ?></time>
                            <?php endif; ?>
                        </div>
                        <h2 class="h5 card-title"><?= e($n['titulo']) ?></h2>
                        <?php if ($n['descricao'] !== ''): ?>
                            <p class="card-text text-muted"><?= e($n['descricao']) ?></p>
                        <?php endif; ?>
                        <a href="<?= e($n['link']) ?>" class="btn btn-outline-primary mt-auto align-self-start"
                           target="_blank" rel="noopener noreferrer">Ler notícia</a>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
