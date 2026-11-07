<?php
/**
 * Notícias de tecnologia via RSS (Tecnoblog, com Canaltech como alternativa) + cache em JSON.
 *
 * Este arquivo só define funções: incluí-lo NÃO faz requisições nem grava nada.
 * A busca acontece quando a página chama noticias_obter().
 *
 * Todo conteúdo vindo do RSS é tratado como não confiável: o HTML é removido, os links e as
 * imagens são validados (somente http/https) e a saída deve ser sempre escapada com e().
 * Este arquivo não usa sessão nem banco de dados e é independente do contador.
 *
 * Fluxo de noticias_obter():
 *   1. cache válido (< 15 min)            -> usa sem requisição externa
 *   2. cache expirado                     -> tenta Tecnoblog, depois Canaltech
 *   3. as duas fontes falharam            -> usa o cache antigo (marcado como desatualizado)
 *   4. sem nenhum cache utilizável        -> lista vazia (a página mostra mensagem amigável)
 */

const NOTICIAS_TTL           = 900;              // validade do cache: 15 minutos
const NOTICIAS_LIMITE        = 9;                // notícias exibidas
const NOTICIAS_TAMANHO_MAX   = 3 * 1024 * 1024;  // o feed do Tecnoblog tem ~0,9 MB
const NOTICIAS_RESUMO        = 150;              // caracteres da descrição
const NOTICIAS_PAUSA_FALHA   = 60;               // após falhar nas duas fontes, não tenta de novo por 60 s
const NOTICIAS_ESPERA_LOCK   = 15;               // quanto esperar outro processo atualizar o cache (s)
const NOTICIAS_PLACEHOLDER   = 'assets/img/placeholder.svg';
const NOTICIAS_NS_MEDIA      = 'http://search.yahoo.com/mrss/';
const NOTICIAS_NS_CONTENT    = 'http://purl.org/rss/1.0/modules/content/';
const NOTICIAS_USER_AGENT    = 'ContadorVisitas-Academico/1.0 (projeto academico; leitor RSS)';

/** Fontes fixas no código, em ordem de preferência. Nunca vêm do usuário. */
function noticias_fontes(): array
{
    return [
        ['nome' => 'Tecnoblog', 'url' => 'https://tecnoblog.net/feed/'],
        ['nome' => 'Canaltech', 'url' => 'https://canaltech.com.br/rss/'],
    ];
}

/* ========================= Caminhos ========================= */

function _noticias_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cache';
}

function noticias_cache_caminho(): string
{
    return _noticias_dir() . DIRECTORY_SEPARATOR . 'noticias.json';
}

/* ========================= Validação ========================= */

/** Retorna a URL se for http/https bem formada; senão null. */
function noticias_url_valida(?string $url): ?string
{
    $url = trim((string) $url);

    if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7F"<>\\\\]/', $url)) {
        return null;
    }

    // URLs sem esquema ("//host/img.jpg") passam a https
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }

    $partes = parse_url($url);
    if ($partes === false
        || !isset($partes['scheme'], $partes['host'])
        || !in_array(strtolower($partes['scheme']), ['http', 'https'], true)
        || isset($partes['user']) || isset($partes['pass'])
        || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    return $url;
}

/* ========================= Requisição HTTP ========================= */

/**
 * Baixa o feed. Somente HTTPS, com timeouts, limite de tamanho e verificação do código HTTP.
 * @return string|null corpo da resposta, ou null em qualquer falha (registrada no log).
 */
function noticias_http_buscar(string $url): ?string
{
    if (!str_starts_with($url, 'https://')) {
        error_log('noticias: URL recusada (somente HTTPS): ' . $url);
        return null;
    }

    return function_exists('curl_init')
        ? _noticias_http_curl($url)
        : _noticias_http_stream($url);
}

