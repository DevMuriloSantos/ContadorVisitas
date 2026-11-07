<?php
/**
 * Login. Único ponto do sistema que chama registrar_login().
 *
 * Ordem: já autenticado? -> POST -> CSRF -> validação -> consulta -> password_verify
 *        -> session_regenerate_id -> registrar_login() -> só então grava a autenticação.
 * Toda resposta a um POST termina em redirecionamento (PRG).
 */
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/contador.php';

iniciar_sessao();

// Quem já está autenticado nunca chega ao processamento: sem novo incremento.
// (Requisições simultâneas na mesma sessão são serializadas pelo bloqueio da sessão do PHP.)
redireciona_se_autenticado();

// Hash de uma senha inexistente: iguala o tempo de resposta quando o e-mail não existe.
const HASH_FALSO = '$2y$10$XmaqoPuNRFCRxniMAvvyDO.fY/pK03XmjECo.bXeMjt3R2WVNmqZ2';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $senha = (string) ($_POST['senha'] ?? '');

    // 2. CSRF (token de uso único)
    if (!csrf_validar('login', $_POST['csrf'] ?? null)) {
        flash_definir('danger', 'Requisição inválida. Tente novamente.');
        redirecionar('login.php');
    }

    // 3. Validação básica (limite de 72 bytes: o bcrypt ignora o que passar disso)
    if ($email === '' || $senha === '' || mb_strlen($email) > 150 || strlen($senha) > 72) {
        flash_definir('danger', 'E-mail ou senha inválidos.', ['email' => mb_substr($email, 0, 150)]);
        redirecionar('login.php');
    }

    try {
        // 4. Consulta com prepared statement
        $stmt = conectar()->prepare('SELECT id, nome, senha_hash FROM usuarios WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $usuario = $stmt->fetch();

        // 5. Verificação da senha (sempre executa um password_verify)
        $senhaOk = password_verify($senha, $usuario['senha_hash'] ?? HASH_FALSO) && $usuario !== false;
    } catch (Throwable $erro) {
        error_log('login.php: erro de banco: ' . $erro->getMessage());
        flash_definir('danger', 'Não foi possível concluir o login no momento. Tente novamente mais tarde.');
        redirecionar('login.php');
    }

    // 6. Credenciais incorretas: mensagem genérica, contador intacto
    if (!$senhaOk) {
        flash_definir('danger', 'E-mail ou senha inválidos.', ['email' => $email]);
        redirecionar('login.php');
    }

    // 7. Novo ID de sessão antes de qualquer dado de autenticação (anti fixação de sessão)
    if (!session_regenerate_id(true)) {
        error_log('login.php: session_regenerate_id falhou');
        flash_definir('danger', 'Não foi possível concluir o login no momento. Tente novamente mais tarde.');
        redirecionar('login.php');
    }

    // 8-9. O login só vale se o contador registrar. Se falhar, nada é gravado na sessão.
    if (!registrar_login()) {
        error_log('login.php: registrar_login() falhou; login recusado para o usuário id ' . (int) $usuario['id']);
        flash_definir('danger', 'Não foi possível concluir o login no momento. Tente novamente mais tarde.');
        redirecionar('login.php');
    }

    unset($_SESSION['flash']); // descarta avisos de requisições concorrentes recusadas
    definir_usuario((int) $usuario['id'], (string) $usuario['nome']);

    // 10. PRG
    redirecionar('visitas.php');
}

// GET: exibe o formulário (não incrementa nada)
$flash  = flash_obter();
$antigo = $flash['antigo'] ?? [];

$titulo = 'Entrar';
require __DIR__ . '/includes/header.php';
?>
<div class="card shadow-sm cartao-form">
    <div class="card-body p-4">
        <h1 class="h4 mb-4">Entrar</h1>
        <form method="post" action="login.php" novalidate>
            <?= csrf_campo('login') ?>
            <div class="mb-3">
                <label for="email" class="form-label">E-mail</label>
                <input type="email" class="form-control" id="email" name="email" maxlength="150"
                       value="<?= e($antigo['email'] ?? '') ?>" autocomplete="email" required autofocus>
            </div>
            <div class="mb-3">
                <label for="senha" class="form-label">Senha</label>
                <input type="password" class="form-control" id="senha" name="senha"
                       autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Entrar</button>
        </form>
        <p class="text-center mt-3 mb-0">Não tem conta? <a href="cadastro.php">Cadastre-se</a></p>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
