<?php
/**
 * Conexão PDO reutilizável.
 * Uso: $pdo = conectar();
 */

function conectar(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $arquivo = __DIR__ . '/config.php';
    if (!is_file($arquivo)) {
        throw new RuntimeException('Arquivo includes/config.php não encontrado. Copie config.example.php.');
    }

    $db = (require $arquivo)['db'];

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['nome'],
        $db['charset']
    );

    try {
        $pdo = new PDO($dsn, $db['usuario'], $db['senha'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Registra o detalhe no log e não expõe credenciais ao usuário.
        error_log('Falha na conexão com o banco: ' . $e->getMessage());
        throw new RuntimeException('Não foi possível conectar ao banco de dados.');
    }

    return $pdo;
}
