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

/*
|--------------------------------------------------------------------------
| Security headers
|--------------------------------------------------------------------------
*/
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
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function deny(int $code, string $message): never
{
    http_response_code($code);
    echo "<h1>" . e($message) . "</h1>";
    exit;
}

function requireAdminSession(): void
{
    if (
        !isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['ip'], $_SESSION['user_agent'], $_SESSION['last_activity'])
    ) {
        deny(401, 'Non authentifié');
    }

    $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $currentUserAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    if (
        !hash_equals((string)$_SESSION['ip'], $currentIp) ||
        !hash_equals((string)$_SESSION['user_agent'], $currentUserAgent)
    ) {
        session_unset();
        session_destroy();
        deny(401, 'Session invalide');
    }

    if ((time() - (int)$_SESSION['last_activity']) > 1800) {
        session_unset();
        session_destroy();
        deny(401, 'Session expirée');
    }

    if (($_SESSION['role'] ?? '') !== 'admin') {
        deny(403, 'Accès interdit');
    }

    $_SESSION['last_activity'] = time();
}

function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(): void
{
    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($token) || !is_string($sessionToken) || !hash_equals($sessionToken, $token)) {
        deny(403, 'Token CSRF invalide');
    }
}

function getDb(): mysqli
{
    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $user = $_ENV['DB_USER'] ?? 'root';
    $pass = $_ENV['DB_PASS'] ?? '';
    $dbName = $_ENV['DB_NAME'] ?? 'backend';

    mysqli_report(MYSQLI_REPORT_OFF);

    $conn = @new mysqli($host, $user, $pass, $dbName);
    if ($conn->connect_error) {
        deny(500, 'Erreur serveur');
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}

function getAllowedTables(mysqli $conn): array
{
    $tables = [];

    $result = $conn->query('SHOW TABLES');
    if ($result) {
        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            $table = (string)$row[0];

            /*
            | Exclusion défensive de tables sensibles éventuelles
            */
            if (in_array($table, ['migrations', 'password_resets', 'sessions'], true)) {
                continue;
            }

            $tables[] = $table;
        }
    }

    return $tables;
}

function validateTableName(string $table, array $allowedTables): string
{
    if ($table === '' || !in_array($table, $allowedTables, true)) {
        deny(400, 'Table invalide');
    }

    return $table;
}

function getTableColumns(mysqli $conn, string $table): array
{
    $columns = [];
    $result = $conn->query("DESCRIBE `$table`");

    if (!$result) {
        deny(500, 'Impossible de lire la structure de table');
    }

    while ($row = $result->fetch_assoc()) {
        $field = (string)$row['Field'];

        $columns[$field] = [
            'type' => (string)$row['Type'],
            'nullable' => ((string)$row['Null'] === 'YES'),
            'key' => (string)$row['Key']
        ];
    }

    return $columns;
}

function getEditableColumns(array $columns): array
{
    $blocked = ['id', 'created_at', 'updated_at', 'executed_at'];
    return array_values(array_filter(array_keys($columns), fn(string $col): bool => !in_array($col, $blocked, true)));
}

function isIntLikeColumn(string $dbType): bool
{
    return preg_match('/^(tinyint|smallint|mediumint|int|bigint)/i', $dbType) === 1;
}

function buildBindTypes(array $values, array $columnsMeta, array $selectedColumns): string
{
    $types = '';

    foreach ($selectedColumns as $col) {
        $types .= isIntLikeColumn($columnsMeta[$col]['type']) ? 'i' : 's';
    }

    return $types;
}

requireAdminSession();
$conn = getDb();
$allowedTables = getAllowedTables($conn);
$csrfToken = getCsrfToken();

$selectedTable = isset($_GET['table']) ? trim((string)$_GET['table']) : '';
$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
$id = $_GET['id'] ?? null;
$message = '';
$error = '';

if ($selectedTable !== '') {
    $selectedTable = validateTableName($selectedTable, $allowedTables);
}

