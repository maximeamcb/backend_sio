<?php
require_once __DIR__ . '/../dotenv.php';

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

$host = $_ENV['DB_HOST'] ?? 'localhost';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'backend';

$conn = new mysqli($host, $user, $pass, $dbName);

if ($conn->connect_error) {
    echo json_encode(['error' => 'La connexion à la base de données a échoué']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sql = "SELECT id, username, email, role FROM users";
    $result = $conn->query($sql);
    $users = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    echo json_encode($users);
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';
    $role = $input['role'] ?? 'user';

    $query = "INSERT INTO users (username, email, password, role) VALUES ('$username', '$email', '$password', '$role')";
    
    if ($conn->query($query) === TRUE) {
        echo json_encode(['success' => true, 'message' => 'Utilisateur créé avec succès']);
    } else {
        echo json_encode(['error' => 'La création de l\'utilisateur a échoué : ' . $conn->error]);
    }
} elseif ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (empty($id)) {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
    }

    if (!empty($id)) {
        $query = "DELETE FROM users WHERE id = $id";
        if ($conn->query($query) === TRUE) {
            echo json_encode(['success' => true, 'message' => 'Utilisateur supprimé avec succès']);
        } else {
            echo json_encode(['error' => 'La suppression de l\'utilisateur a échoué : ' . $conn->error]);
        }
    } else {
        echo json_encode(['error' => 'L\'ID est requis pour la suppression']);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
}

$conn->close();
?>
