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
        jsonResponse(429, ['error' => 'Trop de tentatives. Réessayez plus tard.']);
    }

    $_SESSION['rate_limits'][$key]['count']++;
}

function sleepJitter(): void
{
    usleep(random_int(200000, 500000));
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
    header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'POST') {
    jsonResponse(405, ['error' => 'Méthode non autorisée']);
}

/*
|--------------------------------------------------------------------------
| Rate limit register
|--------------------------------------------------------------------------
*/
rateLimit('register', 5, 900);

/*
|--------------------------------------------------------------------------
| Read input
|--------------------------------------------------------------------------
*/
$input = getRequestData();

$username = trim((string)($input['username'] ?? ''));
$email = trim((string)($input['email'] ?? ''));
$password = (string)($input['password'] ?? '');

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/
if ($username === '' || $email === '' || $password === '') {
    jsonResponse(400, ['error' => 'Tous les champs sont requis']);
}

if (mb_strlen($username, 'UTF-8') < 3 || mb_strlen($username, 'UTF-8') > 30) {
    jsonResponse(400, ['error' => 'Nom utilisateur invalide']);
}

if (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
    jsonResponse(400, ['error' => 'Nom utilisateur invalide']);
}

if (mb_strlen($email, 'UTF-8') > 190) {
    jsonResponse(400, ['error' => 'Email invalide']);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(400, ['error' => 'Email invalide']);
}

if (strlen($password) < 12 || strlen($password) > 255) {
    jsonResponse(400, ['error' => 'Mot de passe invalide']);
}

if (
    !preg_match('/[A-Z]/', $password) ||
    !preg_match('/[a-z]/', $password) ||
    !preg_match('/[0-9]/', $password) ||
    !preg_match('/[^a-zA-Z0-9]/', $password)
) {
    jsonResponse(400, ['error' => 'Mot de passe trop faible']);
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
| Check existing user/email
|--------------------------------------------------------------------------
| On évite les messages trop précis pour limiter l’énumération.
*/
$check = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
if (!$check) {
    $conn->close();
    jsonResponse(500, ['error' => 'Erreur serveur']);
}

$check->bind_param('ss', $username, $email);
$check->execute();
$result = $check->get_result();

if ($result && $result->num_rows > 0) {
    $check->close();
    $conn->close();
    sleepJitter();
    jsonResponse(409, ['error' => 'Compte déjà existant ou données invalides']);
}

$check->close();

/*
|--------------------------------------------------------------------------
| Hash password
|--------------------------------------------------------------------------
*/
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
if ($passwordHash === false) {
    $conn->close();
    jsonResponse(500, ['error' => 'Erreur serveur']);
}

/*
|--------------------------------------------------------------------------
| Insert user
|--------------------------------------------------------------------------
*/
$stmt = $conn->prepare(
    "INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'user')"
);

if (!$stmt) {
    $conn->close();
    jsonResponse(500, ['error' => 'Erreur serveur']);
}

$stmt->bind_param('sss', $username, $email, $passwordHash);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    jsonResponse(500, ['error' => 'Erreur inscription']);
}

$stmt->close();
$conn->close();

jsonResponse(201, [
    'success' => true,
    'message' => 'Compte créé'
]);