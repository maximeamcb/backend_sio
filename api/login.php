<?php
session_start();
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
    $password = $input['password'] ?? '';

    $conn = new mysqli($host, $user, $pass, $dbName);

    if ($conn->connect_error) {
        echo json_encode(['error' => 'La connexion à la base de données a échoué']);
        exit();
    }

    $query = "SELECT * FROM users WHERE username = '$username' AND password = '$password'";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $_SESSION['user'] = $row['username'];
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['role'] = $row['role'];
        
        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $row['id'],
                'username' => $row['username'],
                'role' => $row['role']
            ]
        ]);
    } else {
        echo json_encode(['error' => 'Nom d\'utilisateur ou mot de passe invalide']);
    }
    
    $conn->close();
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
}
?>