function _noticias_http_curl(string $url): ?string
{
    $corpo = '';
    $excedeu = false;
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 3,
        CURLOPT_CONNECTTIMEOUT  => 5,
        CURLOPT_TIMEOUT         => 12,
        CURLOPT_USERAGENT       => NOTICIAS_USER_AGENT,
        CURLOPT_HTTPHEADER      => ['Accept: application/rss+xml, application/xml;q=0.9, text/xml;q=0.8'],
        CURLOPT_ENCODING        => '',
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_WRITEFUNCTION   => function ($ch, string $dados) use (&$corpo, &$excedeu): int {
            $corpo .= $dados;
            if (strlen($corpo) > NOTICIAS_TAMANHO_MAX) {
                $excedeu = true;
                return 0; // aborta a transferência
            }
            return strlen($dados);
        },
    ]);

    // A verificação de certificado continua LIGADA. Além do pacote configurado no php.ini, consulta
    // também o repositório de certificados do sistema (no Windows, onde antivírus que inspecionam
    // HTTPS, como o AVG, instalam a própria raiz). Requer PHP >= 8.2 com cURL/OpenSSL.
    if (defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }

    curl_exec($ch);
    $erro   = curl_errno($ch);
    $msg    = curl_error($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($excedeu) {
        error_log('noticias: resposta maior que o limite de ' . NOTICIAS_TAMANHO_MAX . ' bytes: ' . $url);
        return null;
    }
    if ($erro !== 0) {
        error_log("noticias: falha cURL ($erro) em $url: $msg");
        return null;
    }
    if ($codigo !== 200) {
        error_log("noticias: HTTP $codigo em $url");
        return null;
    }
    if (trim($corpo) === '') {
        error_log("noticias: resposta vazia em $url");
        return null;
    }

    return $corpo;
}

/** Alternativa quando a extensão cURL não está disponível. */
function _noticias_http_stream(string $url): ?string
{
    $contexto = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 12,
            'follow_location' => 1,
            'max_redirects'   => 3,
            'user_agent'      => NOTICIAS_USER_AGENT,
            'header'          => "Accept: application/rss+xml, application/xml;q=0.9, text/xml;q=0.8\r\n",
            'ignore_errors'   => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $h = @fopen($url, 'rb', false, $contexto);
    if ($h === false) {
        error_log("noticias: falha ao abrir $url");
        return null;
    }

    $cabecalhos = stream_get_meta_data($h)['wrapper_data'] ?? [];
    $corpo = stream_get_contents($h, NOTICIAS_TAMANHO_MAX + 1);
    fclose($h);

    // Com redirecionamentos há várias linhas "HTTP/..."; vale a última.
    $codigo = 0;
    foreach ($cabecalhos as $linha) {
        if (is_string($linha) && preg_match('#^HTTP/\S+\s+(\d{3})#', $linha, $m)) {
            $codigo = (int) $m[1];
        }
    }

    if ($corpo === false || strlen($corpo) > NOTICIAS_TAMANHO_MAX) {
        error_log("noticias: leitura inválida ou acima do limite em $url");
        return null;
    }
    if ($codigo !== 200 || trim($corpo) === '') {
        error_log("noticias: HTTP $codigo (ou vazio) em $url");
        return null;
    }

    return $corpo;
}

/* ========================= Interpretação do XML ========================= */

/** Remove HTML e entidades, normaliza espaços. Resultado é texto puro. */
function noticias_texto_limpo(string $html): string
{
    $t = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = strip_tags($t); // caso as entidades escondessem tags (&lt;p&gt;)
    $t = (string) preg_replace('/[\x00-\x1F\x7F]|\x{00A0}|\s+/u', ' ', $t);
    return trim($t);
}

/** Resume em ~$max caracteres, cortando em fim de palavra. */
function noticias_resumir(string $texto, int $max = NOTICIAS_RESUMO): string
{
    // Rodapé automático do WordPress: "O post X apareceu primeiro em Tecnoblog."
    $texto = trim((string) preg_replace('/\s*O post\s.+?\sapareceu primeiro em\s.*$/iu', '', $texto));

    if (mb_strlen($texto) <= $max) {
        return $texto;
    }

    $corte = mb_substr($texto, 0, $max);
    $espaco = mb_strrpos($corte, ' ');
    if ($espaco !== false && $espaco > $max * 0.6) {
        $corte = mb_substr($corte, 0, $espaco);
    }

    return rtrim($corte, " \t,.;:-–—") . '…';
}

