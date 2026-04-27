<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function display_footer() {
    echo '<footer class="mt-20 text-center">';
    echo '<p>&copy; 2026 FestFriends</p>';
    echo '</footer>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>FestFriends</title>
    <link rel="stylesheet" href="/~hnguye14/festfriends/assets/css/styles.css">
</head>
<body>

<header class="top-nav">
    <div class="left-nav">
        <div class="logo">
            <a href="/~hnguye14/festfriends/dashboard.php">FestFriends</a>
        </div>

        <nav class="nav-links">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="/~hnguye14/festfriends/dashboard.php">Dashboard</a>

                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                    <a href="/~hnguye14/festfriends/admin.php">Admin</a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
    </div>

    <?php if (isset($_SESSION['user_id'])): ?>
        <div class="right-nav">
            <a href="/~hnguye14/festfriends/profile.php">
                <?php if (!empty($_SESSION['image'])): ?>
                    <img src="/~hnguye14/festfriends/<?php echo h($_SESSION['image']); ?>" alt="Profile" class="profile-icon-img">
                <?php else: ?>
                    <div class="profile-icon">
                        <?php echo h(strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'], 0, 1))); ?>
                    </div>
                <?php endif; ?>
            </a>
        </div>
    <?php else: ?>
        <div class="right-nav">
            <a href="/~hnguye14/festfriends/login.php" class="btn">Log In</a>
            <a href="/~hnguye14/festfriends/register.php" class="btn">Sign Up</a>
        </div>
    <?php endif; ?>
</header>

<hr>