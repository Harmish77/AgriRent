<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

if (isset($_POST['backup']) || isset($_GET['backup'])) {
    
    $MAX_BACKUP_FILES = 15;
    $BACKUP_DIRECTORY = __DIR__ . '/AgriRentBackup/';
    $BACKUP_FILE_PREFIX = 'AgriRentBackup';

    // Ensure backup directory exists
    if (!is_dir($BACKUP_DIRECTORY)) {
        mkdir($BACKUP_DIRECTORY, 0777, true);
    }

    
    $dsn = 'mysql:host=localhost;port=3306;dbname=agrirent';
    $user = 'root';
    $pass = '';
    $options = [
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ];
    
    try {
        $db = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        $error_message = 'Failed to connect to Database!';
    }

    
    function rotateBackups($dir, $max, $prefix) {
        $files = glob($dir . $prefix . '_*.sql') ?: [];
        if (count($files) < $max) return;
        array_multisort(array_map('filemtime', $files), SORT_ASC, $files);
        $remove = count($files) - $max + 1;
        for ($i = 0; $i < $remove; $i++) @unlink($files[$i]);
    }

    
    function dumpDatabase($db, $path) {
        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $sql = "-- AgriRent Database Backup\n-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($tables as $table) {
            $create = $db->query("SHOW CREATE TABLE `$table`")->fetch();
            $sql .= "DROP TABLE IF EXISTS `$table`;\n{$create['Create Table']};\n\n";
            
            $rows = $db->query("SELECT * FROM `$table`")->fetchAll();
            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $values = array_map(function ($v) {
                        return isset($v) ? "'" . str_replace(["\n", "\r"], ["\\n", "\\r"], addslashes($v)) . "'" : "NULL";
                    }, $row);
                    $sql .= "INSERT INTO `$table` VALUES(" . implode(',', $values) . ");\n";
                }
            }
            $sql .= "\n";
        }
        return (bool)file_put_contents($path, $sql);
    }

    if (!isset($error_message)) {
        $timestamp = date('Y-m-d_H-i-s');
        $backupFile = $BACKUP_DIRECTORY . $BACKUP_FILE_PREFIX . '_' . $timestamp . '.sql';
        
        rotateBackups($BACKUP_DIRECTORY, $MAX_BACKUP_FILES, $BACKUP_FILE_PREFIX);
        
        if (dumpDatabase($db, $backupFile)) {
            $success_message = 'Backup created successfully!';
            $download_file = 'AgriRentBackup/' . basename($backupFile);
        } else {
            $error_message = 'Failed to create backup file!';
        }
    }
}

