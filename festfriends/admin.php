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

function get_report_owner_id($pdo, $target_type, $target_id) {
    if ($target_type === 'user') {
        return $target_id;
    }

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

    if ($target_type === 'user') {
        $stmt = $pdo->prepare("DELETE FROM user WHERE user_id = ?");
        $stmt->execute([$target_id]);
        return;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $report_id = (int)($_POST['report_id'] ?? 0);
    $target_type = $_POST['target_type'] ?? '';
    $target_id = (int)($_POST['target_id'] ?? 0);

    try {
        $stmt = $pdo->prepare("SELECT * FROM report WHERE report_id = ?");
        $stmt->execute([$report_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) {
            $error = "Report not found.";
        } elseif ((int)$report['reporter_id'] === $user_id && in_array($action, ['resolve_report', 'delete_content', 'delete_owner'], true)) {
            $error = "You cannot resolve or act on your own report.";
        } else {
            if ($action === 'resolve_report') {
                $stmt = $pdo->prepare("UPDATE report SET status = 'resolved' WHERE report_id = ?");
                $stmt->execute([$report_id]);
                $message = "Report marked as resolved.";
            }

            if ($action === 'delete_content') {
                delete_reported_content($pdo, $target_type, $target_id);

                $stmt = $pdo->prepare("UPDATE report SET status = 'resolved' WHERE report_id = ?");
                $stmt->execute([$report_id]);

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

                    $stmt = $pdo->prepare("UPDATE report SET status = 'resolved' WHERE report_id = ?");
                    $stmt->execute([$report_id]);

                    $message = "Owner account deleted.";
                }
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

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
            <div id="reportModal<?php echo (int)$report['report_id']; ?>" class="modal">
                <div class="modal-content">
                    <span class="close-btn" onclick="closeModal('reportModal<?php echo (int)$report['report_id']; ?>')">&times;</span>

                    <h3>Report Details</h3>

                    <p><strong>Status:</strong> <?php echo h($report['status']); ?></p>
                    <p><strong>Type:</strong> <?php echo h($report['target_type']); ?></p>
                    <p><strong>Target ID:</strong> <?php echo (int)$report['target_id']; ?></p>
                    <p><strong>Reported By:</strong> @<?php echo h($report['reporter_username']); ?></p>
                    <p><strong>Date:</strong> <?php echo h(date("M j, Y g:i A", strtotime($report['created_at']))); ?></p>
                    <p><strong>Reason:</strong><br><?php echo nl2br(h($report['reason'])); ?></p>

                    <?php if ((int)$report['reporter_id'] === $user_id): ?>
                        <p class="error-message">You cannot resolve or act on your own report.</p>
                    <?php else: ?>
                        <div class="post-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
                            <form method="post">
                                <input type="hidden" name="report_id" value="<?php echo (int)$report['report_id']; ?>">
                                <input type="hidden" name="target_type" value="<?php echo h($report['target_type']); ?>">
                                <input type="hidden" name="target_id" value="<?php echo (int)$report['target_id']; ?>">
                                <button type="submit" name="action" value="delete_content" class="btn delete-btn">
                                    Delete Content
                                </button>
                            </form>

                            <form method="post" onsubmit="return confirm('Delete the owner account? This may delete all content they created.');">
                                <input type="hidden" name="report_id" value="<?php echo (int)$report['report_id']; ?>">
                                <input type="hidden" name="target_type" value="<?php echo h($report['target_type']); ?>">
                                <input type="hidden" name="target_id" value="<?php echo (int)$report['target_id']; ?>">
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
                    <th>Date</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($resolved_reports as $report): ?>
                    <tr onclick="openModal('historyModal<?php echo (int)$report['report_id']; ?>')">
                        <td><?php echo h($report['status']); ?></td>
                        <td><?php echo h($report['target_type']); ?></td>
                        <td>@<?php echo h($report['reporter_username']); ?></td>
                        <td><?php echo h(mb_strimwidth($report['reason'], 0, 70, "...")); ?></td>
                        <td><?php echo h(date("M j, Y", strtotime($report['created_at']))); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php foreach ($resolved_reports as $report): ?>
            <div id="historyModal<?php echo (int)$report['report_id']; ?>" class="modal">
                <div class="modal-content">
                    <span class="close-btn" onclick="closeModal('historyModal<?php echo (int)$report['report_id']; ?>')">&times;</span>

                    <h3>Resolved Report</h3>

                    <p><strong>Type:</strong> <?php echo h($report['target_type']); ?></p>
                    <p><strong>Target ID:</strong> <?php echo (int)$report['target_id']; ?></p>
                    <p><strong>Reported By:</strong> @<?php echo h($report['reporter_username']); ?></p>
                    <p><strong>Date:</strong> <?php echo h(date("M j, Y g:i A", strtotime($report['created_at']))); ?></p>
                    <p><strong>Reason:</strong><br><?php echo nl2br(h($report['reason'])); ?></p>
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