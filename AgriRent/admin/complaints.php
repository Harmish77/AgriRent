<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = $_GET['id'];
    
    if ($action == 'progress') {
        $conn->query("UPDATE complaints SET Status='P' WHERE Complaint_id=$id");
        $message = "Complaint in progress";
    } elseif ($action == 'resolve') {
        $conn->query("UPDATE complaints SET Status='R' WHERE Complaint_id=$id");
        $message = "Complaint solved";
    }
}

$status = isset($_GET['status']) ? $_GET['status'] : 'O';

$complaints = $conn->query("
    SELECT c.*, u.Name as user_name, u.Email as user_email
    FROM complaints c
    JOIN users u ON c.User_id = u.user_id
    WHERE c.Status = '$status'
    ORDER BY c.Complaint_id DESC
");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Complaints</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <div class="tabs">
        <a href="?status=O" class="tab <?= $status == 'O' ? 'active' : '' ?>">Open</a>
        <a href="?status=P" class="tab <?= $status == 'P' ? 'active' : '' ?>">In Progress</a>
        <a href="?status=R" class="tab <?= $status == 'R' ? 'active' : '' ?>">Solved</a>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>User</th>
            <th>Type</th>
            <th>Related ID</th>
            <th>Description</th>
            <th>Actions</th>
        </tr>
        
        <?php if ($complaints->num_rows > 0): ?>
            <?php while($complaint = $complaints->fetch_assoc()): ?>
            <tr>
                <td>C-<?= $complaint['Complaint_id'] ?></td>
                <td>
                    <?= $complaint['user_name'] ?><br>
                    <small><?= $complaint['user_email'] ?></small>
                </td>
                <td><?= $complaint['Complaint_type'] == 'E' ? 'Equipment' : 'Product' ?></td>
                <td><?= $complaint['Complaint_type'] == 'E' ? 'EQ-' : 'P-' ?><?= $complaint['ID'] ?></td>
                <td><?= substr($complaint['Description'], 0, 50) ?>...</td>
                <td>
                    <?php if ($complaint['Status'] == 'O'): ?>
                        <a href="?action=progress&id=<?= $complaint['Complaint_id'] ?>&status=<?= $status ?>">Start Work</a><br>
                        <a href="?action=resolve&id=<?= $complaint['Complaint_id'] ?>&status=<?= $status ?>">Solve</a>
                    <?php elseif ($complaint['Status'] == 'P'): ?>
                        <a href="?action=resolve&id=<?= $complaint['Complaint_id'] ?>&status=<?= $status ?>">Solve</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="6">No complaints found</td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<?php require 'footer.php'; ?>