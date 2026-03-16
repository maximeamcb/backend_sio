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

function requireAdminSession(): void
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

    if (($_SESSION['role'] ?? '') !== 'admin') {
        jsonResponse(403, ['error' => 'Accès interdit']);
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

function sanitizeUserOutput(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'username' => htmlspecialchars((string)$row['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'email' => htmlspecialchars((string)$row['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'role' => $row['role'],
        'created_at' => $row['created_at']
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

header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

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
| GET USERS
|--------------------------------------------------------------------------
*/
if ($method === 'GET') {
    requireAdminSession();
    rateLimit('users_get', 30, 60);

    $stmt = $conn->prepare(
        'SELECT id, username, email, role, created_at
         FROM users
         ORDER BY id DESC
         LIMIT 200'
    );

    if (!$stmt) {
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur serveur']);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = sanitizeUserOutput($row);
    }

    $stmt->close();
    $conn->close();

    jsonResponse(200, $users);
}

/*
|--------------------------------------------------------------------------
| CREATE USER
|--------------------------------------------------------------------------
*/
if ($method === 'POST') {
    requireAdminSession();
    rateLimit('users_create', 10, 300);

    $input = getRequestData();

    $username = trim((string)($input['username'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $role = trim((string)($input['role'] ?? 'user'));

    if ($username === '' || $email === '' || $password === '') {
        $conn->close();
        jsonResponse(400, ['error' => 'Tous les champs sont requis']);
    }

    if (mb_strlen($username, 'UTF-8') < 3 || mb_strlen($username, 'UTF-8') > 30) {
        $conn->close();
        jsonResponse(400, ['error' => 'Nom utilisateur invalide']);
    }

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
        $conn->close();
        jsonResponse(400, ['error' => 'Nom utilisateur invalide']);
    }

    if (mb_strlen($email, 'UTF-8') > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $conn->close();
        jsonResponse(400, ['error' => 'Email invalide']);
    }

    if (strlen($password) < 12 || strlen($password) > 255) {
        $conn->close();
        jsonResponse(400, ['error' => 'Mot de passe invalide']);
    }

    if (
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !preg_match('/[^a-zA-Z0-9]/', $password)
    ) {
        $conn->close();
        jsonResponse(400, ['error' => 'Mot de passe trop faible']);
    }

    $allowedRoles = ['user', 'admin'];
    if (!in_array($role, $allowedRoles, true)) {
        $conn->close();
        jsonResponse(400, ['error' => 'Rôle invalide']);
    }

    $check = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    if (!$check) {
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur serveur']);
    }

    $check->bind_param('ss', $username, $email);
    $check->execute();
    $checkResult = $check->get_result();

    if ($checkResult && $checkResult->num_rows > 0) {
        $check->close();
        $conn->close();
        jsonResponse(409, ['error' => 'Utilisateur ou email déjà existant']);
    }

    $check->close();

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur serveur']);
    }

    $stmt = $conn->prepare(
        'INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)'
    );

    if (!$stmt) {
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur serveur']);
    }

    $stmt->bind_param('ssss', $username, $email, $passwordHash, $role);

    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur création utilisateur']);
    }

    $newUserId = $stmt->insert_id;
    $stmt->close();

    $fetch = $conn->prepare(
        'SELECT id, username, email, role, created_at
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    if (!$fetch) {
        $conn->close();
        jsonResponse(201, [
            'success' => true,
            'message' => 'Utilisateur créé'
        ]);
    }

    $fetch->bind_param('i', $newUserId);
    $fetch->execute();
    $result = $fetch->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $fetch->close();
    $conn->close();

    jsonResponse(201, [
        'success' => true,
        'message' => 'Utilisateur créé',
        'user' => $row ? sanitizeUserOutput($row) : null
    ]);
}

/*
|--------------------------------------------------------------------------
| DELETE USER
|--------------------------------------------------------------------------
*/
if ($method === 'DELETE') {
    requireAdminSession();
    rateLimit('users_delete', 10, 300);

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

    $userIdToDelete = (int)$id;
    $currentUserId = (int)$_SESSION['user_id'];

    if ($userIdToDelete === $currentUserId) {
        $conn->close();
        jsonResponse(400, ['error' => 'Impossible de supprimer votre propre compte connecté']);
    }

    $check = $conn->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    if (!$check) {
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur serveur']);
    }

    $check->bind_param('i', $userIdToDelete);
    $check->execute();
    $checkResult = $check->get_result();

    if (!$checkResult || $checkResult->num_rows !== 1) {
        $check->close();
        $conn->close();
        jsonResponse(404, ['error' => 'Utilisateur introuvable']);
    }

    $check->close();

    $stmt = $conn->prepare('DELETE FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur serveur']);
    }

    $stmt->bind_param('i', $userIdToDelete);

    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        jsonResponse(500, ['error' => 'Erreur suppression']);
    }

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        $conn->close();
        jsonResponse(404, ['error' => 'Utilisateur introuvable']);
    }

    $stmt->close();
    $conn->close();

    jsonResponse(200, [
        'success' => true,
        'message' => 'Utilisateur supprimé'
    ]);
}

$conn->close();
jsonResponse(405, ['error' => 'Méthode non autorisée']);