/*
|--------------------------------------------------------------------------
| POST actions only for create/update/delete
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedTable !== '') {
    verifyCsrfToken();

    $columnsMeta = getTableColumns($conn, $selectedTable);
    $editableColumns = getEditableColumns($columnsMeta);

    if (isset($_POST['create'])) {
        $submittedFields = $_POST['fields'] ?? [];
        if (!is_array($submittedFields)) {
            deny(400, 'Données invalides');
        }

        $insertCols = [];
        $insertValues = [];

        foreach ($editableColumns as $col) {
            if (array_key_exists($col, $submittedFields)) {
                $value = trim((string)$submittedFields[$col]);
                $insertCols[] = $col;
                $insertValues[] = $value;
            }
        }

        if ($insertCols === []) {
            $error = "Aucune donnée à insérer.";
        } else {
            $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
            $sql = "INSERT INTO `$selectedTable` (`" . implode('`,`', $insertCols) . "`) VALUES ($placeholders)";
            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                $error = "Erreur serveur.";
            } else {
                $types = buildBindTypes($insertValues, $columnsMeta, $insertCols);
                $stmt->bind_param($types, ...$insertValues);

                if ($stmt->execute()) {
                    $message = "Enregistrement créé avec succès.";
                } else {
                    $error = "Erreur lors de la création.";
                }

                $stmt->close();
            }
        }
    }

    if (isset($_POST['update'])) {
        $submittedFields = $_POST['fields'] ?? [];
        $postedId = $_POST['id'] ?? null;

        if (!filter_var($postedId, FILTER_VALIDATE_INT)) {
            deny(400, 'ID invalide');
        }

        $recordId = (int)$postedId;

        if (!is_array($submittedFields)) {
            deny(400, 'Données invalides');
        }

        $setParts = [];
        $updateValues = [];
        $selectedCols = [];

        foreach ($editableColumns as $col) {
            if (array_key_exists($col, $submittedFields)) {
                $value = trim((string)$submittedFields[$col]);
                $setParts[] = "`$col` = ?";
                $updateValues[] = $value;
                $selectedCols[] = $col;
            }
        }

        if ($setParts === []) {
            $error = "Aucune modification à enregistrer.";
        } else {
            $sql = "UPDATE `$selectedTable` SET " . implode(', ', $setParts) . " WHERE `id` = ? LIMIT 1";
            $stmt = $conn->prepare($sql);

            if (!$stmt) {
                $error = "Erreur serveur.";
            } else {
                $types = buildBindTypes($updateValues, $columnsMeta, $selectedCols) . 'i';
                $params = [...$updateValues, $recordId];
                $stmt->bind_param($types, ...$params);

                if ($stmt->execute()) {
                    $message = "Enregistrement mis à jour avec succès.";
                } else {
                    $error = "Erreur lors de la mise à jour.";
                }

                $stmt->close();
            }
        }
    }

    if (isset($_POST['delete'])) {
        $postedId = $_POST['id'] ?? null;

        if (!filter_var($postedId, FILTER_VALIDATE_INT)) {
            deny(400, 'ID invalide');
        }

        $recordId = (int)$postedId;

        /*
        | Empêche la suppression de son propre compte dans users
        */
        if ($selectedTable === 'users' && $recordId === (int)$_SESSION['user_id']) {
            $error = "Impossible de supprimer votre propre compte connecté.";
        } else {
            $stmt = $conn->prepare("DELETE FROM `$selectedTable` WHERE `id` = ? LIMIT 1");

            if (!$stmt) {
                $error = "Erreur serveur.";
            } else {
                $stmt->bind_param('i', $recordId);

                if ($stmt->execute()) {
                    $message = $stmt->affected_rows === 1
                        ? "Enregistrement supprimé avec succès."
                        : "Enregistrement introuvable.";
                } else {
                    $error = "Erreur lors de la suppression.";
                }

                $stmt->close();
            }
        }
    }
}

$editData = [];
$tableRows = [];
$tableColumns = [];

