<?php
/**
 * Contador de logins bem-sucedidos, armazenado em arq.in (raiz do projeto).
 *
 * Este arquivo só define funções: incluí-lo NÃO altera o contador.
 * Não usa sessão, banco de dados nem imprime nada.
 *
 *   registrar_login(): bool       -> true somente se o incremento foi gravado.
 *   ler_visitas():     int|false  -> total atual, ou false em caso de falha.
 *
 * Regras do conteúdo de arq.in:
 *   - arquivo inexistente ou vazio (só espaços) = 0;
 *   - apenas dígitos decimais, até PHP_INT_MAX;
 *   - qualquer outra coisa é considerada inválida e NUNCA é sobrescrita.
 */

/** Caminho absoluto de arq.in (raiz do projeto), independente do diretório de execução. */
function _contador_caminho(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'arq.in';
}

/**
 * Converte o conteúdo bruto do arquivo em inteiro.
 * Retorna null se o conteúdo for inválido ou maior que PHP_INT_MAX.
 */
function _contador_interpretar(string $conteudo): ?int
{
    $conteudo = trim($conteudo);

    if ($conteudo === '') {
        return 0;
    }

    if (!preg_match('/^[0-9]+$/', $conteudo)) {
        return null;
    }

    $normalizado = ltrim($conteudo, '0');
    if ($normalizado === '') {
        return 0;
    }

    // Se houve estouro, o inteiro convertido não volta a ser igual ao texto.
    if ((string) (int) $normalizado !== $normalizado) {
        return null;
    }

    return (int) $normalizado;
}

/** Abre o arquivo registrando o motivo da falha em error_log. */
function _contador_abrir(string $modo)
{
    $f = @fopen(_contador_caminho(), $modo);

    if ($f === false) {
        $erro = error_get_last();
        error_log('contador: falha ao abrir arq.in (modo ' . $modo . '): ' . ($erro['message'] ?? 'erro desconhecido'));
    }

    return $f;
}

/**
 * Incrementa o contador em 1 e grava em arq.in, com bloqueio exclusivo
 * durante toda a operação (leitura + incremento + escrita).
 * Cria o arquivo (valor 1) se ele não existir.
 *
 * @return bool true somente se o novo valor foi gravado corretamente.
 */
function registrar_login(): bool
{
    $f = _contador_abrir('c+');
    if ($f === false) {
        return false;
    }

    $bloqueado = false;

    try {
        if (!flock($f, LOCK_EX)) {
            error_log('contador: não foi possível obter LOCK_EX em arq.in');
            return false;
        }
        $bloqueado = true;

        $original = stream_get_contents($f);
        if ($original === false) {
            error_log('contador: falha ao ler arq.in');
            return false;
        }

        $atual = _contador_interpretar($original);
        if ($atual === null) {
            error_log('contador: conteúdo inválido em arq.in; arquivo preservado sem alteração');
            return false;
        }

        if ($atual === PHP_INT_MAX) {
            error_log('contador: valor máximo de inteiro atingido; incremento não realizado');
            return false;
        }

        $novo = (string) ($atual + 1);

        if (!_contador_truncar($f) || !rewind($f)) {
            error_log('contador: falha ao preparar arq.in para escrita');
            _contador_restaurar($f, $original);
            return false;
        }

        $escrito = fwrite($f, $novo);
        if ($escrito !== strlen($novo) || !fflush($f)) {
            error_log('contador: falha ao gravar arq.in');
            _contador_restaurar($f, $original);
            return false;
        }

        return true;
    } finally {
        if ($bloqueado) {
            flock($f, LOCK_UN);
        }
        fclose($f);
    }
}

/**
 * ftruncate() com poucas novas tentativas.
 * No Windows, ftruncate() falha de forma intermitente (cerca de 1 em 5 chamadas sob
 * concorrência) mesmo com LOCK_EX ativo; a falha é transitória e some ao repetir.
 * O bloqueio exclusivo continua ativo durante as tentativas.
 */
function _contador_truncar($f): bool
{
    for ($tentativa = 0; $tentativa < 100; $tentativa++) {
        if (@ftruncate($f, 0)) {
            return true;
        }
        usleep(500);
    }
    return false;
}

/** Tenta devolver ao arquivo o conteúdo anterior após uma escrita que falhou. */
function _contador_restaurar($f, string $original): void
{
    if (_contador_truncar($f) && @rewind($f) && @fwrite($f, $original) !== false) {
        @fflush($f);
        return;
    }
    error_log('contador: não foi possível restaurar o conteúdo original de arq.in');
}

/**
 * Lê o valor atual do contador, sem modificá-lo (bloqueio compartilhado).
 *
 * @return int|false Total de logins (0 se arq.in não existir ou estiver vazio);
 *                   false se houver falha de acesso/leitura ou conteúdo inválido.
 *                   Use "=== false" para testar a falha, pois 0 é um valor válido.
 */
function ler_visitas()
{
    if (!file_exists(_contador_caminho())) {
        return 0;
    }

    $f = _contador_abrir('r');
    if ($f === false) {
        return false;
    }

    $bloqueado = false;

    try {
        if (!flock($f, LOCK_SH)) {
            error_log('contador: não foi possível obter LOCK_SH em arq.in');
            return false;
        }
        $bloqueado = true;

        $conteudo = stream_get_contents($f);
        if ($conteudo === false) {
            error_log('contador: falha ao ler arq.in');
            return false;
        }

        $valor = _contador_interpretar($conteudo);
        if ($valor === null) {
            error_log('contador: conteúdo inválido em arq.in');
            return false;
        }

        return $valor;
    } finally {
        if ($bloqueado) {
            flock($f, LOCK_UN);
        }
        fclose($f);
    }
}
