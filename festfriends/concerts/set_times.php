<?php
require_once("../included_functions.php");
require_once("included_concert.php");

$error = "";

# helper
    function hex_to_rgba($hex, $alpha = 0.18) {
    $hex = ltrim((string)$hex, '#');

    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        $hex = 'BBDEFB';
    }

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    return "rgba($r, $g, $b, $alpha)";
}

function build_concert_date_options($start_date, $end_date) {
    $options = [];

    if (empty($start_date)) {
        return $options;
    }

    $start = new DateTime($start_date);
    $end = new DateTime(!empty($end_date) ? $end_date : $start_date);
    $end->setTime(0, 0, 0);

    while ($start <= $end) {
        $key = $start->format('Y-m-d');
        $options[$key] = $start->format('F j, Y');
        $start->modify('+1 day');
    }

    return $options;
}

$pastel_colors = [
    '#F8BBD0' => 'Pink',
    '#D1C4E9' => 'Purple',
    '#BBDEFB' => 'Blue',
    '#B2EBF2' => 'Cyan',
    '#C8E6C9' => 'Green',
    '#DCEDC8' => 'Lime',
    '#FFF9C4' => 'Yellow',
    '#FFE0B2' => 'Orange',
    '#FFCCBC' => 'Coral',
    '#D7CCC8' => 'Brown'
];

$concert_date_options = build_concert_date_options($concert['start_date'], $concert['end_date'] ?? null);

# get group info
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

$is_group_owner = ((int)$group['owner_id'] === (int)$user_id);

