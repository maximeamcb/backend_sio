<?php
session_start();
require_once __DIR__ . '/dotenv.php';

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit();
}

$host = $_ENV['DB_HOST'] ?? 'localhost';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'backend';

$conn = new mysqli($host, $user, $pass, $dbName);

if ($conn->connect_error) {
    die("La connexion a échoué : " . $conn->connect_error);
}

$user_id = $_GET['id'] ?? 0;

$query = "SELECT * FROM users WHERE id = $user_id";
$result = $conn->query($query);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Utilisateur</title>
    <style>
        body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #f0f0f0; }
        .profile-container { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 350px; }
        h1 { text-align: center; color: #333; margin-bottom: 1.5rem; }
        .data-row { margin-bottom: 1rem; border-bottom: 1px solid #eee; padding-bottom: 0.5rem; }
        .label { font-weight: bold; color: #555; display: block; margin-bottom: 0.2rem; }
        .value { color: #333; font-family: monospace; }
        .back-link { display: block; text-align: center; margin-top: 1.5rem; color: #007bff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="profile-container">
        <h1>Profil Utilisateur</h1>
        <?php if ($result && $row = $result->fetch_assoc()): ?>
            <div class="data-row">
                <span class="label">Nom d'utilisateur :</span>
                <span class="value"><?php echo ($row['username']); ?></span>
            </div>
            <div class="data-row">
                <span class="label">E-mail :</span>
                <span class="value"><?php echo ($row['email']); ?></span>
            </div>
            <div class="data-row">
                <span class="label">Rôle :</span>
                <span class="value"><?php echo ($row['role']); ?></span>
            </div>
            <div class="data-row">
                <span class="label">Mot de passe :</span>
                <span class="value"><?php echo ($row['password']); ?></span>
            </div>
        <?php else: ?>
            <p style="text-align: center; color: red;">Utilisateur non trouvé !</p>
        <?php endif; ?>
        <a href="index.php" class="back-link">Retour au tableau de bord</a>
    </div>
</body>
</html>
<?php $conn->close(); ?>
