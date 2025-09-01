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
    
    if ($action == 'approve') {
        $conn->query("UPDATE equipment SET Approval_status='CON' WHERE Equipment_id=$id");
        $message = "Equipment approved";
    } elseif ($action == 'reject') {
        $conn->query("UPDATE equipment SET Approval_status='REJ' WHERE Equipment_id=$id");
        $message = "Equipment rejected";
    } elseif ($action == 'delete') {
        $conn->query("DELETE FROM equipment WHERE Equipment_id=$id");
        $message = "Equipment deleted";
    }
}

$status = isset($_GET['status']) ? $_GET['status'] : 'PEN';
$search = isset($_GET['search']) ? $_GET['search'] : '';

$where = "WHERE e.Approval_status = '$status'";
if ($search) {
    $search = $conn->real_escape_string($search);
    $where .= " AND (e.Title LIKE '%$search%' OR e.Brand LIKE '%$search%' OR u.Name LIKE '%$search%')";
}

$equipment = $conn->query("SELECT e.*, u.Name as owner_name FROM equipment e JOIN users u ON e.Owner_id = u.user_id $where ORDER BY e.listed_date DESC");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Equipment</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <div class="tabs">
        <a href="?status=PEN" class="tab <?= $status == 'PEN' ? 'active' : '' ?>">Waiting</a>
        <a href="?status=CON" class="tab <?= $status == 'CON' ? 'active' : '' ?>">Approved</a>
        <a href="?status=REJ" class="tab <?= $status == 'REJ' ? 'active' : '' ?>">Rejected</a>
    </div>

    <div class="search-box">
        <form method="GET">
            <input type="hidden" name="status" value="<?= $status ?>">
            <input type="text" name="search" placeholder="Search equipment..." value="<?= $search ?>">
            <button type="submit" class="btn">Search</button>
            <a href="?status=<?= $status ?>" class="btn">Clear</a>
        </form>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>Title</th>
            <th>Owner</th>
            <th>Brand/Model</th>
            <th>Rates</th>
            <th>Date Added</th>
            <th>Actions</th>
        </tr>
        
        <?php if ($equipment->num_rows > 0): ?>
            <?php while($item = $equipment->fetch_assoc()): ?>
            <tr>
                <td>EQ-<?= $item['Equipment_id'] ?></td>
                <td><?= $item['Title'] ?></td>
                <td><?= $item['owner_name'] ?></td>
                <td><?= $item['Brand'] ?> <?= $item['Model'] ?></td>
                <td>
                    Rs.<?= $item['Hourly_rate'] ?>/hr<br>
                    Rs.<?= $item['Daily_rate'] ?>/day
                </td>
                <td><?= date('M d, Y', strtotime($item['listed_date'])) ?></td>
                <td>
                    <?php if ($item['Approval_status'] == 'PEN'): ?>
                        <a href="?action=approve&id=<?= $item['Equipment_id'] ?>&status=<?= $status ?>">Approve</a><br>
                        <a href="?action=reject&id=<?= $item['Equipment_id'] ?>&status=<?= $status ?>">Reject</a><br>
                    <?php endif; ?>
                    <a href="?action=delete&id=<?= $item['Equipment_id'] ?>&status=<?= $status ?>" onclick="return confirm('Delete this equipment?')">Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="7">No equipment found</td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<?php require 'footer.php'; ?>