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

function destroySessionAndRedirect(): never
{
    session_unset();

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

function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(): void
{
    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($token) || !is_string($sessionToken) || !hash_equals($sessionToken, $token)) {
        http_response_code(403);
        exit('Token CSRF invalide');
    }
}

requireValidSession();

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'backend';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = @new mysqli($host, $user, $pass, $dbName);

if ($conn->connect_error) {
    http_response_code(500);
    exit('Erreur serveur');
}

$conn->set_charset('utf8mb4');

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content'])) {
    verifyCsrfToken();

    $content = trim((string)$_POST['content']);
    $userId = (int)$_SESSION['user_id'];

    if ($content === '') {
        $error = 'Le message est vide.';
    } elseif (mb_strlen($content, 'UTF-8') > 1000) {
        $error = 'Le message est trop long.';
    } else {
        $stmt = $conn->prepare('INSERT INTO posts (user_id, content) VALUES (?, ?)');
        if (!$stmt) {
            $error = 'Erreur serveur.';
        } else {
            $stmt->bind_param('is', $userId, $content);

            if ($stmt->execute()) {
                header('Location: index.php?posted=1');
                $stmt->close();
                $conn->close();
                exit;
            } else {
                $error = 'Erreur lors de la publication.';
            }

            $stmt->close();
        }
    }
}

if (isset($_GET['posted']) && $_GET['posted'] === '1') {
    $message = 'Message publié avec succès.';
}

$posts = [];
$stmt = $conn->prepare(
    'SELECT posts.id, posts.content, posts.created_at, posts.user_id, users.username
     FROM posts
     INNER JOIN users ON posts.user_id = users.id
     ORDER BY posts.created_at DESC
     LIMIT 100'
);

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $posts[] = $row;
    }

    $stmt->close();
}

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forum</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f9;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2rem;
            color: #222;
        }
        .forum-container {
            width: 100%;
            max-width: 700px;
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.08);
        }
        h1, h3 {
            text-align: center;
            color: #333;
        }
        .welcome-msg {
            text-align: center;
            margin-bottom: 2rem;
        }
        .admin-badge {
            color: red;
            font-size: 0.8rem;
            font-weight: bold;
        }
        .post-form textarea {
            width: 100%;
            min-height: 100px;
            padding: 0.75rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            resize: vertical;
            margin-bottom: 1rem;
        }
        .post-form button {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            cursor: pointer;
        }
        .post-form button:hover {
            opacity: 0.95;
        }
        .message-success {
            padding: 10px;
            background: #d4edda;
            color: #155724;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .message-error {
            padding: 10px;
            background: #f8d7da;
            color: #842029;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .posts-list {
            margin-top: 2rem;
        }
        .post {
            border-bottom: 1px solid #eee;
            padding: 1rem 0;
        }
        .post-header {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            font-size: 0.9rem;
            color: #777;
            margin-bottom: 0.5rem;
        }
        .post-author {
            font-weight: bold;
            color: #007bff;
        }
        .post-content {
            color: #333;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .nav-links {
            margin-top: 2rem;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .nav-links a {
            color: #007bff;
            text-decoration: none;
        }
        .nav-links a.admin-link {
            color: red;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="forum-container">
        <h1>Forum</h1>

        <div class="welcome-msg">
            Bienvenue, <strong><?php echo e($_SESSION['user']); ?></strong>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                <span class="admin-badge">[ADMIN]</span>
            <?php endif; ?>
        </div>

        <?php if ($message !== ''): ?>
            <div class="message-success"><?php echo e($message); ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="message-error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <div class="post-form">
            <h3>Publier quelque chose...</h3>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <textarea name="content" placeholder="Qu'avez-vous en tête ?" maxlength="1000" required></textarea>
                <button type="submit">Publier</button>
            </form>
        </div>

        <div class="posts-list">
            <h3>Messages récents</h3>
            <?php if ($posts !== []): ?>
                <?php foreach ($posts as $post): ?>
                    <div class="post">
                        <div class="post-header">
                            <span class="post-author"><?php echo e($post['username']); ?></span>
                            <span class="post-date"><?php echo e($post['created_at']); ?></span>
                        </div>
                        <div class="post-content"><?php echo e($post['content']); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: #777;">Aucun message pour l'instant.</p>
            <?php endif; ?>
        </div>

        <div class="nav-links">
            <a href="profile.php?id=<?php echo e($_SESSION['user_id']); ?>">Profil</a>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                <a href="admin.php" class="admin-link">Panneau d'administration</a>
            <?php endif; ?>
            <a href="logout.php">Déconnexion</a>
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>