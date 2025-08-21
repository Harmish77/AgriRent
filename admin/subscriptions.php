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
    
    if ($action == 'activate') {
        $conn->query("UPDATE user_subscriptions SET Status='A' WHERE subscription_id=$id");
        $message = "Subscription activated";
    } elseif ($action == 'cancel') {
        $conn->query("UPDATE user_subscriptions SET Status='C' WHERE subscription_id=$id");
        $message = "Subscription cancelled";
    }
}

$status = isset($_GET['status']) ? $_GET['status'] : 'A';

$subscriptions = $conn->query("
    SELECT us.*, u.Name as user_name, u.Email as user_email, u.User_type,
           sp.Plan_name, sp.Plan_type, sp.price
    FROM user_subscriptions us
    JOIN users u ON us.user_id = u.user_id
    JOIN subscription_plans sp ON us.plan_id = sp.plan_id
    WHERE us.Status = '$status'
    ORDER BY us.start_date DESC
");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Subscriptions</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <div class="tabs">
        <a href="?status=A" class="tab <?= $status == 'A' ? 'active' : '' ?>">Active</a>
        <a href="?status=E" class="tab <?= $status == 'E' ? 'active' : '' ?>">Expired</a>
        <a href="?status=C" class="tab <?= $status == 'C' ? 'active' : '' ?>">Cancelled</a>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>User</th>
            <th>User Type</th>
            <th>Plan</th>
            <th>Price</th>
            <th>Duration</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        
        <?php if ($subscriptions->num_rows > 0): ?>
            <?php while($sub = $subscriptions->fetch_assoc()): ?>
            <tr>
                <td>S-<?= $sub['subscription_id'] ?></td>
                <td>
                    <?= $sub['user_name'] ?><br>
                    <small><?= $sub['user_email'] ?></small>
                </td>
                <td><?= $sub['User_type'] == 'O' ? 'Equipment Owner' : 'Farmer' ?></td>
                <td>
                    <?= $sub['Plan_name'] ?><br>
                    <small><?= $sub['Plan_type'] == 'M' ? 'Monthly' : 'Yearly' ?></small>
                </td>
                <td>Rs.<?= $sub['price'] ?></td>
                <td>
                    <?= date('M d, Y', strtotime($sub['start_date'])) ?> -<br>
                    <?= date('M d, Y', strtotime($sub['end_date'])) ?>
                </td>
                <td>
                    <?php if($sub['Status'] == 'A'): ?>
                        Active
                    <?php elseif($sub['Status'] == 'E'): ?>
                        Expired
                    <?php else: ?>
                        Cancelled
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($sub['Status'] == 'E'): ?>
                        <a href="?action=activate&id=<?= $sub['subscription_id'] ?>&status=<?= $status ?>">Activate</a>
                    <?php elseif ($sub['Status'] == 'A'): ?>
                        <a href="?action=cancel&id=<?= $sub['subscription_id'] ?>&status=<?= $status ?>">Cancel</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="8">No subscriptions found</td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<?php require 'footer.php'; ?>
