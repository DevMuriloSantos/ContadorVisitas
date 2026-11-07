<?php
/**
 * Cadastro de usuários. Não faz login automático e NÃO toca no contador.
 * Toda resposta a um POST termina em redirecionamento (PRG).
 */
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';

iniciar_sessao();
redireciona_se_autenticado();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $nome      = trim((string) ($_POST['nome'] ?? ''));
    $email     = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $senha     = (string) ($_POST['senha'] ?? '');
    $confirmar = (string) ($_POST['confirmar'] ?? '');
    $antigo    = ['nome' => mb_substr($nome, 0, 100), 'email' => mb_substr($email, 0, 150)]; // nunca senhas

    if (!csrf_validar('cadastro', $_POST['csrf'] ?? null)) {
        flash_definir('danger', 'Requisição inválida. Tente novamente.');
        redirecionar('cadastro.php');
    }

    $erros = [];

    if ($nome === '') {
        $erros[] = 'Informe o nome.';
    } elseif (mb_strlen($nome) > 100) {
        $erros[] = 'O nome deve ter no máximo 100 caracteres.';
    }

    if ($email === '') {
        $erros[] = 'Informe o e-mail.';
    } elseif (mb_strlen($email) > 150) {
        $erros[] = 'O e-mail deve ter no máximo 150 caracteres.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'Informe um e-mail válido.';
    }

    if ($senha === '') {
        $erros[] = 'Informe a senha.';
    } elseif (mb_strlen($senha) < 8) {
        $erros[] = 'A senha deve ter pelo menos 8 caracteres.';
    } elseif (strlen($senha) > 72) {
        $erros[] = 'A senha deve ter no máximo 72 bytes (o algoritmo bcrypt ignora o excedente).';
    }

    if ($confirmar === '') {
        $erros[] = 'Confirme a senha.';
    } elseif ($senha !== $confirmar) {
        $erros[] = 'A confirmação de senha não confere.';
    }

    if ($erros) {
        flash_definir('danger', $erros, $antigo);
        redirecionar('cadastro.php');
    }

    try {
        $pdo = conectar();

        $stmt = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() !== false) {
            flash_definir('danger', 'Este e-mail já está cadastrado.', $antigo);
            redirecionar('cadastro.php');
        }

        $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash) VALUES (?, ?, ?)');
        $stmt->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
    } catch (PDOException $erro) {
        // 1062 = violação do UNIQUE (cadastro simultâneo do mesmo e-mail)
        if (($erro->errorInfo[1] ?? 0) === 1062) {
            flash_definir('danger', 'Este e-mail já está cadastrado.', $antigo);
        } else {
            error_log('cadastro.php: erro de banco: ' . $erro->getMessage());
            flash_definir('danger', 'Não foi possível concluir o cadastro no momento. Tente novamente mais tarde.', $antigo);
        }
        redirecionar('cadastro.php');
    } catch (Throwable $erro) {
        error_log('cadastro.php: ' . $erro->getMessage());
        flash_definir('danger', 'Não foi possível concluir o cadastro no momento. Tente novamente mais tarde.', $antigo);
        redirecionar('cadastro.php');
    }

    flash_definir('success', 'Cadastro realizado com sucesso! Faça login para continuar.');
    redirecionar('login.php');
}

$flash  = flash_obter();
$antigo = $flash['antigo'] ?? [];

$titulo = 'Cadastrar-se';
require __DIR__ . '/includes/header.php';
?>
<div class="card shadow-sm cartao-form">
    <div class="card-body p-4">
        <h1 class="h4 mb-4">Criar conta</h1>
        <form method="post" action="cadastro.php" novalidate>
            <?= csrf_campo('cadastro') ?>
            <div class="mb-3">
                <label for="nome" class="form-label">Nome</label>
                <input type="text" class="form-control" id="nome" name="nome" maxlength="100"
                       value="<?= e($antigo['nome'] ?? '') ?>" autocomplete="name" required autofocus>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">E-mail</label>
                <input type="email" class="form-control" id="email" name="email" maxlength="150"
                       value="<?= e($antigo['email'] ?? '') ?>" autocomplete="email" required>
            </div>
            <div class="mb-3">
                <label for="senha" class="form-label">Senha</label>
                <input type="password" class="form-control" id="senha" name="senha"
                       autocomplete="new-password" aria-describedby="ajudaSenha" required>
                <div id="ajudaSenha" class="form-text">Mínimo de 8 caracteres.</div>
            </div>
            <div class="mb-3">
                <label for="confirmar" class="form-label">Confirmar senha</label>
                <input type="password" class="form-control" id="confirmar" name="confirmar"
                       autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Cadastrar</button>
        </form>
        <p class="text-center mt-3 mb-0">Já tem conta? <a href="login.php">Entrar</a></p>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
