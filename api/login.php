<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Session cookies
|--------------------------------------------------------------------------
| En local HTTP, laisse secure à false.
| En production HTTPS, passe secure à true.
*/
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => false,
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

function getClientIp(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function sleepJitter(): void
{
    usleep(random_int(200000, 600000));
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

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(405, ['error' => 'Méthode non autorisée']);
}

/*
|--------------------------------------------------------------------------
| Anti brute-force simple en session + IP
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}

if (!isset($_SESSION['login_block_until'])) {
    $_SESSION['login_block_until'] = 0;
}

$now = time();

if ($_SESSION['login_block_until'] > $now) {
    jsonResponse(429, ['error' => 'Trop de tentatives. Réessayez plus tard.']);
}

/*
|--------------------------------------------------------------------------
| Read input
|--------------------------------------------------------------------------
*/
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = [];

if (stripos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        jsonResponse(400, ['error' => 'JSON invalide']);
    }
} else {
    $input = $_POST;
}

$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/
if ($username === '' || $password === '') {
    jsonResponse(400, ['error' => 'Champs requis manquants']);
}

if (mb_strlen($username, 'UTF-8') < 3 || mb_strlen($username, 'UTF-8') > 50) {
    sleepJitter();
    jsonResponse(401, ['error' => 'Identifiants invalides']);
}

if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
    sleepJitter();
    jsonResponse(401, ['error' => 'Identifiants invalides']);
}

if (mb_strlen($password, 'UTF-8') > 500) {
    sleepJitter();
    jsonResponse(401, ['error' => 'Identifiants invalides']);
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

/*
|--------------------------------------------------------------------------
| DB connection
|--------------------------------------------------------------------------
*/
mysqli_report(MYSQLI_REPORT_OFF);

$conn = @new mysqli($host, $user, $pass, $dbName);

if ($conn->connect_error) {
    jsonResponse(500, ['error' => 'Erreur serveur']);
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Query user
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare('SELECT id, username, password, role FROM users WHERE username = ? LIMIT 1');
if (!$stmt) {
    $conn->close();
    jsonResponse(500, ['error' => 'Erreur serveur']);
}

$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();
$row = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;

/*
|--------------------------------------------------------------------------
| Dummy hash to reduce user enumeration timing
|--------------------------------------------------------------------------
*/
$dummyHash = '$2y$10$wH2uMZlX0n3A4k9T4u0M8O8rM2V7m9JxBvQ2r1l8fKcD5sW6nY7q2';

$storedHash = $row['password'] ?? $dummyHash;
$loginOk = false;

if (is_string($storedHash) && password_verify($password, $storedHash)) {
    $loginOk = $row !== null;
}

/*
|--------------------------------------------------------------------------
| Optional legacy plaintext migration
|--------------------------------------------------------------------------
| À garder seulement si tu as encore d’anciens comptes non hashés.
| Dès que tout est migré, supprime ce bloc.
*/
if (!$loginOk && $row !== null && is_string($row['password']) && hash_equals($row['password'], $password)) {
    $loginOk = true;

    $newHash = password_hash($password, PASSWORD_DEFAULT);
    if ($newHash !== false) {
        $update = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        if ($update) {
            $userId = (int)$row['id'];
            $update->bind_param('si', $newHash, $userId);
            $update->execute();
            $update->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Login success / fail
|--------------------------------------------------------------------------
*/
if ($loginOk && $row !== null) {
    session_regenerate_id(true);

    $_SESSION = [];
    $_SESSION['user'] = $row['username'];
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['role'] = $row['role'];
    $_SESSION['ip'] = getClientIp();
    $_SESSION['user_agent'] = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $_SESSION['last_activity'] = time();

    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_block_until'] = 0;

    $stmt->close();
    $conn->close();

    jsonResponse(200, [
        'success' => true,
        'user' => [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'role' => $row['role']
        ]
    ]);
}

/*
|--------------------------------------------------------------------------
| Failed login handling
|--------------------------------------------------------------------------
*/
$_SESSION['login_attempts']++;

if ($_SESSION['login_attempts'] >= 5) {
    $_SESSION['login_block_until'] = time() + 900;
}

sleepJitter();

$stmt->close();
$conn->close();

jsonResponse(401, ['error' => 'Identifiants invalides']);