# form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

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
                        DELETE FROM performance_interest
                        WHERE user_id = ?
                          AND performance_id IN (
                              SELECT performance_id
                              FROM performance_slot
                              WHERE group_concert_id = ?
                          )
                    ");
                    $stmt->execute([$user_id, $concert_id]);
                }
            }

            header("Location: set_times.php?concert_id=" . urlencode($concert_id));
            exit();
        }

        if (!$is_going && in_array($action, [
            'add_stage',
            'update_stage',
            'delete_stage',
            'add_performance',
            'update_performance',
            'delete_performance',
            'toggle_artist_interest'
        ], true)) {
            $error = "You are not going to this event!";
        } else {
            if ($action === 'add_stage') {
                $stage_name = trim($_POST['stage_name'] ?? '');
                $stage_color = trim($_POST['stage_color'] ?? '#BBDEFB');

                if ($stage_name === '') {
                    $error = "Stage name is required.";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO concert_stage (group_concert_id, name, color)
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$concert_id, $stage_name, $stage_color]);

                    header("Location: set_times.php?concert_id=" . urlencode($concert_id));
                    exit();
                }
            }

            if ($action === 'update_stage') {
                $stage_id = (int)($_POST['stage_id'] ?? 0);
                $stage_name = trim($_POST['stage_name'] ?? '');
                $stage_color = trim($_POST['stage_color'] ?? '#BBDEFB');

                if ($is_group_owner && $stage_id > 0 && $stage_name !== '') {
                    $stmt = $pdo->prepare("
                        UPDATE concert_stage
                        SET name = ?, color = ?
                        WHERE stage_id = ? AND group_concert_id = ?
                    ");
                    $stmt->execute([$stage_name, $stage_color, $stage_id, $concert_id]);
                }

                header("Location: set_times.php?concert_id=" . urlencode($concert_id));
                exit();
            }

            if ($action === 'delete_stage') {
                $stage_id = (int)($_POST['stage_id'] ?? 0);

                if ($is_group_owner && $stage_id > 0) {
                    $stmt = $pdo->prepare("
                        DELETE FROM concert_stage
                        WHERE stage_id = ? AND group_concert_id = ?
                    ");
                    $stmt->execute([$stage_id, $concert_id]);
                }

                header("Location: set_times.php?concert_id=" . urlencode($concert_id));
                exit();
            }

            if ($action === 'add_performance') {
                $artist_name = trim($_POST['artist_name'] ?? '');
                $stage_id = ($_POST['stage_id'] ?? '') !== '' ? (int)$_POST['stage_id'] : null;
                $performance_date = trim($_POST['performance_date'] ?? '');
                $start_clock = trim($_POST['start_clock'] ?? '');
                $end_clock = trim($_POST['end_clock'] ?? '');

                if ($artist_name === '' || $performance_date === '' || $start_clock === '' || $end_clock === '') {
                    $error = "Artist name, date, start time, and end time are required.";
                } elseif (!isset($concert_date_options[$performance_date])) {
                    $error = "Selected date is outside the concert dates.";
                } else {
                    $start_time = $performance_date . ' ' . $start_clock . ':00';
                    $end_time = $performance_date . ' ' . $end_clock . ':00';

                    if (strtotime($end_time) <= strtotime($start_time)) {
                        $error = "End time must be after start time.";
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO performance_slot
                            (group_concert_id, user_id, artist_name, stage_id, start_time, end_time)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $concert_id,
                            $user_id,
                            $artist_name,
                            $stage_id,
                            $start_time,
                            $end_time
                        ]);

                        header("Location: set_times.php?concert_id=" . urlencode($concert_id));
                        exit();
                    }
                }
            }

            if ($action === 'update_performance') {
                $performance_id = (int)($_POST['performance_id'] ?? 0);
                $artist_name = trim($_POST['artist_name'] ?? '');
                $stage_id = ($_POST['stage_id'] ?? '') !== '' ? (int)$_POST['stage_id'] : null;
                $performance_date = trim($_POST['performance_date'] ?? '');
                $start_clock = trim($_POST['start_clock'] ?? '');
                $end_clock = trim($_POST['end_clock'] ?? '');

                if (
                    $performance_id > 0 &&
                    $artist_name !== '' &&
                    isset($concert_date_options[$performance_date]) &&
                    $start_clock !== '' &&
                    $end_clock !== ''
                ) {
                    $start_time = $performance_date . ' ' . $start_clock . ':00';
                    $end_time = $performance_date . ' ' . $end_clock . ':00';

                    if (strtotime($end_time) > strtotime($start_time)) {
                        if ($is_group_owner) {
                            $stmt = $pdo->prepare("
                                UPDATE performance_slot
                                SET artist_name = ?, stage_id = ?, start_time = ?, end_time = ?
                                WHERE performance_id = ? AND group_concert_id = ?
                            ");
                            $stmt->execute([
                                $artist_name,
                                $stage_id,
                                $start_time,
                                $end_time,
                                $performance_id,
                                $concert_id
                            ]);
                        } else {
                            $stmt = $pdo->prepare("
                                UPDATE performance_slot
                                SET artist_name = ?, stage_id = ?, start_time = ?, end_time = ?
                                WHERE performance_id = ? AND user_id = ? AND group_concert_id = ?
                            ");
                            $stmt->execute([
                                $artist_name,
                                $stage_id,
                                $start_time,
                                $end_time,
                                $performance_id,
                                $user_id,
                                $concert_id
                            ]);
                        }
                    }
                }

                header("Location: set_times.php?concert_id=" . urlencode($concert_id));
                exit();
            }

            if ($action === 'delete_performance') {
                $performance_id = (int)($_POST['performance_id'] ?? 0);

                if ($is_group_owner) {
                    $stmt = $pdo->prepare("
                        DELETE FROM performance_slot
                        WHERE performance_id = ? AND group_concert_id = ?
                    ");
                    $stmt->execute([$performance_id, $concert_id]);
                } else {
                    $stmt = $pdo->prepare("
                        DELETE FROM performance_slot
                        WHERE performance_id = ? AND user_id = ? AND group_concert_id = ?
                    ");
                    $stmt->execute([$performance_id, $user_id, $concert_id]);
                }

                header("Location: set_times.php?concert_id=" . urlencode($concert_id));
                exit();
            }

            if ($action === 'toggle_artist_interest') {
                $performance_id = (int)($_POST['performance_id'] ?? 0);
                $stage_redirect = trim($_POST['stage_redirect'] ?? 'all');

                $stmt = $pdo->prepare("
                    SELECT performance_id
                    FROM performance_slot
                    WHERE performance_id = ? AND group_concert_id = ?
                ");
                $stmt->execute([$performance_id, $concert_id]);

                if ($stmt->fetchColumn()) {
                    $stmt = $pdo->prepare("
                        SELECT 1
                        FROM performance_interest
                        WHERE performance_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$performance_id, $user_id]);
                    $already_interested = (bool)$stmt->fetchColumn();

                    if ($already_interested) {
                        $stmt = $pdo->prepare("
                            DELETE FROM performance_interest
                            WHERE performance_id = ? AND user_id = ?
                        ");
                        $stmt->execute([$performance_id, $user_id]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT IGNORE INTO performance_interest (performance_id, user_id)
                            VALUES (?, ?)
                        ");
                        $stmt->execute([$performance_id, $user_id]);
                    }
                }

                header("Location: set_times.php?concert_id=" . urlencode($concert_id) . "&stage=" . urlencode($stage_redirect));
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

# get rsvp list
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

foreach ($rsvp_users as $rsvp_user) {
    if ($rsvp_user['status'] === 'going') {
        $going_users[] = $rsvp_user;
    } elseif ($rsvp_user['status'] === 'not_going') {
        $not_going_users[] = $rsvp_user;
    }
}

# get stages
$stmt = $pdo->prepare("
    SELECT *
    FROM concert_stage
    WHERE group_concert_id = ?
    ORDER BY name ASC
");
$stmt->execute([$concert_id]);
$stages = $stmt->fetchAll(PDO::FETCH_ASSOC);

# get performances with interest info
$stmt = $pdo->prepare("
    SELECT
        p.*,
        s.name AS stage_name,
        s.color AS stage_color,
        u.username,
        (
            SELECT COUNT(*)
            FROM performance_interest pi
            WHERE pi.performance_id = p.performance_id
        ) AS interested_count,
        EXISTS(
            SELECT 1
            FROM performance_interest pi2
            WHERE pi2.performance_id = p.performance_id
              AND pi2.user_id = ?
        ) AS user_interested
    FROM performance_slot p
    LEFT JOIN concert_stage s ON p.stage_id = s.stage_id
    JOIN user u ON p.user_id = u.user_id
    WHERE p.group_concert_id = ?
    ORDER BY p.start_time ASC
");
$stmt->execute([$user_id, $concert_id]);
$performances = $stmt->fetchAll(PDO::FETCH_ASSOC);

# get interest users
$interest_users = [];

foreach ($performances as $performance) {
    $stmt = $pdo->prepare("
        SELECT u.username, u.full_name, u.image
        FROM performance_interest pi
        JOIN user u ON pi.user_id = u.user_id
        WHERE pi.performance_id = ?
        ORDER BY u.username ASC
    ");
    $stmt->execute([(int)$performance['performance_id']]);
    $interest_users[$performance['performance_id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

# get current stage filter
$current_stage = $_GET['stage'] ?? 'all';
$current_stage = (string)$current_stage;

$stage_lookup = [];
$valid_stage_keys = ['all', 'unassigned'];

foreach ($stages as $stage) {
    $stage_lookup[(string)$stage['stage_id']] = $stage;
    $valid_stage_keys[] = (string)$stage['stage_id'];
}

if (!in_array($current_stage, $valid_stage_keys, true)) {
    $current_stage = 'all';
}

# organize performances by date and stage
$timeline_by_date = [];
$has_unassigned = false;

foreach ($performances as $performance) {
    $date_key = date("Y-m-d", strtotime($performance['start_time']));
    $stage_key = !empty($performance['stage_id']) ? (string)$performance['stage_id'] : 'unassigned';

    if ($stage_key === 'unassigned') {
        $has_unassigned = true;
    }

    if (!isset($timeline_by_date[$date_key])) {
        $timeline_by_date[$date_key] = [];
    }

    $timeline_by_date[$date_key][] = $performance;
}

$timeline_stage_columns = [];

foreach ($stages as $stage) {
    if ($current_stage === 'all' || $current_stage === (string)$stage['stage_id']) {
        $timeline_stage_columns[] = [
            'key' => (string)$stage['stage_id'],
            'name' => $stage['name'],
            'color' => $stage['color'] ?: '#BBDEFB'
        ];
    }
}

if ($has_unassigned && ($current_stage === 'all' || $current_stage === 'unassigned')) {
    $timeline_stage_columns[] = [
        'key' => 'unassigned',
        'name' => 'Unassigned',
        'color' => '#D1D5DB'
    ];
}

$action_buttons = $is_going
    ? '
        <button class="btn primary-action-btn" type="button" onclick="openModal(\'stageModal\')">Add Stage</button>
        <button class="btn primary-action-btn" type="button" onclick="openModal(\'performanceModal\')">Add Artist</button>
      '
    : '
        <button class="btn primary-action-btn" type="button" onclick="alert(\'You are not going to this event!\')">Add Stage</button>
        <button class="btn primary-action-btn" type="button" onclick="alert(\'You are not going to this event!\')">Add Artist</button>
      ';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo h($concert['name']); ?> - Set-Times</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="container">

<?php render_concert_header($concert, $concert_image_src, $current_rsvp, $going_users, $not_going_users); ?>

<?php if ($error): ?>
    <p class="error-message"><?php echo h($error); ?></p>
<?php endif; ?>

<?php render_concert_action_row($concert_id, "set_times", $action_buttons); ?>

<!-- toggle stages -->
<div class="stage-toggle-row">
    <div class="stage-toggle-left">
        <a
            href="set_times.php?concert_id=<?php echo (int)$concert_id; ?>&stage=all"
            class="stage-toggle-btn <?php echo $current_stage === 'all' ? 'active' : ''; ?>"
        >
            All Stages
        </a>

        <?php foreach ($stages as $stage): ?>
            <a
                href="set_times.php?concert_id=<?php echo (int)$concert_id; ?>&stage=<?php echo (int)$stage['stage_id']; ?>"
                class="stage-toggle-btn <?php echo $current_stage === (string)$stage['stage_id'] ? 'active' : ''; ?>"
            >
                <?php echo h($stage['name']); ?>
            </a>
        <?php endforeach; ?>

        <?php if ($has_unassigned): ?>
            <a
                href="set_times.php?concert_id=<?php echo (int)$concert_id; ?>&stage=unassigned"
                class="stage-toggle-btn <?php echo $current_stage === 'unassigned' ? 'active' : ''; ?>"
            >
                Unassigned
            </a>
        <?php endif; ?>
    </div>

    <div class="stage-toggle-right">
        <?php if ($is_going && $current_stage !== 'all' && $current_stage !== 'unassigned'): ?>
            <button
                type="button"
                class="text-action-btn"
                onclick="openModal('editStageModal<?php echo (int)$current_stage; ?>')"
            >
                Edit Stage
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="timeline-day-card">

<?php if (empty($timeline_by_date) || empty($timeline_stage_columns)): ?>
    <p>No set-times yet.</p>
<?php else: ?>

<div class="timeline-wrapper">

<?php foreach ($timeline_by_date as $date_key => $items): ?>

<?php
    $filtered_items = [];

    foreach ($items as $item) {
        $stage_key = !empty($item['stage_id']) ? (string)$item['stage_id'] : 'unassigned';

        if ($current_stage === 'all' || $current_stage === $stage_key) {
            $filtered_items[] = $item;
        }
    }

    if (empty($filtered_items)) continue;

    $min_hour = 23;
    $max_hour = 0;

    foreach ($filtered_items as $item) {
        $start = strtotime($item['start_time']);
        $end = strtotime($item['end_time']);

        $min_hour = min($min_hour, (int)date('G', $start));
        $max_hour = max($max_hour, (int)date('G', $end) + 1);
    }

    $timeline_height = ($max_hour - $min_hour) * 80;
?>

<h2 class="timeline-date-heading">
    <?php echo h(date("F j, Y", strtotime($date_key))); ?>
</h2>

<div class="timeline-grid" style="--stage-count: <?php echo count($timeline_stage_columns); ?>;">

<div class="timeline-header-cell">Time</div>

<?php foreach ($timeline_stage_columns as $column): ?>
    <div class="timeline-header-cell">
        <span class="stage-color-dot" style="background:<?php echo h($column['color']); ?>"></span>
        <?php echo h($column['name']); ?>
    </div>
<?php endforeach; ?>

<div class="timeline-body-time" style="height:<?php echo $timeline_height; ?>px;">
<?php for ($h = $min_hour; $h <= $max_hour; $h++): ?>
    <div class="timeline-time-label" style="top:<?php echo ($h - $min_hour)*80; ?>px;">
        <?php echo date("g A", strtotime("$h:00")); ?>
    </div>
<?php endfor; ?>
</div>

<?php foreach ($timeline_stage_columns as $column): ?>
<div class="timeline-column" style="height:<?php echo $timeline_height; ?>px;">

<?php foreach ($filtered_items as $item): ?>

<?php
    $stage_key = !empty($item['stage_id']) ? (string)$item['stage_id'] : 'unassigned';
    if ($stage_key !== $column['key']) continue;

    $start = strtotime($item['start_time']);
    $end = strtotime($item['end_time']);

    $top = (((date('G',$start)-$min_hour)*60)+date('i',$start))/60*80;
    $height = max(50, (((date('G',$end)-$min_hour)*60)+date('i',$end)
              -((date('G',$start)-$min_hour)*60)-date('i',$start))/60*80);

    $can_edit = ((int)$item['user_id'] === (int)$user_id) || $is_group_owner;
?>

<div class="timeline-event"
     style="top:<?php echo $top; ?>px;height:<?php echo $height; ?>px;
            border-left-color:<?php echo h($column['color']); ?>;
            background:<?php echo hex_to_rgba($column['color']); ?>;">

    <!-- arist name and modal -->
    <div class="timeline-event-top">
        <button class="artist-link"
                onclick="openModal('artistModal<?php echo $item['performance_id']; ?>')">
            <?php echo h($item['artist_name']); ?>
        </button>
    </div>

    <div class="timeline-event-time">
        <?php echo date("g:i A", $start); ?> - <?php echo date("g:i A", $end); ?>
    </div>

    <div class="timeline-event-user">
        @<?php echo h($item['username']); ?>
    </div>

    <div class="timeline-event-bottom">
        <span class="timeline-going-count">
            <?php echo (int)$item['interested_count']; ?> interested
        </span>
    </div>
</div>

<!-- artist modal -->
<div id="artistModal<?php echo $item['performance_id']; ?>" class="modal">
<div class="modal-content" style="position: relative;">
<span class="close-btn" onclick="closeModal('artistModal<?php echo $item['performance_id']; ?>')">&times;</span>

<?php if ($can_edit): ?>
    <form
        method="post"
        style="position:absolute; top:18px; right:52px;"
        onsubmit="return confirm('Delete artist?');"
    >
        <input type="hidden" name="action" value="delete_performance">
        <input type="hidden" name="performance_id" value="<?php echo $item['performance_id']; ?>">
        <button type="submit" class="text-action-btn delete-action">Delete</button>
    </form>
<?php endif; ?>

<h3><?php echo h($item['artist_name']); ?></h3>

<!-- interest form -->
<form method="post">
<input type="hidden" name="action" value="toggle_artist_interest">
<input type="hidden" name="performance_date" value="<?php echo date('Y-m-d', $start); ?>">
<input type="hidden" name="performance_id" value="<?php echo $item['performance_id']; ?>">
<input type="hidden" name="stage_redirect" value="<?php echo h($current_stage); ?>">

<button class="btn primary-action-btn">
    <?php echo $item['user_interested'] ? "Remove Interest" : "I'm Interested"; ?>
</button>
</form>

<!-- interested users -->
<div class="artist-interest-box">
    <h4>
        Interested: <?php echo (int)$item['interested_count']; ?>
    </h4>

    <?php if (!empty($interest_users[$item['performance_id']])): ?>
        <?php foreach ($interest_users[$item['performance_id']] as $person): ?>
            <div
                class="members-card-top"
                style="padding: 12px 0; border-bottom: 1px solid #ececec;"
            >
                <div style="display: flex; align-items: center; gap: 12px;">
                    <?php if (!empty($person['image'])): ?>
                        <img
                            src="../<?php echo h($person['image']); ?>"
                            alt="Profile"
                            class="member-avatar-img"
                        >
                    <?php else: ?>
                        <div class="member-avatar-fallback">
                            <?php echo h(strtoupper(substr($person['full_name'] ?? $person['username'], 0, 1))); ?>
                        </div>
                    <?php endif; ?>

                    <div>
                        <div class="member-name" style="font-size: 0.95rem;">
                            <?php echo h($person['full_name'] ?: $person['username']); ?>
                        </div>

                        <div class="group-owner">
                            @<?php echo h($person['username']); ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p class="muted-text">No one is interested yet.</p>
    <?php endif; ?>
</div>

<?php if ($can_edit): ?>

<hr style="margin: 28px 0 22px;">
<h4 style="margin-bottom: 14px;">Edit Artist Information</h4>

<!-- edit form -->
<form method="post">
<input type="hidden" name="action" value="update_performance">
<input type="hidden" name="performance_date" value="<?php echo date('Y-m-d', $start); ?>">
<input type="hidden" name="performance_id" value="<?php echo $item['performance_id']; ?>">

<input type="text" name="artist_name" value="<?php echo h($item['artist_name']); ?>" required>

<select name="stage_id">
<option value="">No stage</option>
<?php foreach ($stages as $stage): ?>
<option value="<?php echo $stage['stage_id']; ?>"
<?php echo ((int)$item['stage_id'] === (int)$stage['stage_id']) ? 'selected' : ''; ?>>
<?php echo h($stage['name']); ?>
</option>
<?php endforeach; ?>
</select>

<input type="time" name="start_clock" value="<?php echo date("H:i",$start); ?>" required>
<input type="time" name="end_clock" value="<?php echo date("H:i",$end); ?>" required>

<button class="btn">Save</button>
</form>

<!-- delete form -->
<form method="post" onsubmit="return confirm('Delete artist?');">
<input type="hidden" name="action" value="delete_performance">
<input type="hidden" name="performance_date" value="<?php echo date('Y-m-d', $start); ?>">
<input type="hidden" name="performance_id" value="<?php echo $item['performance_id']; ?>">


</form>

<?php endif; ?>

</div>
</div>

<?php endforeach; ?>
</div>
<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

</div>
<?php endif; ?>

</div>



<!-- add stage -->
<div id="stageModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('stageModal')">&times;</span>
        <h3>Add Stage</h3>

        <form method="post">
            <input type="hidden" name="action" value="add_stage">

            <label>Stage Name:</label>
            <input type="text" name="stage_name" required>

            <label>Stage Color:</label>
            <select name="stage_color">
                <?php foreach ($pastel_colors as $hex => $label): ?>
                    <option value="<?php echo h($hex); ?>">
                        <?php echo h($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn primary-action-btn">Save Stage</button>
        </form>
    </div>
</div>

<!-- add artist  -->
<div id="performanceModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('performanceModal')">&times;</span>
        <h3>Add Artist</h3>

        <form method="post">
            <input type="hidden" name="action" value="add_performance">

            <label>Artist Name:</label>
            <input type="text" name="artist_name" required>

            <label>Stage:</label>
            <select name="stage_id">
                <option value="">No stage</option>
                <?php foreach ($stages as $stage): ?>
                    <option value="<?php echo (int)$stage['stage_id']; ?>">
                        <?php echo h($stage['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Date:</label>
            <select name="performance_date" required>
                <?php foreach ($concert_date_options as $date_value => $date_label): ?>
                    <option value="<?php echo h($date_value); ?>">
                        <?php echo h($date_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Start Time:</label>
            <input type="time" name="start_clock" required>

            <label>End Time:</label>
            <input type="time" name="end_clock" required>

            <button type="submit" class="btn primary-action-btn">Save Artist</button>
        </form>
    </div>
</div>
<?php foreach ($stages as $stage): ?>
    <div id="editStageModal<?php echo (int)$stage['stage_id']; ?>" class="modal">
<div class="modal-content" style="position: relative;">
    <span class="close-btn" onclick="closeModal('editStageModal<?php echo (int)$stage['stage_id']; ?>')">&times;</span>

    <?php if ($is_group_owner): ?>
        <form
            method="post"
            style="position:absolute; top:18px; right:52px;"
            onsubmit="return confirm('Delete this stage? Artists assigned to it will become unassigned.');"
        >
            <input type="hidden" name="action" value="delete_stage">
            <input type="hidden" name="stage_id" value="<?php echo (int)$stage['stage_id']; ?>">
            <button type="submit" class="text-action-btn delete-action">Delete</button>
        </form>
    <?php endif; ?>

    <h3>Edit Stage</h3>

            <form method="post">
                <input type="hidden" name="action" value="update_stage">
                <input type="hidden" name="stage_id" value="<?php echo (int)$stage['stage_id']; ?>">

                <label>Stage Name:</label>
                <input type="text" name="stage_name" value="<?php echo h($stage['name']); ?>" required>

                <label>Stage Color:</label>
                <select name="stage_color">
                    <?php foreach ($pastel_colors as $hex => $label): ?>
                        <option value="<?php echo h($hex); ?>" <?php echo strtoupper($stage['color']) === strtoupper($hex) ? 'selected' : ''; ?>>
                            <?php echo h($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn primary-action-btn">Save Stage</button>
            </form>


        </div>
    </div>
<?php endforeach; ?>
<?php render_rsvp_modal($going_users, $not_going_users); ?>

<script>
function openModal(id){document.getElementById(id).style.display="flex";}
function closeModal(id){document.getElementById(id).style.display="none";}
window.onclick=function(e){
document.querySelectorAll('.modal').forEach(m=>{
if(e.target===m)m.style.display="none";
});
};
</script>



<?php display_footer(); ?>
</body>
</html>