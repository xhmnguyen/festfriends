<?php
require_once("../session.php");
require_once("../database.php");

if (!isset($_GET['group_id'])) {
    die("Group ID not provided.");
}
$group_id = intval($_GET['group_id']);

// Check if current user is the owner
$stmt = $pdo->prepare("SELECT owner_id FROM user_group WHERE group_id = ?");
$stmt->execute([$group_id]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    die("Group not found.");
}

if ($group['owner_id'] != $_SESSION['user_id']) {
    die("You are not the owner of this group.");
}

// Delete the group
$stmt = $pdo->prepare("DELETE FROM user_group WHERE group_id = ?");
$stmt->execute([$group_id]);

header("Location: ../dashboard.php");
exit();
?>