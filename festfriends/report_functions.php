<?php

function handle_submit_report($pdo, $user_id) {
    $action = $_POST['action'] ?? '';

    if ($action !== 'submit_report') {
        return;
    }

    $target_type = $_POST['target_type'] ?? '';
    $target_id = (int)($_POST['target_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if (
        in_array($target_type, ['group', 'concert', 'general_post', 'housing', 'transport', 'user'], true) &&
        $target_id > 0 &&
        $reason !== ''
    ) {
        $stmt = $pdo->prepare("
            INSERT INTO report (reporter_id, target_type, target_id, reason)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $target_type, $target_id, $reason]);
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit();
}

function render_report_button($target_type, $target_id, $label = "Report") {
    $modal_id = "reportModal" . preg_replace('/[^a-zA-Z0-9]/', '', $target_type) . (int)$target_id;
    ?>
    <a
        href="#"
        class="text-action-btn report-btn"
        onclick="event.preventDefault(); event.stopPropagation(); openModal('<?php echo h($modal_id); ?>');"
    >
        <?php echo h($label); ?>
    </a>
    <?php
}

function render_report_modal($target_type, $target_id, $title = "Report") {
    $modal_id = "reportModal" . preg_replace('/[^a-zA-Z0-9]/', '', $target_type) . (int)$target_id;
    ?>
    <div id="<?php echo h($modal_id); ?>" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal('<?php echo h($modal_id); ?>')">&times;</span>

            <h3 class="text-center mt-20"><?php echo h($title); ?></h3>

            <form method="post">
                <input type="hidden" name="action" value="submit_report">
                <input type="hidden" name="target_type" value="<?php echo h($target_type); ?>">
                <input type="hidden" name="target_id" value="<?php echo (int)$target_id; ?>">

                <label>Reason:</label>
                <textarea name="reason" maxlength="1000" required></textarea>

                <button type="submit" class="btn">Submit Report</button>
            </form>
        </div>
    </div>
    <?php
}
?>