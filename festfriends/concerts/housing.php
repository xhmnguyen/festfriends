<?php
require_once("../included_functions.php");
require_once("included_concert.php");
require_once("../report_functions.php");

$error = "";

# helper
function upload_image($file_input_name, $subfolder = 'posts') {
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

    $target_dir = "../uploads/" . trim($subfolder, '/') . "/";

    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    $file_name = uniqid($subfolder . '_', true) . '.' . $extension;
    $target_file = $target_dir . $file_name;

    if (move_uploaded_file($_FILES[$file_input_name]['tmp_name'], $target_file)) {
        return "uploads/" . trim($subfolder, '/') . "/" . $file_name;
    }

    return null;
}

function format_option_datetime($date, $time = null, $include_time = 0) {
    if (empty($date)) {
        return "";
    }

    $formatted = date("F j, Y", strtotime($date));

    if ((int)$include_time === 1 && !empty($time)) {
        $formatted .= " at " . date("g:i A", strtotime($time));
    }

    return $formatted;
}

function joined_label($count, $max_people = null, $limited_spots = 0) {
    if ((int)$limited_spots === 1 && !empty($max_people)) {
        return $count . "/" . (int)$max_people . " Joined";
    }

    return $count . " Joined";
}

function cost_per_person($total_cost, $max_people, $limited_spots, $joined_count) {
    if ($total_cost === null || $total_cost === '') {
        return null;
    }

    if ((int)$limited_spots === 1 && !empty($max_people)) {
        $divisor = (int)$max_people;
    } else {
        $divisor = max(1, (int)$joined_count);
    }

    if ($divisor <= 0) {
        return null;
    }

    return (float)$total_cost / $divisor;
}

function validate_planning_text($title, $notes, $link) {
    if (mb_strlen($title) > 50) {
        return "Title must be 50 characters or less.";
    }

    if (mb_strlen($notes) > 200) {
        return "Notes must be 200 characters or less.";
    }

    if (mb_strlen($link) > 500) {
        return "Link must be 500 characters or less.";
    }

    return "";
}

