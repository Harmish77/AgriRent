<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

$year = date('Y');

$equipment_monthly = $conn->query("
    SELECT MONTH(listed_date) as month, COUNT(*) as count 
    FROM equipment 
    WHERE YEAR(listed_date) = $year 
    GROUP BY MONTH(listed_date) 
    ORDER BY MONTH(listed_date)
");

$sales_monthly = $conn->query("
    SELECT MONTH(order_date) as month, SUM(total_price) as revenue, COUNT(*) as orders
    FROM product_orders 
    WHERE YEAR(order_date) = $year 
    GROUP BY MONTH(order_date) 
    ORDER BY MONTH(order_date)
");

$equipment_status = $conn->query("SELECT Approval_status, COUNT(*) as count FROM equipment GROUP BY Approval_status");
$product_status = $conn->query("SELECT Approval_status, COUNT(*) as count FROM product GROUP BY Approval_status");
$booking_status = $conn->query("SELECT status, COUNT(*) as count FROM equipment_bookings GROUP BY status");

$top_owners = $conn->query("
    SELECT u.Name, u.Email, COUNT(e.Equipment_id) as equipment_count,
           SUM(eb.total_amount) as total_earnings
    FROM users u
    LEFT JOIN equipment e ON u.user_id = e.Owner_id
    LEFT JOIN equipment_bookings eb ON e.Equipment_id = eb.equipment_id AND eb.status = 'CON'
    WHERE u.User_type = 'O'
    GROUP BY u.user_id
    ORDER BY total_earnings DESC
    LIMIT 10
");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Reports</h1>
    
    <div class="cards">
        <div class="card">
            <h3>Equipment Status</h3>
            <div class="status-list">
                <?php while($status = $equipment_status->fetch_assoc()): ?>
                    <div><?= $status['count'] ?> 
                    <?= $status['Approval_status'] == 'PEN' ? 'Waiting' : ($status['Approval_status'] == 'CON' ? 'Approved' : 'Rejected') ?></div>
                <?php endwhile; ?>
            </div>
        </div>
        
        <div class="card">
            <h3>Product Status</h3>
            <div class="status-list">
                <?php 
                $product_status->data_seek(0);
                while($status = $product_status->fetch_assoc()): 
                ?>
                    <div><?= $status['count'] ?> 
                    <?= $status['Approval_status'] == 'PEN' ? 'Waiting' : ($status['Approval_status'] == 'CON' ? 'Approved' : 'Rejected') ?></div>
                <?php endwhile; ?>
            </div>
        </div>
        
        <div class="card">
            <h3>Booking Status</h3>
            <div class="status-list">
                <?php while($status = $booking_status->fetch_assoc()): ?>
                    <div><?= $status['count'] ?> 
                    <?= $status['status'] == 'PEN' ? 'Waiting' : ($status['status'] == 'CON' ? 'Confirmed' : ($status['status'] == 'COM' ? 'Completed' : 'Cancelled')) ?></div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>
    
    <div class="report-sections">
        <div class="report-section">
            <h3>Equipment Added by Month (<?= $year ?>)</h3>
            <table>
                <tr><th>Month</th><th>Equipment Added</th></tr>
                <?php 
                $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                while($row = $equipment_monthly->fetch_assoc()): 
                ?>
                <tr>
                    <td><?= $months[$row['month'] - 1] ?> <?= $year ?></td>
                    <td><?= $row['count'] ?></td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>
        
        <div class="report-section">
            <h3>Product Sales by Month (<?= $year ?>)</h3>
            <table>
                <tr><th>Month</th><th>Orders</th><th>Revenue</th></tr>
                <?php while($row = $sales_monthly->fetch_assoc()): ?>
                <tr>
                    <td><?= $months[$row['month'] - 1] ?> <?= $year ?></td>
                    <td><?= $row['orders'] ?></td>
                    <td>Rs.<?= $row['revenue'] ?></td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>
    </div>
    
    <div class="report-section">
        <h3>Top Equipment Owners</h3>
        <table>
            <tr>
                <th>Owner Name</th>
                <th>Email</th>
                <th>Equipment Listed</th>
                <th>Total Earnings</th>
            </tr>
            <?php while($owner = $top_owners->fetch_assoc()): ?>
            <tr>
                <td><?= $owner['Name'] ?></td>
                <td><?= $owner['Email'] ?></td>
                <td><?= $owner['equipment_count'] ?></td>
                <td>₹<?= number_format($owner['total_earnings']); ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</div>

<?php require 'footer.php'; ?>