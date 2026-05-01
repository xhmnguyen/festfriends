<?php
require_once("../included_functions.php");
require_once("included_concert.php");
require_once("../report_functions.php");

$error = "";

# helper
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

function validate_transport_text($title, $notes, $link) {
    if (mb_strlen($title) > 50) {
        return "Title must be 50 characters or less.";
    }

    if (mb_strlen($notes) > 500) {
        return "Notes must be 500 characters or less.";
    }

    if (mb_strlen($link) > 500) {
        return "Link must be 500 characters or less.";
    }

    return "";
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

            header("Location: transportation.php?concert_id=" . urlencode($concert_id));
            exit();
        }

        # transportation actions require user to be going
        if (!$is_going && in_array($action, [
            'add_transport',
            'edit_transport',
            'delete_transport',
            'toggle_join_transport'
        ], true)) {
            $error = "You are not going to this event!";
        } else {
            if ($action === 'add_transport') {
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

                $validation_error = validate_transport_text($title, $notes, $link);

            if ($validation_error !== "") {
                $error = $validation_error;
            } elseif ($title === '' || $arrival_date === '' || $departure_date === '') {
                $error = "Transportation title, arrival, and departure are required.";
            } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO transport_option
                        (group_concert_id, user_id, title, arrival_date, departure_date, include_time, arrival_time, departure_time, total_cost, limited_spots, max_people, link, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                        $link !== '' ? $link : null,
                        $notes !== '' ? $notes : null
                    ]);

                    header("Location: transportation.php?concert_id=" . urlencode($concert_id));
                    exit();
                }
            }

            # edit transportation
            if ($action === 'edit_transport') {
                $transport_id = (int)($_POST['transport_id'] ?? 0);
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
                    SELECT user_id
                    FROM transport_option
                    WHERE transport_id = ? AND group_concert_id = ?
                ");
                $stmt->execute([$transport_id, $concert_id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$existing || (int)$existing['user_id'] !== $user_id) {
                    header("Location: transportation.php?concert_id=" . urlencode($concert_id));
                    exit();
                }

                $validation_error = validate_transport_text($title, $notes, $link);

                if ($validation_error !== "") {
                    $error = $validation_error;
                } elseif ($title === '' || $arrival_date === '' || $departure_date === '') {
                    $error = "Transportation title, arrival, and departure are required.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE transport_option
                        SET title = ?,
                            arrival_date = ?,
                            departure_date = ?,
                            include_time = ?,
                            arrival_time = ?,
                            departure_time = ?,
                            total_cost = ?,
                            limited_spots = ?,
                            max_people = ?,
                            link = ?,
                            notes = ?
                        WHERE transport_id = ? AND user_id = ? AND group_concert_id = ?
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
                        $link !== '' ? $link : null,
                        $notes !== '' ? $notes : null,
                        $transport_id,
                        $user_id,
                        $concert_id
                    ]);

                    header("Location: transportation.php?concert_id=" . urlencode($concert_id));
                    exit();
                }
            }

            # delete transportation
            if ($action === 'delete_transport') {
                $transport_id = (int)($_POST['transport_id'] ?? 0);

                if ($is_group_owner) {
                    $stmt = $pdo->prepare("
                        DELETE FROM transport_option
                        WHERE transport_id = ? AND group_concert_id = ?
                    ");
                    $stmt->execute([$transport_id, $concert_id]);
                } else {
                    $stmt = $pdo->prepare("
                        DELETE FROM transport_option
                        WHERE transport_id = ? AND user_id = ? AND group_concert_id = ?
                    ");
                    $stmt->execute([$transport_id, $user_id, $concert_id]);
                }

                header("Location: transportation.php?concert_id=" . urlencode($concert_id));
                exit();
            }

            # toggle join/leave transportation
            if ($action === 'toggle_join_transport') {
                $transport_id = (int)($_POST['transport_id'] ?? 0);

                $stmt = $pdo->prepare("
                    SELECT limited_spots, max_people
                    FROM transport_option
                    WHERE transport_id = ? AND group_concert_id = ?
                ");
                $stmt->execute([$transport_id, $concert_id]);
                $transport = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($transport) {
                    $stmt = $pdo->prepare("
                        SELECT 1
                        FROM transport_option_join
                        WHERE transport_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$transport_id, $user_id]);
                    $already_joined = (bool)$stmt->fetchColumn();

                    if ($already_joined) {
                        $stmt = $pdo->prepare("
                            DELETE FROM transport_option_join
                            WHERE transport_id = ? AND user_id = ?
                        ");
                        $stmt->execute([$transport_id, $user_id]);
                    } else {
                        if ((int)$transport['limited_spots'] === 1 && !empty($transport['max_people'])) {
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*)
                                FROM transport_option_join
                                WHERE transport_id = ?
                            ");
                            $stmt->execute([$transport_id]);
                            $joined_total = (int)$stmt->fetchColumn();

                            if ($joined_total >= (int)$transport['max_people']) {
                                header("Location: transportation.php?concert_id=" . urlencode($concert_id));
                                exit();
                            }
                        }

                        $stmt = $pdo->prepare("
                            INSERT IGNORE INTO transport_option_join (transport_id, user_id)
                            VALUES (?, ?)
                        ");
                        $stmt->execute([$transport_id, $user_id]);
                    }
                }

                header("Location: transportation.php?concert_id=" . urlencode($concert_id));
                exit();
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

# get current users rsvp status
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

# get transportation options for this concert
$stmt = $pdo->prepare("
    SELECT
        t.*,
        u.username,
        u.full_name,
        (SELECT COUNT(*) FROM transport_option_join toj WHERE toj.transport_id = t.transport_id) AS joined_count,
        EXISTS(
            SELECT 1
            FROM transport_option_join toj2
            WHERE toj2.transport_id = t.transport_id
              AND toj2.user_id = ?
        ) AS user_joined
    FROM transport_option t
    JOIN user u ON t.user_id = u.user_id
    WHERE t.group_concert_id = ?
    ORDER BY t.arrival_date ASC, t.arrival_time ASC, t.created_at DESC
");
$stmt->execute([$user_id, $concert_id]);
$transport_options = $stmt->fetchAll(PDO::FETCH_ASSOC);

# get joined users for each transportation option
$transport_join_users = [];

foreach ($transport_options as $transport) {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.full_name, u.image
        FROM transport_option_join toj
        JOIN user u ON toj.user_id = u.user_id
        WHERE toj.transport_id = ?
        ORDER BY u.username ASC
    ");
    $stmt->execute([$transport['transport_id']]);

    $transport_join_users[$transport['transport_id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$add_transport_button = $is_going
    ? '<button class="btn post-btn" type="button" onclick="openModal(\'transportModal\')">Add Transportation</button>'
    : '<button class="btn" type="button" onclick="alert(\'You are not going to this event!\')">Add Transportation</button>';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo h($concert['name']); ?> - Transportation</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="container">
    <?php render_concert_header($concert, $concert_image_src, $current_rsvp, $going_users, $not_going_users); ?>

    <?php if ($error): ?>
        <p class="error-message"><?php echo h($error); ?></p>
    <?php endif; ?>

    <?php render_concert_action_row($concert_id, "transportation", $add_transport_button); ?>

    <?php if (empty($transport_options)): ?>
        <p>No transportation options yet.</p>
    <?php else: ?>
        <div class="planning-grid">
            <?php foreach ($transport_options as $transport): ?>
                <?php
                    $transport_cost_per_person = cost_per_person(
                        $transport['total_cost'] ?? null,
                        $transport['max_people'] ?? null,
                        $transport['limited_spots'] ?? 0,
                        $transport['joined_count'] ?? 0
                    );

                    $can_edit_transport = ((int)$transport['user_id'] === $user_id);
                    $can_delete_transport = $can_edit_transport || $is_group_owner;
                ?>

                <div
                    class="planning-summary-card"
                    id="transportCard<?php echo (int)$transport['transport_id']; ?>"
                    onclick="openModal('transportDetailsModal<?php echo (int)$transport['transport_id']; ?>')"
                >
                    <div class="planning-summary-body">
                        <div class="planning-summary-top">
                            <div class="planning-summary-title"><?php echo h($transport['title']); ?></div>

<div class="planning-summary-actions" onclick="event.stopPropagation();">
    <?php if ($is_going && $can_edit_transport): ?>
        <a
            href="#"
            class="text-action-btn"
            onclick="event.preventDefault(); openModal('editTransportModal<?php echo (int)$transport['transport_id']; ?>');"
        >
            Edit
        </a>
    <?php endif; ?>

    <?php if ($can_delete_transport): ?>
        <form
            method="post"
            id="deleteTransportForm<?php echo (int)$transport['transport_id']; ?>"
            onsubmit="return confirm('Delete this transportation option?');"
        >
            <input type="hidden" name="action" value="delete_transport">
            <input type="hidden" name="transport_id" value="<?php echo (int)$transport['transport_id']; ?>">
        </form>

        <a
            href="#"
            class="text-action-btn delete-action"
            onclick="event.preventDefault(); document.getElementById('deleteTransportForm<?php echo (int)$transport['transport_id']; ?>').requestSubmit();"
        >
            Delete
        </a>
    <?php endif; ?>

    <?php if ((int)$transport['user_id'] !== $user_id): ?>
        <?php render_report_button('transport', $transport['transport_id'], 'Report'); ?>
    <?php endif; ?>
</div>

</div>
<div class="planning-summary-line">
    <strong>Arrival:</strong>
    <?php echo h(format_option_datetime($transport['arrival_date'], $transport['arrival_time'] ?? null, $transport['include_time'])); ?>
</div>

<div class="planning-summary-line">
    <strong>Departure:</strong>
    <?php echo h(format_option_datetime($transport['departure_date'], $transport['departure_time'] ?? null, $transport['include_time'])); ?>
</div>

<div class="planning-summary-line">
    <strong>Total Cost:</strong>
    <?php echo $transport['total_cost'] !== null && $transport['total_cost'] !== '' ? '$' . number_format((float)$transport['total_cost'], 2) : '—'; ?>
</div>

<div class="planning-summary-line">
    <strong>Cost Per Person:</strong>
    <?php echo $transport_cost_per_person !== null ? '$' . number_format((float)$transport_cost_per_person, 2) : '—'; ?>
</div>

<div class="planning-meta-row">
    <div class="left">@<?php echo h($transport['username']); ?></div>

    <div class="center" onclick="event.stopPropagation();">
        <?php if ($is_going): ?>
            <form method="post">
                <input type="hidden" name="action" value="toggle_join_transport">
                <input type="hidden" name="transport_id" value="<?php echo (int)$transport['transport_id']; ?>">

    <button
        type="submit"
        class="btn rsvp-toggle-btn <?php echo ((int)$transport['user_joined'] === 1) ? 'not-going' : 'going'; ?>"
    >
        <?php echo ((int)$transport['user_joined'] === 1) ? 'Leave' : 'Join'; ?>
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
            onclick="event.preventDefault(); event.stopPropagation(); openModal('transportMembersModal<?php echo (int)$transport['transport_id']; ?>');"
        >
            <?php echo h(joined_label((int)$transport['joined_count'], $transport['max_people'] ?? null, $transport['limited_spots'] ?? 0)); ?>
        </a>
    </div>
</div>
</div>
    </div>
    <?php if ((int)$transport['user_id'] !== $user_id): ?>
            <?php render_report_modal('transport', $transport['transport_id'], 'Report Transportation'); ?>
            <?php endif; ?>
        
        <div id="transportDetailsModal<?php echo (int)$transport['transport_id']; ?>" class="modal">
            <div class="modal-content housing-details-modal">
                <span class="close-btn" onclick="closeModal('transportDetailsModal<?php echo (int)$transport['transport_id']; ?>')">&times;</span>
                <h2 class="housing-modal-title"><?php echo h($transport['title']); ?></h2>

                <p>
                    <strong>Link:</strong>
                    <?php if (!empty($transport['link'])): ?>
                        <a
                            href="<?php echo h($transport['link']); ?>"
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
                    <?php echo !empty($transport['notes']) ? nl2br(h($transport['notes'])) : '—'; ?>
                </p>


            </div>
        </div>

                <div id="transportMembersModal<?php echo (int)$transport['transport_id']; ?>" class="modal">
                    <div class="modal-content">
                        <span class="close-btn" onclick="closeModal('transportMembersModal<?php echo (int)$transport['transport_id']; ?>')">&times;</span>
                        <h3 class="text-center mt-20">Joined Users</h3>

                        <?php if (empty($transport_join_users[$transport['transport_id']])): ?>
                            <p class="mt-20">No one has joined yet.</p>
                        <?php else: ?>
                            <?php foreach ($transport_join_users[$transport['transport_id']] as $joined_user): ?>
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

                <!-- edit transportation modal -->
                <?php if ($is_going && $can_edit_transport): ?>
                    <div id="editTransportModal<?php echo (int)$transport['transport_id']; ?>" class="modal">
                        <div class="modal-content">
                            <span class="close-btn" onclick="closeModal('editTransportModal<?php echo (int)$transport['transport_id']; ?>')">&times;</span>
                            <h3>Edit Transportation</h3>

                            <form method="post">
                                <input type="hidden" name="action" value="edit_transport">
                                <input type="hidden" name="transport_id" value="<?php echo (int)$transport['transport_id']; ?>">

                                <label>Title:</label>
                                <input type="text" name="title" maxlength="50" value="<?php echo h($transport['title']); ?>" required>

                                <label>Arrival Date:</label>
                                <input type="date" name="arrival_date" value="<?php echo h($transport['arrival_date']); ?>" required>

                                <label>Departure Date:</label>
                                <input type="date" name="departure_date" value="<?php echo h($transport['departure_date']); ?>" required>

                                <label>Total Cost:</label>
                                <input type="number" step="0.01" name="total_cost" value="<?php echo h($transport['total_cost'] ?? ''); ?>">

                                <label class="checkbox-row">
                                    <input
                                        type="checkbox"
                                        name="include_time"
                                        value="1"
                                        <?php echo ((int)$transport['include_time'] === 1) ? 'checked' : ''; ?>
                                        onclick="toggleTimeFields('editTransportTimeFields<?php echo (int)$transport['transport_id']; ?>', this)"
                                    >
                                    Include Time
                                </label>

                                <div
                                    id="editTransportTimeFields<?php echo (int)$transport['transport_id']; ?>"
                                    class="<?php echo ((int)$transport['include_time'] === 1) ? '' : 'hidden'; ?>"
                                >
                                    <label>Arrival Time:</label>
                                    <input type="time" name="arrival_time" value="<?php echo h($transport['arrival_time'] ?? ''); ?>">

                                    <label>Departure Time:</label>
                                    <input type="time" name="departure_time" value="<?php echo h($transport['departure_time'] ?? ''); ?>">
                                </div>

                                <label class="checkbox-row">
                                    <input
                                        type="checkbox"
                                        name="limited_spots"
                                        value="1"
                                        <?php echo ((int)$transport['limited_spots'] === 1) ? 'checked' : ''; ?>
                                        onclick="toggleSpotFields('editTransportSpots<?php echo (int)$transport['transport_id']; ?>', this)"
                                    >
                                    Limited Spots
                                </label>

                                <div
                                    id="editTransportSpots<?php echo (int)$transport['transport_id']; ?>"
                                    class="<?php echo ((int)$transport['limited_spots'] === 1) ? '' : 'hidden'; ?>"
                                >
                                    <label>Max People:</label>
                                    <select name="max_people">
                                        <option value="">Select max</option>
                                        <?php for ($i = 2; $i <= 20; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ((int)($transport['max_people'] ?? 0) === $i) ? 'selected' : ''; ?>>
                                                <?php echo $i; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>

                                <label>Link (optional):</label>
                                <input type="url" name="link" maxlength="500" value="<?php echo h($transport['link'] ?? ''); ?>">

                                                                <label>Notes:</label>
                                <textarea name="notes" maxlength="500"><?php echo h($transport['notes'] ?? ''); ?></textarea>

                                                                <button type="submit" class="btn">Save Changes</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

<div id="transportModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('transportModal')">&times;</span>
        <h3 class="text-center mt-20">Add Transportation</h3>

        <form method="post">
            <input type="hidden" name="action" value="add_transport">

            <label>Title:</label>
<input type="text" name="title" maxlength="50" required>

            <label>Arrival Date:</label>
            <input type="date" name="arrival_date" required>

            <label>Departure Date:</label>
            <input type="date" name="departure_date" required>

            <label>Total Cost:</label>
            <input type="number" step="0.01" name="total_cost" placeholder="e.g. 80.00">

            <label class="checkbox-row">
                <input
                    type="checkbox"
                    name="include_time"
                    value="1"
                    onclick="toggleTimeFields('transportTimeFields', this)"
                >
                Include Time
            </label>

            <div id="transportTimeFields" class="hidden">
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
                    onclick="toggleSpotFields('transportSpots', this)"
                >
                Limited Spots
            </label>

            <div id="transportSpots" class="hidden">
                <label>Max People:</label>
                <select name="max_people">
                    <option value="">Select max</option>
                    <?php for ($i = 2; $i <= 20; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <label>Link (optional):</label>
            <input type="url" name="link" maxlength="500">

            <label>Notes:</label>
            <textarea name="notes" maxlength="500"></textarea>

            <button type="submit" class="btn primary-action-btn">Save Transportation</button>
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