<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

$status = isset($_GET['status']) ? $_GET['status'] : 'PEN';

$bookings = $conn->query("
    SELECT eb.*, e.Title as equipment_name, e.Brand, 
           customer.Name as customer_name, customer.Email as customer_email,
           owner.Name as owner_name
    FROM equipment_bookings eb
    JOIN equipment e ON eb.equipment_id = e.Equipment_id
    JOIN users customer ON eb.customer_id = customer.user_id
    JOIN users owner ON e.Owner_id = owner.user_id
    WHERE eb.status = '$status'
    ORDER BY eb.start_date DESC
");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Bookings (View Only)</h1>

    <div class="tabs">
        <a href="?status=PEN" class="tab <?= $status == 'PEN' ? 'active' : '' ?>">Waiting</a>
        <a href="?status=CON" class="tab <?= $status == 'CON' ? 'active' : '' ?>">Confirmed</a>
        <a href="?status=COM" class="tab <?= $status == 'COM' ? 'active' : '' ?>">Completed</a>
        <a href="?status=CAN" class="tab <?= $status == 'CAN' ? 'active' : '' ?>">Cancelled</a>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>Equipment</th>
            <th>Customer</th>
            <th>Owner</th>
            <th>Duration</th>
            <th>Amount</th>
            <th>Status</th>
        </tr>
        
        <?php if ($bookings->num_rows > 0): ?>
            <?php while($booking = $bookings->fetch_assoc()): ?>
            <tr>
                <td>B-<?= $booking['booking_id'] ?></td>
                <td>
                    <?= $booking['equipment_name'] ?><br>
                    <small><?= $booking['Brand'] ?></small>
                </td>
                <td>
                    <?= $booking['customer_name'] ?><br>
                    <small><?= $booking['customer_email'] ?></small>
                </td>
                <td><?= $booking['owner_name'] ?></td>
                <td>
                    <?= date('M d', strtotime($booking['start_date'])) ?> - 
                    <?= date('M d, Y', strtotime($booking['end_date'])) ?><br>
                    <small><?= $booking['Hours'] ?> hours</small>
                </td>
                <td>Rs.<?= $booking['total_amount'] ?></td>
                <td>
                    <?php if ($booking['status'] == 'PEN'): ?>
                        <span style="color: orange;">Waiting</span>
                    <?php elseif ($booking['status'] == 'CON'): ?>
                        <span style="color: green;">Confirmed</span>
                    <?php elseif ($booking['status'] == 'COM'): ?>
                        <span style="color: blue;">Completed</span>
                    <?php else: ?>
                        <span style="color: red;">Cancelled</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="7">No bookings found</td>
            </tr>
        <?php endif; ?>
    </table>
    
    <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 5px;">
        <strong>Note:</strong> Admin can only view bookings. Equipment owners and customers handle booking approvals directly.
    </div>
</div>

<?php require 'footer.php'; ?>
