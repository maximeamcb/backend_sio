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

function redirectToLogin(): never
{
    header('Location: login.php');
    exit;
}

function deny(int $code, string $message): never
{
    http_response_code($code);
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Erreur</title></head><body>';
    echo '<h1>' . e($message) . '</h1>';
    echo '<p><a href="index.php">Retour</a></p>';
    echo '</body></html>';
    exit;
}

function destroySessionAndRedirect(): never
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }

    session_destroy();
    redirectToLogin();
}

function requireValidSession(): void
{
    if (
        !isset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['role'], $_SESSION['ip'], $_SESSION['user_agent'], $_SESSION['last_activity'])
    ) {
        redirectToLogin();
    }

    $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $currentUserAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    if (
        !hash_equals((string)$_SESSION['ip'], $currentIp) ||
        !hash_equals((string)$_SESSION['user_agent'], $currentUserAgent)
    ) {
        destroySessionAndRedirect();
    }

    if ((time() - (int)$_SESSION['last_activity']) > 1800) {
        destroySessionAndRedirect();
    }

    $_SESSION['last_activity'] = time();
}

requireValidSession();

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'backend';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = @new mysqli($host, $user, $pass, $dbName);
if ($conn->connect_error) {
    deny(500, 'Erreur serveur');
}

$conn->set_charset('utf8mb4');

$requestedUserId = $_GET['id'] ?? null;

if (!filter_var($requestedUserId, FILTER_VALIDATE_INT)) {
    $conn->close();
    deny(400, 'ID utilisateur invalide');
}

$requestedUserId = (int)$requestedUserId;
$currentUserId = (int)$_SESSION['user_id'];
$currentRole = (string)$_SESSION['role'];

/*
|--------------------------------------------------------------------------
| Un utilisateur normal ne peut voir que son propre profil.
| Un admin peut voir les profils des autres.
|--------------------------------------------------------------------------
*/
if ($requestedUserId !== $currentUserId && $currentRole !== 'admin') {
    $conn->close();
    deny(403, 'Accès interdit');
}

$stmt = $conn->prepare(
    'SELECT id, username, email, role, created_at
     FROM users
     WHERE id = ?
     LIMIT 1'
);

if (!$stmt) {
    $conn->close();
    deny(500, 'Erreur serveur');
}

$stmt->bind_param('i', $requestedUserId);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result ? $result->fetch_assoc() : null;

$stmt->close();
$conn->close();

if (!$userData) {
    deny(404, 'Utilisateur non trouvé');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil utilisateur</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f0f0f0;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .profile-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.08);
            width: 100%;
            max-width: 420px;
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 1.5rem;
        }
        .data-row {
            margin-bottom: 1rem;
            border-bottom: 1px solid #eee;
            padding-bottom: 0.75rem;
        }
        .label {
            font-weight: bold;
            color: #555;
            display: block;
            margin-bottom: 0.2rem;
        }
        .value {
            color: #333;
            font-family: monospace;
            word-break: break-word;
        }
        .back-links {
            display: flex;
            justify-content: center;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }
        .back-link {
            color: #007bff;
            text-decoration: none;
        }
        .admin-badge {
            color: red;
            font-size: 0.8rem;
            font-weight: bold;
            margin-left: 0.4rem;
        }
    </style>
</head>
<body>
    <div class="profile-container">
        <h1>
            Profil utilisateur
            <?php if (($userData['role'] ?? '') === 'admin'): ?>
                <span class="admin-badge">[ADMIN]</span>
            <?php endif; ?>
        </h1>

        <div class="data-row">
            <span class="label">ID :</span>
            <span class="value"><?php echo e($userData['id']); ?></span>
        </div>

        <div class="data-row">
            <span class="label">Nom d'utilisateur :</span>
            <span class="value"><?php echo e($userData['username']); ?></span>
        </div>

        <div class="data-row">
            <span class="label">E-mail :</span>
            <span class="value"><?php echo e($userData['email']); ?></span>
        </div>

        <div class="data-row">
            <span class="label">Rôle :</span>
            <span class="value"><?php echo e($userData['role']); ?></span>
        </div>

        <?php if (isset($userData['created_at'])): ?>
            <div class="data-row">
                <span class="label">Créé le :</span>
                <span class="value"><?php echo e($userData['created_at']); ?></span>
            </div>
        <?php endif; ?>

        <div class="back-links">
            <a href="index.php" class="back-link">Retour au forum</a>
            <a href="logout.php" class="back-link">Déconnexion</a>
        </div>
    </div>
</body>
</html>