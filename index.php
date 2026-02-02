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

// Handle post submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content'])) {
    $content = $_POST['content'];
    $user_id = $_SESSION['user_id'];
    
    $sql = "INSERT INTO posts (user_id, content) VALUES ($user_id, '$content')";
    $conn->query($sql);
}

// Fetch all posts
$posts_query = "SELECT posts.*, users.username FROM posts JOIN users ON posts.user_id = users.id ORDER BY created_at DESC";
$posts_result = $conn->query($posts_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forum</title>
    <style>
        body { font-family: sans-serif; background-color: #f4f4f9; display: flex; flex-direction: column; align-items: center; padding: 2rem; }
        .forum-container { width: 100%; max-width: 600px; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; }
        .post-form textarea { width: 100%; height: 100px; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; resize: vertical; margin-bottom: 1rem; }
        .post-form button { background-color: #007bff; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 4px; cursor: pointer; float: right; }
        .posts-list { margin-top: 3rem; clear: both; }
        .post { border-bottom: 1px solid #eee; padding: 1rem 0; }
        .post-header { display: flex; justify-content: space-between; font-size: 0.85rem; color: #777; margin-bottom: 0.5rem; }
        .post-author { font-weight: bold; color: #007bff; }
        .post-content { color: #333; line-height: 1.6; }
        .nav-links { margin-top: 2rem; }
        .nav-links a { margin: 0 1rem; color: #007bff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="forum-container">
        <h1>Forum</h1>
        
        <div class="welcome-msg" style="text-align: center; margin-bottom: 2rem;">
            Bienvenue, <strong><?php echo ($_SESSION['user']); ?></strong> ! 
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <span style="color: red; font-size: 0.8rem;">[ADMIN]</span>
            <?php endif; ?>
        </div>

        <div class="post-form">
            <h3>Publier quelque chose...</h3>
            <form method="POST">
                <textarea name="content" placeholder="Qu'avez-vous en tête ?" required></textarea>
                <button type="submit">Publier</button>
            </form>
        </div>

        <div class="posts-list">
            <h3>Messages récents</h3>
            <?php if ($posts_result && $posts_result->num_rows > 0): ?>
                <?php while($post = $posts_result->fetch_assoc()): ?>
                    <div class="post">
                        <div class="post-header">
                            <span class="post-author"><?php echo ($post['username']); ?></span>
                            <span class="post-date"><?php echo $post['created_at']; ?></span>
                        </div>
                        <div class="post-content">
                            <?php echo $post['content']; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="text-align: center; color: #777;">Aucun message pour l'instant.</p>
            <?php endif; ?>
        </div>

        <div class="nav-links">
            <a href="profile.php?id=<?php echo $_SESSION['user_id']; ?>">Profil</a>
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="admin.php" style="color: red;">Panneau d'administration</a>
            <?php endif; ?>
            <a href="logout.php">Déconnexion</a>
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>
