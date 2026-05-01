<?php
require_once("../session.php");
require_once("../database.php");
require_once("../included_functions.php");

if (!isset($_GET['concert_id'])) {
    die("Festival ID not provided.");
}

if (!isset($_SESSION['user_id'])) {
    die("You must be logged in.");
}

# get concert details
$concert_id = (int)$_GET['concert_id'];
$user_id = (int)$_SESSION['user_id'];

$error = "";

$stmt = $pdo->prepare("
    SELECT
        gc.*,
        ug.owner_id,
        ug.name AS group_name
    FROM group_concert gc
    JOIN user_group ug ON gc.group_id = ug.group_id
    WHERE gc.group_concert_id = ?
");
$stmt->execute([$concert_id]);
$concert = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$concert) {
    die("Concert not found.");
}

if ((int)$concert['owner_id'] !== $user_id) {
    die("Only the group owner can edit this concert.");
}

$group_id = (int)$concert['group_id'];
$name = $concert['name'] ?? '';
$location = $concert['location'] ?? '';
$start_date = $concert['start_date'] ?? '';
$end_date = $concert['end_date'] ?? '';
$all_day = $concert['all_day'] ?? 1;
$image_path = $concert['image'] ?? null;

# helper functions
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function image_src($path) {
    if (empty($path)) {
        return "";
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return "../" . ltrim($path, "/");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['delete_concert'])) {
        $stmt = $pdo->prepare("
            DELETE FROM group_concert
            WHERE group_concert_id = ? AND group_id = ?
        ");
        $stmt->execute([$concert_id, $group_id]);

        header("Location: ../groups/group.php?group_id=" . urlencode($group_id));
        exit();
    }

    $name = trim($_POST['name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $existing_image = trim($_POST['existing_image'] ?? '');
    $all_day = 1;

    if ($end_date === '') {
        $end_date = $start_date;
    }
    if ($name === '' || $start_date === '') {
        $error = "Festival name and start date are required.";
    } elseif (strlen($name) > 100) {
        $error = "Festival name must be 100 characters or fewer.";
    } elseif (strlen($location) > 100) {
        $error = "Location must be 100 characters or fewer.";
    }
    if ($existing_image !== '') {
        $image_path = $existing_image;
    }

    if (!empty($_FILES['image']['name'])) {
        $upload_dir = "../uploads/concerts/";

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $original_name = basename($_FILES['image']['name']);
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($extension, $allowed, true)) {
            $error = "Invalid image type.";
        } else {
            $file_name = uniqid('concert_', true) . "." . $extension;
            $target_file = $upload_dir . $file_name;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                $image_path = "uploads/concerts/" . $file_name;
            } else {
                $error = "Failed to upload concert image.";
            }
        }
    }

    if ($error === "") {
        $stmt = $pdo->prepare("
            UPDATE group_concert
            SET name = ?,
                location = ?,
                start_date = ?,
                end_date = ?,
                all_day = ?,
                image = ?
            WHERE group_concert_id = ?
        ");
        $stmt->execute([
            $name,
            $location !== '' ? $location : null,
            $start_date,
            $end_date,
            $all_day,
            $image_path,
            $concert_id
        ]);

        header("Location: concert.php?concert_id=" . urlencode($concert_id));
        exit();
    }
}

# display edit form
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Concert</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>

<div class="container flex" style="justify-content:center; margin-top:50px;">
    <div class="card" style="max-width:700px; width:100%;">
        <h1 class="text-center">Edit Festival</h1>

        <?php if ($error): ?>
            <p class="error-message"><?php echo h($error); ?></p>
        <?php endif; ?>

        <div class="mb-20">
            <label for="concertSearchInput">Search Festival:</label>

            <div class="search-bar">
                <input
                    type="text"
                    id="concertSearchInput"

                >
                <button type="button" class="btn" onclick="searchConcerts()">Search</button>
            </div>

            <div id="searchResults" class="card hidden mt-20"></div>
        </div>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="existing_image" id="existing_image">

            <label>Festival Name:</label>
            <input type="text" name="name" id="name" value="<?php echo h($name); ?>" maxlength="100" required>
            <label>Location:</label>
            <input type="text" name="location" id="location" value="<?php echo h($location); ?>" maxlength="100">
            <label>Start Date:</label>
            <input type="date" name="start_date" id="start_date" value="<?php echo h($start_date); ?>" required>

            <label>End Date:</label>
            <input type="date" name="end_date" id="end_date" value="<?php echo h($end_date); ?>">

            <?php if (!empty($image_path)): ?>
                <div class="mb-20">
                    <label>Current Image:</label>
                    <img
                        src="<?php echo h(image_src($image_path)); ?>"
                        alt="Current Concert Image"
                        style="width:100%; max-height:220px; object-fit:cover; border-radius:12px;"
                    >
                </div>
            <?php endif; ?>

            <div id="selectedImageWrap" class="hidden mb-20">
                <label>Selected API Image:</label>
                <img
                    id="selectedImagePreview"
                    src=""
                    alt="Selected Concert Image"
                    style="width:100%; max-height:220px; object-fit:cover; border-radius:12px;"
                >
            </div>

            <label>Image (optional):</label>
            <input type="file" name="image" accept="image/*">

            <div id="selectedEventLinkWrap" class="hidden mt-20">
                <p>
                    <strong>Event Link:</strong>
                    <a id="selectedEventLink" href="#" target="_blank">View Event</a>
                </p>
            </div>

            <div class="text-center mt-20">
                <button type="submit" class="btn">Save Changes</button>
            </div>
        </form>

                <hr class="mt-20">

        <div style="display:flex; justify-content:center; margin-top:20px;">
            <form method="post" onsubmit="return confirm('Are you sure you want to delete this festival?');">
                <button type="submit" name="delete_concert" value="1" class="btn delete-btn">
                    Delete Festival
                </button>
            </form>
        </div>

<script>

// search for concerts
let searchConcertData = [];
async function searchConcerts() {
    const keyword = document.getElementById('concertSearchInput').value.trim();
    const resultsBox = document.getElementById('searchResults');

    if (!keyword) {
        resultsBox.classList.add('hidden');
        resultsBox.innerHTML = '';
        return;
    }

    resultsBox.classList.remove('hidden');
    resultsBox.innerHTML = '<p>Searching...</p>';

    try {
        const response = await fetch('search_concert.php?keyword=' + encodeURIComponent(keyword));
        const text = await response.text();

        let data;

        try {
            data = JSON.parse(text);
        } catch (e) {
            resultsBox.innerHTML = '<p>Invalid response from search_concert.php:</p><pre>' + escapeHtml(text) + '</pre>';
            return;
        }

        if (data.error) {
            resultsBox.innerHTML = '<p>' + escapeHtml(data.error) + '</p>';
            return;
        }

        if (!Array.isArray(data) || data.length === 0) {
            resultsBox.innerHTML = '<p>No festivals found.</p>';
            return;
        }

        searchConcertData = data;

        resultsBox.innerHTML = data.map((concert, index) => {
            const dateText = formatDateRange(concert.start_date, concert.end_date);

            return `
                <div class="card mb-20" style="cursor:pointer;" onclick="selectConcert(${index})">
                    ${concert.image ? `
                        <img src="${escapeHtml(concert.image)}" alt="Concert Image" style="width:100%; height:180px; object-fit:cover; border-radius:10px; margin-bottom:12px;">
                    ` : ''}

                    <h3>${escapeHtml(concert.name || '')}</h3>
                    <p><strong>Location:</strong> ${escapeHtml(concert.location || 'N/A')}</p>
                    <p><strong>Date(s):</strong> ${escapeHtml(dateText)}</p>
                </div>
            `;
        }).join('');
    } catch (error) {
        resultsBox.innerHTML = '<p>Search failed.</p>';
        console.error(error);
    }
}

// select concert from search results
function selectConcert(index) {
    const concert = searchConcertData[index];

    if (!concert) {
        return;
    }

    document.getElementById('name').value = concert.name || '';
    document.getElementById('location').value = concert.location || '';
    document.getElementById('start_date').value = concert.start_date || '';
    document.getElementById('end_date').value = concert.end_date || concert.start_date || '';

    const existingImage = document.getElementById('existing_image');
    const imageWrap = document.getElementById('selectedImageWrap');
    const imagePreview = document.getElementById('selectedImagePreview');

    if (concert.image) {
        existingImage.value = concert.image;
        imagePreview.src = concert.image;
        imageWrap.classList.remove('hidden');
    } else {
        existingImage.value = '';
        imagePreview.src = '';
        imageWrap.classList.add('hidden');
    }

    const linkWrap = document.getElementById('selectedEventLinkWrap');
    const link = document.getElementById('selectedEventLink');

    if (concert.event_url) {
        link.href = concert.event_url;
        linkWrap.classList.remove('hidden');
    } else {
        link.href = '#';
        linkWrap.classList.add('hidden');
    }

    document.getElementById('searchResults').classList.add('hidden');
}

// helper functions
function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function(match) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        };

        return map[match];
    });
}

// format date range for display
function formatDateRange(startDate, endDate) {
    if (!startDate) {
        return '';
    }

    const options = {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric'
    };

    const start = new Date(startDate + 'T00:00:00');
    const end = endDate ? new Date(endDate + 'T00:00:00') : start;

    const startText = start.toLocaleDateString('en-US', options);
    const endText = end.toLocaleDateString('en-US', options);

    if (startText === endText) {
        return startText;
    }

    return startText + ' - ' + endText;
}
</script>

<?php display_footer(); ?>
</body>
</html>