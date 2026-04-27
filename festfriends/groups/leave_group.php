<?php
require_once("../session.php");
require_once("../database.php");

if (!isset($_GET['group_id'])) {
    die("Group ID not provided.");
}
$group_id = intval($_GET['group_id']);

// Check if user is the owner
$stmt = $pdo->prepare("SELECT owner_id FROM user_group WHERE group_id = ?");
$stmt->execute([$group_id]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    die("Group not found.");
}

if ($group['owner_id'] == $_SESSION['user_id']) {
    die("Owners cannot leave their own group. Use delete option instead.");
}

// Remove user from group_member
$stmt = $pdo->prepare("DELETE FROM group_member WHERE group_id = ? AND user_id = ?");
$stmt->execute([$group_id, $_SESSION['user_id']]);

header("Location: ../dashboard.php");
exit();
?>