# get concert
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
                        DELETE FROM housing_option_join
                        WHERE user_id = ?
                          AND housing_id IN (
                              SELECT housing_id
                              FROM housing_option
                              WHERE group_concert_id = ?
                          )
                    ");
                    $stmt->execute([$user_id, $concert_id]);
                }
            }

            header("Location: housing.php?concert_id=" . urlencode($concert_id));
            exit();
        }

        if (!$is_going && in_array($action, [
            'add_housing',
            'edit_housing',
            'delete_housing',
            'toggle_join_housing'
        ], true)) {
            $error = "You are not going to this event!";
        } else {
            if ($action === 'add_housing') {
                $title = trim($_POST['title'] ?? '');
                $arrival_date = trim($_POST['arrival_date'] ?? '');
                $departure_date = trim($_POST['departure_date'] ?? '');
                $total_cost = ($_POST['total_cost'] ?? '') !== '' ? (float)$_POST['total_cost'] : null;
                $include_time = isset($_POST['include_time']) ? 1 : 0;
                $arrival_time = $include_time ? trim($_POST['arrival_time'] ?? '') : null;
                $departure_time = $include_time ? trim($_POST['departure_time'] ?? '') : null;
                $limited_spots = isset($_POST['limited_spots']) ? 1 : 0;
                $max_people = $limited_spots && ($_POST['max_people'] ?? '') !== '' ? (int)$_POST['max_people'] : null;
                $link = trim($_POST['link'] ?? '');
                $notes = trim($_POST['notes'] ?? '');
                $image = upload_image('housing_image', 'posts');

                $validation_error = validate_planning_text($title, $notes, $link);

            if ($validation_error !== "") {
                $error = $validation_error;
            } elseif ($title === '' || $arrival_date === '' || $departure_date === '') {
                $error = "Housing title, arrival, and departure are required.";
            } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO housing_option
                        (group_concert_id, user_id, title, arrival_date, departure_date, include_time, arrival_time, departure_time, total_cost, limited_spots, max_people, image, link, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $concert_id,
                        $user_id,
                        $title,
                        $arrival_date,
                        $departure_date,
                        $include_time,
                        $arrival_time ?: null,
                        $departure_time ?: null,
                        $total_cost,
                        $limited_spots,
                        $max_people,
                        $image,
                        $link !== '' ? $link : null,
                        $notes !== '' ? $notes : null
                    ]);

                    header("Location: housing.php?concert_id=" . urlencode($concert_id));
                    exit();
                }
            }

            # only allow owner or creator to edit
            if ($action === 'edit_housing') {
                $housing_id = (int)($_POST['housing_id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $arrival_date = trim($_POST['arrival_date'] ?? '');
                $departure_date = trim($_POST['departure_date'] ?? '');
                $total_cost = ($_POST['total_cost'] ?? '') !== '' ? (float)$_POST['total_cost'] : null;
                $include_time = isset($_POST['include_time']) ? 1 : 0;
                $arrival_time = $include_time ? trim($_POST['arrival_time'] ?? '') : null;
                $departure_time = $include_time ? trim($_POST['departure_time'] ?? '') : null;
                $limited_spots = isset($_POST['limited_spots']) ? 1 : 0;
                $max_people = $limited_spots && ($_POST['max_people'] ?? '') !== '' ? (int)$_POST['max_people'] : null;
                $link = trim($_POST['link'] ?? '');
                $notes = trim($_POST['notes'] ?? '');

                $stmt = $pdo->prepare("
                    SELECT image, user_id
                    FROM housing_option
                    WHERE housing_id = ? AND group_concert_id = ?
                ");
                $stmt->execute([$housing_id, $concert_id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing || (int)$existing['user_id'] !== $user_id) {
                    header("Location: housing.php?concert_id=" . urlencode($concert_id));
                    exit();
                }

                $image = $existing['image'] ?? null;
                $new_image = upload_image('housing_image', 'posts');

                if ($new_image !== null) {
                    $image = $new_image;
                }

                $validation_error = validate_planning_text($title, $notes, $link);

                if ($validation_error !== "") {
                    $error = $validation_error;
                } elseif ($title === '' || $arrival_date === '' || $departure_date === '') {
                    $error = "Housing title, arrival, and departure are required.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE housing_option
                        SET title = ?,
                            arrival_date = ?,
                            departure_date = ?,
                            include_time = ?,
                            arrival_time = ?,
                            departure_time = ?,
                            total_cost = ?,
                            limited_spots = ?,
                            max_people = ?,
                            image = ?,
                            link = ?,
                            notes = ?
                        WHERE housing_id = ? AND user_id = ? AND group_concert_id = ?
                    ");
                    $stmt->execute([
                        $title,
                        $arrival_date,
                        $departure_date,
                        $include_time,
                        $arrival_time ?: null,
                        $departure_time ?: null,
                        $total_cost,
                        $limited_spots,
                        $max_people,
                        $image,
                        $link !== '' ? $link : null,
                        $notes !== '' ? $notes : null,
                        $housing_id,
                        $user_id,
                        $concert_id
                    ]);

                    header("Location: housing.php?concert_id=" . urlencode($concert_id));
                    exit();
                }
            }

            if ($action === 'delete_housing') {
                $housing_id = (int)($_POST['housing_id'] ?? 0);

                if ($is_group_owner) {
                    $stmt = $pdo->prepare("
                        DELETE FROM housing_option
                        WHERE housing_id = ? AND group_concert_id = ?
                    ");
                    $stmt->execute([$housing_id, $concert_id]);
                } else {
                    $stmt = $pdo->prepare("
                        DELETE FROM housing_option
                        WHERE housing_id = ? AND user_id = ? AND group_concert_id = ?
                    ");
                    $stmt->execute([$housing_id, $user_id, $concert_id]);
                }

                header("Location: housing.php?concert_id=" . urlencode($concert_id));
                exit();
            }

            if ($action === 'toggle_join_housing') {
                $housing_id = (int)($_POST['housing_id'] ?? 0);

                $stmt = $pdo->prepare("
                    SELECT limited_spots, max_people
                    FROM housing_option
                    WHERE housing_id = ? AND group_concert_id = ?
                ");
                $stmt->execute([$housing_id, $concert_id]);
                $housing = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($housing) {
                    $stmt = $pdo->prepare("
                        SELECT 1
                        FROM housing_option_join
                        WHERE housing_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$housing_id, $user_id]);
                    $already_joined = (bool)$stmt->fetchColumn();

                    if ($already_joined) {
                        $stmt = $pdo->prepare("
                            DELETE FROM housing_option_join
                            WHERE housing_id = ? AND user_id = ?
                        ");
                        $stmt->execute([$housing_id, $user_id]);
                    } else {
                        if ((int)$housing['limited_spots'] === 1 && !empty($housing['max_people'])) {
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*)
                                FROM housing_option_join
                                WHERE housing_id = ?
                            ");
                            $stmt->execute([$housing_id]);
                            $joined_total = (int)$stmt->fetchColumn();

                            if ($joined_total >= (int)$housing['max_people']) {
                                header("Location: housing.php?concert_id=" . urlencode($concert_id));
                                exit();
                            }
                        }

                        $stmt = $pdo->prepare("
                            INSERT IGNORE INTO housing_option_join (housing_id, user_id)
                            VALUES (?, ?)
                        ");
                        $stmt->execute([$housing_id, $user_id]);
                    }
                }

                header("Location: housing.php?concert_id=" . urlencode($concert_id));
                exit();
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

# get current user rsvp
$stmt = $pdo->prepare("
    SELECT status
    FROM concert_rsvp
    WHERE group_concert_id = ? AND user_id = ?
");
$stmt->execute([$concert_id, $user_id]);
$current_rsvp = $stmt->fetchColumn();
$is_going = ($current_rsvp === 'going');

$stmt = $pdo->prepare("
    SELECT u.username, cr.status
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

# get housing options 
$stmt = $pdo->prepare("
    SELECT
        ho.*,
        u.username,
        u.full_name,
        u.image AS owner_image,
        (SELECT COUNT(*) FROM housing_option_join hoj WHERE hoj.housing_id = ho.housing_id) AS joined_count,
        EXISTS(
            SELECT 1
            FROM housing_option_join hoj2
            WHERE hoj2.housing_id = ho.housing_id
              AND hoj2.user_id = ?
        ) AS user_joined
    FROM housing_option ho
    JOIN user u ON ho.user_id = u.user_id
    WHERE ho.group_concert_id = ?
    ORDER BY ho.arrival_date ASC, ho.arrival_time ASC, ho.created_at DESC
");
$stmt->execute([$user_id, $concert_id]);
$housing_options = $stmt->fetchAll(PDO::FETCH_ASSOC);

# get joined users for each housing option
$housing_join_users = [];

foreach ($housing_options as $housing) {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.full_name, u.image
        FROM housing_option_join hoj
        JOIN user u ON hoj.user_id = u.user_id
        WHERE hoj.housing_id = ?
        ORDER BY u.username ASC
    ");
    $stmt->execute([$housing['housing_id']]);

    $housing_join_users[$housing['housing_id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$add_housing_button = $is_going
    ? '<button class="btn post-btn" type="button" onclick="openModal(\'housingModal\')">Add Housing</button>'
    : '<button class="btn" type="button" onclick="alert(\'You are not going to this event!\')">Add Housing</button>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo h($concert['name']); ?> - Housing</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="container">
    <?php render_concert_header($concert, $concert_image_src, $current_rsvp, $going_users, $not_going_users); ?>

    <?php if ($error): ?>
        <p class="error-message"><?php echo h($error); ?></p>
    <?php endif; ?>

    <?php render_concert_action_row($concert_id, "housing", $add_housing_button); ?>

    <?php if (empty($housing_options)): ?>
        <p>No housing options yet.</p>
    <?php else: ?>
        <div class="planning-grid">
            <?php foreach ($housing_options as $housing): ?>
                <?php
                    $housing_image = !empty($housing['image'])
                        ? get_image_src($housing['image'])
                        : "../assets/images/default.jpg";

                    $housing_cost_per_person = cost_per_person(
                        $housing['total_cost'] ?? null,
                        $housing['max_people'] ?? null,
                        $housing['limited_spots'] ?? 0,
                        $housing['joined_count'] ?? 0
                    );

                    $can_edit_housing = ((int)$housing['user_id'] === $user_id);
                    $can_delete_housing = $can_edit_housing || $is_group_owner;
                ?>

                <div
                    class="planning-summary-card"
                    id="housingCard<?php echo (int)$housing['housing_id']; ?>"
                    onclick="openModal('housingDetailsModal<?php echo (int)$housing['housing_id']; ?>')"
                >
                    <img
                        src="<?php echo h($housing_image); ?>"
                        alt="Housing Image"
                        class="planning-extra-image"
                    >

                    <div class="planning-summary-body">
                    <div class="planning-summary-top">
                        <div class="planning-summary-title"><?php echo h($housing['title']); ?></div>

                        <div class="planning-summary-actions" onclick="event.stopPropagation();">
                            <?php if ($is_going && $can_edit_housing): ?>
                                <a
                                    href="#"
                                    class="text-action-btn"
                                    onclick="event.preventDefault(); openModal('editHousingModal<?php echo (int)$housing['housing_id']; ?>');"
                                >
                                    Edit
                                </a>
                            <?php endif; ?>

                            <?php if ($can_delete_housing): ?>
                                <form
                                    method="post"
                                    id="deleteHousingForm<?php echo (int)$housing['housing_id']; ?>"
                                    onsubmit="return confirm('Delete this housing option?');"
                                >
                                    <input type="hidden" name="action" value="delete_housing">
                                    <input type="hidden" name="housing_id" value="<?php echo (int)$housing['housing_id']; ?>">
                                </form>

                                <a
                                    href="#"
                                    class="text-action-btn delete-action"
                                    onclick="event.preventDefault(); document.getElementById('deleteHousingForm<?php echo (int)$housing['housing_id']; ?>').requestSubmit();"
                                >
                                    Delete
                                </a>
                            <?php endif; ?>

                            <?php if ((int)$housing['user_id'] !== $user_id): ?>
                                <?php render_report_button('housing', $housing['housing_id'], 'Report'); ?>
                            <?php endif; ?>
                        </div>
                    </div>

<div class="planning-summary-line">
    <strong>Arrival:</strong>
    <?php echo h(format_option_datetime($housing['arrival_date'], $housing['arrival_time'] ?? null, $housing['include_time'])); ?>
</div>

<div class="planning-summary-line">
    <strong>Departure:</strong>
    <?php echo h(format_option_datetime($housing['departure_date'], $housing['departure_time'] ?? null, $housing['include_time'])); ?>
</div>

<div class="planning-summary-line">
    <strong>Total Cost:</strong>
    <?php echo $housing['total_cost'] !== null && $housing['total_cost'] !== '' ? '$' . number_format((float)$housing['total_cost'], 2) : '—'; ?>
</div>

<div class="planning-summary-line">
    <strong>Cost Per Person:</strong>
    <?php echo $housing_cost_per_person !== null ? '$' . number_format((float)$housing_cost_per_person, 2) : '—'; ?>
</div>

<div class="planning-meta-row">
    <div class="left">@<?php echo h($housing['username']); ?></div>

    <div class="center" onclick="event.stopPropagation();">
        <?php if ($is_going): ?>
            <form method="post">
                <input type="hidden" name="action" value="toggle_join_housing">
                <input type="hidden" name="housing_id" value="<?php echo (int)$housing['housing_id']; ?>">

                <button
    type="submit"
    class="btn rsvp-toggle-btn <?php echo ((int)$housing['user_joined'] === 1) ? 'not-going' : 'going'; ?>"
>
    <?php echo ((int)$housing['user_joined'] === 1) ? 'Leave' : 'Join'; ?>
</button>
            </form>
        <?php else: ?>
            <button type="button" class="btn" onclick="alert('You are not going to this event!')">
                Join
            </button>
        <?php endif; ?>
    </div>

    <div class="right">
        <a
            href="#"
            class="inline-link"
            onclick="event.preventDefault(); event.stopPropagation(); openModal('housingMembersModal<?php echo (int)$housing['housing_id']; ?>');"
        >
            <?php echo h(joined_label((int)$housing['joined_count'], $housing['max_people'] ?? null, $housing['limited_spots'] ?? 0)); ?>
        </a>
    </div>
</div>

</div>
</div>

<?php if ((int)$housing['user_id'] !== $user_id): ?>
    <?php render_report_modal('housing', $housing['housing_id'], 'Report Housing'); ?>
<?php endif; ?>

<div id="housingDetailsModal<?php echo (int)$housing['housing_id']; ?>" class="modal">
    <div class="modal-content housing-details-modal">
        <span class="close-btn" onclick="closeModal('housingDetailsModal<?php echo (int)$housing['housing_id']; ?>')">&times;</span>

<img
    src="<?php echo h($housing_image); ?>"
    alt="Housing Image"
    class="modal-small-image"
>

<p>
    <strong>Link:</strong>
    <?php if (!empty($housing['link'])): ?>
        <a
            href="<?php echo h($housing['link']); ?>"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-link"
        >
            Link
        </a>
    <?php else: ?>
        —
    <?php endif; ?>
</p>

<p>
    <strong>Notes:</strong>
    <?php echo !empty($housing['notes']) ? nl2br(h($housing['notes'])) : '—'; ?>
</p>




                        
</div>
</div>

<!-- members modal -->
<div id="housingMembersModal<?php echo (int)$housing['housing_id']; ?>" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('housingMembersModal<?php echo (int)$housing['housing_id']; ?>')">&times;</span>
        <h3 class="text-center mt-20">Joined Users</h3>

        <?php if (empty($housing_join_users[$housing['housing_id']])): ?>
            <p class="mt-20">No one has joined yet.</p>
        <?php else: ?>
            <?php foreach ($housing_join_users[$housing['housing_id']] as $joined_user): ?>
                <div
                    class="members-card-top"
                    style="padding: 12px 0; border-bottom: 1px solid #ececec;"
                >
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <?php if (!empty($joined_user['image'])): ?>
                            <img
                                src="../<?php echo h($joined_user['image']); ?>"
                                alt="Profile"
                                class="member-avatar-img"
                            >
                        <?php else: ?>
                            <div class="member-avatar-fallback">
                                <?php echo h(strtoupper(substr($joined_user['full_name'] ?? $joined_user['username'], 0, 1))); ?>
                            </div>
                        <?php endif; ?>

                        <div>
                            <div class="member-name" style="font-size: 0.95rem;">
                                <?php echo h($joined_user['full_name'] ?: $joined_user['username']); ?>
                            </div>

                            <div class="group-owner">
                                @<?php echo h($joined_user['username']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- edit modal -->
                <?php if ($is_going && $can_edit_housing): ?>
                    <div id="editHousingModal<?php echo (int)$housing['housing_id']; ?>" class="modal">
                        <div class="modal-content">
                            <span class="close-btn" onclick="closeModal('editHousingModal<?php echo (int)$housing['housing_id']; ?>')">&times;</span>
                            <h3>Edit Housing</h3>

                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="edit_housing">
                                <input type="hidden" name="housing_id" value="<?php echo (int)$housing['housing_id']; ?>">

                                <label>Title:</label>
                                <input type="text" name="title" maxlength="50" value="<?php echo h($housing['title']); ?>" required>

                                <label>Arrival Date:</label>
                                <input type="date" name="arrival_date" value="<?php echo h($housing['arrival_date']); ?>" required>

                                <label>Departure Date:</label>
                                <input type="date" name="departure_date" value="<?php echo h($housing['departure_date']); ?>" required>

                                <label>Total Cost:</label>
                                <input type="number" step="0.01" name="total_cost" value="<?php echo h($housing['total_cost'] ?? ''); ?>">

                                <label class="checkbox-row">
                                    <input
                                        type="checkbox"
                                        name="include_time"
                                        value="1"
                                        <?php echo ((int)$housing['include_time'] === 1) ? 'checked' : ''; ?>
                                        onclick="toggleTimeFields('editHousingTimeFields<?php echo (int)$housing['housing_id']; ?>', this)"
                                    >
                                    Include Time
                                </label>

                                <div
                                    id="editHousingTimeFields<?php echo (int)$housing['housing_id']; ?>"
                                    class="<?php echo ((int)$housing['include_time'] === 1) ? '' : 'hidden'; ?>"
                                >
                                    <label>Arrival Time:</label>
                                    <input type="time" name="arrival_time" value="<?php echo h($housing['arrival_time'] ?? ''); ?>">

                                    <label>Departure Time:</label>
                                    <input type="time" name="departure_time" value="<?php echo h($housing['departure_time'] ?? ''); ?>">
                                </div>

                                <label class="checkbox-row">
                                    <input
                                        type="checkbox"
                                        name="limited_spots"
                                        value="1"
                                        <?php echo ((int)$housing['limited_spots'] === 1) ? 'checked' : ''; ?>
                                        onclick="toggleSpotFields('editHousingSpots<?php echo (int)$housing['housing_id']; ?>', this)"
                                    >
                                    Limited Spots
                                </label>

                                <div
                                    id="editHousingSpots<?php echo (int)$housing['housing_id']; ?>"
                                    class="<?php echo ((int)$housing['limited_spots'] === 1) ? '' : 'hidden'; ?>"
                                >
                                    <label>Max People:</label>
                                    <select name="max_people">
                                        <option value="">Select max</option>
                                        <?php for ($i = 2; $i <= 20; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ((int)($housing['max_people'] ?? 0) === $i) ? 'selected' : ''; ?>>
                                                <?php echo $i; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>

                                <label>Housing Image (optional):</label>
                                <input type="file" name="housing_image" accept="image/*">

                                <label>Link (optional):</label>
                                <input type="url" name="link" maxlength="500" value="<?php echo h($housing['link'] ?? ''); ?>">

                                <label>Notes:</label>
                                <textarea name="notes" maxlength="500"><?php echo h($housing['notes'] ?? ''); ?></textarea>

                                <button type="submit" class="btn">Save Changes</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- add modal -->
<div id="housingModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('housingModal')">&times;</span>
        <h3 class="text-center mt-20">Add Housing</h3>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_housing">

            <label>Title:</label>
<input type="text" name="title" maxlength="50" required>

            <label>Arrival Date:</label>
            <input type="date" name="arrival_date" required>

            <label>Departure Date:</label>
            <input type="date" name="departure_date" required>

            <label>Total Cost:</label>
            <input type="number" step="0.01" name="total_cost" placeholder="e.g. 150.00">

            <label class="checkbox-row">
                <input
                    type="checkbox"
                    name="include_time"
                    value="1"
                    onclick="toggleTimeFields('housingTimeFields', this)"
                >
                Include Time
            </label>

            <div id="housingTimeFields" class="hidden">
                <label>Arrival Time:</label>
                <input type="time" name="arrival_time">

                <label>Departure Time:</label>
                <input type="time" name="departure_time">
            </div>

            <label class="checkbox-row">
                <input
                    type="checkbox"
                    name="limited_spots"
                    value="1"
                    onclick="toggleSpotFields('housingSpots', this)"
                >
                Limited Spots
            </label>

            <div id="housingSpots" class="hidden">
                <label>Max People:</label>
                <select name="max_people">
                    <option value="">Select max</option>
                    <?php for ($i = 2; $i <= 20; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <label>Housing Image (optional):</label>
            <input type="file" name="housing_image" accept="image/*">

            <label>Link (optional):</label>
            <input type="url" name="link" maxlength="500">

            <label>Notes:</label>
            <textarea name="notes" maxlength="500"></textarea>

            <button type="submit" class="btn">Save Housing</button>
        </form>
    </div>
</div>

<?php render_rsvp_modal($going_users, $not_going_users); ?>

<script>

// modal functions
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

function toggleTimeFields(id, checkbox) {
    const el = document.getElementById(id);

    if (!el) {
        return;
    }

    if (checkbox.checked) {
        el.classList.remove('hidden');
    } else {
        el.classList.add('hidden');
    }
}

function toggleSpotFields(id, checkbox) {
    const el = document.getElementById(id);

    if (!el) {
        return;
    }

    if (checkbox.checked) {
        el.classList.remove('hidden');
    } else {
        el.classList.add('hidden');
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