<?php
// This page displays files and folders for a *specific* server.
require_once 'header.php'; // Includes DB connection, stats, and all layout

// --- CONFIGURATION ---
$items_per_page = 100; // Show 100 files/folders at a time
$truncate_file_list_at = 20; // For folders with many files, only show this many *files*
// --- END CONFIGURATION ---

$error_message = $error_message ?? null; // Inherit error from header
$server_id = $_GET['server_id'] ?? null;
$current_path = $_GET['path'] ?? '/';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $items_per_page;

$server_name = "Unknown Server";
$folders = [];
$files = [];
$total_items = 0;

/**
 * Cleans up and normalizes a file path.
 * Ensures it starts with a / and ends with a /
 */
function normalize_path($path) {
    // Replace backslashes with forward slashes
    $path = str_replace('\\', '/', $path);
    // Ensure it starts with a slash
    if (empty($path) || $path[0] !== '/') {
        $path = '/' . $path;
    }
    // Ensure it ends with a slash (for directory browsing)
    if (substr($path, -1) !== '/') {
        $path .= '/';
    }
    // Remove any double slashes
    $path = preg_replace('#/+#', '/', $path);
    return $path;
}

/**
 * Creates breadcrumb links from the current path.
 */
function build_breadcrumbs($path, $server_id, $mode) {
    $parts = explode('/', trim($path, '/'));
    $breadcrumbs = '<a href="file_list.php?server_id=' . urlencode($server_id) . '&path=/&mode=' . $mode . '">root</a> / ';
    $built_path = '/';
    
    foreach ($parts as $part) {
        if (!empty($part)) {
            $built_path .= $part . '/';
            $breadcrumbs .= '<a href="file_list.php?server_id=' . urlencode($server_id) . '&path=' . urlencode($built_path) . '&mode=' . $mode . '">' . htmlspecialchars($part) . '</a> / ';
        }
    }
    return $breadcrumbs;
}

// Normalize the path for querying
$current_path_normalized = normalize_path($current_path);

