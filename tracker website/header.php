<?php
// --- CONFIGURATION ---
require_once 'db_connect.php'; // This file just has $db_host, $db_user, $db_pass, $db_name

// --- ERROR & DATABASE CONNECTION ---
$error_message = null;
$pdo = null;
$stats = [
    'server_count' => 0,
    'file_count' => 0,
    'folder_count' => 0,
    'server_last_run' => 'Never',
    'file_last_run' => 'Never'
];

try {
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    $error_message = "Database Error: " . $e->getMessage();
    // Do not show detailed errors on a public page
    // error_log("PDO Connection Error: "D . $e->getMessage());
    // $error_message = "Database connection error. Please try again later.";
}

/**
 * Formats a UTC timestamp into a human-readable "time ago" string.
 * Also provides a full UTC timestamp in a tooltip.
 */
function format_timestamp($utc_timestamp) {
    if (empty($utc_timestamp)) {
        return 'Never';
    }
    try {
        $date = new DateTime($utc_timestamp, new DateTimeZone('UTC'));
        $now = new DateTime('now', new DateTimeZone('UTC'));
        $interval = $now->diff($date);

        $full_date = $date->format('Y-m-d H:i:s T'); // T will show 'UTC'

        if ($interval->y > 0) $time_ago = $interval->y . ' year' . ($interval->y > 1 ? 's' : '') . ' ago';
        elseif ($interval->m > 0) $time_ago = $interval->m . ' month' . ($interval->m > 1 ? 's' : '') . ' ago';
        elseif ($interval->d > 0) $time_ago = $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
        elseif ($interval->h > 0) $time_ago = $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
        elseif ($interval->i > 0) $time_ago = $interval->i . ' min' . ($interval->i > 1 ? 's' : '') . ' ago';
        else $time_ago = 'Just now';

        return '<span title="' . htmlspecialchars($full_date) . '">' . htmlspecialchars($time_ago) . '</span>';

    } catch (Exception $e) {
        return 'Invalid Date';
    }
}

