<?php
require_once("session.php");
require_once("database.php");
include("included_functions.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$stmt = $pdo->prepare("SELECT username, full_name, email, password, image FROM user WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$message = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    if (isset($_POST['update_profile'])) {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $username = trim($_POST['username']);
        $image_path = $user['image'];

        if (!$full_name || !$email || !$username) {
            $error = "All fields are required.";
        } else {
            $stmt = $pdo->prepare("SELECT user_id FROM user WHERE username = ? AND user_id != ?");
            $stmt->execute([$username, $_SESSION['user_id']]);

            if ($stmt->rowCount() > 0) {
                $error = "Username already taken.";
            } else {
                if (!empty($_FILES['image']['name'])) {
                    $upload_dir = "uploads/profiles/";

                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $file_name = uniqid() . "_" . basename($_FILES['image']['name']);
                    $target_file = $upload_dir . $file_name;

                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                        $image_path = $target_file;
                    } else {
                        $error = "Failed to upload profile picture.";
                    }
                }

                if (!$error) {
                    $update = $pdo->prepare("
                        UPDATE user
                        SET full_name = ?, email = ?, username = ?, image = ?
                        WHERE user_id = ?
                    ");
                    $update->execute([
                        $full_name,
                        $email,
                        $username,
                        $image_path,
                        $_SESSION['user_id']
                    ]);

                    $message = "Profile updated successfully!";
                    $user['full_name'] = $full_name;
                    $user['email'] = $email;
                    $user['username'] = $username;
                    $user['image'] = $image_path;

                    $_SESSION['username'] = $username;
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['image'] = $image_path;
                }
            }
        }
    }

    if (isset($_POST['change_password'])) {
        $old_password = $_POST['old_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (!$old_password || !$new_password || !$confirm_password) {
            $error = "All password fields are required.";
        } elseif (!password_verify($old_password, $user['password'])) {
            $error = "Old password is incorrect.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New password and confirmation do not match.";
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE user SET password = ? WHERE user_id = ?");
            $stmt->execute([$hashed, $_SESSION['user_id']]);

            $message = "Password updated successfully!";
        }
    }

        if (isset($_POST['delete_account'])) {

            // delete related data first (prevents foreign key issues)
            $stmt = $pdo->prepare("DELETE FROM general_post_vote WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);

            $stmt = $pdo->prepare("DELETE FROM general_post WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);

            $stmt = $pdo->prepare("DELETE FROM housing_option WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);

            $stmt = $pdo->prepare("DELETE FROM transport_option WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);

            $stmt = $pdo->prepare("DELETE FROM performance_slot WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);

            $stmt = $pdo->prepare("DELETE FROM user_budget WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);

            $stmt = $pdo->prepare("DELETE FROM group_member WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);

            // finally delete user
            $stmt = $pdo->prepare("DELETE FROM user WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);

            session_unset();
            session_destroy();

            header("Location: index.php");
            exit;
        }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Profile - FestFriends</title>
<link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>

<div class="container flex" style="justify-content:center; margin-top:60px;">
    <div class="card" style="max-width:500px; width:100%;">

        <h1 class="text-center">Edit Profile</h1>

        <?php if ($message): ?>
            <p style="color:green; text-align:center;">
                <?php echo htmlspecialchars($message); ?>
            </p>
        <?php endif; ?>

        <?php if ($error): ?>
            <p style="color:red; text-align:center;">
                <?php echo htmlspecialchars($error); ?>
            </p>
        <?php endif; ?>

        <div class="text-center mb-20">
            <?php if (!empty($user['image'])): ?>
                <img src="<?php echo htmlspecialchars($user['image']); ?>" alt="Profile Picture" class="profile-preview">
            <?php else: ?>
                <div class="profile-preview-fallback">
                    <?php echo strtoupper(substr($user['full_name'] ?? $user['username'], 0, 1)); ?>
                </div>
            <?php endif; ?>
        </div>

        <form method="post" enctype="multipart/form-data">
            <label>Full Name:</label>
            <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>

            <label>Email:</label>
            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>

            <label>Username:</label>
            <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>

            <label>Profile Picture:</label>
            <input type="file" name="image" accept="image/*">

            <div class="text-center mt-20">
                <button type="submit" name="update_profile" class="btn">Update Profile</button>
            </div>
        </form>

        <hr class="mt-20">

        <h3 class="text-center mt-20">Change Password</h3>
        <form method="post">
            <label>Old Password:</label>
            <input type="password" name="old_password" required>

            <label>New Password:</label>
            <input type="password" name="new_password" required>

            <label>Confirm New Password:</label>
            <input type="password" name="confirm_password" required>

            <div class="text-center mt-20">
                <button type="submit" name="change_password" class="btn">Change Password</button>
            </div>
        </form>

        <hr class="mt-20">

        <div class="text-center mt-20">
            <form method="post" onsubmit="return confirmDelete();">
                <button type="submit" name="delete_account" class="btn delete-btn">
                    Delete Account
                </button>
            </form>
        </div>

        <div class="text-center mt-20">
            <a href="logout.php" class="btn">Log Out</a>
        </div>

    </div>
</div>

</body>
</html>
<?php display_footer(); ?>