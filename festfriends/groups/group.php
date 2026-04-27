<?php
require_once("../session.php");
require_once("../database.php");
require_once("../included_functions.php");
require_once("../report_functions.php");

$group_id = intval($_GET['group_id'] ?? 0);
$user_id = $_SESSION['user_id'] ?? 0;
$message = "";

if ($group_id <= 0) {
    die("Group not found.");
}

if ($user_id <= 0) {
    die("You must be logged in.");
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function get_image_src($path, $default = "../assets/images/default.jpg") {
    if (empty($path)) {
        return $default;
    }

    $path = trim((string)$path);

    if (filter_var($path, FILTER_VALIDATE_URL)) {
        return $path;
    }

    return "../" . ltrim($path, "/");
}

function format_concert_dates($start_date, $end_date = null) {
    $start = date("F j, Y", strtotime($start_date));
    $end = !empty($end_date) ? date("F j, Y", strtotime($end_date)) : "";

    if ($end && $end !== $start) {
        return $start . " - " . $end;
    }

    return $start;
}

/* ===================== FETCH GROUP ===================== */
$stmt = $pdo->prepare("
    SELECT ug.*, u.username AS owner_username
    FROM user_group ug
    JOIN user u ON ug.owner_id = u.user_id
    WHERE ug.group_id = ?
");
$stmt->execute([$group_id]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    die("Group not found.");
}

/* ===================== MEMBERSHIP CHECK ===================== */
$stmt = $pdo->prepare("
    SELECT *
    FROM group_member
    WHERE group_id = ? AND user_id = ? AND status = 'approved'
");
$stmt->execute([$group_id, $user_id]);
$is_member = $stmt->rowCount() > 0;

$is_owner = ((int)$group['owner_id'] === (int)$user_id);

/* ===================== POST ACTIONS ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_submit_report($pdo, $user_id);

    if (isset($_POST['request_join'])) {
        $stmt = $pdo->prepare("
            INSERT INTO group_member (group_id, user_id, status)
            VALUES (?, ?, 'pending')
            ON DUPLICATE KEY UPDATE status = 'pending'
        ");
        $stmt->execute([$group_id, $user_id]);
        $message = "Join request sent.";
    }

    if (!$is_owner && $is_member && isset($_POST['leave_group'])) {
        $stmt = $pdo->prepare("
            DELETE FROM group_member
            WHERE group_id = ? AND user_id = ? AND status = 'approved'
        ");
        $stmt->execute([$group_id, $user_id]);

        header("Location: ../dashboard.php");
        exit();
    }

    if ($is_owner && isset($_POST['handle_request'])) {
        $request_user = intval($_POST['request_user'] ?? 0);
        $action = $_POST['action'] ?? '';

        if ($request_user > 0) {
            if ($action === 'approve') {
                $stmt = $pdo->prepare("
                    UPDATE group_member
                    SET status = 'approved'
                    WHERE group_id = ? AND user_id = ?
                ");
                $stmt->execute([$group_id, $request_user]);
            }

            if ($action === 'deny') {
                $stmt = $pdo->prepare("
                    DELETE FROM group_member
                    WHERE group_id = ? AND user_id = ? AND status = 'pending'
                ");
                $stmt->execute([$group_id, $request_user]);
            }
        }

        header("Location: group.php?group_id=" . urlencode($group_id));
        exit();
    }

    if ($is_owner && isset($_POST['remove_member'])) {
        $remove_user_id = intval($_POST['remove_user_id'] ?? 0);

        if ($remove_user_id > 0 && $remove_user_id !== (int)$user_id) {
            $stmt = $pdo->prepare("
                DELETE FROM group_member
                WHERE group_id = ? AND user_id = ? AND status = 'approved'
            ");
            $stmt->execute([$group_id, $remove_user_id]);
        }

        header("Location: group.php?group_id=" . urlencode($group_id));
        exit();
    }

    if ($is_owner && isset($_POST['remove_concert'])) {
        $remove_concert_id = intval($_POST['remove_concert_id'] ?? 0);

        if ($remove_concert_id > 0) {
            $stmt = $pdo->prepare("
                DELETE FROM group_concert
                WHERE group_concert_id = ? AND group_id = ?
            ");
            $stmt->execute([$remove_concert_id, $group_id]);
        }

        header("Location: group.php?group_id=" . urlencode($group_id));
        exit();
    }
}

/* ===================== FETCH MEMBERS ===================== */
$stmt = $pdo->prepare("
    SELECT u.user_id, u.username, u.full_name, u.image
    FROM user u
    JOIN group_member gm ON u.user_id = gm.user_id
    WHERE gm.group_id = ? AND gm.status = 'approved'
    ORDER BY u.username ASC
");
$stmt->execute([$group_id]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===================== FETCH PENDING REQUESTS ===================== */
$pending_requests = [];

if ($is_owner) {
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.full_name, u.image
        FROM user u
        JOIN group_member gm ON u.user_id = gm.user_id
        WHERE gm.group_id = ? AND gm.status = 'pending'
        ORDER BY u.username ASC
    ");
    $stmt->execute([$group_id]);
    $pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ===================== CHECK PENDING REQUEST ===================== */
$stmt = $pdo->prepare("
    SELECT *
    FROM group_member
    WHERE group_id = ? AND user_id = ? AND status = 'pending'
");
$stmt->execute([$group_id, $user_id]);
$pending_request = $stmt->fetch(PDO::FETCH_ASSOC);

/* ===================== FETCH CONCERTS ===================== */
$stmt = $pdo->prepare("
    SELECT *
    FROM group_concert
    WHERE group_id = ?
    ORDER BY start_date ASC
");
$stmt->execute([$group_id]);
$concerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$upcoming_concerts = [];
$past_concerts = [];

foreach ($concerts as $concert) {
    $end_date = !empty($concert['end_date']) ? $concert['end_date'] : $concert['start_date'];

    if ($end_date >= date("Y-m-d")) {
        $upcoming_concerts[] = $concert;
    } else {
        $past_concerts[] = $concert;
    }
}

$group_image_src = get_image_src($group['image'] ?? null);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo h($group['name']); ?> - Group Dashboard</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="container">

    <!-- ===================== GROUP HEADER ===================== -->
    <div class="group-header">
        <img
            class="group-header-img"
            src="<?php echo h($group_image_src); ?>"
            alt="Group Image"
        >

        <div class="group-header-info" style="position: relative; min-height: 180px;">
            <div style="position:absolute; top:0; right:0; display:flex; gap:10px;">
                <?php if ($is_owner): ?>
                    <a
                        href="edit_group.php?group_id=<?php echo (int)$group_id; ?>"
                        class="text-action-btn members-remove-toggle"
                    >
                        Edit
                    </a>
                <?php endif; ?>
<?php if (!$is_owner): ?>
    <?php render_report_button('group', $group_id, 'Report'); ?>
<?php endif; ?>
            </div>

            <div class="group-header-name"><?php echo h($group['name']); ?></div>

            <div class="group-header-description">
                <?php echo h($group['description']); ?>
            </div>

            <?php if (!$is_owner && $is_member): ?>
                <div class="mt-20">
                    <form method="post" onsubmit="return confirm('Are you sure you want to leave this group?');">
                        <input type="hidden" name="leave_group" value="1">
                        <button type="submit" class="btn rsvp-toggle-btn not-going">Leave Group</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($is_owner): ?>
                <div class="mt-20">
                    <a href="../concerts/create_concert.php?group_id=<?php echo (int)$group_id; ?>" class="btn post-btn">
                        Add Festival
                    </a>
                </div>
            <?php endif; ?>

            <div
                class="group-header-stats"
                style="position: absolute; right: 0; bottom: 0; justify-content: flex-end;"
            >
                <a href="#" class="inline-link" onclick="event.preventDefault(); openModal('membersModal');">
                    Members: <?php echo count($members); ?>
                </a>

                <?php if ($is_owner): ?>
                    <a href="#" class="inline-link" onclick="event.preventDefault(); openModal('requestsModal');">
                        Requests: <?php echo count($pending_requests); ?>
                    </a>
                <?php endif; ?>

                <span>
                    Owner: <?php echo $is_owner ? "You" : h($group['owner_username']); ?>
                </span>
            </div>
        </div>
    </div>

<?php if (!$is_owner): ?>
    <?php render_report_modal('group', $group_id, 'Report Group'); ?>
<?php endif; ?>

    <?php if (!empty($message)): ?>
        <p class="text-center mt-20"><?php echo h($message); ?></p>
    <?php endif; ?>

    <?php if (!$is_member && !$is_owner && !$pending_request): ?>
        <form method="post" class="text-center">
            <input type="hidden" name="request_join" value="1">
            <button type="submit" class="btn">Request to Join</button>
        </form>
    <?php elseif ($pending_request): ?>
        <p class="text-center">Your request is pending approval.</p>
    <?php endif; ?>

<!-- ===================== UPCOMING CONCERTS ===================== -->
<h2>Upcoming Festivals</h2>

<?php if (empty($upcoming_concerts)): ?>
    <p>No upcoming festivals.

    </p>
<?php else: ?>
    <div class="flex">
        <?php foreach ($upcoming_concerts as $concert): ?>
            <?php $concert_image_src = get_image_src($concert['image'] ?? null); ?>

            <div class="concert-link-wrapper">
                <a href="../concerts/concert.php?concert_id=<?php echo (int)$concert['group_concert_id']; ?>" class="concert-link">
                    <div class="card concert-card">
                        <img src="<?php echo h($concert_image_src); ?>" alt="Concert Image">

                        <div class="card-body">
                            <div class="concert-name"><?php echo h($concert['name']); ?></div>
                            <div class="concert-location"><?php echo h($concert['location'] ?? ''); ?></div>
                            <div class="concert-dates">
                                <?php echo h(format_concert_dates($concert['start_date'], $concert['end_date'] ?? null)); ?>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<!-- ===================== PAST CONCERTS ===================== -->
<h2>Past Festivals</h2>

<?php if (empty($past_concerts)): ?>
    <p>No past festivals.</p>
<?php else: ?>
    <div class="flex">
        <?php foreach ($past_concerts as $concert): ?>
            <?php $concert_image_src = get_image_src($concert['image'] ?? null); ?>

            <div class="concert-link-wrapper">
                <a href="../concerts/concert.php?concert_id=<?php echo (int)$concert['group_concert_id']; ?>" class="concert-link">
                    <div class="card concert-card">
                        <img src="<?php echo h($concert_image_src); ?>" alt="Concert Image">

                        <div class="card-body">
                            <div class="concert-name"><?php echo h($concert['name']); ?></div>
                            <div class="concert-location"><?php echo h($concert['location'] ?? ''); ?></div>
                            <div class="concert-dates">
                                <?php echo h(format_concert_dates($concert['start_date'], $concert['end_date'] ?? null)); ?>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ===================== MEMBERS MODAL ===================== -->
<div id="membersModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('membersModal')">&times;</span>

        <h3 class="text-center mt-20">Members</h3>

        <div class="members-grid mt-20">
            <?php foreach ($members as $member): ?>
                <div class="member-item">
                    <?php if (!empty($member['image'])): ?>
                        <img
                            src="../<?php echo h($member['image']); ?>"
                            alt="Profile"
                            class="member-avatar-img"
                        >
                    <?php else: ?>
                        <div class="member-avatar-fallback">
                            <?php echo h(strtoupper(substr($member['full_name'] ?? $member['username'], 0, 1))); ?>
                        </div>
                    <?php endif; ?>

                    <div class="member-name">
                        @<?php echo h($member['username']); ?>
                    </div>

                    <?php if ($is_owner && (int)$member['user_id'] !== (int)$user_id): ?>
                        <form
                            method="post"
                            class="remove-member-form"
                            onsubmit="return confirm('Remove this member from the group?');"
                        >
                            <input type="hidden" name="remove_user_id" value="<?php echo (int)$member['user_id']; ?>">
                            <button type="submit" name="remove_member" value="1" class="remove-member-btn">
                                Remove
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ===================== REQUESTS MODAL ===================== -->
<?php if ($is_owner): ?>
    <div id="requestsModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal('requestsModal')">&times;</span>

            <h3 class="text-center mt-20">Join Requests</h3>

            <?php if (empty($pending_requests)): ?>
                <p class="mt-20">No pending requests.</p>
            <?php else: ?>
                <?php foreach ($pending_requests as $request): ?>
                    <div
                        class="members-card-top"
                        style="padding: 12px 0; border-bottom: 1px solid #ececec;"
                    >
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <?php if (!empty($request['image'])): ?>
                                <img
                                    src="../<?php echo h($request['image']); ?>"
                                    alt="Profile"
                                    class="member-avatar-img"
                                >
                            <?php else: ?>
                                <div class="member-avatar-fallback">
                                    <?php echo h(strtoupper(substr($request['full_name'] ?? $request['username'], 0, 1))); ?>
                                </div>
                            <?php endif; ?>

                            <div>
                                <div class="member-name" style="font-size: 0.95rem;">
                                    <?php echo h($request['full_name'] ?: $request['username']); ?>
                                </div>

                                <div class="group-owner">
                                    @<?php echo h($request['username']); ?>
                                </div>
                            </div>
                        </div>

                        <form method="post" class="post-actions">
                            <input type="hidden" name="request_user" value="<?php echo (int)$request['user_id']; ?>">
                            <input type="hidden" name="handle_request" value="1">

                            <button type="submit" name="action" value="approve" class="btn">
                                Approve
                            </button>

                            <button type="submit" name="action" value="deny" class="btn delete-btn">
                                Deny
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

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

function toggleConcertRemoveMode() {
    document.querySelectorAll('.remove-concert-form').forEach(function(form) {
        form.classList.toggle('is-hidden');
    });
}

window.onclick = function(event) {
    document.querySelectorAll('.modal').forEach(function(modal) {
        if (event.target === modal) {
            modal.style.display = "none";
        }
    });
};
</script>
</div>

<?php display_footer(); ?>
</body>
</html>