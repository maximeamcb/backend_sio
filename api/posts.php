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
    $sql = "SELECT posts.*, users.username FROM posts JOIN users ON posts.user_id = users.id ORDER BY created_at DESC";
    $result = $conn->query($sql);
    $posts = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $posts[] = $row;
        }
    }
    echo json_encode($posts);
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $user_id = $input['user_id'] ?? 0;
    $content = $input['content'] ?? '';

    $query = "INSERT INTO posts (user_id, content) VALUES ($user_id, '$content')";
    
    if ($conn->query($query) === TRUE) {
        echo json_encode(['success' => true, 'message' => 'Message créé avec succès']);
    } else {
        echo json_encode(['error' => 'La création du message a échoué : ' . $conn->error]);
    }
} elseif ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (empty($id)) {
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
    }

    if (!empty($id)) {
        $query = "DELETE FROM posts WHERE id = $id";
        if ($conn->query($query) === TRUE) {
            echo json_encode(['success' => true, 'message' => 'Message supprimé avec succès']);
        } else {
            echo json_encode(['error' => 'La suppression du message a échoué : ' . $conn->error]);
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