if ($pdo && !$error_message && $server_id) {
    try {
        // 1. Get Server Name
        $stmt = $pdo->prepare("SELECT name FROM hotline_servers WHERE unique_id = ?");
        $stmt->execute([$server_id]);
        $server = $stmt->fetch();
        if ($server) {
            $server_name = $server['name'];
        } else {
            $error_message = "Server not found.";
        }

        if (!$error_message) {
            // 2. Get total item count for this path (for pagination)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM hotline_files WHERE server_id = ? AND parent_path = ?");
            $stmt->execute([$server_id, $current_path_normalized]);
            $total_items = (int)$stmt->fetchColumn();

            // 3. Get paginated folders
            $stmt = $pdo->prepare("
                SELECT name, full_path, size, type_code, creator_code 
                FROM hotline_files 
                WHERE server_id = ? AND parent_path = ? AND is_folder = 1
                ORDER BY name ASC
            ");
            // No LIMIT/OFFSET for folders, show all folders first
            $stmt->execute([$server_id, $current_path_normalized]);
            $folders = $stmt->fetchAll();

            // 4. Get paginated files
            // We apply pagination ONLY to the file list.
            $stmt = $pdo->prepare("
                SELECT name, full_path, size, type_code, creator_code 
                FROM hotline_files 
                WHERE server_id = :server_id AND parent_path = :parent_path AND is_folder = 0
                ORDER BY name ASC
                LIMIT :limit OFFSET :offset
            ");
            
            // Apply the "top 10" truncation limit if specified
            $file_limit = ($truncate_file_list_at > 0) ? $truncate_file_list_at : $items_per_page;
            
            $stmt->bindValue(':server_id', $server_id);
            $stmt->bindValue(':parent_path', $current_path_normalized);
            $stmt->bindValue(':limit', (int)$file_limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $files = $stmt->fetchAll();
            
            // Get total *file* count (ignoring folders) for pagination/truncation message
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM hotline_files WHERE server_id = ? AND parent_path = ? AND is_folder = 0");
            $stmt->execute([$server_id, $current_path_normalized]);
            $total_files = (int)$stmt->fetchColumn();

            // Adjust total item count for pagination to only reflect files
            $total_items = $total_files; 

        }

    } catch (PDOException $e) {
        $error_message = "Database Error: " . $e->getMessage();
    }
} elseif (!$server_id) {
    $error_message = "No Server ID provided.";
}
?>

<div id="content">
    <h1>File Browser: <?php echo htmlspecialchars($server_name); ?></h1>

    <?php if ($error_message): ?>
        <div class="error-message">
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php else: ?>
        
        <div class="breadcrumb">
            <?php echo build_breadcrumbs($current_path_normalized, $server_id, $mode); ?>
        </div>
        
        <!-- Search within this server (Future feature placeholder)
        <div class="search-box">
            <form action="file_list.php" method="get">
                <input type="hidden" name="server_id" value="<?php echo htmlspecialchars($server_id); ?>">
                <input type="hidden" name="path" value="<?php echo htmlspecialchars($current_path_normalized); ?>">
                <input type="hidden" name="mode" value="<?php echo htmlspecialchars($mode); ?>">
                <input type="text" name="search" placeholder="Search files in this folder...">
                <input type="submit" value="Search">
            </form>
        </div>
        -->
        
        <?php if ($truncate_file_list_at > 0 && $total_files > $truncate_file_list_at): ?>
            <div class="info-message">
                Showing the first <?php echo $truncate_file_list_at; ?> files. 
                Use the <a href="file_search.php?mode=<?php echo $mode; ?>">Global File Search</a> for specifics.
            </div>
        <?php endif; ?>

        <table class="file-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th style="width: 100px;">Size</th>
                    <th style="width: 80px;">Type</th>
                    <th style="width: 80px;">Creator</th>
                </tr>
            </thead>
            <tbody>
                <?php $alt_row = false; ?>
                
                <!-- .. (Parent Directory) Link -->
                <?php if ($current_path_normalized !== '/'): ?>
                    <?php 
                        $parent_path = dirname($current_path_normalized);
                        $parent_path = $parent_path === '/' ? '/' : $parent_path . '/';
                    ?>
                    <tr class="<?php echo $alt_row ? 'alt' : ''; $alt_row = !$alt_row; ?>">
                        <td colspan="4">
                            <a href="file_list.php?server_id=<?php echo urlencode($server_id); ?>&path=<?php echo urlencode($parent_path); ?>&mode=<?php echo $mode; ?>">
                                <strong>.. (Parent Directory)</strong>
                            </a>
                        </td>
                    </tr>
                <?php endif; ?>

                <!-- List Folders -->
                <?php foreach ($folders as $item): ?>
                    <tr class="<?php echo $alt_row ? 'alt' : ''; $alt_row = !$alt_row; ?>">
                        <td>
                            <a href="file_list.php?server_id=<?php echo urlencode($server_id); ?>&path=<?php echo urlencode($item['full_path']); ?>&mode=<?php echo $mode; ?>">
                                <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                            </a>
                        </td>
                        <td class="size">--</td>
                        <td class="type">Folder</td>
                        <td class="creator"><?php echo htmlspecialchars($item['creator_code']); ?></td>
                    </tr>
                <?php endforeach; ?>

                <!-- List Files -->
                <?php foreach ($files as $item): ?>
                    <tr class="<?php echo $alt_row ? 'alt' : ''; $alt_row = !$alt_row; ?>">
                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                        <td class="size"><?php echo number_format($item['size']); ?></td>
                        <td class="type"><?php echo htmlspecialchars($item['type_code']); ?></td>
                        <td class="creator"><?php echo htmlspecialchars($item['creator_code']); ?></td>
                    </tr>
                <?php endforeach; ?>

                <!-- Empty State -->
                <?php if (empty($folders) && empty($files)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center;">This folder is empty.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Pagination (only if truncation is disabled) -->
        <?php if ($truncate_file_list_at === 0 && $total_items > $items_per_page): ?>
            <div class="pagination">
                <?php
                $total_pages = ceil($total_items / $items_per_page);
                $base_url = "file_list.php?server_id=" . urlencode($server_id) . "&path=" . urlencode($current_path_normalized) . "&mode=$mode&page=";

                if ($page > 1) {
                    echo '<a href="' . $base_url . '1">&laquo; First</a>';
                    echo '<a href="' . $base_url . ($page - 1) . '">&lsaquo; Prev</a>';
                } else {
                    echo '<span>&laquo; First</span>';
                    echo '<span>&lsaquo; Prev</span>';
                }

                echo '<span>Page ' . $page . ' of ' . $total_pages . '</span>';

                if ($page < $total_pages) {
                    echo '<a href="' . $base_url . ($page + 1) . '">Next &rsaquo;</a>';
                    echo '<a href="' . $base_url . $total_pages . '">Last &raquo;</a>';
                } else {
                    echo '<span>Next &rsaquo;</span>';
                    echo '<span>Last &raquo;</span>';
                }
                ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    <div class="footer">
        Page generated by PHP/MySQL.
    </div>
</div>

<?php
include 'footer.php';
?>