if ($selectedTable !== '') {
    $tableColumns = getTableColumns($conn, $selectedTable);

    if ($action === 'edit' && filter_var($id, FILTER_VALIDATE_INT)) {
        $recordId = (int)$id;
        $stmt = $conn->prepare("SELECT * FROM `$selectedTable` WHERE `id` = ? LIMIT 1");

        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $result = $stmt->get_result();
            $editData = $result ? ($result->fetch_assoc() ?: []) : [];
            $stmt->close();
        }
    }

    if ($action !== 'new' && $action !== 'edit') {
        $result = $conn->query("SELECT * FROM `$selectedTable` ORDER BY `id` DESC LIMIT 200");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $tableRows[] = $row;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Panneau d'administration sécurisé</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px; color: #222; }
        .container { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.08); }
        h1, h2, h3 { margin-top: 0; }
        .nav { margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 10px; }
        .nav a { text-decoration: none; color: #0b57d0; }
        .nav a.active { font-weight: bold; text-decoration: underline; }
        .message { padding: 10px; background: #d4edda; color: #155724; border-radius: 4px; margin-bottom: 20px; }
        .error { padding: 10px; background: #f8d7da; color: #842029; border-radius: 4px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; background: white; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; vertical-align: top; }
        th { background: #eee; }
        .btn { padding: 8px 12px; border: 0; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-create { background: #198754; color: white; }
        .btn-edit { background: #ffc107; color: black; }
        .btn-delete { background: #dc3545; color: white; }
        .btn-cancel { background: #6c757d; color: white; }
        form.inline { display: inline; }
        form label { display: block; margin-top: 10px; font-weight: bold; }
        form input[type="text"],
        form input[type="email"],
        form input[type="password"],
        form textarea,
        form select {
            width: 100%;
            max-width: 700px;
            padding: 8px;
            margin-top: 4px;
            box-sizing: border-box;
        }
        textarea { min-height: 100px; resize: vertical; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .muted { color: #666; }
        code { background: #f1f1f1; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Panneau d'administration</h1>

    <div class="nav">
        <a href="index.php">Tableau de bord</a>
        <a href="admin.php">Toutes les tables</a>
        <?php foreach ($allowedTables as $table): ?>
            <a href="admin.php?table=<?php echo e($table); ?>" class="<?php echo $selectedTable === $table ? 'active' : ''; ?>">
                <?php echo e($table); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message"><?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if ($selectedTable === ''): ?>
        <h2>Sélectionnez une table à gérer</h2>
        <ul>
            <?php foreach ($allowedTables as $table): ?>
                <li><a href="admin.php?table=<?php echo e($table); ?>"><?php echo e($table); ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <h2>Table : <?php echo e($selectedTable); ?></h2>
        <p class="muted">Affichage limité à 200 lignes pour éviter les abus.</p>

        <?php if ($action === 'new' || $action === 'edit'): ?>
            <?php $editableColumns = getEditableColumns($tableColumns); ?>
            <h3><?php echo $action === 'edit' ? 'Modifier un enregistrement' : 'Créer un nouvel enregistrement'; ?></h3>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="id" value="<?php echo e($id); ?>">
                <?php endif; ?>

                <?php foreach ($editableColumns as $column): ?>
                    <?php $value = $editData[$column] ?? ''; ?>
                    <label for="field_<?php echo e($column); ?>"><?php echo e($column); ?></label>

                    <?php if (str_contains(strtolower($column), 'password')): ?>
                        <input
                            id="field_<?php echo e($column); ?>"
                            type="password"
                            name="fields[<?php echo e($column); ?>]"
                            value=""
                            autocomplete="new-password"
                        >
                    <?php elseif (str_contains(strtolower($column), 'email')): ?>
                        <input
                            id="field_<?php echo e($column); ?>"
                            type="email"
                            name="fields[<?php echo e($column); ?>]"
                            value="<?php echo e($value); ?>"
                        >
                    <?php elseif (strlen((string)$value) > 100): ?>
                        <textarea id="field_<?php echo e($column); ?>" name="fields[<?php echo e($column); ?>]"><?php echo e($value); ?></textarea>
                    <?php else: ?>
                        <input
                            id="field_<?php echo e($column); ?>"
                            type="text"
                            name="fields[<?php echo e($column); ?>]"
                            value="<?php echo e($value); ?>"
                        >
                    <?php endif; ?>
                <?php endforeach; ?>

                <div class="actions" style="margin-top: 16px;">
                    <button type="submit" name="<?php echo $action === 'edit' ? 'update' : 'create'; ?>" class="btn btn-create">
                        <?php echo $action === 'edit' ? 'Enregistrer les modifications' : 'Créer'; ?>
                    </button>
                    <a href="admin.php?table=<?php echo e($selectedTable); ?>" class="btn btn-cancel">Annuler</a>
                </div>
            </form>

        <?php else: ?>
            <p>
                <a href="admin.php?table=<?php echo e($selectedTable); ?>&action=new" class="btn btn-create">Nouvel enregistrement</a>
            </p>

            <?php if ($tableRows === []): ?>
                <p>Aucune donnée trouvée.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <?php foreach (array_keys($tableRows[0]) as $column): ?>
                                <th><?php echo e($column); ?></th>
                            <?php endforeach; ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tableRows as $row): ?>
                            <tr>
                                <?php foreach ($row as $value): ?>
                                    <td><?php echo e($value); ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <div class="actions">
                                        <?php if (isset($row['id'])): ?>
                                            <a href="admin.php?table=<?php echo e($selectedTable); ?>&action=edit&id=<?php echo e($row['id']); ?>" class="btn btn-edit">Modifier</a>

                                            <form method="POST" class="inline" onsubmit="return confirm('Supprimer cet enregistrement ?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="id" value="<?php echo e($row['id']); ?>">
                                                <button type="submit" name="delete" class="btn btn-delete">Supprimer</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted">Aucune action</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
<?php $conn->close(); ?>