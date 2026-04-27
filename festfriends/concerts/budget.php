<?php
require_once("../included_functions.php");
require_once("included_concert.php");
require_once("../report_functions.php");

$error = "";

/* ===================== HANDLE ACTIONS ===================== */
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

    header("Location: budget.php?concert_id=" . urlencode($concert_id));
    exit();
}

if ($action === 'save_budget') {
    if (!$is_going) {
        $error = "You must RSVP as going before saving a budget.";
    } else {
        $housing_budget = ($_POST['housing_budget'] ?? '') !== ''
            ? (float)$_POST['housing_budget']
            : null;

        $transportation_budget = ($_POST['transportation_budget'] ?? '') !== ''
            ? (float)$_POST['transportation_budget']
            : null;

        $stmt = $pdo->prepare("
            INSERT INTO user_budget
                (group_concert_id, user_id, housing_budget, transportation_budget)
            VALUES
                (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                housing_budget = VALUES(housing_budget),
                transportation_budget = VALUES(transportation_budget)
        ");

        $stmt->execute([
            $concert_id,
            $user_id,
            $housing_budget,
            $transportation_budget
        ]);

        header("Location: budget.php?concert_id=" . urlencode($concert_id));
        exit();
    }
}
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* ===================== REFRESH RSVP ===================== */
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

/* ===================== FETCH CURRENT USER BUDGET ===================== */
$stmt = $pdo->prepare("
    SELECT *
    FROM user_budget
    WHERE user_id = ? AND group_concert_id = ?
");
$stmt->execute([$user_id, $concert_id]);
$current_budget = $stmt->fetch(PDO::FETCH_ASSOC);

/* ===================== FETCH GROUP BUDGET AVERAGES ===================== */
$stmt = $pdo->prepare("
    SELECT
        AVG(housing_budget) AS avg_housing_budget,
        AVG(transportation_budget) AS avg_transportation_budget,
        COUNT(*) AS response_count
    FROM user_budget
    WHERE group_concert_id = ?
");
$stmt->execute([$concert_id]);
$budget_averages = $stmt->fetch(PDO::FETCH_ASSOC);

$total_going = count($going_users);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo h($concert['name']); ?> - Budget</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="container">
    <?php render_concert_header($concert, $concert_image_src, $current_rsvp, $going_users, $not_going_users); ?>

    <?php if ($error): ?>
        <p class="error-message"><?php echo h($error); ?></p>
    <?php endif; ?>

    <?php render_concert_action_row($concert_id, "budget"); ?>

    <div class="tab-content active">
        <div class="budget-layout">
            <div class="card budget-card">
                <h3>Your Budget</h3>

                <form method="post">
                    <input type="hidden" name="action" value="save_budget">

                    <label>Housing Budget:</label>
                    <input
                        type="number"
                        step="0.01"
                        name="housing_budget"
                        value="<?php echo h($current_budget['housing_budget'] ?? ''); ?>"
                    >

                    <label>Transportation Budget:</label>
                    <input
                        type="number"
                        step="0.01"
                        name="transportation_budget"
                        value="<?php echo h($current_budget['transportation_budget'] ?? ''); ?>"
                    >

                    <button type="submit" class="btn">Save Budget</button>
                </form>
            </div>

            <div class="card budget-card">
                <h3>Group Budget Averages</h3>

                <p>
                    <strong>Housing Average:</strong>
                    <?php
                        echo ($budget_averages['response_count'] > 0 && $budget_averages['avg_housing_budget'] !== null)
                            ? '$' . number_format((float)$budget_averages['avg_housing_budget'], 2)
                            : 'No responses yet';
                    ?>
                </p>

                <p>
                    <strong>Transportation Average:</strong>
                    <?php
                        echo ($budget_averages['response_count'] > 0 && $budget_averages['avg_transportation_budget'] !== null)
                            ? '$' . number_format((float)$budget_averages['avg_transportation_budget'], 2)
                            : 'No responses yet';
                    ?>
                </p>

                <p>
                    <strong>Total Responses:</strong>
                    <?php echo (int)($budget_averages['response_count'] ?? 0); ?>/<?php echo (int)$total_going; ?>
                </p>
            </div>
        </div>
    </div>
</div>

<?php render_rsvp_modal($going_users, $not_going_users); ?>

<?php display_footer(); ?>

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

</body>
</html>
