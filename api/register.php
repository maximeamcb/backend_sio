<?php
require_once __DIR__ . '/../dotenv.php';

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'backend';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';

    $conn = new mysqli($host, $user, $pass, $dbName);

    if ($conn->connect_error) {
        echo json_encode(['error' => 'La connexion à la base de données a échoué']);
        exit();
    }

    $query = "INSERT INTO users (username, email, password, role) VALUES ('$username', '$email', '$password', 'user')";
    
    if ($conn->query($query) === TRUE) {
        echo json_encode(['success' => true, 'message' => 'Inscription réussie !']);
    } else {
        echo json_encode(['error' => 'Erreur : ' . $conn->error]);
    }
    
    $conn->close();
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
}
?>
