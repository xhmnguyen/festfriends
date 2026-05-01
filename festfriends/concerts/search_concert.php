<?php
require_once("../session.php");
require_once("/home/hnguye14/DBNguyen.php");

header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$keyword = trim($_GET['keyword'] ?? '');

if ($keyword === '') {
    echo json_encode([]);
    exit;
}

if (!defined('TICKETMASTER_API_KEY') || trim(TICKETMASTER_API_KEY) === '') {
    echo json_encode(["error" => "Ticketmaster API key is missing."]);
    exit;
}

# check if eventbrite token is set, if not, skip and return ticketmaster results
function safe_get_json($url, $headers = []) {
    $header_string = "";

    if (!empty($headers)) {
        $header_string = implode("\r\n", $headers);
    }

    $context = stream_context_create([
        "http" => [
            "method" => "GET",
            "header" => $header_string,
            "timeout" => 20,
            "ignore_errors" => true
        ]
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        return ["error" => "Failed to fetch API using file_get_contents."];
    }

    $http_code = 200;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                $http_code = (int)$matches[1];
                break;
            }
        }
    }

    if ($http_code < 200 || $http_code >= 300) {
        return [
            "error" => "API returned HTTP " . $http_code . ". Response: " . substr($response, 0, 300)
        ];
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        return [
            "error" => "API did not return valid JSON. Response: " . substr($response, 0, 300)
        ];
    }

    return $decoded;
}

// normalize concert names 
function normalize_name($name) {
    $name = strtolower(trim((string)$name));
    $name = preg_replace('/day\s*\d+/i', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);

    return trim($name);
}

// fetch and process ticketmaster results, group by normalized name, and return array of concerts with details
function ticketmaster_results($keyword) {
    $url = "https://app.ticketmaster.com/discovery/v2/events.json?" . http_build_query([
        'apikey' => TICKETMASTER_API_KEY,
        'keyword' => $keyword,
        'classificationName' => 'music',
        'size' => 50,
        'sort' => 'date,asc',
        'countryCode' => 'US'
    ]);

    $data = safe_get_json($url);

    if (isset($data['error'])) {
        return ["error" => "Ticketmaster: " . $data['error']];
    }

    if (empty($data['_embedded']['events']) || !is_array($data['_embedded']['events'])) {
        return [];
    }

    $grouped = [];

    // group events by normalized name and aggregate dates
    foreach ($data['_embedded']['events'] as $event) {
        $raw_name = trim($event['name'] ?? '');
        $date = $event['dates']['start']['localDate'] ?? null;

        if ($raw_name === '' || !$date) {
            continue;
        }

        $key = normalize_name($raw_name);

        $venue = $event['_embedded']['venues'][0] ?? [];

        $venue_name = $venue['name'] ?? '';
        $city = $venue['city']['name'] ?? '';
        $state = $venue['state']['stateCode'] ?? '';
        $country = $venue['country']['name'] ?? '';

        $location = implode(', ', array_filter([
            $venue_name,
            $city,
            $state,
            $country
        ]));

        $image = '';

        if (!empty($event['images']) && is_array($event['images'])) {
            $best = $event['images'][0]['url'] ?? '';

            foreach ($event['images'] as $img) {
                if (!empty($img['url']) && (($img['width'] ?? 0) >= 500)) {
                    $best = $img['url'];
                    break;
                }
            }

            $image = $best;
        }

        $event_url = $event['url'] ?? '';

        if (!isset($grouped[$key])) {
            $display_name = preg_replace('/\s*day\s*\d+/i', '', $raw_name);

            $grouped[$key] = [
                'name' => trim($display_name),
                'location' => $location,
                'image' => $image,
                'dates' => [],
                'source' => 'Ticketmaster',
                'event_url' => $event_url
            ];
        }

        $grouped[$key]['dates'][] = $date;
    }

    $results = [];

    foreach ($grouped as $concert) {
        sort($concert['dates']);

        $results[] = [
            'name' => $concert['name'],
            'location' => $concert['location'],
            'image' => $concert['image'],
            'start_date' => $concert['dates'][0],
            'end_date' => end($concert['dates']),
            'all_day' => 1,
            'source' => $concert['source'],
            'event_url' => $concert['event_url']
        ];
    }

    return $results;
}

// fetch and process eventbrite results, return array of concerts with details
function eventbrite_results($keyword) {
    if (!defined('EVENTBRITE_API_TOKEN') || trim(EVENTBRITE_API_TOKEN) === '') {
        return [];
    }

    $url = "https://www.eventbriteapi.com/v3/events/search/?" . http_build_query([
        'q' => $keyword,
        'expand' => 'venue',
        'location.address' => 'United States'
    ]);

    $data = safe_get_json($url, [
        'Authorization: Bearer ' . EVENTBRITE_API_TOKEN
    ]);

    if (isset($data['error'])) {
        return [];
    }

    if (empty($data['events']) || !is_array($data['events'])) {
        return [];
    }

    $results = [];

    foreach ($data['events'] as $event) {
        $name = trim($event['name']['text'] ?? '');

        if ($name === '') {
            continue;
        }

        $start_local = $event['start']['local'] ?? '';
        $end_local = $event['end']['local'] ?? '';

        $start_date = $start_local ? date('Y-m-d', strtotime($start_local)) : '';
        $end_date = $end_local ? date('Y-m-d', strtotime($end_local)) : $start_date;

        if ($start_date === '') {
            continue;
        }

        $venue = $event['venue'] ?? [];
        $address = $venue['address'] ?? [];

        $location = implode(', ', array_filter([
            $venue['name'] ?? '',
            $address['city'] ?? '',
            $address['region'] ?? '',
            $address['country'] ?? ''
        ]));

        $image = $event['logo']['url'] ?? '';
        $event_url = $event['url'] ?? '';

        $results[] = [
            'name' => $name,
            'location' => $location,
            'image' => $image,
            'start_date' => $start_date,
            'end_date' => $end_date ?: $start_date,
            'all_day' => 1,
            'source' => 'Eventbrite',
            'event_url' => $event_url
        ];
    }

    return $results;
}

$ticketmaster = ticketmaster_results($keyword);

if (isset($ticketmaster['error'])) {
    echo json_encode(["error" => $ticketmaster['error']]);
    exit;
}

$eventbrite = eventbrite_results($keyword);

$results = array_merge($ticketmaster, $eventbrite);

usort($results, function ($a, $b) {
    return strcmp($a['start_date'] ?? '', $b['start_date'] ?? '');
});

echo json_encode($results);