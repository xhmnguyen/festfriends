<?php
require_once("session.php");
require_once("database.php");
require_once("included_functions.php");

$user_id = $_SESSION['user_id'] ?? 0;
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$message = "";

# join request handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_join'])) {
    $group_id = intval($_POST['group_id'] ?? 0);

    if ($group_id > 0 && $user_id > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO group_member (group_id, user_id, status)
            VALUES (?, ?, 'pending')
            ON DUPLICATE KEY UPDATE status = 'pending'
        ");
        $stmt->execute([$group_id, $user_id]);
    }
}

# Fetch groups that the user can join (not owned and not already a member)
$params = [$user_id, $user_id];
$sql = "
    SELECT
        ug.group_id,
        ug.name,
        ug.description,
        ug.image,
        u.username AS owner_name,
        gm_user.status AS user_status
    FROM user_group ug
    JOIN user u ON ug.owner_id = u.user_id
    LEFT JOIN group_member gm_user
        ON ug.group_id = gm_user.group_id
        AND gm_user.user_id = ?
    WHERE ug.owner_id != ?
      AND (
            gm_user.status IS NULL
            OR gm_user.status = 'pending'
          )
";

if ($search_query !== '') {
    $sql .= " AND (ug.name LIKE ? OR ug.description LIKE ?)";
    $params[] = '%' . $search_query . '%';
    $params[] = '%' . $search_query . '%';
}

$sql .= " ORDER BY ug.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

# fetch member counts for displayed groups
$group_ids = array_column($groups, 'group_id');
$members_count = [];

if (!empty($group_ids)) {
    $placeholders = implode(',', array_fill(0, count($group_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT group_id, COUNT(*) AS count
        FROM group_member
        WHERE group_id IN ($placeholders) AND status = 'approved'
        GROUP BY group_id
    ");
    $stmt->execute($group_ids);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $members_count[$row['group_id']] = $row['count'];
    }
}
?>

<div class="container">

    <h1 class="text-center mt-20 mb-20">Join Groups</h1>

    <?php if ($message): ?>
        <p class="text-center mb-20" style="color: green;">
            <?php echo htmlspecialchars($message); ?>
        </p>
    <?php endif; ?>

    <form method="get" action="discover.php" class="search-bar">
        <input
            type="text"
            name="q"
            placeholder="Search for group..."
            value="<?php echo htmlspecialchars($search_query); ?>"
        >
        <button type="submit" class="btn">Search</button>
    </form>

    <div class="flex flex-wrap">
        <?php if (empty($groups)): ?>
            <p class="text-center w-full">No groups found.</p>
        <?php else: ?>
            <?php foreach ($groups as $group): ?>
                <div class="group-link">
                    <div class="card group-card" style="height: 380px; overflow: visible;">
                        <?php if (!empty($group['image'])): ?>
                            <img src="<?php echo htmlspecialchars($group['image']); ?>" alt="Group Image">
                        <?php else: ?>
                            <img src="assets/images/default-group.png" alt="Default Group Image">
                        <?php endif; ?>

                        <div class="card-body" style="padding-bottom: 20px;">
                            <div class="group-name"><?php echo htmlspecialchars($group['name']); ?></div>
                            <div class="group-description"><?php echo htmlspecialchars($group['description']); ?></div>

                            <div class="group-info" style="display: flex; justify-content: space-between; width: 100%; margin-top: 8px;">
                                <span>
                                    Members: <?php echo $members_count[$group['group_id']] ?? 0; ?>
                                </span>
                                <span>
                                    Owner: <?php echo htmlspecialchars($group['owner_name']); ?>
                                </span>
                            </div>

                            <div style="margin-top: 16px; text-align: center;">
                                <?php if (($group['user_status'] ?? '') === 'pending'): ?>
                                    <div style="margin-top: 16px; text-align: center;">
                                        <span style="color: #16a34a;">
                                            Request Submitted
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <form method="post" style="margin: 0;">
                                        <input type="hidden" name="group_id" value="<?php echo $group['group_id']; ?>">
                                        <button type="submit" name="request_join" value="1" class="btn">
                                            Request to Join
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php display_footer(); ?>