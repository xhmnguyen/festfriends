<?php
session_start();
require_once("database.php");

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = "";
$full_name = "";
$username = "";
$email = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

if (empty($full_name) || empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
    $error = "All fields are required.";
} elseif (strlen($full_name) > 50) {
    $error = "Full name must be 50 characters or fewer.";
} elseif (strlen($username) < 5 || strlen($username) > 12) {
    $error = "Username must be 5 to 12 characters long.";
} elseif (!preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/', $password)) {    $error = "Password must be at least 8 characters long and include an uppercase letter, symbol, and number.";
} elseif ($password !== $confirm_password) {
    $error = "Passwords do not match.";
}
    else {
        try {

            # check if username already exists
            $stmt = $pdo->prepare("SELECT user_id FROM user WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->rowCount() > 0) {
                $error = "Email already registered.";
            } else {

                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                # image upload handling
                $image_path = null;

                if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === 0) {

                    $upload_dir = "uploads/profile_pics/";

                    # create directory if it doesn't exist
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $file_tmp = $_FILES['profile_image']['tmp_name'];
                    $file_name = time() . "_" . basename($_FILES['profile_image']['name']);
                    $target_path = $upload_dir . $file_name;

                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];

                    if (in_array($_FILES['profile_image']['type'], $allowed_types)) {
                        move_uploaded_file($file_tmp, $target_path);
                        $image_path = $target_path;
                    }
                }

                # insert user
                $stmt = $pdo->prepare("
                    INSERT INTO user (full_name, username, email, password, image) 
                    VALUES (?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $full_name,
                    $username,
                    $email,
                    $hashedPassword,
                    $image_path
                ]);

                $_SESSION['user_id'] = $pdo->lastInsertId();
                $_SESSION['username'] = $username;
                $_SESSION['full_name'] = $full_name;
                $_SESSION['image'] = $image_path;

                header("Location: dashboard.php");
                exit();
            }

        } catch (PDOException $e) {
            $error = "Email or username already exists.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Register - FestFriends</title>
<link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>

<div class="container flex" style="justify-content:center; align-items:center; height:100vh;">
    <div class="card" style="max-width:400px; width:100%;">

        <h1 class="text-center">FestFriends</h1>
        <h3 class="text-center" style="margin-top:5px;">Register</h3>

        <?php if (!empty($error)): ?>
            <p style="color:red; text-align:center;">
                <?php echo htmlspecialchars($error); ?>
            </p>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">

            <label>Full Name:</label>
<input 
    type="text" 
    name="full_name" 
    value="<?php echo htmlspecialchars($full_name); ?>" 
    maxlength="50"
    required
>

            <label>Username:</label>
<input 
    type="text" 
    name="username" 
    value="<?php echo htmlspecialchars($username); ?>" 
    minlength="5"
    maxlength="12"
    required
>

            <label>Email:</label>
            <input 
                type="email" 
                name="email" 
                value="<?php echo htmlspecialchars($email); ?>" 
                required
            >

            <label>Password:</label>
<input type="password" name="password" minlength="8" required>
            <label>Confirm Password:</label>
            <input 
                type="password" 
                name="confirm_password" 
                required
            >

            <label>Profile Picture:</label>
            <input type="file" name="profile_image" accept="image/*">

            <div class="text-center mt-20">
                <button type="submit" class="btn">Register</button>
            </div>

        </form>

        <p class="text-center mt-20">
            Already have an account?  
            <a href="login.php">Login here</a>
        </p>

    </div>
</div>

</body>
</html>