<?php
require_once("../session.php");
require_once("../database.php");
include("../included_functions.php");

if (!isset($_GET['group_id'])) {
    die("Group ID not provided.");
}

$group_id = intval($_GET['group_id']);
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM user_group WHERE group_id = ?");
$stmt->execute([$group_id]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$group) die("Group not found.");

# only group owner can edit group
if ($group['owner_id'] != $user_id) {
    die("You do not have permission to edit this group.");
}

# get group members for ownership transfer
$stmt = $pdo->prepare("
    SELECT u.user_id, u.username 
    FROM user u 
    JOIN group_member gm ON u.user_id = gm.user_id 
    WHERE gm.group_id = ? AND gm.status='approved'
");
$stmt->execute([$group_id]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

$edit_error = "";
$message = "";

# form submission for editing group details
if (isset($_POST['edit_group'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $image_path = $group['image'];

    if ($name === '') {
        $edit_error = "Group name is required.";
    } elseif (strlen($name) > 100) {
        $edit_error = "Group name cannot be more than 100 characters.";
    } elseif (strlen($description) > 1000) {
        $edit_error = "Group description cannot be more than 1000 characters.";
    }

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = "../uploads/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = uniqid() . "_" . basename($_FILES['image']['name']);
        $targetFile = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
            $image_path = "uploads/" . $fileName;
        } else {
            $edit_error = "Failed to upload image.";
        }
    }

    if (!$edit_error) {
        $stmt = $pdo->prepare("
            UPDATE user_group
            SET name = ?, description = ?, image = ?
            WHERE group_id = ?
        ");
        $stmt->execute([$name, $description, $image_path, $group_id]);

        $message = "Group updated successfully!";
        $group['name'] = $name;
        $group['description'] = $description;
        $group['image'] = $image_path;
    }
}

# transfer ownership form submission
if (isset($_POST['transfer_owner'])) {
    $new_owner_id = intval($_POST['new_owner'] ?? 0);

    if ($new_owner_id > 0 && $new_owner_id != $user_id) {
        $stmt = $pdo->prepare("UPDATE user_group SET owner_id = ? WHERE group_id = ?");
        $stmt->execute([$new_owner_id, $group_id]);

        header("Location: group.php?group_id=$group_id");
        exit();
    } else {
        $edit_error = "Please select a valid member.";
    }
}

# delete group form submission
if (isset($_POST['delete_group'])) {
    $stmt = $pdo->prepare("DELETE FROM user_group WHERE group_id = ?");
    $stmt->execute([$group_id]);

    header("Location: ../dashboard.php");
    exit();
}
?>

<!-- html and form for editing group details, transferring ownership, and deleting group -->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Group - FestFriends</title>
<link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="container flex" style="justify-content:center; margin-top:60px;">
    <div class="card" style="max-width:500px; width:100%;">

        <h1 class="text-center">Edit Group</h1>

        <?php if ($message): ?>
            <p style="color:green; text-align:center;">
                <?php echo htmlspecialchars($message); ?>
            </p>
        <?php endif; ?>

        <?php if ($edit_error): ?>
            <p style="color:red; text-align:center;">
                <?php echo htmlspecialchars($edit_error); ?>
            </p>
        <?php endif; ?>

        <div class="text-center mb-20">
            <?php if (!empty($group['image'])): ?>
                <img
                    src="../<?php echo htmlspecialchars($group['image']); ?>"
                    alt="Group Image"
                    class="group-image-preview"
                >
            <?php else: ?>
                <div class="group-image-fallback">
                    <?php echo strtoupper(substr($group['name'], 0, 1)); ?>
                </div>
            <?php endif; ?>
        </div>

        <form method="post" enctype="multipart/form-data">
            <label>Group Name:</label>
            <input type="text" name="name" maxlength="50" value="<?php echo htmlspecialchars($group['name']); ?>" required>

            <label>Description:</label>
            <textarea name="description"  maxlength="100"><?php echo htmlspecialchars($group['description']); ?></textarea>

            <label>Group Image:</label>
            <input type="file" name="image" accept="image/*">

            <div class="text-center mt-20">
                <button type="submit" name="edit_group" class="btn">Save Changes</button>
            </div>
        </form>

        <hr class="mt-20">

        <form method="post" onsubmit="return confirm('Are you sure you want to transfer ownership? You will lose owner privileges.');">
                    <h3 class="text-center mt-20">Transfer Ownership</h3>
            <select name="new_owner" required>
                <option value="">Select a member</option>
                <?php foreach ($members as $member): ?>
                    <?php if ($member['user_id'] == $user_id) continue; ?>
                    <option value="<?php echo $member['user_id']; ?>">
                        <?php echo htmlspecialchars($member['username']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="text-center mt-20">
                <button type="submit" name="transfer_owner" class="btn">
                    Transfer Ownership
                </button>
            </div>
        </form>

        <hr class="mt-20">

        <div class="text-center mt-20">
            <form method="post" onsubmit="return confirm('Are you sure you want to delete this group?');">
                <button type="submit" name="delete_group" class="btn delete-btn">
                    Delete Group
                </button>
            </form>
        </div>

                <p class="text-center mt-20">
            <a href="../groups/group.php?group_id=<?php echo (int)$group_id; ?>">Back to Group Dashboard</a>
        </p>

    </div>
</div>

</body>
</html>
<?php display_footer(); ?>