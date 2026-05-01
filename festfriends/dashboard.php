<?php
require_once("session.php");
require_once("database.php");
require_once("included_functions.php");

$user_id = $_SESSION['user_id'];
$search = trim($_GET['search'] ?? '');
$search_results = [];

if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT ug.*, u.username AS owner_name
        FROM user_group ug
        JOIN user u ON ug.owner_id = u.user_id
        WHERE ug.name LIKE ?
           OR u.username LIKE ?
        ORDER BY ug.name ASC
    ");
    $like = "%" . $search . "%";
    $stmt->execute([$like, $like]);
    $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

# get all groups the user is a member of
$stmt = $pdo->prepare("
    SELECT 
        ug.group_id,
        ug.name,
        ug.description,
        ug.image,
        ug.owner_id,
        u.username AS owner_name
    FROM user_group ug
    JOIN user u ON ug.owner_id = u.user_id
    JOIN group_member gm ON ug.group_id = gm.group_id
    WHERE gm.user_id = ? AND gm.status = 'approved'
");
$stmt->execute([$user_id]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

# get member counts for each group
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
# get upcoming concerts the user is attending through their groups
$stmt = $pdo->prepare("
    SELECT gc.*, ug.name AS group_name
    FROM group_concert gc
    JOIN user_group ug ON gc.group_id = ug.group_id
    JOIN concert_rsvp cr ON gc.group_concert_id = cr.group_concert_id
    JOIN group_member gm ON gc.group_id = gm.group_id
    WHERE cr.user_id = ?
      AND cr.status = 'going'
      AND gm.user_id = ?
      AND gm.status = 'approved'
      AND (gc.end_date IS NULL OR gc.end_date >= CURDATE())
    ORDER BY gc.start_date ASC
");

$stmt->execute([$user_id, $user_id]);
$upcoming_concerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container">

    <div class="text-center" style="display:flex; justify-content:center; gap:12px; margin:10px 0;">
        <a href="groups/create_group.php" class="btn post-btn">Create Group</a>
        <a href="discover.php" class="btn">Join Group</a>
    </div>


<!-- groups section -->
    <h2>My Groups</h2>

    <div class="flex">
        <?php if (empty($groups)): ?>
            <p>You are not a member of any groups yet.</p>
        <?php else: ?>
            <?php foreach ($groups as $group): ?>
                <a href="groups/group.php?group_id=<?php echo (int)$group['group_id']; ?>" class="group-link">
                    <div class="card group-card">
                        <?php if (!empty($group['image'])): ?>
                            <img src="<?php echo htmlspecialchars($group['image']); ?>" alt="Group Image">
                        <?php else: ?>
                            <img src="assets/images/default.jpg" alt="Default Image">
                        <?php endif; ?>

                        <div class="card-body" style="padding-bottom: 15px;">
                            <div class="group-name">
                                <?php echo htmlspecialchars($group['name']); ?>
                            </div>



                            <div class="group-info" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                                <span>
                                    Members: <?php echo $members_count[$group['group_id']] ?? 0; ?>
                                </span>

                                <span>
                                    Owner:
                                    <?php echo ((int)$group['owner_id'] === (int)$user_id) ? 'You' : htmlspecialchars($group['owner_name']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<!-- concerts section -->
<h2 style="margin-top: 25px;">Upcoming Festivals</h2>

    <div class="flex">
        <?php if (empty($upcoming_concerts)): ?>
            <p>No upcoming concerts.</p>
        <?php else: ?>
            <?php foreach ($upcoming_concerts as $concert): ?>
                <a href="concerts/concert.php?concert_id=<?php echo (int)$concert['group_concert_id']; ?>" class="concert-link">
                    <div class="card concert-card">
                        <?php if (!empty($concert['image'])): ?>
                            <img src="<?php echo htmlspecialchars($concert['image']); ?>" alt="Concert Image">
                        <?php else: ?>
                            <img src="assets/images/default.jpg" alt="Default Image">
                        <?php endif; ?>

                        <div class="card-body">
                            <div class="concert-name">
                                <?php echo htmlspecialchars($concert['name']); ?>
                            </div>

                            <div class="concert-location">
                                <?php echo htmlspecialchars($concert['location'] ?? ''); ?>
                            </div>

                            <div class="concert-dates">
                                <?php
                                    $start = date("F j, Y", strtotime($concert['start_date']));
                                    $end = !empty($concert['end_date']) ? date("F j, Y", strtotime($concert['end_date'])) : '';

                                    echo htmlspecialchars($start);

                                    if ($end && $end !== $start) {
                                        echo " - " . htmlspecialchars($end);
                                    }
                                ?>
                            </div>

                            <div class="group-owner">
                                Group: <?php echo htmlspecialchars($concert['group_name']); ?>
                            </div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<div id="joinGroupModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('joinGroupModal')">&times;</span>

        <h3 class="text-center mt-20">Join a Group</h3>

        <!-- search form -->
        <form method="get" style="margin-top: 15px;">
            <input
                type="text"
                name="search"
                placeholder="Search by group name or owner username..."
                value="<?php echo htmlspecialchars($search); ?>"
                style="width: 100%; margin-bottom: 10px;"
            >

            <button type="submit" class="btn" style="width: 100%;">
                Search
            </button>
        </form>

        <!-- search results -->
        <?php if ($search !== ''): ?>
            <div style="margin-top: 20px;">
                <?php if (empty($search_results)): ?>
                    <p>No groups found.</p>
                <?php else: ?>
                    <?php foreach ($search_results as $group): ?>
                        <div class="card" style="margin-bottom: 10px; padding: 10px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <div class="group-name">
                                        <?php echo htmlspecialchars($group['name']); ?>
                                    </div>

                                    <div class="group-owner">
                                        @<?php echo htmlspecialchars($group['owner_name']); ?>
                                    </div>
                                </div>

                                <form method="post" action="groups/request_join.php">
                                    <input type="hidden" name="group_id" value="<?php echo (int)$group['group_id']; ?>">

                                    <button type="submit" class="btn">
                                        Request
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>



<?php display_footer(); ?>