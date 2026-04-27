<?php
require_once("included_concert.php");
require_once("../included_functions.php");
require_once("../report_functions.php");

$error = "";
$stmt = $pdo->prepare("
    SELECT owner_id
    FROM user_group
    WHERE group_id = ?
");
$stmt->execute([$concert['group_id']]);
$group_owner_id = (int)$stmt->fetchColumn();

$is_group_owner = ($group_owner_id === (int)$user_id);

/* ===================== HELPERS ===================== */
function is_ajax_request() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function json_response($payload) {
    if (ob_get_length()) {
        ob_clean();
    }

    header('Content-Type: application/json');
    echo json_encode($payload);
    exit();
}

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

function validate_post_content($content) {
    $content = trim($content);

    if ($content === '') {
        return "Post text is required.";
    }

    if (mb_strlen($content) > 2000) {
        return "Posts must be 2000 characters or less.";
    }

    return "";
}

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

            header("Location: general.php?concert_id=" . urlencode($concert_id));
            exit();
        }

        if (!$is_going && !$is_group_owner && in_array($action, [
            'add_general_post',
            'edit_general_post',
            'delete_general_post',
            'vote_post'
        ], true)) {
            if ($action === 'vote_post' && is_ajax_request()) {
                json_response([
                    'success' => false,
                    'message' => 'You are not going to this event!'
                ]);
            }

            $error = "You are not going to this event!";
        } else {
            if ($action === 'add_general_post') {
                $title = trim($_POST['title'] ?? '');
                $content = trim($_POST['content'] ?? '');
                $validation_error = validate_post_content($content);

                if ($validation_error !== "") {
                    $error = $validation_error;
                } else {
                    $image = upload_image('image', 'posts');

                    $stmt = $pdo->prepare("
                        INSERT INTO general_post (group_concert_id, user_id, title, content, image)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $concert_id,
                        $user_id,
                        $title !== '' ? $title : null,
                        $content,
                        $image
                    ]);

                    header("Location: general.php?concert_id=" . urlencode($concert_id));
                    exit();
                }
            }

            if ($action === 'edit_general_post') {
                $post_id = (int)($_POST['post_id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $content = trim($_POST['content'] ?? '');
                $validation_error = validate_post_content($content);

                if ($validation_error !== "") {
                    $error = $validation_error;
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE general_post
                        SET title = ?, content = ?
                        WHERE post_id = ? AND user_id = ? AND group_concert_id = ?
                    ");
                    $stmt->execute([
                        $title !== '' ? $title : null,
                        $content,
                        $post_id,
                        $user_id,
                        $concert_id
                    ]);

                    header("Location: general.php?concert_id=" . urlencode($concert_id));
                    exit();
                }
            }

if ($action === 'delete_general_post') {
    $post_id = (int)($_POST['post_id'] ?? 0);

    if ($is_group_owner) {
        $stmt = $pdo->prepare("
            DELETE FROM general_post
            WHERE post_id = ? AND group_concert_id = ?
        ");
        $stmt->execute([$post_id, $concert_id]);
    } else {
        $stmt = $pdo->prepare("
            DELETE FROM general_post
            WHERE post_id = ? AND user_id = ? AND group_concert_id = ?
        ");
        $stmt->execute([$post_id, $user_id, $concert_id]);
    }

    header("Location: general.php?concert_id=" . urlencode($concert_id));
    exit();
}

            if ($action === 'vote_post') {
                $post_id = (int)($_POST['post_id'] ?? 0);
                $vote = (int)($_POST['vote'] ?? 0);
                $is_ajax = is_ajax_request();

                if ($post_id <= 0 || !in_array($vote, [1, -1], true)) {
                    if ($is_ajax) {
                        json_response([
                            'success' => false,
                            'message' => 'Invalid vote request.'
                        ]);
                    }

                    header("Location: general.php?concert_id=" . urlencode($concert_id));
                    exit();
                }

                $stmt = $pdo->prepare("
                    SELECT post_id
                    FROM general_post
                    WHERE post_id = ? AND group_concert_id = ?
                ");
                $stmt->execute([$post_id, $concert_id]);
                $post_exists = $stmt->fetchColumn();

                if (!$post_exists) {
                    if ($is_ajax) {
                        json_response([
                            'success' => false,
                            'message' => 'Post not found.'
                        ]);
                    }

                    header("Location: general.php?concert_id=" . urlencode($concert_id));
                    exit();
                }

                $stmt = $pdo->prepare("
                    SELECT vote
                    FROM general_post_vote
                    WHERE post_id = ? AND user_id = ?
                ");
                $stmt->execute([$post_id, $user_id]);
                $existing_vote = $stmt->fetchColumn();

                if ($existing_vote !== false && (int)$existing_vote === $vote) {
                    $stmt = $pdo->prepare("
                        DELETE FROM general_post_vote
                        WHERE post_id = ? AND user_id = ?
                    ");
                    $stmt->execute([$post_id, $user_id]);
                    $new_user_vote = 0;
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO general_post_vote (post_id, user_id, vote)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            vote = VALUES(vote),
                            created_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->execute([$post_id, $user_id, $vote]);
                    $new_user_vote = $vote;
                }

                $stmt = $pdo->prepare("
                    SELECT
                        COALESCE(SUM(CASE WHEN vote = 1 THEN 1 ELSE 0 END), 0) AS upvotes,
                        COALESCE(SUM(CASE WHEN vote = -1 THEN 1 ELSE 0 END), 0) AS downvotes
                    FROM general_post_vote
                    WHERE post_id = ?
                ");
                $stmt->execute([$post_id]);
                $counts = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($is_ajax) {
                    json_response([
                        'success' => true,
                        'upvotes' => (int)($counts['upvotes'] ?? 0),
                        'downvotes' => (int)($counts['downvotes'] ?? 0),
                        'user_vote' => (int)$new_user_vote
                    ]);
                }

                header("Location: general.php?concert_id=" . urlencode($concert_id));
                exit();
            }
        }
    } catch (Throwable $e) {
        if (($action ?? '') === 'vote_post' && is_ajax_request()) {
            json_response([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }

        $error = $e->getMessage();
    }
}

/* ===================== REFRESH RSVP AFTER POST ===================== */
$stmt = $pdo->prepare("
    SELECT status
    FROM concert_rsvp
    WHERE group_concert_id = ? AND user_id = ?
");
$stmt->execute([$concert_id, $user_id]);
$current_rsvp = $stmt->fetchColumn();

$is_going = ($current_rsvp === 'going');

$stmt = $pdo->prepare("
    SELECT u.username, u.full_name, cr.status
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

$sort = $_GET['sort'] ?? 'recent';

if (!in_array($sort, ['recent', 'liked'], true)) {
    $sort = 'recent';
}

$order_by = "gp.created_at DESC";

if ($sort === 'liked') {
    $order_by = "upvotes DESC, gp.created_at DESC";
}


/* ===================== FETCH GENERAL POSTS ===================== */
$stmt = $pdo->prepare("
    SELECT
        gp.post_id,
        gp.user_id,
        gp.title,
        gp.content,
        gp.image,
        gp.created_at,
        u.username,
        u.full_name,
        u.image AS image_user,
        COALESCE(SUM(CASE WHEN gpv.vote = 1 THEN 1 ELSE 0 END), 0) AS upvotes,
        COALESCE(SUM(CASE WHEN gpv.vote = -1 THEN 1 ELSE 0 END), 0) AS downvotes,
        MAX(CASE WHEN gpv.user_id = ? THEN gpv.vote ELSE NULL END) AS user_vote
    FROM general_post gp
    JOIN user u ON gp.user_id = u.user_id
    LEFT JOIN general_post_vote gpv ON gp.post_id = gpv.post_id
    WHERE gp.group_concert_id = ?
    GROUP BY
        gp.post_id,
        gp.user_id,
        gp.title,
        gp.content,
        gp.image,
        gp.created_at,
        u.username,
        u.full_name,
        u.image
    ORDER BY $order_by
");
$stmt->execute([$user_id, $concert_id]);
$general_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo h($concert['name']); ?> - General</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="container">

    <?php render_concert_header($concert, $concert_image_src, $current_rsvp, $going_users, $not_going_users); ?>

    <?php if ($error): ?>
        <p class="error-message"><?php echo h($error); ?></p>
    <?php endif; ?>

<div class="concert-actions-row">
    <?php render_concert_tabs($concert_id, "general"); ?>

    <div class="section-topbar section-topbar-double general-actions-right">
        <form method="get" class="general-filter-form">
            <input type="hidden" name="concert_id" value="<?php echo (int)$concert_id; ?>">

            <select name="sort" onchange="this.form.submit()">
                <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Most Recent</option>
                <option value="liked" <?php echo $sort === 'liked' ? 'selected' : ''; ?>>Most Liked</option>
            </select>
        </form>
        <?php if ($is_going): ?>
            <button class="btn post-btn" type="button" onclick="openModal('generalModal')">Add Post</button>
        <?php else: ?>
            <button class="btn" type="button" onclick="alert('You are not going to this event!')">Add Post</button>
        <?php endif; ?>


    </div>
</div>

<div class="general-posts-wrapper">

        <?php if (empty($general_posts)): ?>
            <p>No posts yet.</p>
        <?php else: ?>
            <div class="posts-grid">
                <?php foreach ($general_posts as $post): ?>
                    <div class="general-post-card card">
                        <div class="general-post-top">
                            <div class="general-post-top-left">
                                <div class="post-user-avatar-wrap">
                                    <?php if (!empty($post['image_user'])): ?>
                                        <img
                                            src="../<?php echo h($post['image_user']); ?>"
                                            alt="Profile"
                                            class="post-user-avatar-img"
                                        >
                                    <?php else: ?>
                                        <div class="post-user-avatar">
                                            <?php echo h(strtoupper(substr($post['full_name'] ?? $post['username'], 0, 1))); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="general-post-heading">
                                    <?php if (!empty($post['title'])): ?>
                                        <h3 class="general-post-title"><?php echo h($post['title']); ?></h3>
                                    <?php endif; ?>

                                    <div class="group-owner">@<?php echo h($post['username']); ?></div>
                                </div>
                            </div>

<div class="post-actions">
    <?php if ((int)$post['user_id'] === $user_id && $is_going): ?>
        <a
            href="#"
            class="text-action-btn"
            onclick="event.preventDefault(); openEditModal(<?php echo (int)$post['post_id']; ?>);"
        >
            Edit
        </a>
    <?php endif; ?>

    <?php if ((int)$post['user_id'] !== $user_id): ?>
        <?php render_report_button('general_post', $post['post_id'], 'Report'); ?>
    <?php endif; ?>

<?php if ((int)$post['user_id'] === $user_id || $is_group_owner): ?>
        <form
            method="post"
            id="deletePostForm<?php echo (int)$post['post_id']; ?>"
            onsubmit="return confirm('Delete this post?');"
        >
            <input type="hidden" name="action" value="delete_general_post">
            <input type="hidden" name="post_id" value="<?php echo (int)$post['post_id']; ?>">
        </form>

        <a
            href="#"
            class="text-action-btn delete-action"
            onclick="event.preventDefault(); document.getElementById('deletePostForm<?php echo (int)$post['post_id']; ?>').requestSubmit();"
        >
            Delete
        </a>
    <?php endif; ?>
</div>

<?php if ((int)$post['user_id'] !== $user_id): ?>
    <?php render_report_modal('general_post', $post['post_id'], 'Report Post'); ?>
<?php endif; ?>
                        </div>

                        <div class="general-post-content">
                            <p><?php echo nl2br(h($post['content'])); ?></p>

                            <?php if (!empty($post['image'])): ?>
                                <img
                                    src="../<?php echo h($post['image']); ?>"
                                    alt="Post Image"
                                    class="post-image-small"
                                >
                            <?php endif; ?>
                        </div>

                        <div class="general-post-bottom">
                            <div class="vote-row" data-post-id="<?php echo (int)$post['post_id']; ?>">
                                <button
                                    type="button"
                                    class="vote-btn upvote-btn <?php echo ((int)($post['user_vote'] ?? 0) === 1) ? 'selected-vote' : ''; ?>"
                                    onclick="votePost(<?php echo (int)$post['post_id']; ?>, 1, this)"
                                >
                                    👍 <span class="upvote-count"><?php echo (int)$post['upvotes']; ?></span>
                                </button>

                                <button
                                    type="button"
                                    class="vote-btn downvote-btn <?php echo ((int)($post['user_vote'] ?? 0) === -1) ? 'selected-vote' : ''; ?>"
                                    onclick="votePost(<?php echo (int)$post['post_id']; ?>, -1, this)"
                                >
                                    👎 <span class="downvote-count"><?php echo (int)$post['downvotes']; ?></span>
                                </button>
                            </div>

                            <div class="post-timestamp">
                                <?php echo h(date("F j, Y g:i A", strtotime($post['created_at']))); ?>
                            </div>
                        </div>
                    </div>

                    <?php if ((int)$post['user_id'] === $user_id && $is_going): ?>
                        <div id="editPostModal<?php echo (int)$post['post_id']; ?>" class="modal">
                            <div class="modal-content">
                                <span class="close-btn" onclick="closeModal('editPostModal<?php echo (int)$post['post_id']; ?>')">&times;</span>
                                <h3>Edit Post</h3>

                                <form method="post">
                                    <input type="hidden" name="action" value="edit_general_post">
                                    <input type="hidden" name="post_id" value="<?php echo (int)$post['post_id']; ?>">

                                    <label>Title:</label>
                                    <input type="text" name="title" value="<?php echo h($post['title'] ?? ''); ?>">

                                    <label>Main Text:</label>
                                    <textarea name="content" maxlength="2000" required><?php echo h($post['content']); ?></textarea>

                                    <button type="submit" class="btn">Save Changes</button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="generalModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('generalModal')">&times;</span>
        <h3 class="text-center mt-20">Add Post</h3>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_general_post">

            <label>Title:</label>
            <input type="text" name="title">

            <label>Main Text:</label>
            <textarea name="content" maxlength="2000" required></textarea>

            <label>Attach Image (optional):</label>
            <input type="file" name="image" accept="image/*">

            <button type="submit" class="btn">Post</button>
        </form>
    </div>
</div>

<?php render_rsvp_modal($going_users, $not_going_users); ?>

<script>
const userIsGoing = <?php echo $is_going ? 'true' : 'false'; ?>;

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

function openEditModal(postId) {
    openModal('editPostModal' + postId);
}

window.onclick = function(event) {
    document.querySelectorAll('.modal').forEach(function(modal) {
        if (event.target === modal) {
            modal.style.display = "none";
        }
    });
};

async function votePost(postId, vote, clickedBtn) {
    if (!userIsGoing) {
        alert('You are not going to this event!');
        return;
    }

    const voteRow = clickedBtn.closest('.vote-row');

    if (!voteRow) {
        return;
    }

    const upvoteBtn = voteRow.querySelector('.upvote-btn');
    const downvoteBtn = voteRow.querySelector('.downvote-btn');
    const upvoteCount = voteRow.querySelector('.upvote-count');
    const downvoteCount = voteRow.querySelector('.downvote-count');

    upvoteBtn.disabled = true;
    downvoteBtn.disabled = true;

    const formData = new FormData();
    formData.append('action', 'vote_post');
    formData.append('post_id', postId);
    formData.append('vote', vote);

    try {
        const response = await fetch('general.php?concert_id=<?php echo (int)$concert_id; ?>', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        const data = await response.json();

        if (!data.success) {
            alert(data.message || 'Vote failed.');
            return;
        }

        upvoteCount.textContent = data.upvotes;
        downvoteCount.textContent = data.downvotes;

        upvoteBtn.classList.remove('selected-vote');
        downvoteBtn.classList.remove('selected-vote');

        if (parseInt(data.user_vote, 10) === 1) {
            upvoteBtn.classList.add('selected-vote');
        } else if (parseInt(data.user_vote, 10) === -1) {
            downvoteBtn.classList.add('selected-vote');
        }
    } catch (error) {
        console.error(error);
    } finally {
        upvoteBtn.disabled = false;
        downvoteBtn.disabled = false;
    }
}
</script>

<?php display_footer(); ?>
</body>
</html>