// Get existing backup files
$existing_backups = [];
$backup_dir = __DIR__ . '/AgriRentBackup/';
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . 'AgriRentBackup_*.sql');
    foreach ($files as $file) {
        $existing_backups[] = [
            'name' => basename($file),
            'size' => number_format(filesize($file) / 1024, 2) . ' KB',
            'date' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }
    usort($existing_backups, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
}

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Database Export & Backup</h1>
    
    <?php if (isset($success_message)): ?>
        <div class="message"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="error"><?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <!-- Create Backup Form -->
    <div class="form-box">
        <h3>Create Database Backup</h3>
        <p>Generate a complete backup of your AgriRent database for data security and migration purposes.</p>
        <form method="post">
            <button type="submit" name="backup" class="btn">
                 Create New Backup
            </button>
        </form>
    </div>

    <!-- Available Backup Files -->
    <h3>Available Backup Files</h3>

    <?php if (!empty($existing_backups)): ?>
        <table id="backupTable">
            <tr>
                <th>File Name</th>
                <th>File Size</th>
                <th>Created Date</th>
                <th>Actions</th>
            </tr>
            
            <?php foreach ($existing_backups as $backup): ?>
            <tr class="backup-row">
                <td>
                    <i class="file-icon">📄</i>
                    <?= htmlspecialchars($backup['name']) ?>
                </td>
                <td><?= htmlspecialchars($backup['size']) ?></td>
                <td><?= htmlspecialchars($backup['date']) ?></td>
                <td>
                    <a href="AgriRentBackup/<?= htmlspecialchars($backup['name']) ?>" 
                       class="btn-action btn-download" download>
                        Download
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div class="no-data">
            <i class="empty-icon">📁</i>
            <h4>No backup files found</h4>
            <p>Create your first database backup to get started.</p>
        </div>
    <?php endif; ?>
</div>

<style>
/* Main layout matching categories.php */
.main-content {
    margin-left: 250px;
    padding: 20px;
    min-height: calc(100vh - 60px);
    background-color: #f8f9fa;
}

.main-content h1 {
    color: #234a23;
    margin-bottom: 20px;
    font-size: 28px;
    font-weight: 600;
}

.main-content h3 {
    color: #234a23;
    margin: 20px 0 10px 0;
    font-size: 20px;
    font-weight: 600;
}

/* Form box styling matching categories.php */
.form-box {
    background: white;
    padding: 25px;
    border-radius: 8px;
    margin-bottom: 30px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: 1px solid #e9ecef;
}

.form-box h3 {
    margin-top: 0;
    color: #234a23;
    margin-bottom: 10px;
}

.form-box p {
    color: #666;
    margin-bottom: 20px;
    font-size: 14px;
}

/* Button styling matching categories.php */
.btn {
    display: inline-block;
    padding: 12px 24px;
    background: #234a23;
    color: white;
    text-decoration: none;
    border-radius: 5px;
    border: none;
    cursor: pointer;
    margin-right: 10px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn:hover {
    background: #1e3e1e;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(35, 74, 35, 0.3);
}

.btn .icon {
    margin-right: 8px;
    font-size: 16px;
}

/* Table styling matching categories.php */
#backupTable {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

#backupTable th,
#backupTable td {
    padding: 15px 12px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

#backupTable th {
    background: #f8f9fa;
    font-weight: 600;
    color: #333;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

#backupTable tr:hover {
    background: #f8f9fa;
}

#backupTable tr:last-child td {
    border-bottom: none;
}

/* Action buttons matching categories.php */
.btn-action {
    display: inline-block;
    padding: 6px 12px;
    margin: 2px;
    text-decoration: none;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.btn-download {
    background: #234a23;
    color: white;
    border-color: #234a23;
}

.btn-download:hover {
    background: white;
    color: #234a23;
    border-color: #234a23;
}

/* File icon styling */
.file-icon {
    margin-right: 8px;
    font-size: 16px;
}

/* Message styling matching categories.php */
.message {
    background: #d4edda;
    color: #155724;
    padding: 12px 16px;
    border-radius: 5px;
    margin-bottom: 20px;
    border: 1px solid #c3e6cb;
    border-left: 4px solid #28a745;
}

.error {
    background: #f8d7da;
    color: #721c24;
    padding: 12px 16px;
    border-radius: 5px;
    margin-bottom: 20px;
    border: 1px solid #f5c6cb;
    border-left: 4px solid #dc3545;
}

/* No data state */
.no-data {
    text-align: center;
    padding: 40px 20px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.no-data .empty-icon {
    font-size: 48px;
    margin-bottom: 15px;
    display: block;
}

.no-data h4 {
    color: #666;
    margin-bottom: 10px;
    font-weight: 600;
}

.no-data p {
    color: #999;
    font-size: 14px;
}

/* Mobile responsiveness */
@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding: 15px;
    }
    
    .btn-action {
        display: block;
        margin: 2px 0;
        text-align: center;
        width: 100%;
    }
    
    #backupTable {
        font-size: 12px;
        min-width: 600px;
    }
    
    .main-content {
        overflow-x: auto;
    }
}

@media (max-width: 480px) {
    .btn-action {
        padding: 8px 6px;
        font-size: 12px;
    }
    
    #backupTable {
        font-size: 11px;
    }
    
    #backupTable th,
    #backupTable td {
        padding: 8px 6px;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
    // Auto hide messages after 4 seconds
    if ($('.message, .error').length > 0) {
        setTimeout(function() {
            $('.message, .error').fadeOut(800, function() {
                $(this).remove();
            });
        }, 4000);
    }
    
    // Add smooth hover effects
    $('.btn-action').hover(
        function() {
            $(this).css('transform', 'translateY(-1px)');
        },
        function() {
            $(this).css('transform', 'translateY(0)');
        }
    );
});
</script>

<?php require 'footer.php'; ?>
