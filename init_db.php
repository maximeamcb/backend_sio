<?php
declare(strict_types=1);

require_once __DIR__ . '/dotenv.php';

mysqli_report(MYSQLI_REPORT_OFF);

function out(string $message): void
{
    echo $message . PHP_EOL;
}

function fail(string $message, ?mysqli $conn = null): never
{
    if ($conn instanceof mysqli) {
        $conn->close();
    }

    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'backend';

/*
|--------------------------------------------------------------------------
| Validation minimale du nom de base
|--------------------------------------------------------------------------
*/
if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
    fail('Nom de base de données invalide.');
}

/*
|--------------------------------------------------------------------------
| Connexion MySQL serveur
|--------------------------------------------------------------------------
*/
$conn = @new mysqli($host, $user, $pass);

if ($conn->connect_error) {
    fail('Connexion MySQL échouée : ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Création base de données
|--------------------------------------------------------------------------
*/
$sqlCreateDb = "CREATE DATABASE IF NOT EXISTS `$dbName`
                CHARACTER SET utf8mb4
                COLLATE utf8mb4_unicode_ci";

if (!$conn->query($sqlCreateDb)) {
    fail('Erreur création base : ' . $conn->error, $conn);
}

out("Base de données prête : $dbName");

if (!$conn->select_db($dbName)) {
    fail('Impossible de sélectionner la base : ' . $conn->error, $conn);
}

/*
|--------------------------------------------------------------------------
| Transactions
|--------------------------------------------------------------------------
*/
$conn->begin_transaction();

try {
    /*
    |--------------------------------------------------------------------------
    | Table users
    |--------------------------------------------------------------------------
    */
    $sqlUsers = <<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(30) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    if (!$conn->query($sqlUsers)) {
        throw new RuntimeException('Erreur création table users : ' . $conn->error);
    }

    out('Table users prête');

    /*
    |--------------------------------------------------------------------------
    | Table posts
    |--------------------------------------------------------------------------
    */
    $sqlPosts = <<<SQL
CREATE TABLE IF NOT EXISTS posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_posts_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    KEY idx_posts_user_id (user_id),
    KEY idx_posts_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    if (!$conn->query($sqlPosts)) {
        throw new RuntimeException('Erreur création table posts : ' . $conn->error);
    }

    out('Table posts prête');

    /*
    |--------------------------------------------------------------------------
    | Admin par défaut
    |--------------------------------------------------------------------------
    | Créé seulement s’il n’existe pas déjà
    |--------------------------------------------------------------------------
    */
    $defaultAdminUsername = $_ENV['DEFAULT_ADMIN_USERNAME'] ?? 'admin';
    $defaultAdminEmail = $_ENV['DEFAULT_ADMIN_EMAIL'] ?? 'admin@example.com';
    $defaultAdminPassword = $_ENV['DEFAULT_ADMIN_PASSWORD'] ?? 'ChangeMe123!';

    if (
        !preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $defaultAdminUsername) ||
        !filter_var($defaultAdminEmail, FILTER_VALIDATE_EMAIL) ||
        strlen($defaultAdminPassword) < 12
    ) {
        throw new RuntimeException('Identifiants admin par défaut invalides dans le .env');
    }

    $checkAdmin = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    if (!$checkAdmin) {
        throw new RuntimeException('Erreur préparation vérification admin : ' . $conn->error);
    }

    $checkAdmin->bind_param('ss', $defaultAdminUsername, $defaultAdminEmail);
    $checkAdmin->execute();
    $adminResult = $checkAdmin->get_result();

    if ($adminResult && $adminResult->num_rows === 0) {
        $passwordHash = password_hash($defaultAdminPassword, PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            $checkAdmin->close();
            throw new RuntimeException('Impossible de hasher le mot de passe admin');
        }

        $insertAdmin = $conn->prepare(
            "INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'admin')"
        );

        if (!$insertAdmin) {
            $checkAdmin->close();
            throw new RuntimeException('Erreur préparation insertion admin : ' . $conn->error);
        }

        $insertAdmin->bind_param('sss', $defaultAdminUsername, $defaultAdminEmail, $passwordHash);

        if (!$insertAdmin->execute()) {
            $insertAdmin->close();
            $checkAdmin->close();
            throw new RuntimeException('Erreur insertion admin : ' . $insertAdmin->error);
        }

        $insertAdmin->close();
        out('Compte admin par défaut créé');
    } else {
        out('Compte admin déjà présent, aucune recréation');
    }

    $checkAdmin->close();

    /*
    |--------------------------------------------------------------------------
    | Utilisateur de démo optionnel
    |--------------------------------------------------------------------------
    */
    $createDemoUser = ($_ENV['CREATE_DEMO_USER'] ?? 'false') === 'true';

    if ($createDemoUser) {
        $demoUsername = $_ENV['DEMO_USERNAME'] ?? 'guest';
        $demoEmail = $_ENV['DEMO_EMAIL'] ?? 'guest@example.com';
        $demoPassword = $_ENV['DEMO_PASSWORD'] ?? 'GuestDemo123!';

        $checkDemo = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
        if (!$checkDemo) {
            throw new RuntimeException('Erreur préparation vérification demo user : ' . $conn->error);
        }

        $checkDemo->bind_param('ss', $demoUsername, $demoEmail);
        $checkDemo->execute();
        $demoResult = $checkDemo->get_result();

        if ($demoResult && $demoResult->num_rows === 0) {
            $demoHash = password_hash($demoPassword, PASSWORD_DEFAULT);

            if ($demoHash === false) {
                $checkDemo->close();
                throw new RuntimeException('Impossible de hasher le mot de passe demo');
            }

            $insertDemo = $conn->prepare(
                "INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')"
            );

            if (!$insertDemo) {
                $checkDemo->close();
                throw new RuntimeException('Erreur préparation insertion demo user : ' . $conn->error);
            }

            $insertDemo->bind_param('sss', $demoUsername, $demoEmail, $demoHash);

            if (!$insertDemo->execute()) {
                $insertDemo->close();
                $checkDemo->close();
                throw new RuntimeException('Erreur insertion demo user : ' . $insertDemo->error);
            }

            $insertDemo->close();
            out('Compte démo créé');
        } else {
            out('Compte démo déjà présent, aucune recréation');
        }

        $checkDemo->close();
    }

    $conn->commit();
    out('Initialisation terminée avec succès');
} catch (Throwable $e) {
    $conn->rollback();
    fail($e->getMessage(), $conn);
}

$conn->close();