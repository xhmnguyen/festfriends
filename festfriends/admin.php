<?php
require_once("session.php");
require_once("database.php");
require_once("included_functions.php");

$user_id = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if ($role !== 'admin') {
    die("Access denied.");
}

$message = "";
$error = "";

if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function admin_image_src($path) {
    if (empty($path)) {
        return "";
    }

    if (filter_var($path, FILTER_VALIDATE_URL)) {
        return $path;
    }

    return ltrim((string)$path, "/");
}

function get_report_owner_id($pdo, $target_type, $target_id) {
    if ($target_type === 'group') {
        $stmt = $pdo->prepare("SELECT owner_id FROM user_group WHERE group_id = ?");
        $stmt->execute([$target_id]);
        return (int)$stmt->fetchColumn();
    }

    if ($target_type === 'concert') {
        $stmt = $pdo->prepare("
            SELECT ug.owner_id
            FROM group_concert gc
            JOIN user_group ug ON gc.group_id = ug.group_id
            WHERE gc.group_concert_id = ?
        ");
        $stmt->execute([$target_id]);
        return (int)$stmt->fetchColumn();
    }

    if ($target_type === 'general_post') {
        $stmt = $pdo->prepare("SELECT user_id FROM general_post WHERE post_id = ?");
        $stmt->execute([$target_id]);
        return (int)$stmt->fetchColumn();
    }

    if ($target_type === 'housing') {
        $stmt = $pdo->prepare("SELECT user_id FROM housing_option WHERE housing_id = ?");
        $stmt->execute([$target_id]);
        return (int)$stmt->fetchColumn();
    }

    if ($target_type === 'transport') {
        $stmt = $pdo->prepare("SELECT user_id FROM transport_option WHERE transport_id = ?");
        $stmt->execute([$target_id]);
        return (int)$stmt->fetchColumn();
    }

    if ($target_type === 'gallery') {
        $stmt = $pdo->prepare("SELECT user_id FROM gallery_image WHERE image_id = ?");
        $stmt->execute([$target_id]);
        return (int)$stmt->fetchColumn();
    }

    return 0;
}

function get_reported_content($pdo, $target_type, $target_id) {
    if ($target_type === 'group') {
        $stmt = $pdo->prepare("
            SELECT 
                ug.name,
                ug.description,
                ug.image,
                u.username AS owner_username,
                ug.created_at
            FROM user_group ug
            JOIN user u ON ug.owner_id = u.user_id
            WHERE ug.group_id = ?
        ");
        $stmt->execute([$target_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($target_type === 'concert') {
        $stmt = $pdo->prepare("
            SELECT 
                gc.name,
                gc.location,
                gc.start_date,
                gc.end_date,
                gc.image,
                ug.name AS group_name,
                ug.owner_id,
                u.username AS owner_username,
                gc.created_at
            FROM group_concert gc
            JOIN user_group ug ON gc.group_id = ug.group_id
            JOIN user u ON ug.owner_id = u.user_id
            WHERE gc.group_concert_id = ?
        ");
        $stmt->execute([$target_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($target_type === 'general_post') {
        $stmt = $pdo->prepare("
            SELECT 
                gp.title,
                gp.content,
                gp.image,
                u.username AS posted_by,
                gp.created_at
            FROM general_post gp
            JOIN user u ON gp.user_id = u.user_id
            WHERE gp.post_id = ?
        ");
        $stmt->execute([$target_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($target_type === 'housing') {
        $stmt = $pdo->prepare("
            SELECT 
                ho.title,
                ho.arrival_date,
                ho.departure_date,
                ho.total_cost,
                ho.link,
                ho.notes,
                ho.image,
                u.username AS posted_by,
                ho.created_at
            FROM housing_option ho
            JOIN user u ON ho.user_id = u.user_id
            WHERE ho.housing_id = ?
        ");
        $stmt->execute([$target_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($target_type === 'transport') {
        $stmt = $pdo->prepare("
            SELECT 
                t.title,
                t.arrival_date,
                t.departure_date,
                t.total_cost,
                t.link,
                t.notes,
                u.username AS posted_by,
                t.created_at
            FROM transport_option t
            JOIN user u ON t.user_id = u.user_id
            WHERE t.transport_id = ?
        ");
        $stmt->execute([$target_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($target_type === 'gallery') {
        $stmt = $pdo->prepare("
            SELECT 
                gi.image,
                gi.caption,
                u.username AS posted_by,
                gi.created_at
            FROM gallery_image gi
            JOIN user u ON gi.user_id = u.user_id
            WHERE gi.image_id = ?
        ");
        $stmt->execute([$target_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    return null;
}

function delete_reported_content($pdo, $target_type, $target_id) {
    if ($target_type === 'group') {
        $stmt = $pdo->prepare("DELETE FROM user_group WHERE group_id = ?");
        $stmt->execute([$target_id]);
        return;
    }

    if ($target_type === 'concert') {
        $stmt = $pdo->prepare("DELETE FROM group_concert WHERE group_concert_id = ?");
        $stmt->execute([$target_id]);
        return;
    }

    if ($target_type === 'general_post') {
        $stmt = $pdo->prepare("DELETE FROM general_post WHERE post_id = ?");
        $stmt->execute([$target_id]);
        return;
    }

    if ($target_type === 'housing') {
        $stmt = $pdo->prepare("DELETE FROM housing_option WHERE housing_id = ?");
        $stmt->execute([$target_id]);
        return;
    }

    if ($target_type === 'transport') {
        $stmt = $pdo->prepare("DELETE FROM transport_option WHERE transport_id = ?");
        $stmt->execute([$target_id]);
        return;
    }

    if ($target_type === 'gallery') {
        $stmt = $pdo->prepare("DELETE FROM gallery_image WHERE image_id = ?");
        $stmt->execute([$target_id]);
        return;
    }
}

function render_reported_content($content) {
    if (!$content) {
        echo '<p><em>This content may have already been deleted.</em></p>';
        return;
    }

    foreach ($content as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $label = ucwords(str_replace("_", " ", $key));

        if ($key === 'posted_by' || $key === 'owner_username') {
    echo '<p>';
    echo '<strong>Posted by:</strong><br>';
    echo '@' . h($value);
    echo '</p>';
    continue;
}
        if ($key === 'image') {
            $src = admin_image_src($value);

            echo '<p><strong>Image:</strong></p>';
            echo '<img src="' . h($src) . '" alt="Reported Image" style="max-width:100%; max-height:260px; border-radius:10px; margin-bottom:12px;">';
            continue;
        }

        echo '<p>';
        echo '<strong>' . h($label) . ':</strong><br>';
        echo nl2br(h($value));
        echo '</p>';
    }
}

# admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $report_id = (int)($_POST['report_id'] ?? 0);

    try {
        $stmt = $pdo->prepare("SELECT * FROM report WHERE report_id = ?");
        $stmt->execute([$report_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) {
            $error = "Report not found.";
        } elseif ((int)$report['reporter_id'] === $user_id) {
            $error = "You cannot resolve or act on your own report.";
        } else {
            $target_type = $report['target_type'];
            $target_id = (int)$report['target_id'];

            if ($action === 'resolve_report') {
                $stmt = $pdo->prepare("
                    UPDATE report
                    SET status = 'resolved',
                        resolved_by = ?,
                        resolved_at = CURRENT_TIMESTAMP,
                        action_taken = 'Marked resolved'
                    WHERE report_id = ?
                ");
                $stmt->execute([$user_id, $report_id]);

                $message = "Report marked as resolved.";
            }

            if ($action === 'delete_content') {
                delete_reported_content($pdo, $target_type, $target_id);

                $stmt = $pdo->prepare("
                    UPDATE report
                    SET status = 'resolved',
                        resolved_by = ?,
                        resolved_at = CURRENT_TIMESTAMP,
                        action_taken = 'Deleted reported content'
                    WHERE report_id = ?
                ");
                $stmt->execute([$user_id, $report_id]);

                $message = "Reported content deleted.";
            }

            if ($action === 'delete_owner') {
                $owner_id = get_report_owner_id($pdo, $target_type, $target_id);

                if ($owner_id <= 0) {
                    $error = "Could not find the content owner.";
                } elseif ($owner_id === $user_id) {
                    $error = "You cannot delete your own account.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM user WHERE user_id = ?");
                    $stmt->execute([$owner_id]);

                    $stmt = $pdo->prepare("
                        UPDATE report
                        SET status = 'resolved',
                            resolved_by = ?,
                            resolved_at = CURRENT_TIMESTAMP,
                            action_taken = 'Deleted owner account'
                        WHERE report_id = ?
                    ");
                    $stmt->execute([$user_id, $report_id]);

                    $message = "Owner account deleted.";
                }
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

# get reports
$stmt = $pdo->prepare("
    SELECT 
        r.*,
        u.username AS reporter_username,
        u.full_name AS reporter_full_name
    FROM report r
    JOIN user u ON r.reporter_id = u.user_id
    WHERE r.status <> 'resolved'
    ORDER BY r.created_at DESC
");
$stmt->execute();
$unresolved_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT 
        r.*,
        u.username AS reporter_username,
        u.full_name AS reporter_full_name
    FROM report r
    JOIN user u ON r.reporter_id = u.user_id
    WHERE r.status = 'resolved'
    ORDER BY r.created_at DESC
    LIMIT 50
");
$stmt->execute();
$resolved_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container">
    <h1 class="text-center">Admin Panel</h1>

    <?php if ($message): ?>
        <p class="text-center" style="color: green;"><?php echo h($message); ?></p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p class="error-message"><?php echo h($error); ?></p>
    <?php endif; ?>

    <h2>Unresolved Reports</h2>

    <?php if (empty($unresolved_reports)): ?>
        <p>No unresolved reports.</p>
    <?php else: ?>
        <table class="admin-report-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Type</th>
                    <th>Reported By</th>
                    <th>Reason</th>
                    <th>Date</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($unresolved_reports as $report): ?>
                    <tr onclick="openModal('reportModal<?php echo (int)$report['report_id']; ?>')">
                        <td><?php echo h($report['status']); ?></td>
                        <td><?php echo h($report['target_type']); ?></td>
                        <td>@<?php echo h($report['reporter_username']); ?></td>
                        <td><?php echo h(mb_strimwidth($report['reason'], 0, 70, "...")); ?></td>
                        <td><?php echo h(date("M j, Y", strtotime($report['created_at']))); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php foreach ($unresolved_reports as $report): ?>
            <?php $reported_content = get_reported_content($pdo, $report['target_type'], (int)$report['target_id']); ?>

            <div id="reportModal<?php echo (int)$report['report_id']; ?>" class="modal">
                <div class="modal-content admin-report-modal">
                    <span class="close-btn" onclick="closeModal('reportModal<?php echo (int)$report['report_id']; ?>')">&times;</span>

                    <h3>Report Details</h3>

                    <p><strong>Status:</strong> <?php echo h($report['status']); ?></p>
                    <p><strong>Type:</strong> <?php echo h($report['target_type']); ?></p>
                    <p><strong>Reported By:</strong> @<?php echo h($report['reporter_username']); ?></p>
                    <p><strong>Date:</strong> <?php echo h(date("M j, Y g:i A", strtotime($report['created_at']))); ?></p>

                    <hr>

                    <h4>Reporter Reason</h4>
                    <p><?php echo nl2br(h($report['reason'])); ?></p>

                    <hr>

                    <h4>Reported Content</h4>
                    <?php render_reported_content($reported_content); ?>

                    <hr>

                    <?php if ((int)$report['reporter_id'] === $user_id): ?>
                        <p class="error-message">You cannot resolve or act on your own report.</p>
                    <?php else: ?>
                        <div class="post-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
                            <form method="post" onsubmit="return confirm('Delete the reported content?');">
                                <input type="hidden" name="report_id" value="<?php echo (int)$report['report_id']; ?>">
                                <button type="submit" name="action" value="delete_content" class="btn delete-btn">
                                    Delete Content
                                </button>
                            </form>

                            <form method="post" onsubmit="return confirm('Delete the owner account? This may delete all content they created.');">
                                <input type="hidden" name="report_id" value="<?php echo (int)$report['report_id']; ?>">
                                <button type="submit" name="action" value="delete_owner" class="btn delete-btn">
                                    Delete Account
                                </button>
                            </form>

                            <form method="post">
                                <input type="hidden" name="report_id" value="<?php echo (int)$report['report_id']; ?>">
                                <button type="submit" name="action" value="resolve_report" class="btn">
                                    Mark Resolved
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2 style="margin-top:40px;">Report History</h2>

    <?php if (empty($resolved_reports)): ?>
        <p>No resolved reports yet.</p>
    <?php else: ?>
        <table class="admin-report-table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Type</th>
                    <th>Reported By</th>
                    <th>Reason</th>
                    <th>Action Taken</th>
                    <th>Date</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($resolved_reports as $report): ?>
                    <tr onclick="openModal('historyModal<?php echo (int)$report['report_id']; ?>')">
                        <td><?php echo h($report['status']); ?></td>
                        <td><?php echo h($report['target_type']); ?></td>
                        <td>@<?php echo h($report['reporter_username']); ?></td>
                        <td><?php echo h(mb_strimwidth($report['reason'], 0, 55, "...")); ?></td>
                        <td><?php echo h($report['action_taken'] ?? 'Resolved'); ?></td>
                        <td><?php echo h(date("M j, Y", strtotime($report['created_at']))); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php foreach ($resolved_reports as $report): ?>
            <?php $reported_content = get_reported_content($pdo, $report['target_type'], (int)$report['target_id']); ?>

            <div id="historyModal<?php echo (int)$report['report_id']; ?>" class="modal">
                <div class="modal-content admin-report-modal">
                    <span class="close-btn" onclick="closeModal('historyModal<?php echo (int)$report['report_id']; ?>')">&times;</span>

                    <h3>Resolved Report</h3>

                    <p><strong>Type:</strong> <?php echo h($report['target_type']); ?></p>
                    <p><strong>Reported By:</strong> @<?php echo h($report['reporter_username']); ?></p>
                    <p><strong>Date:</strong> <?php echo h(date("M j, Y g:i A", strtotime($report['created_at']))); ?></p>
                    <p><strong>Action Taken:</strong> <?php echo h($report['action_taken'] ?? 'Resolved'); ?></p>

                    <hr>

                    <h4>Reporter Reason</h4>
                    <p><?php echo nl2br(h($report['reason'])); ?></p>

                    <hr>

                    <h4>Reported Content</h4>
                    <?php render_reported_content($reported_content); ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
.admin-report-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
    background: white;
}

.admin-report-table th,
.admin-report-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #e5e5e5;
    text-align: left;
    font-size: 0.92rem;
}

.admin-report-table th {
    font-weight: 700;
    background: #f8f8f8;
}

.admin-report-table tr {
    cursor: pointer;
}

.admin-report-table tbody tr:hover {
    background: #f9fafb;
}

.admin-report-modal {
    max-width: 650px;
    max-height: 85vh;
    overflow-y: auto;
}
</style>

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