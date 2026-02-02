<?php
session_start();
require_once __DIR__ . '/dotenv.php';

$host = $_ENV['DB_HOST'] ?? 'localhost';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'backend';

$conn = new mysqli($host, $user, $pass, $dbName);
if ($conn->connect_error) {
    die("La connexion a échoué : " . $conn->connect_error);
}

$selected_table = $_GET['table'] ?? '';
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? '';

$message = '';

// Handle CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selected_table) {
    if (isset($_POST['create'])) {
        $columns = [];
        $values = [];
        foreach ($_POST['fields'] as $col => $val) {
            $columns[] = $col;
            $values[] = "'$val'";
        }
        $sql = "INSERT INTO $selected_table (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ")";
        if ($conn->query($sql)) {
            $message = "Enregistrement créé avec succès !";
        } else {
            $message = "Erreur : " . $conn->error;
        }
    } elseif (isset($_POST['update']) && $id) {
        $updates = [];
        foreach ($_POST['fields'] as $col => $val) {
            $updates[] = "$col = '$val'";
        }
        $sql = "UPDATE $selected_table SET " . implode(',', $updates) . " WHERE id = $id";
        if ($conn->query($sql)) {
            $message = "Enregistrement mis à jour avec succès !";
        } else {
            $message = "Erreur : " . $conn->error;
        }
    }
}

// Handle Delete
if ($action === 'delete' && $selected_table && $id) {
    $sql = "DELETE FROM $selected_table WHERE id = $id";
    if ($conn->query($sql)) {
        $message = "Enregistrement supprimé avec succès !";
    } else {
        $message = "Erreur : " . $conn->error;
    }
}

// Fetch Tables
$tables_result = $conn->query("SHOW TABLES");
$tables = [];
while ($row = $tables_result->fetch_array()) {
    $tables[] = $row[0];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Panneau d'administration - CRUD</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f4; padding: 20px; }
        .container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #eee; }
        .btn { padding: 5px 10px; text-decoration: none; border-radius: 3px; font-size: 0.9em; }
        .btn-edit { background: #ffc107; color: black; }
        .btn-delete { background: #dc3545; color: white; }
        .btn-create { background: #28a745; color: white; margin-bottom: 20px; display: inline-block; }
        .nav { margin-bottom: 20px; }
        .nav a { margin-right: 15px; text-decoration: none; color: #007bff; }
        .nav a.active { font-weight: bold; text-decoration: underline; }
        .message { padding: 10px; background: #d4edda; color: #155724; border-radius: 4px; margin-bottom: 20px; }
        form input { padding: 8px; width: 95%; margin-bottom: 10px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Panneau d'administration</h1>
    <div class="nav">
        <a href="index.php">Tableau de bord</a>
        <a href="admin.php">Toutes les tables</a>
        <?php foreach ($tables as $t): ?>
            <a href="admin.php?table=<?php echo $t; ?>" class="<?php echo $selected_table === $t ? 'active' : ''; ?>"><?php echo $t; ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($message): ?>
        <div class="message"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if ($selected_table): ?>
        <h2>Table : <?php echo htmlspecialchars($selected_table); ?></h2>
        
        <?php if ($action === 'edit' || $action === 'new'): 
            $edit_data = [];
            if ($action === 'edit' && $id) {
                $edit_res = $conn->query("SELECT * FROM $selected_table WHERE id = $id");
                $edit_data = $edit_res->fetch_assoc();
            }
            
            // Get columns for the form
            $cols_res = $conn->query("DESCRIBE $selected_table");
            ?>
            <h3><?php echo $action === 'edit' ? 'Modifier l\'enregistrement' : 'Créer un nouvel enregistrement'; ?></h3>
            <form method="POST">
                <?php while ($col = $cols_res->fetch_assoc()): 
                    if ($col['Field'] === 'id' || $col['Field'] === 'created_at' || $col['Field'] === 'executed_at') continue;
                    $val = $edit_data[$col['Field']] ?? '';
                    ?>
                    <label><?php echo $col['Field']; ?></label>
                    <input type="text" name="fields[<?php echo $col['Field']; ?>]" value="<?php echo htmlspecialchars($val); ?>">
                <?php endwhile; ?>
                <button type="submit" name="<?php echo $action === 'edit' ? 'update' : 'create'; ?>" class="btn btn-create">
                    <?php echo $action === 'edit' ? 'Enregistrer les modifications' : 'Créer l\'enregistrement'; ?>
                </button>
                <a href="admin.php?table=<?php echo $selected_table; ?>">Annuler</a>
            </form>
        <?php else: ?>
            <a href="admin.php?table=<?php echo $selected_table; ?>&action=new" class="btn btn-create">Nouvel enregistrement</a>
            <table>
                <?php
                $rows_res = $conn->query("SELECT * FROM $selected_table");
                $first = true;
                while ($row = $rows_res->fetch_assoc()):
                    if ($first): ?>
                        <thead><tr>
                            <?php foreach (array_keys($row) as $th): ?><th><?php echo $th; ?></th><?php endforeach; ?>
                            <th>Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php $first = false; 
                    endif; ?>
                    <tr>
                        <?php foreach ($row as $val): ?><td><?php echo ($val); ?></td><?php endforeach; ?>
                        <td>
                            <a href="admin.php?table=<?php echo $selected_table; ?>&action=edit&id=<?php echo $row['id'] ?? ''; ?>" class="btn btn-edit">Modifier</a>
                            <a href="admin.php?table=<?php echo $selected_table; ?>&action=delete&id=<?php echo $row['id'] ?? ''; ?>" class="btn btn-delete" onclick="return confirm('Supprimer ?')">Supprimer</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php else: ?>
        <h2>Sélectionnez une table à gérer :</h2>
        <ul>
            <?php foreach ($tables as $t): ?>
                <li><a href="admin.php?table=<?php echo $t; ?>"><?php echo $t; ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
</body>
</html>
<?php $conn->close(); ?>
