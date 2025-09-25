<?php
session_start();
require_once('../auth/config.php');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header("Location: ../login.php");
    exit();
}

$owner_id = $_SESSION['user_id'];


$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN Approval_status = 'CON' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN Approval_status = 'PEN' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN Approval_status = 'REJ' THEN 1 ELSE 0 END) as rejected
    FROM equipment
    WHERE Owner_id = ?
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$equipment_stats = $stmt->get_result()->fetch_assoc();
$total_equipment   = $equipment_stats['total'] ?? 0;
$approved_equipment = $equipment_stats['approved'] ?? 0;
$pending_equipment = $equipment_stats['pending'] ?? 0;
$rejected_equipment = $equipment_stats['rejected'] ?? 0;


$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN eb.status = 'PEN' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN eb.status = 'CON' THEN 1 ELSE 0 END) as confirmed,
        COALESCE(SUM(CASE WHEN eb.status = 'CON' THEN eb.total_amount END),0) as earnings
    FROM equipment_bookings eb
    JOIN equipment e ON eb.equipment_id = e.Equipment_id
    WHERE e.Owner_id = ?
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$booking_stats = $stmt->get_result()->fetch_assoc();
$total_bookings     = $booking_stats['total'] ?? 0;
$pending_bookings   = $booking_stats['pending'] ?? 0;
$confirmed_bookings = $booking_stats['confirmed'] ?? 0;
$total_earnings     = $booking_stats['earnings'] ?? 0;



$stmt = $conn->prepare("
    SELECT Equipment_id, Title, Brand, Model, Approval_status, listed_date
    FROM equipment
    WHERE Owner_id = ?
    ORDER BY listed_date DESC
    LIMIT 5
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$recent_equipment = $stmt->get_result();


$stmt = $conn->prepare("
    SELECT eb.booking_id, eb.start_date, eb.end_date, eb.total_amount, eb.status,
           e.Title as equipment_title, u.Name as customer_name
    FROM equipment_bookings eb
    JOIN equipment e ON eb.equipment_id = e.Equipment_id
    JOIN users u ON eb.customer_id = u.user_id
    WHERE e.Owner_id = ?
    ORDER BY eb.booking_id DESC
    LIMIT 5
");
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$recent_bookings = $stmt->get_result();

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../assets/css/equipment.css">

<div class="main-content">
   
    <h1>Equipment Owner Dashboard</h1>
    <h2>Welcome <?= htmlspecialchars($_SESSION['user_name']); ?></h2>

    <!-- Statistics Cards -->
    <div class="cards">
        <div class="card"><h3>Total Equipment</h3><div class="count"><?= $total_equipment; ?></div></div>
        <div class="card"><h3>Approved Equipment</h3><div class="count"><?= $approved_equipment; ?></div></div>
        <div class="card"><h3>Pending Approval</h3><div class="count"><?= $pending_equipment; ?></div></div>
        <div class="card"><h3>Total Bookings</h3><div class="count"><?= $total_bookings; ?></div></div>
        <div class="card"><h3>Total Earnings</h3><div class="count">₹<?= number_format($total_earnings, 2); ?></div></div>
    </div>

    
    <h2>Recent Booking Requests</h2>
    <table>
        <thead>
            <tr>
                <th>Equipment</th>
                <th>Customer</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if($recent_bookings->num_rows > 0): ?>
                <?php while($booking = $recent_bookings->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($booking['equipment_title']); ?></td>
                    <td><?= htmlspecialchars($booking['customer_name']); ?></td>
                    <td><?= date('M j, Y', strtotime($booking['start_date'])); ?></td>
                    <td><?= date('M j, Y', strtotime($booking['end_date'])); ?></td>
                    <td>₹<?= number_format($booking['total_amount'], 2); ?></td>
                    <td>
                        <?php
                        $status_map = [
                            'CON' => ['status-confirmed', 'Confirmed'],
                            'PEN' => ['status-pending', 'Pending'],
                            'REJ' => ['status-rejected', 'Rejected']
                        ];
                        [$cls,$txt] = $status_map[$booking['status']] ?? ['','Unknown'];
                        ?>
                        <span class="status-badge <?= $cls; ?>"><?= $txt; ?></span>
                    </td>
                    <td>
                        <a href="booking_details.php?id=<?= $booking['booking_id']; ?>">Details</a>
                        <?php if($booking['status'] == 'PEN'): ?>
                        | <a href="respond_booking.php?id=<?= $booking['booking_id']; ?>">Respond</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align:center;color:#666;">No booking requests yet</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    
    <br/>
    <h2>Recent Equipment Listings</h2>
    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>Brand</th>
                <th>Model</th>
                <th>Status</th>
                <th>Listed Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if($recent_equipment->num_rows > 0): ?>
                <?php while($eq = $recent_equipment->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($eq['Title']); ?></td>
                    <td><?= htmlspecialchars($eq['Brand']); ?></td>
                    <td><?= htmlspecialchars($eq['Model']); ?></td>
                    <td>
                        <?php
                        $status_map = [
                            'CON' => ['status-confirmed','Approved'],
                            'PEN' => ['status-pending','Pending'],
                            'REJ' => ['status-rejected','Rejected']
                        ];
                        [$cls,$txt] = $status_map[$eq['Approval_status']] ?? ['','Unknown'];
                        ?>
                        <span class="status-badge <?= $cls; ?>"><?= $txt; ?></span>
                    </td>
                    <td><?= date('M j, Y', strtotime($eq['listed_date'])); ?></td>
                    <td>
                        <a href="edit_equipment.php?id=<?= $eq['Equipment_id']; ?>">Edit</a> |
                        <a href="view_equipment.php?id=<?= $eq['Equipment_id']; ?>">View</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6" style="text-align:center;color:#666;">No equipment listed yet</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    
    
</div>


<?php 
require 'ofooter.php';

?>