/** Primeira <img src="..."> encontrada em um trecho de HTML. */
function _noticias_primeira_img(string $html): ?string
{
    if ($html !== '' && preg_match('/<img\b[^>]*?\ssrc\s*=\s*(["\'])(.*?)\1/is', $html, $m)) {
        return noticias_url_valida(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return null;
}

/**
 * Valor de um atributo sem prefixo. Em SimpleXML, depois de children($namespace), $el['url']
 * procuraria o atributo dentro desse namespace e voltaria vazio; attributes() sem argumento não.
 */
function _noticias_atributo(SimpleXMLElement $el, string $nome): string
{
    $atributos = $el->attributes();
    return isset($atributos[$nome]) ? (string) $atributos[$nome] : '';
}

function _noticias_extensao_imagem(string $url): bool
{
    $caminho = (string) parse_url($url, PHP_URL_PATH);
    return (bool) preg_match('/\.(jpe?g|png|gif|webp|avif)$/i', $caminho);
}

/**
 * Imagem do item, na ordem: media:thumbnail, media:content, enclosure,
 * primeira <img> de content:encoded ou da descrição. null se não houver.
 */
function noticias_extrair_imagem(SimpleXMLElement $item): ?string
{
    $media = $item->children(NOTICIAS_NS_MEDIA);

    foreach ($media->thumbnail as $t) {
        if ($url = noticias_url_valida(_noticias_atributo($t, 'url'))) {
            return $url;
        }
    }

    foreach ($media->content as $c) {
        $url    = noticias_url_valida(_noticias_atributo($c, 'url'));
        $tipo   = strtolower(_noticias_atributo($c, 'type'));
        $medium = strtolower(_noticias_atributo($c, 'medium'));
        if ($url && (str_starts_with($tipo, 'image/') || $medium === 'image'
                || ($tipo === '' && $medium === '' && _noticias_extensao_imagem($url)))) {
            return $url;
        }
    }

    foreach ($item->enclosure as $e) {
        $url = noticias_url_valida(_noticias_atributo($e, 'url'));
        if ($url && str_starts_with(strtolower(_noticias_atributo($e, 'type')), 'image/')) {
            return $url;
        }
    }

    $conteudo  = (string) $item->children(NOTICIAS_NS_CONTENT)->encoded;
    $descricao = (string) $item->description;

    return _noticias_primeira_img($conteudo)
        ?? _noticias_primeira_img($descricao)
        ?? _noticias_primeira_img(html_entity_decode($descricao, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Texto da descrição, sem parágrafos vazios nem repetições do título
 * (o Tecnoblog termina a descrição com um parágrafo que é só o link da própria matéria).
 */
function noticias_descricao(string $html, string $titulo): string
{
    $partes = [];
    foreach (preg_split('#</p\s*>#i', $html) ?: [$html] as $paragrafo) {
        $texto = noticias_texto_limpo($paragrafo);
        if ($texto !== '' && mb_strtolower($texto) !== mb_strtolower($titulo)) {
            $partes[] = $texto;
        }
    }

    return noticias_resumir(implode(' ', $partes));
}

/** Converte um <item> em notícia, ou null se faltar título ou link válido. */
function noticias_extrair_item(SimpleXMLElement $item, string $fonte): ?array
{
    $titulo = mb_substr(noticias_texto_limpo((string) $item->title), 0, 200);
    $link   = noticias_url_valida((string) $item->link);

    if ($titulo === '' || $link === null) {
        return null;
    }

    $data = strtotime((string) $item->pubDate);

    return [
        'titulo'    => $titulo,
        'link'      => $link,
        'descricao' => noticias_descricao((string) $item->description, $titulo),
        'data'      => $data === false ? null : $data,
        'imagem'    => noticias_extrair_imagem($item),
        'fonte'     => $fonte,
    ];
}

/**
 * Interpreta um RSS 2.0.
 * @return array|null lista de notícias (pode ser vazia); null se o XML for inválido ou não for RSS.
 */
function noticias_interpretar_xml(string $xml, string $fonte): ?array
{
    $xml = ltrim($xml, "\xEF\xBB\xBF \t\r\n");

    // Sem DTDs com entidades (evita XXE e "billion laughs"); feeds RSS legítimos não usam.
    if (stripos($xml, '<!ENTITY') !== false) {
        error_log("noticias: XML com declaração de entidade recusado ($fonte)");
        return null;
    }

    $anterior = libxml_use_internal_errors(true);
    // LIBXML_NONET: sem acesso à rede. Sem LIBXML_NOENT: entidades externas não são substituídas.
    $rss = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($anterior);

    if ($rss === false || $rss->getName() !== 'rss' || !isset($rss->channel)) {
        error_log("noticias: XML inválido ou não é RSS ($fonte)");
        return null;
    }

    $noticias = [];
    $vistos   = [];
    foreach ($rss->channel->item as $item) {
        $noticia = noticias_extrair_item($item, $fonte);
        if ($noticia !== null && !isset($vistos[$noticia['link']])) {
            $vistos[$noticia['link']] = true;
            $noticias[] = $noticia;
        }
    }

    return $noticias;
}

/** Ordena da mais recente para a mais antiga e limita a quantidade. */
function noticias_ordenar_limitar(array $noticias, int $limite = NOTICIAS_LIMITE): array
{
    usort($noticias, fn(array $a, array $b) => ($b['data'] ?? 0) <=> ($a['data'] ?? 0));
    return array_slice($noticias, 0, $limite);
}

/* ========================= Cache ========================= */

/** Valida (e saneia) uma notícia lida do cache. */
function _noticias_item_cache(mixed $n): ?array
{
    if (!is_array($n) || !is_string($n['titulo'] ?? null) || trim($n['titulo']) === ''
        || !is_string($n['descricao'] ?? null) || !is_string($n['fonte'] ?? null)) {
        return null;
    }

    $link = noticias_url_valida($n['link'] ?? null);
    if ($link === null) {
        return null;
    }

    $data = $n['data'] ?? null;

    return [
        'titulo'    => $n['titulo'],
        'link'      => $link,
        'descricao' => $n['descricao'],
        'data'      => is_int($data) ? $data : null,
        'imagem'    => is_string($n['imagem'] ?? null) ? noticias_url_valida($n['imagem']) : null,
        'fonte'     => $n['fonte'],
    ];
}

/**
 * Lê e valida o cache.
 * @return array{atualizado_em:int,fonte:string,noticias:array}|null null se ausente, inválido ou vazio.
 */
function noticias_cache_ler(): ?array
{
    $arquivo = noticias_cache_caminho();
    if (!is_file($arquivo)) {
        return null;
    }

    // Sem bloqueio: a gravação é atômica (rename), então o leitor vê o arquivo antigo ou o novo, inteiro.
    $json = @file_get_contents($arquivo);
    if ($json === false || strlen($json) > NOTICIAS_TAMANHO_MAX) {
        return null;
    }

    $dados = json_decode($json, true);
    if (!is_array($dados) || !is_int($dados['atualizado_em'] ?? null)
        || !is_string($dados['fonte'] ?? null) || !is_array($dados['noticias'] ?? null)) {
        error_log('noticias: cache inválido (estrutura)');
        return null;
    }

    $noticias = array_values(array_filter(array_map('_noticias_item_cache', $dados['noticias'])));
    if (!$noticias) {
        error_log('noticias: cache sem notícias utilizáveis');
        return null;
    }

    return ['atualizado_em' => $dados['atualizado_em'], 'fonte' => $dados['fonte'], 'noticias' => $noticias];
}

/** true se o cache tem menos de 15 minutos (e não está "no futuro"). */
function noticias_cache_valido(array $cache): bool
{
    $idade = time() - $cache['atualizado_em'];
    return $idade > -60 && $idade < NOTICIAS_TTL;
}

/**
 * Grava o cache de forma atômica: escreve em arquivo temporário e troca por rename().
 * Nunca deixa um JSON parcial em noticias.json.
 */
function noticias_cache_gravar(array $cache): bool
{
    $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) {
        error_log('noticias: json_encode falhou');
        return false;
    }

    $temporario = _noticias_dir() . DIRECTORY_SEPARATOR . 'noticias.' . bin2hex(random_bytes(6)) . '.tmp';

    if (@file_put_contents($temporario, $json) !== strlen($json)) {
        error_log('noticias: falha ao gravar arquivo temporário do cache');
        @unlink($temporario);
        return false;
    }

    // No Windows o rename() falha enquanto outro processo está lendo o arquivo naquele instante.
    // A leitura é curtíssima, então algumas tentativas rápidas resolvem; o cache antigo fica intacto.
    for ($tentativa = 0; $tentativa < 50; $tentativa++) {
        if (@rename($temporario, noticias_cache_caminho())) {
            return true;
        }
        usleep(10000);
    }

    error_log('noticias: falha ao substituir noticias.json');
    @unlink($temporario);
    return false;
}

/* ========================= Atualização ========================= */

/** Tenta cada fonte, em ordem. Em caso de sucesso grava e devolve o novo cache; senão null. */
function noticias_atualizar(): ?array
{
    foreach (noticias_fontes() as $fonte) {
        $corpo = noticias_http_buscar($fonte['url']);
        if ($corpo === null) {
            continue;
        }

        $noticias = noticias_interpretar_xml($corpo, $fonte['nome']);
        if (!$noticias) {
            error_log('noticias: nenhuma notícia utilizável em ' . $fonte['nome']);
            continue;
        }

        $cache = [
            'atualizado_em' => time(),
            'fonte'         => $fonte['nome'],
            'noticias'      => noticias_ordenar_limitar($noticias),
        ];
        noticias_cache_gravar($cache); // se falhar, ainda exibimos o que foi baixado

        return $cache;
    }

    return null;
}

/**
 * Obtém o lock da atualização. Evita que várias requisições baixem o feed ao mesmo tempo.
 * Com cache antigo disponível não espera: quem não conseguir o lock exibe o cache antigo.
 */
function _noticias_lock($lock, bool $temCacheAntigo): bool
{
    if (flock($lock, LOCK_EX | LOCK_NB)) {
        return true;
    }
    if ($temCacheAntigo) {
        return false;
    }

    $limite = microtime(true) + NOTICIAS_ESPERA_LOCK;
    while (microtime(true) < $limite) {
        usleep(200000);
        if (flock($lock, LOCK_EX | LOCK_NB)) {
            return true;
        }
    }

    return false;
}

function _noticias_resultado(array $cache, bool $desatualizado): array
{
    return [
        'noticias'      => $cache['noticias'],
        'fonte'         => $cache['fonte'],
        'atualizado_em' => $cache['atualizado_em'],
        'desatualizado' => $desatualizado,
    ];
}

/**
 * Ponto de entrada para a página.
 * @return array{noticias:array,fonte:?string,atualizado_em:?int,desatualizado:bool}
 *         "noticias" vazio = indisponível (sem cache e sem fontes). Nunca lança exceção por falha de RSS.
 */
function noticias_obter(): array
{
    $cache = noticias_cache_ler();
    if ($cache !== null && noticias_cache_valido($cache)) {
        return _noticias_resultado($cache, false);
    }

    $falha = _noticias_dir() . DIRECTORY_SEPARATOR . 'noticias.falha';
    $lock  = @fopen(_noticias_dir() . DIRECTORY_SEPARATOR . 'noticias.lock', 'c');
    $temLock = $lock !== false && _noticias_lock($lock, $cache !== null);

    try {
        // Outro processo pode ter atualizado enquanto esperávamos.
        $atual = noticias_cache_ler();
        if ($atual !== null && noticias_cache_valido($atual)) {
            return _noticias_resultado($atual, false);
        }
        $cache = $atual ?? $cache;

        $pausa = is_file($falha) && (time() - (int) @filemtime($falha)) < NOTICIAS_PAUSA_FALHA;

        if ($temLock && !$pausa) {
            $novo = noticias_atualizar();
            if ($novo !== null) {
                @unlink($falha);
                return _noticias_resultado($novo, false);
            }
            @touch($falha); // evita repetir as requisições lentas a cada acesso enquanto as fontes estão fora
        }
    } finally {
        if ($lock !== false) {
            if ($temLock) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
    }

    if ($cache !== null) {
        return _noticias_resultado($cache, true);
    }

    return ['noticias' => [], 'fonte' => null, 'atualizado_em' => null, 'desatualizado' => false];
}

/* ========================= Apresentação ========================= */

/** Data em horário de Brasília, ex.: 06/11/2026 às 17:14. Vazio se não houver data. */
function noticias_formatar_data(?int $timestamp): string
{
    if ($timestamp === null || $timestamp <= 0) {
        return '';
    }

    return (new DateTimeImmutable('@' . $timestamp))
        ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
        ->format('d/m/Y \à\s H:i');
}

/** Data em formato ISO 8601 para o atributo datetime de <time>. */
function noticias_data_iso(?int $timestamp): string
{
    return $timestamp ? gmdate('c', $timestamp) : '';
}
