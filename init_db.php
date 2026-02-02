<?php
require_once __DIR__ . '/dotenv.php';

$host = $_ENV['DB_HOST'] ?? 'localhost';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'backend';

// Create connection
$conn = new mysqli($host, $user, $pass);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database
$sql = "CREATE DATABASE IF NOT EXISTS $dbName";
if ($conn->query($sql) === TRUE) {
    echo "Database created successfully\n";
} else {
    echo "Error creating database: " . $conn->error . "\n";
}

$conn->select_db($dbName);

$conn->query("SET FOREIGN_KEY_CHECKS = 0");

// Create users table
$conn->query("DROP TABLE IF EXISTS users");
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user'
)";

if ($conn->query($sql) === TRUE) {
    echo "Table users created successfully\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

// Create posts table
$conn->query("DROP TABLE IF EXISTS posts");
$sql = "CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
)";

if ($conn->query($sql) === TRUE) {
    echo "Table posts created successfully\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

$conn->query("TRUNCATE TABLE users");
$conn->query("TRUNCATE TABLE posts");

$conn->query("INSERT INTO users (username, email, password, role) VALUES ('admin', 'admin@example.com', 'password123', 'admin')");
$conn->query("INSERT INTO users (username, email, password, role) VALUES ('guest', 'guest@example.com', 'guest123', 'user')");

$conn->query("SET FOREIGN_KEY_CHECKS = 1");

echo "Database initialized successfully with default users.\n";

$conn->close();
?>
