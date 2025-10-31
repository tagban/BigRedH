<?php
// --- CONFIGURATION ---
// This file assumes 'db_connect.php' and 'header.php' are in the same directory
require_once 'header.php';
// --- END CONFIGURATION ---

// Page-specific variables for header
$current_page = 'file_search.php';
$page_title = 'Hotline File Search';

// Search-specific variables
$search_query = $_GET['query'] ?? '';
$search_results = [];
$search_performed = !empty($search_query);

// The $pdo variable and $error_message are now available from header.php
if (empty($error_message) && $search_performed) {
    try {
        // --- Fetch Search Results ---
        // This query searches file/folder names and paths.
        // It's limited to 100 results for speed.
        // We also join with hotline_servers to get the server name and IP/port.
        $sql = "
            SELECT 
                f.name, f.full_path, f.size, f.creator_code, f.is_folder,
                s.unique_id, s.name as server_name, s.ip, s.port
            FROM 
                hotline_files f
            JOIN 
                hotline_servers s ON f.server_id = s.unique_id
            WHERE 
                f.name LIKE :query
            LIMIT 100
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['query' => '%' . $search_query . '%']);
        $search_results = $stmt->fetchAll();

    } catch (PDOException $e) {
        $error_message = "Database Error: " . $e->getMessage();
        error_log("PDO Error: " . $e->getMessage());
    }
}
?>

<!-- This is the main content block for file_search.php -->
<div id="content">
    <h2>Global File Search</h2>

    <div class="search-box" style="margin-bottom: 20px;">
        <form action="file_search.php" method="GET">
            <input type="hidden" name="mode" value="<?php echo htmlspecialchars($mode); ?>">
            <input type="text" name="query" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Enter file name..." style="width: 80%;">
            <button type="submit" style="width: 18%;">Search</button>
        </form>
    </div>

    <?php if ($error_message): ?>
        <div class="error-message">
            <h2>Error</h2>
            <p>A database error occurred.</p>
            <p><i>Details: <?php echo htmlspecialchars($error_message); ?></i></p>
        </div>
    <?php elseif ($search_performed): ?>
        
        <h3>Results for "<?php echo htmlspecialchars($search_query); ?>"</h3>
        
        <?php if (empty($search_results)): ?>
            <div class="search-prompt" style="text-align: center; padding: 20px;">
                <p>No files or folders found matching your query.</p>
            </div>
        <?php else: ?>
            <table class="server-table" id="searchTable">
                <thead>
                    <tr>
                        <th style="width:30%;">Name</th>
                        <th style="width:15%;">Server</th>
                        <th>Full Path</th>
                        <th style="width:70px;">Size</th>
                        <th style="width:50px;">Kind</th>
                        <th style="width:50px;">Creator</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $alt_row = false; ?>
                    <?php foreach ($search_results as $file): ?>
                        <tr class="<?php echo $alt_row ? 'alt' : ''; $alt_row = !$alt_row; ?>">
                            <td>
                                <?php if ($file['is_folder']): ?>
                                    <strong><a href="file_list.php?server_id=<?php echo urlencode($file['unique_id']); ?>&path=<?php echo urlencode($file['full_path']); ?>&mode=<?php echo $mode; ?>" title="Browse this folder">[<?php echo htmlspecialchars($file['name']); ?>]</a></strong>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($file['name']); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="hotline://<?php echo htmlspecialchars($file['ip'] . ':' . $file['port']); ?>" title="Connect to <?php echo htmlspecialchars($file['server_name']); ?>">
                                    <?php echo htmlspecialchars($file['server_name']); ?>
                                </a>
                            </td>
                            <td>
                                <?php
                                    // We create a link to the parent folder
                                    $parent_path = dirname($file['full_path']);
                                ?>
                                <a href="file_list.php?server_id=<?php echo urlencode($file['unique_id']); ?>&path=<?php echo urlencode($parent_path); ?>&mode=<?php echo $mode; ?>" title="Browse parent folder">
                                    <?php echo htmlspecialchars($file['full_path']); ?>
                                </a>
                            </td>
                            <td class="users">
                                <?php echo $file['is_folder'] ? '--' : number_format($file['size']); ?>
                            </td>
                            <td class="users">
                                <?php echo $file['is_folder'] ? 'Folder' : (htmlspecialchars(trim($file['type_code'])) ?: 'File'); ?>
                            </td>
                            <td class="users">
                                <?php echo htmlspecialchars(trim($file['creator_code'])) ?: '----'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php else: ?>
        <div class="search-prompt" style="text-align: center; padding: 20px;">
            <p>Please enter a term to search the file index.</p>
        </div>
    <?php endif; ?>
</div>
<!-- End main content block -->

<?php
// Include the shared footer
include 'footer.php';
?>

