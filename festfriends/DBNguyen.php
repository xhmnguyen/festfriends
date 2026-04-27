<?php
define("USERNAME", "YOUR_USERNAME_HERE");
define("PASSWORD", "YOUR_PASSWORD_HERE");
define("DBHOST", "YOUR_DB_HOST_HERE");
define("DBNAME", "YOUR_DB_NAME_HERE");

define("TICKETMASTER_API_KEY", "YOUR_TICKETMASTER_API_KEY_HERE");
define("EVENTBRITE_API_TOKEN", "YOUR_EVENTBRITE_API_TOKEN_HERE");

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === 'config.php') {
    http_response_code(403);
    exit("Access denied.");
}
?>

