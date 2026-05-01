<?php
require_once("../included_functions.php");
require_once("included_concert.php");
require_once("../report_functions.php");

$error = "";

function upload_gallery_image($file_input_name) {
    if (
        empty($_FILES[$file_input_name]['name']) ||
        empty($_FILES[$file_input_name]['tmp_name']) ||
        !is_uploaded_file($_FILES[$file_input_name]['tmp_name'])
    ) {
        return null;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $original_name = basename($_FILES[$file_input_name]['name']);
    $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowed, true)) {
        return null;
    }

    $target_dir = "../uploads/gallery/";

    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    $file_name = uniqid('gallery_', true) . "." . $extension;
    $target_file = $target_dir . $file_name;

    if (move_uploaded_file($_FILES[$file_input_name]['tmp_name'], $target_file)) {
        return "uploads/gallery/" . $file_name;
    }

    return null;
}

# get group concert
$stmt = $pdo->prepare("
    SELECT *
    FROM user_group
    WHERE group_id = ?
");
$stmt->execute([$concert['group_id']]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    die("Group not found.");
}

$is_group_owner = ((int)$group['owner_id'] === $user_id);

# handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    handle_submit_report($pdo, $user_id);

    try {
        if ($action === 'save_rsvp') {
            $status = $_POST['status'] ?? '';

            if (in_array($status, ['going', 'not_going'], true)) {
                $stmt = $pdo->prepare("
                    INSERT INTO concert_rsvp (group_concert_id, user_id, status)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$concert_id, $user_id, $status]);

                if ($status === 'not_going') {
                    $stmt = $pdo->prepare("
                        DELETE FROM user_budget
                        WHERE user_id = ?
                          AND group_concert_id = ?
                    ");
                    $stmt->execute([$user_id, $concert_id]);

                    $stmt = $pdo->prepare("
                        DELETE FROM housing_option_join
                        WHERE user_id = ?
                          AND housing_id IN (
                              SELECT housing_id
                              FROM housing_option
                              WHERE group_concert_id = ?
                          )
                    ");
                    $stmt->execute([$user_id, $concert_id]);

                    $stmt = $pdo->prepare("
                        DELETE FROM transport_option_join
                        WHERE user_id = ?
                          AND transport_id IN (
                              SELECT transport_id
                              FROM transport_option
                              WHERE group_concert_id = ?
                          )
                    ");
                    $stmt->execute([$user_id, $concert_id]);
                }
            }

            header("Location: gallery.php?concert_id=" . urlencode($concert_id));
            exit();
        }

        if ($action === 'add_gallery_image') {
            $caption = trim($_POST['caption'] ?? '');
            $image = upload_gallery_image('gallery_image');

            if ($image === null) {
                $error = "Please upload a valid image.";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO gallery_image (group_concert_id, user_id, image, caption)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $concert_id,
                    $user_id,
                    $image,
                    $caption !== '' ? $caption : null
                ]);

                header("Location: gallery.php?concert_id=" . urlencode($concert_id));
                exit();
            }
        }

        if ($action === 'delete_gallery_image') {
            $image_id = (int)($_POST['image_id'] ?? 0);

            if ($is_group_owner) {
                $stmt = $pdo->prepare("
                    DELETE FROM gallery_image
                    WHERE image_id = ? AND group_concert_id = ?
                ");
                $stmt->execute([$image_id, $concert_id]);
            } else {
                $stmt = $pdo->prepare("
                    DELETE FROM gallery_image
                    WHERE image_id = ? AND user_id = ? AND group_concert_id = ?
                ");
                $stmt->execute([$image_id, $user_id, $concert_id]);
            }

            header("Location: gallery.php?concert_id=" . urlencode($concert_id));
            exit();
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

# get rsvp info
$stmt = $pdo->prepare("
    SELECT status
    FROM concert_rsvp
    WHERE group_concert_id = ? AND user_id = ?
");
$stmt->execute([$concert_id, $user_id]);
$current_rsvp = $stmt->fetchColumn();
$is_going = ($current_rsvp === 'going');

$stmt = $pdo->prepare("
    SELECT u.username, u.full_name, u.image, cr.status
    FROM concert_rsvp cr
    JOIN user u ON cr.user_id = u.user_id
    WHERE cr.group_concert_id = ?
    ORDER BY cr.status ASC, u.username ASC
");
$stmt->execute([$concert_id]);
$rsvp_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$going_users = [];
$not_going_users = [];

foreach ($rsvp_users as $r) {
    if ($r['status'] === 'going') {
        $going_users[] = $r;
    } elseif ($r['status'] === 'not_going') {
        $not_going_users[] = $r;
    }
}

# get gallery images
$stmt = $pdo->prepare("
    SELECT
        gi.*,
        u.username,
        u.full_name
    FROM gallery_image gi
    JOIN user u ON gi.user_id = u.user_id
    WHERE gi.group_concert_id = ?
    ORDER BY gi.created_at DESC
");
$stmt->execute([$concert_id]);
$gallery_images = $stmt->fetchAll(PDO::FETCH_ASSOC);

$add_gallery_button = '<button class="btn post-btn" type="button" onclick="openModal(\'galleryModal\')">Add to Gallery</button>';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo h($concert['name']); ?> - Gallery</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="container">
    <?php render_concert_header($concert, $concert_image_src, $current_rsvp, $going_users, $not_going_users); ?>

    <?php if ($error): ?>
        <p class="error-message"><?php echo h($error); ?></p>
    <?php endif; ?>

    <?php render_concert_action_row($concert_id, "gallery", $add_gallery_button); ?>

    <div class="tab-content active">
        <?php if (empty($gallery_images)): ?>
            <p>No gallery images yet.</p>
        <?php else: ?>
            <div class="gallery-grid">
                <?php foreach ($gallery_images as $image): ?>
                    <?php
                        $can_delete = ((int)$image['user_id'] === $user_id) || $is_group_owner;
                        $gallery_image_src = get_image_src($image['image']);
                    ?>

<div
    class="gallery-item"
    onclick="openModal('galleryViewModal<?php echo (int)$image['image_id']; ?>')"
>
    <img
        src="<?php echo h($gallery_image_src); ?>"
        alt="Gallery Image"
        class="gallery-img"
    >
</div>

<div id="galleryViewModal<?php echo (int)$image['image_id']; ?>" class="modal">
    <div class="gallery-modal-container">
        <span class="close-btn" onclick="closeModal('galleryViewModal<?php echo (int)$image['image_id']; ?>')">&times;</span>



        <img
            src="<?php echo h($gallery_image_src); ?>"
            alt="Gallery Image"
            class="gallery-view-img"
        >

<?php if (!empty($image['caption'])): ?>
    <p class="gallery-view-caption">
        <?php echo h($image['caption']); ?>
    </p>
<?php endif; ?>

<div class="gallery-view-meta">
    <span>@<?php echo h($image['username']); ?></span>

    <div class="gallery-view-meta-right">
        <span><?php echo h(date("M j, Y g:i A", strtotime($image['created_at']))); ?></span>

<?php if ($can_delete): ?>
    <form
        method="post"
        class="gallery-modal-delete-inline"
        onsubmit="return confirm('Delete this image?');"
    >
        <input type="hidden" name="action" value="delete_gallery_image">
        <input type="hidden" name="image_id" value="<?php echo (int)$image['image_id']; ?>">
        <button type="submit" class="text-action-btn delete-action">Delete</button>
    </form>
<?php endif; ?>

<?php if ((int)$image['user_id'] !== $user_id): ?>
    <?php render_report_button('gallery', $image['image_id'], 'Report'); ?>
<?php endif; ?>
    </div>
</div>
    </div>
</div>

<?php if ((int)$image['user_id'] !== $user_id): ?>
    <?php render_report_modal('gallery', $image['image_id'], 'Report Image'); ?>
<?php endif; ?>

                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="galleryModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('galleryModal')">&times;</span>
        <h3 class="text-center mt-20">Add to Gallery</h3>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_gallery_image">

            <label>Image:</label>
            <input type="file" name="gallery_image" accept="image/*" required>

            <label>Caption (optional):</label>
            <input type="text" name="caption" maxlength="255">

            <button type="submit" class="btn">Upload</button>
        </form>
    </div>
</div>

<?php render_rsvp_modal($going_users, $not_going_users); ?>

<script>
function openModal(id) {
    const modal = document.getElementById(id);

    if (modal) {
        modal.style.display = "flex";
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);

    if (modal) {
        modal.style.display = "none";
    }
}

window.onclick = function(event) {
    document.querySelectorAll('.modal').forEach(function(modal) {
        if (event.target === modal) {
            modal.style.display = "none";
        }
    });
};
</script>

<?php display_footer(); ?>
</body>
</html>