<?php
/**
 * Autenticação: sessão, CSRF, mensagens temporárias e proteção de páginas.
 * Não acessa o banco nem o contador: isso fica em login.php / cadastro.php.
 *
 * Chaves usadas em $_SESSION:
 *   usuario_id, usuario_nome  -> só existem se o usuário está autenticado
 *   csrf[<formulario>]        -> tokens CSRF
 *   flash                     -> mensagem temporária (lida uma única vez)
 */

const SESSAO_NOME = 'CVSESSID';

/* ---------- Utilidades ---------- */

/** Escapa texto para saída HTML. */
function e(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redireciona e encerra a requisição (evita que o código seguinte rode). */
function redirecionar(string $destino): never
{
    header('Location: ' . $destino);
    exit;
}

function usando_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/* ---------- Sessão ---------- */

/** Inicia a sessão com configuração segura. Pode ser chamada várias vezes por requisição. */
function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');    // recusa IDs que o servidor não criou
    ini_set('session.use_only_cookies', '1');   // nunca aceita ID pela URL
    ini_set('session.use_trans_sid', '0');

    session_name(SESSAO_NOME);
    session_set_cookie_params([
        'lifetime' => 0,                        // cookie de sessão: some ao fechar o navegador
        'path'     => '/',
        'secure'   => usando_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/** Destrói a sessão atual e invalida o cookie. */
function encerrar_sessao(): void
{
    iniciar_sessao();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?: 'Lax',
        ]);
    }

    session_destroy();
}

/* ---------- Estado de autenticação ---------- */

function usuario_autenticado(): bool
{
    iniciar_sessao();
    return isset($_SESSION['usuario_id']) && is_int($_SESSION['usuario_id']);
}

/** @return array{id:int,nome:string}|null Dados básicos do usuário logado. */
function usuario_atual(): ?array
{
    if (!usuario_autenticado()) {
        return null;
    }
    return ['id' => $_SESSION['usuario_id'], 'nome' => (string) ($_SESSION['usuario_nome'] ?? '')];
}

/** Estabelece a autenticação na sessão (chamar só depois de regenerar o ID). */
function definir_usuario(int $id, string $nome): void
{
    $_SESSION['usuario_id']   = $id;
    $_SESSION['usuario_nome'] = $nome;
}

/** Bloqueia páginas restritas: sem login, redireciona para login.php. */
function exige_login(): void
{
    if (!usuario_autenticado()) {
        flash_definir('warning', 'Faça login para acessar esta página.');
        redirecionar('login.php');
    }
}

/** Páginas só para visitantes (login e cadastro): quem já entrou vai para visitas.php. */
function redireciona_se_autenticado(string $destino = 'visitas.php'): void
{
    if (usuario_autenticado()) {
        redirecionar($destino);
    }
}

/* ---------- CSRF ---------- */

/** Token do formulário; é criado na primeira chamada e reaproveitado até ser consumido. */
function csrf_token(string $formulario): string
{
    iniciar_sessao();
    if (empty($_SESSION['csrf'][$formulario])) {
        $_SESSION['csrf'][$formulario] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'][$formulario];
}

/**
 * Valida o token enviado. Com $uso_unico (padrão) o token é descartado ao ser
 * verificado, certo ou errado, então não pode ser reutilizado.
 */
function csrf_validar(string $formulario, mixed $enviado, bool $uso_unico = true): bool
{
    iniciar_sessao();
    $esperado = $_SESSION['csrf'][$formulario] ?? '';

    if ($uso_unico) {
        unset($_SESSION['csrf'][$formulario]);

        // Persiste o consumo AGORA. Sem isso, requisições simultâneas que já esperam o
        // bloqueio da mesma sessão leeriam o token ainda válido (o session_regenerate_id()
        // do login grava na sessão nova, não na antiga) e repetiriam o login/incremento.
        session_write_close();
        session_start();
    }

    return is_string($enviado) && $esperado !== '' && hash_equals($esperado, $enviado);
}

/** Campo hidden pronto para uso nos formulários. */
function csrf_campo(string $formulario): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token($formulario)) . '">';
}

/* ---------- Mensagens temporárias (flash) ---------- */

/**
 * Guarda uma mensagem para a próxima página.
 * @param string          $tipo      success | danger | warning | info (classes do Bootstrap)
 * @param string|string[] $mensagens
 * @param array           $antigo    valores a repreencher no formulário (nunca senhas)
 */
function flash_definir(string $tipo, string|array $mensagens, array $antigo = []): void
{
    iniciar_sessao();
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensagens' => (array) $mensagens, 'antigo' => $antigo];
}

/** Retorna a mensagem pendente (e a remove), ou null. */
function flash_obter(): ?array
{
    iniciar_sessao();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}
