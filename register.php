<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => false, // passe à true en HTTPS
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_name('APPSESSID');
session_start();

require_once __DIR__ . '/dotenv.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    return is_string($token)
        && is_string($sessionToken)
        && hash_equals($sessionToken, $token);
}

function rateLimit(string $key, int $maxAttempts, int $windowSeconds): bool
{
    if (!isset($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }

    $now = time();

    if (!isset($_SESSION['rate_limits'][$key])) {
        $_SESSION['rate_limits'][$key] = [
            'count' => 0,
            'start' => $now
        ];
    }

    $bucket = $_SESSION['rate_limits'][$key];

    if (($now - $bucket['start']) > $windowSeconds) {
        $_SESSION['rate_limits'][$key] = [
            'count' => 0,
            'start' => $now
        ];
        $bucket = $_SESSION['rate_limits'][$key];
    }

    if ($bucket['count'] >= $maxAttempts) {
        return false;
    }

    $_SESSION['rate_limits'][$key]['count']++;
    return true;
}

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'backend';

mysqli_report(MYSQLI_REPORT_OFF);

$error = '';
$success = '';

$oldUsername = '';
$oldEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rateLimit('register_form', 5, 900)) {
        $error = "Trop de tentatives. Réessayez plus tard.";
    } elseif (!verifyCsrfToken()) {
        $error = "Requête invalide.";
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $oldUsername = $username;
        $oldEmail = $email;

        if ($username === '' || $email === '' || $password === '') {
            $error = "Tous les champs sont requis.";
        } elseif (mb_strlen($username, 'UTF-8') < 3 || mb_strlen($username, 'UTF-8') > 30) {
            $error = "Nom d'utilisateur invalide.";
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
            $error = "Nom d'utilisateur invalide.";
        } elseif (mb_strlen($email, 'UTF-8') > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Email invalide.";
        } elseif (strlen($password) < 12 || strlen($password) > 255) {
            $error = "Le mot de passe doit contenir entre 12 et 255 caractères.";
        } elseif (
            !preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) ||
            !preg_match('/[0-9]/', $password) ||
            !preg_match('/[^a-zA-Z0-9]/', $password)
        ) {
            $error = "Le mot de passe doit contenir une majuscule, une minuscule, un chiffre et un caractère spécial.";
        } else {
            $conn = @new mysqli($host, $user, $pass, $dbName);

            if ($conn->connect_error) {
                $error = "Erreur serveur.";
            } else {
                $conn->set_charset('utf8mb4');

                $check = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');

                if (!$check) {
                    $error = "Erreur serveur.";
                } else {
                    $check->bind_param('ss', $username, $email);
                    $check->execute();
                    $result = $check->get_result();

                    if ($result && $result->num_rows > 0) {
                        $error = "Compte déjà existant ou données invalides.";
                    } else {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                        if ($passwordHash === false) {
                            $error = "Erreur serveur.";
                        } else {
                            $stmt = $conn->prepare(
                                "INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')"
                            );

                            if (!$stmt) {
                                $error = "Erreur serveur.";
                            } else {
                                $stmt->bind_param('sss', $username, $email, $passwordHash);

                                if ($stmt->execute()) {
                                    $success = "Inscription réussie. Vous pouvez maintenant vous connecter.";
                                    $oldUsername = '';
                                    $oldEmail = '';
                                } else {
                                    $error = "Erreur lors de l'inscription.";
                                }

                                $stmt->close();
                            }
                        }
                    }

                    $check->close();
                }

                $conn->close();
            }
        }
    }
}

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S'inscrire</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f0f8ff;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .register-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.08);
            width: 100%;
            max-width: 360px;
            border-top: 5px solid #28a745;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-top: 0;
        }
        .error {
            color: #842029;
            background: #f8d7da;
            padding: 10px;
            border-radius: 4px;
            text-align: center;
            margin-bottom: 1rem;
        }
        .success {
            color: #155724;
            background: #d4edda;
            padding: 10px;
            border-radius: 4px;
            text-align: center;
            margin-bottom: 1rem;
        }
        label {
            display: block;
            margin-top: 0.75rem;
            margin-bottom: 0.25rem;
            color: #444;
            font-weight: bold;
        }
        input {
            width: 100%;
            padding: 0.65rem;
            margin: 0;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: 0.75rem;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 1rem;
        }
        button:hover {
            opacity: 0.96;
        }
        .hint {
            font-size: 0.88rem;
            color: #666;
            margin-top: 0.35rem;
        }
        .login-link {
            text-align: center;
            margin-top: 1rem;
            display: block;
            color: #007bff;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <h1>S'inscrire</h1>

        <?php if ($error !== ''): ?>
            <div class="error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="success"><?php echo e($success); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

            <label for="username">Nom d'utilisateur</label>
            <input
                id="username"
                type="text"
                name="username"
                maxlength="30"
                value="<?php echo e($oldUsername); ?>"
                required
            >

            <label for="email">E-mail</label>
            <input
                id="email"
                type="email"
                name="email"
                maxlength="190"
                value="<?php echo e($oldEmail); ?>"
                required
            >

            <label for="password">Mot de passe</label>
            <input
                id="password"
                type="password"
                name="password"
                minlength="12"
                maxlength="255"
                required
            >
            <div class="hint">
                12 caractères minimum avec majuscule, minuscule, chiffre et caractère spécial.
            </div>

            <button type="submit">S'inscrire</button>
        </form>

        <a href="login.php" class="login-link">Déjà un compte ? Se connecter ici</a>
    </div>
</body>
</html>
