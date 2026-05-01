<?php
require_once("../session.php");
require_once("../database.php");
require_once("../report_functions.php");

# validate
$concert_id = (int)($_GET['concert_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($concert_id <= 0) {
    die("Invalid concert ID.");
}

if ($user_id <= 0) {
    die("You must be logged in.");
}

# helper function
if (!function_exists('h')) {
    function h($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function get_image_src($path, $default = "../assets/images/default.jpg") {
    if (!$path) {
        return $default;
    }

    if (preg_match('/^https?:\/\//', $path)) {
        return $path;
    }

    return "../" . ltrim($path, '/');
}

# fetch concert with group info
$stmt = $pdo->prepare("
    SELECT
        gc.*,
        ug.name AS group_name,
        ug.owner_id AS group_owner_id
    FROM group_concert gc
    JOIN user_group ug ON gc.group_id = ug.group_id
    WHERE gc.group_concert_id = ?
");
$stmt->execute([$concert_id]);
$concert = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$concert) {
    die("Concert not found.");
}

# check if user is approved member of the group
$stmt = $pdo->prepare("
    SELECT 1
    FROM group_member
    WHERE group_id = ? AND user_id = ? AND status = 'approved'
");
$stmt->execute([$concert['group_id'], $user_id]);

if (!$stmt->fetchColumn()) {
    die("No access.");
}

# get current user's RSVP status
$stmt = $pdo->prepare("
    SELECT status
    FROM concert_rsvp
    WHERE group_concert_id = ? AND user_id = ?
");
$stmt->execute([$concert_id, $user_id]);
$current_rsvp = $stmt->fetchColumn();
$is_going = ($current_rsvp === 'going');

# get all RSVP
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
    }

    if ($r['status'] === 'not_going') {
        $not_going_users[] = $r;
    }
}

$concert_image_src = get_image_src($concert['image'] ?? null);

function render_concert_header($concert, $img, $current_rsvp, $going, $not) {
    global $user_id;

    $is_group_owner = ((int)$concert['group_owner_id'] === (int)$user_id);
?>
<p class="mb-20">
    <a href="../groups/group.php?group_id=<?php echo (int)$concert['group_id']; ?>" class="inline-link" style="text-decoration:none;">
        ← Back to Group
    </a>
</p>

<div class="group-header" style="position:relative;">
    <img class="group-header-img" src="<?php echo h($img); ?>" alt="Concert Image">

    <div class="group-header-info" style="position: relative; min-height:180px;">
        <div style="position:absolute; top:0; right:0; display:flex; gap:10px;">
            <?php if ($is_group_owner): ?>
                <a
                    href="edit_concert.php?concert_id=<?php echo (int)$concert['group_concert_id']; ?>"
                    class="text-action-btn members-remove-toggle"
                >
                    Edit
                </a>
            <?php endif; ?>

<?php if (!$is_group_owner): ?>
    <?php render_report_button('concert', $concert['group_concert_id'], 'Report'); ?>
<?php endif; ?>
        </div>

        <div>
            <div class="group-header-name"><?php echo h($concert['name']); ?></div>

            <div class="group-header-description">
                <?php echo h($concert['location']); ?><br>
                <?php
                    $start = date("F j, Y", strtotime($concert['start_date']));
                    $end = !empty($concert['end_date']) ? date("F j, Y", strtotime($concert['end_date'])) : '';

                    echo h($start);

                    if ($end && $end !== $start) {
                        echo " - " . h($end);
                    }
                ?>
            </div>

            <form method="post" style="margin-top:12px;">
                <input type="hidden" name="action" value="save_rsvp">

                <button
                    type="submit"
                    name="status"
                    value="<?php echo $current_rsvp === 'going' ? 'not_going' : 'going'; ?>"
                    class="btn rsvp-toggle-btn <?php echo $current_rsvp === 'going' ? 'going' : 'not-going'; ?>"
                >
                    <?php echo $current_rsvp === 'going' ? 'I\'m going!' : 'Not Going'; ?>
                </button>
            </form>
        </div>

        <div style="position:absolute; right:20px; bottom:20px;">
            <a href="#" class="inline-link" onclick="event.preventDefault(); openModal('rsvpModal');">
                <?php echo count($going); ?> member(s) going
            </a>
        </div>
    </div>
</div>

<?php if (!$is_group_owner): ?>
    <?php render_report_modal('concert', $concert['group_concert_id'], 'Report Concert'); ?>
<?php endif; ?>
<?php
}

# concert tabs
function render_concert_tabs($concert_id, $active) {
?>
<div class="tabs">
    <div class="tabs-inner">
        <a href="general.php?concert_id=<?php echo (int)$concert_id; ?>" class="tab <?php if ($active === 'general') echo 'active'; ?>">General</a>
        <a href="housing.php?concert_id=<?php echo (int)$concert_id; ?>" class="tab <?php if ($active === 'housing') echo 'active'; ?>">Housing</a>
        <a href="transportation.php?concert_id=<?php echo (int)$concert_id; ?>" class="tab <?php if ($active === 'transportation') echo 'active'; ?>">Transportation</a>
        <a href="set_times.php?concert_id=<?php echo (int)$concert_id; ?>" class="tab <?php if ($active === 'set_times') echo 'active'; ?>">Set-Times</a>
        <a href="budget.php?concert_id=<?php echo (int)$concert_id; ?>" class="tab <?php if ($active === 'budget') echo 'active'; ?>">Budget</a>
        <a href="gallery.php?concert_id=<?php echo (int)$concert_id; ?>" class="tab <?php if ($active === 'gallery') echo 'active'; ?>">Gallery</a>
    </div>
</div>
<?php
}

# concert action
function render_concert_action_row($concert_id, $active, $button_html = '', $right_html = '') {
?>
<div class="concert-actions-row">
    <?php render_concert_tabs($concert_id, $active); ?>

    <div class="actions-right">
        <?php echo $button_html; ?>
        <?php echo $right_html; ?>
    </div>
</div>
<?php
}

# rsvp modal
function render_rsvp_modal($going, $not) {
?>
<div id="rsvpModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('rsvpModal')">&times;</span>

        <h3 class="text-center mt-20">RSVP</h3>

        <h4 class="mt-20">Going: <?php echo count($going); ?></h4>

        <?php if (empty($going)): ?>
            <p>No one is going yet.</p>
        <?php else: ?>
            <?php foreach ($going as $u): ?>
                <div class="members-card-top" style="padding: 12px 0; border-bottom: 1px solid #ececec;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <?php if (!empty($u['image'])): ?>
                            <img src="../<?php echo h($u['image']); ?>" alt="Profile" class="member-avatar-img">
                        <?php else: ?>
                            <div class="member-avatar-fallback">
                                <?php echo h(strtoupper(substr($u['full_name'] ?? $u['username'], 0, 1))); ?>
                            </div>
                        <?php endif; ?>

                        <div>
                            <div class="member-name" style="font-size: 0.95rem;">
                                <?php echo h($u['full_name'] ?: $u['username']); ?>
                            </div>

                            <div class="group-owner">
                                @<?php echo h($u['username']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <h4 class="mt-20">Not Going: <?php echo count($not); ?></h4>

        <?php if (empty($not)): ?>
            <p>No one marked not going.</p>
        <?php else: ?>
            <?php foreach ($not as $u): ?>
                <div class="members-card-top" style="padding: 12px 0; border-bottom: 1px solid #ececec;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <?php if (!empty($u['image'])): ?>
                            <img src="../<?php echo h($u['image']); ?>" alt="Profile" class="member-avatar-img">
                        <?php else: ?>
                            <div class="member-avatar-fallback">
                                <?php echo h(strtoupper(substr($u['full_name'] ?? $u['username'], 0, 1))); ?>
                            </div>
                        <?php endif; ?>

                        <div>
                            <div class="member-name" style="font-size: 0.95rem;">
                                <?php echo h($u['full_name'] ?: $u['username']); ?>
                            </div>

                            <div class="group-owner">
                                @<?php echo h($u['username']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php
}
?>
