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

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
function jsonResponse(int $statusCode, array $data): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getRequestData(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            jsonResponse(400, ['error' => 'JSON invalide']);
        }

        return $decoded;
    }

    return $_POST;
}

function requireAuthenticatedSession(): void
{
    if (
        !isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['ip'], $_SESSION['user_agent'], $_SESSION['last_activity'])
    ) {
        jsonResponse(401, ['error' => 'Non authentifié']);
    }

    $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $currentUserAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    if (
        !hash_equals((string)$_SESSION['ip'], $currentIp) ||
        !hash_equals((string)$_SESSION['user_agent'], $currentUserAgent)
    ) {
        session_unset();
        session_destroy();
        jsonResponse(401, ['error' => 'Session invalide']);
    }

    if ((time() - (int)$_SESSION['last_activity']) > 1800) {
        session_unset();
        session_destroy();
        jsonResponse(401, ['error' => 'Session expirée']);
    }

    $_SESSION['last_activity'] = time();
}

function rateLimit(string $key, int $maxAttempts, int $windowSeconds): void
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
        jsonResponse(429, ['error' => 'Trop de requêtes. Réessayez plus tard.']);
    }

    $_SESSION['rate_limits'][$key]['count']++;
}

function sanitizePostOutput(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'content' => htmlspecialchars((string)$row['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'created_at' => $row['created_at'],
        'username' => htmlspecialchars((string)$row['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    ];
}

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/
$allowedOrigins = [
    'http://localhost:8000',
    'https://anabella-lardaceous-windingly.ngrok-free.dev'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| DB config
|--------------------------------------------------------------------------
*/
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'backend';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = @new mysqli($host, $user, $pass, $dbName);
if ($conn->connect_error) {
    jsonResponse(500, ['error' => 'Erreur serveur']);
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| GET POSTS
|--------------------------------------------------------------------------
*/
if ($method === 'GET') {
    $stmt = $conn->prepare(
        'SELECT posts.id, posts.content, posts.created_at, users.username
         FROM posts
         INNER JOIN users ON posts.user_id = users.id
         ORDER BY posts.created_at DESC
         LIMIT 100'
    );

    if (!$stmt) {
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur serveur']);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $posts = [];

    while ($row = $result->fetch_assoc()) {
        $posts[] = sanitizePostOutput($row);
    }

    $stmt->close();
    $conn->close();

    jsonResponse(200, $posts);
}

/*
|--------------------------------------------------------------------------
| POST CREATE
|--------------------------------------------------------------------------
*/
if ($method === 'POST') {
    requireAuthenticatedSession();
    rateLimit('create_post', 10, 60);

    $input = getRequestData();
    $content = trim((string)($input['content'] ?? ''));

    if ($content === '') {
        $conn->close();
        jsonResponse(400, ['error' => 'Message vide']);
    }

    if (mb_strlen($content, 'UTF-8') > 1000) {
        $conn->close();
        jsonResponse(400, ['error' => 'Message trop long']);
    }

    $userId = (int)$_SESSION['user_id'];

    $stmt = $conn->prepare('INSERT INTO posts (user_id, content) VALUES (?, ?)');
    if (!$stmt) {
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur serveur']);
    }

    $stmt->bind_param('is', $userId, $content);

    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur création message']);
    }

    $newPostId = $stmt->insert_id;
    $stmt->close();

    $fetch = $conn->prepare(
        'SELECT posts.id, posts.content, posts.created_at, users.username
         FROM posts
         INNER JOIN users ON posts.user_id = users.id
         WHERE posts.id = ?
         LIMIT 1'
    );

    if (!$fetch) {
        $conn->close();
        jsonResponse(201, [
            'success' => true,
            'message' => 'Message créé'
        ]);
    }

    $fetch->bind_param('i', $newPostId);
    $fetch->execute();
    $result = $fetch->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $fetch->close();
    $conn->close();

    jsonResponse(201, [
        'success' => true,
        'message' => 'Message créé',
        'post' => $row ? sanitizePostOutput($row) : null
    ]);
}

/*
|--------------------------------------------------------------------------
| DELETE POST
|--------------------------------------------------------------------------
*/
if ($method === 'DELETE') {
    requireAuthenticatedSession();
    rateLimit('delete_post', 20, 60);

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        $input = [];
    }

    $id = $input['id'] ?? $_GET['id'] ?? null;

    if (!filter_var($id, FILTER_VALIDATE_INT)) {
        $conn->close();
        jsonResponse(400, ['error' => 'ID invalide']);
    }

    $postId = (int)$id;
    $currentUserId = (int)$_SESSION['user_id'];
    $currentRole = (string)$_SESSION['role'];

    $check = $conn->prepare('SELECT user_id FROM posts WHERE id = ? LIMIT 1');
    if (!$check) {
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur serveur']);
    }

    $check->bind_param('i', $postId);
    $check->execute();
    $result = $check->get_result();

    if (!$result || $result->num_rows !== 1) {
        $check->close();
        $conn->close();
        jsonResponse(404, ['error' => 'Post introuvable']);
    }

    $post = $result->fetch_assoc();
    $postOwnerId = (int)$post['user_id'];

    $check->close();

    if ($postOwnerId !== $currentUserId && $currentRole !== 'admin') {
        $conn->close();
        jsonResponse(403, ['error' => 'Permission refusée']);
    }

    $stmt = $conn->prepare('DELETE FROM posts WHERE id = ? LIMIT 1');
    if (!$stmt) {
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur serveur']);
    }

    $stmt->bind_param('i', $postId);

    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur suppression']);
    }

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        $conn->close();
        jsonResponse(404, ['error' => 'Post introuvable']);
    }

    $stmt->close();
    $conn->close();

    jsonResponse(200, [
        'success' => true,
        'message' => 'Message supprimé'
    ]);
}

$conn->close();
jsonResponse(405, ['error' => 'Méthode non autorisée']);