<?php
require_once("../session.php");
require_once("../report_functions.php");

if (!isset($_GET['concert_id'])) {
    die("Concert ID not provided.");
}

$concert_id = (int)($_GET['concert_id'] ?? 0);

if ($concert_id <= 0) {
    die("Invalid concert ID.");
}

if (!isset($_SESSION['user_id'])) {
    die("You must be logged in.");
}

header("Location: general.php?concert_id=" . urlencode($concert_id));
exit();
?>