// --- FETCH STATS (Only if DB connection is good) ---
if ($pdo) {
    try {
        // 1. Get server, file, and folder counts
        $stmt = $pdo->query("
            SELECT
                (SELECT COUNT(*) FROM hotline_servers) AS server_count,
                (SELECT COUNT(*) FROM hotline_files WHERE is_folder = 0) AS file_count,
                (SELECT COUNT(*) FROM hotline_files WHERE is_folder = 1) AS folder_count
        ");
        $counts = $stmt->fetch();
        if ($counts) {
            $stats = array_merge($stats, $counts);
        }

        // 2. Get last run times
        $stmt = $pdo->query("SELECT script_name, last_run_utc FROM script_log");
        $logs = $stmt->fetchAll();
        foreach ($logs as $log) {
            if ($log['script_name'] == 'server_updater') {
                $stats['server_last_run'] = format_timestamp($log['last_run_utc']);
            } elseif ($log['script_name'] == 'file_indexer') {
                $stats['file_last_run'] = format_timestamp($log['last_run_utc']);
            }
        }

    } catch (PDOException $e) {
        $error_message = "Database Stats Error: " . $e->getMessage();
    }
}


// --- THEME / MODE ---
$mode = 'dark'; // Default to dark mode
if (isset($_GET['mode']) && $_GET['mode'] === 'light') {
    $mode = 'light';
}
$toggle_mode = ($mode === 'light' ? 'dark' : 'light');

// Build the base query string for theme toggling
$query_params = $_GET;
$query_params['mode'] = $toggle_mode;
$toggle_url = htmlspecialchars(basename($_SERVER['PHP_SELF']) . '?' . http_build_query($query_params));

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
<head>
    <title>Hotline Tracker</title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style type="text/css">
        /* --- Base & Dark Mode (Default) --- */
        body {
            font-family: "Lucida Console", Monaco, monospace;
            font-size: 12px;
            background-color: #222;
            color: #00FF00;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }
        #container {
            width: 800px;
            margin: 10px auto;
            border: 2px solid #FF0000;
            background-color: #000;
        }
        #header, #nav, #stats-bar, #content, .footer {
            padding: 10px;
        }
        a {
            color: #00FFFF;
            text-decoration: none;
        }
        a:hover {
            color: #FFFFFF;
            background-color: #00AAAA;
        }
        h1, h2, h3 {
            color: #00FF00;
            border-bottom: 1px solid #FF0000;
            padding-bottom: 5px;
            margin-top: 0;
        }
        
        /* --- Header & Nav --- */
        #header h2 {
            margin: 0;
            font-size: 24px;
            border-bottom: none;
        }
        #nav {
            background-color: #111;
            border-bottom: 1px solid #FF0000;
            border-top: 1px solid #FF0000;
            padding: 8px 10px;
            display: flex;
            justify-content: space-between;
        }
        #nav a {
            font-weight: bold;
            padding: 5px 8px;
        }

        /* --- Stats Bar --- */
        #stats-bar {
            background-color: #1a1a1a;
            border-bottom: 1px solid #FF0000;
            padding: 6px;
            font-size: 9px;
        }
        #stats-bar strong {
            font-size: 10px;
            color: #00FF00;
        }
        #stats-bar span[title] {
            cursor: help;
            border-bottom: 1px dotted #AAAAAA;
            font-size: 10px;
        }

        /* --- Content & Tables --- */
        #content {
            padding: 15px;
        }
        table.server-table, table.file-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #FF0000;
        }
        table.server-table th, table.file-table th {
            background-color: #330000;
            border: 1px solid #FF0000;
            padding: 5px;
            text-align: left;
            font-weight: bold;
        }
        table.server-table td, table.file-table td {
            border: 1px solid #555;
            padding: 5px;
            vertical-align: top;
        }
        
        /* --- Row Styling (Dark) --- */
        body.dark table.server-table tr.alt td,
        body.dark table.file-table tr.alt td {
            background-color: #1a1a1a;
        }

        /* --- Row Styling (Light) --- */
        body.light table.server-table tr.alt td,
        body.light table.file-table tr.alt td {
            background-color: #f0f0f0;
        }

        .users, .files, .size, .type, .creator {
            text-align: center;
        }
        .footer {
            text-align: center;
            font-size: 11px;
            color: #888;
            border-top: 1px solid #FF0000;
            margin-top: 15px;
        }
        
        /* --- Forms & Messages --- */
        .search-box input[type="text"] {
            background-color: #333;
            color: #00FF00;
            border: 1px solid #FF0000;
            padding: 4px;
            font-family: "Lucida Console", Monaco, monospace;
        }
        .search-box input[type="submit"] {
            background-color: #330000;
            color: #00FF00;
            border: 1px solid #FF0000;
            padding: 4px 8px;
            font-family: "Lucida Console", Monaco, monospace;
            cursor: pointer;
        }
        .error-message {
            color: #FF6666;
            border: 1px solid #FF0000;
            background-color: #330000;
            padding: 10px;
            margin-bottom: 15px;
        }
        .info-message {
            color: #FFFF00;
            border: 1px solid #FFFF00;
            background-color: #333300;
            padding: 10px;
            margin-bottom: 15px;
        }

        /* --- Breadcrumb & Pagination --- */
        .breadcrumb {
            background-color: #1a1a1a;
            border: 1px solid #555;
            padding: 8px;
            margin-bottom: 10px;
        }
        .breadcrumb a {
            font-weight: bold;
        }
        .pagination {
            text-align: center;
            padding: 10px 0;
        }
        .pagination a, .pagination span {
            padding: 5px 10px;
            margin: 2px;
            border: 1px solid #FF0000;
        }
        .pagination span.current {
            background-color: #330000;
            color: #FFFFFF;
            font-weight: bold;
        }
        .pagination span {
            color: #888;
        }


        /* --- Light Mode Overrides --- */
        body.light {
            background-color: #FFFFFF;
            color: #000000;
        }
        body.light #container {
            border-color: #999999;
            background-color: #FFFFFF;
        }
        body.light h1, body.light h2, body.light h3 {
            color: #000000;
            border-color: #CCCCCC;
        }
        body.light a {
            color: #0000FF;
        }
        body.light a:hover {
            color: #FFFFFF;
            background-color: #0000AA;
        }
        body.light #nav {
            background-color: #EEEEEE;
            border-color: #CCCCCC;
        }
        body.light #stats-bar {
            background-color: #F8F8F8;
            border-color: #CCCCCC;
        }
        body.light #stats-bar li {
            color: #555555;
        }
        body.light #stats-bar strong {
            color: #000000;
        }
        body.light #stats-bar span[title] {
            border-color: #555555;
        }
        body.light table.server-table, body.light table.file-table {
            border-color: #AAAAAA;
        }
        body.light table.server-table th, body.light table.file-table th {
            background-color: #E0E0E0;
            border-color: #AAAAAA;
        }
        body.light table.server-table td, body.light table.file-table td {
            border-color: #CCCCCC;
        }
        body.light .footer {
            border-color: #CCCCCC;
        }
        body.light .search-box input[type="text"] {
            background-color: #FFFFFF;
            color: #000000;
            border-color: #999999;
        }
        body.light .search-box input[type="submit"] {
            background-color: #E0E0E0;
            color: #000000;
            border-color: #999999;
        }
        body.light .error-message {
            color: #DD0000;
            border-color: #DD0000;
            background-color: #FFF0F0;
        }
        body.light .info-message {
            color: #666600;
            border-color: #AAAA00;
            background-color: #FFFFF0;
        }
        body.light .breadcrumb {
            background-color: #F8F8F8;
            border-color: #CCCCCC;
        }
        body.light .pagination a, body.light .pagination span {
            border-color: #AAAAAA;
        }
        body.light .pagination span.current {
            background-color: #E0E0E0;
            color: #000000;
        }
        body.light .pagination span {
            color: #999999;
        }

    </style>
</head>
<body class="<?php echo $mode; ?>">

<div id="container">
    <div id="header">
        <h2>Hotline Tracker</h2>
    </div>
    
    <div id="nav">
        <div>
            <a href="default.php?mode=<?php echo $mode; ?>">Server List</a>
            <a href="file_search.php?mode=<?php echo $mode; ?>">File Search</a>
        </div>
        <div>
            <a href="<?php echo $toggle_url; ?>">Dark/Light</a>
        </div>
    </div>
    
    <div id="stats-bar">
                <strong><?php echo number_format($stats['server_count']); ?></strong>
                Servers Online | 
    
                <strong><?php echo number_format($stats['file_count']); ?></strong>
                Files Indexed | 
          
                <strong><?php echo number_format($stats['folder_count']); ?></strong>
                Folders Indexed | 
          
                <strong>Server Check:</strong>
                <?php echo $stats['server_last_run']; ?> | 
                <strong>File Index:</strong>
                <?php echo $stats['file_last_run']; ?>
    </div>
    
    <!-- Content starts here, and is closed by footer.php -->

