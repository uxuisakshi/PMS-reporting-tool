<?php
require 'config/database.php';
$db = Database::getInstance();
$stmt = $db->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['role'] . ": " . $row['cnt'] . "\n";
}
