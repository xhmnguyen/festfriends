<?php
require_once("../session.php");
require_once("../database.php");
require_once("../included_functions.php");

$error = "";
$name = "";
$description = "";
$image = null;

// Ensure logged-in user exists
$stmt = $pdo->prepare("SELECT * FROM user WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
if ($stmt->rowCount() === 0) {
    die("Invalid user. Please log in again.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);

    // Handle image upload
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = "../uploads/";

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $tmpName = $_FILES['image']['tmp_name'];
        $fileName = uniqid() . "_" . basename($_FILES['image']['name']);
        $targetFile = $uploadDir . $fileName;

        if (move_uploaded_file($tmpName, $targetFile)) {
            $image = "uploads/" . $fileName;
        } else {
            $error = "Failed to upload image.";
        }
    }

    if (!$name) {
        $error = "Group name is required.";
    }

    if (!$error) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_group (name, description, owner_id, image)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$name, $description, $_SESSION['user_id'], $image]);

            $group_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO group_member (group_id, user_id, status)
                VALUES (?, ?, 'approved')
            ");
            $stmt->execute([$group_id, $_SESSION['user_id']]);

            header("Location: ../dashboard.php");
            exit();
        } catch (PDOException $e) {
            $error = "Database error.";
        }
    }
}
?>

    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <title>$title</title>
        <link rel='stylesheet' href='../assets/css/styles.css'>
    </head>
    <body>

<div class="container flex" style="justify-content:center; margin-top:60px;">
    <div class="card" style="max-width:500px; width:100%;">

        <h1 class="text-center">Create a New Group</h1>

        <?php if ($error): ?>
            <p style="color:red; text-align:center;">
                <?php echo htmlspecialchars($error); ?>
            </p>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">

            <label>Group Name:</label>
            <input 
                type="text" 
                name="name" 
                value="<?php echo htmlspecialchars($name); ?>" 
                required
            >

            <label>Description:</label>
            <textarea name="description"><?php echo htmlspecialchars($description); ?></textarea>

            <label class="mt-20">Image (optional):</label>
            <input type="file" name="image" accept="image/*">

            <div class="text-center mt-20">
                <button type="submit" class="btn">Create Group</button>
            </div>

        </form>

        <div class="text-center mt-20">
            <a href="../dashboard.php">Back to Dashboard</a>
        </div>

    </div>
</div>

<?php display_footer(); ?>