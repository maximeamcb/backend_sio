<?php

/* ------------------------
   SECURE SESSION SETTINGS
------------------------ */

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => false, // mettre true si HTTPS
    'httponly' => true,
    'samesite' => 'Strict'
]);

session_start();

require_once __DIR__ . '/../dotenv.php';


/* ------------------------
   SECURITY HEADERS
------------------------ */

header('Content-Type: application/json');

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Referrer-Policy: no-referrer");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}


/* ------------------------
   METHOD CHECK
------------------------ */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit();

}


/* ------------------------
   READ INPUT
------------------------ */

$input = json_decode(file_get_contents('php://input'), true);

$username = trim($input['username'] ?? '');
$password = trim($input['password'] ?? '');

if ($username === '' || $password === '') {

    http_response_code(400);
    echo json_encode(['error' => 'Username et password requis']);
    exit();

}


/* ------------------------
   BASIC RATE LIMIT
------------------------ */

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}

if ($_SESSION['login_attempts'] > 5) {

    http_response_code(429);
    echo json_encode(['error' => 'Trop de tentatives, réessayez plus tard']);
    exit();

}


/* ------------------------
   DATABASE CONFIG
------------------------ */

$host = $_ENV['DB_HOST'] ?? 'localhost';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'backend';

$conn = new mysqli($host, $user, $pass, $dbName);

if ($conn->connect_error) {

    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
    exit();

}

$conn->set_charset('utf8mb4');


/* ------------------------
   USER LOOKUP
------------------------ */

$stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");

$stmt->bind_param("s", $username);

$stmt->execute();

$result = $stmt->get_result();


/* ------------------------
   LOGIN LOGIC
------------------------ */

if ($result && $result->num_rows === 1) {

    $row = $result->fetch_assoc();

    if (password_verify($password, $row['password'])) {

        session_regenerate_id(true);

        $_SESSION['user_id'] = $row['id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['role'] = $row['role'];

        $_SESSION['login_attempts'] = 0;

        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $row['id'],
                'username' => $row['username'],
                'role' => $row['role']
            ]
        ]);

    } else {

        $_SESSION['login_attempts']++;

        http_response_code(401);
        echo json_encode(['error' => 'Identifiants invalides']);

    }

} else {

    $_SESSION['login_attempts']++;

    http_response_code(401);
    echo json_encode(['error' => 'Identifiants invalides']);

}


/* ------------------------
   CLEANUP
------------------------ */

$stmt->close();
$conn->close();

?>