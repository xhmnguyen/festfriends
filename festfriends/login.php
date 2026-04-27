<?php
session_start();
require_once("database.php");

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = "";
$email = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = "All fields are required.";
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT user_id, username, full_name, password, image, role
                FROM user
                WHERE email = ?
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['image'] = $user['image'];
                $_SESSION['role'] = $user['role'];

                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            $error = "Database error.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - FestFriends</title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>

<div class="container flex" style="justify-content:center; align-items:center; height:100vh;">
    <div class="card" style="max-width:400px; width:100%;">

        <h1 class="text-center">FestFriends</h1>
        <h3 class="text-center" style="margin-top:5px;">Login</h3>

        <?php if (!empty($error)): ?>
            <p style="color:red; text-align:center;">
                <?php echo htmlspecialchars($error); ?>
            </p>
        <?php endif; ?>

        <form method="post">
            <label>Email:</label>
            <input
                type="email"
                name="email"
                value="<?php echo htmlspecialchars($email); ?>"
                required
            >

            <label>Password:</label>
            <input
                type="password"
                name="password"
                required
            >

            <div class="text-center mt-20">
                <button type="submit" class="btn">Login</button>
            </div>
        </form>

        <p class="text-center mt-20">
            Don't have an account?
            <a href="register.php">Register here</a>
        </p>

    </div>
</div>

</body>
</html>