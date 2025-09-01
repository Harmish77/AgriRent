<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Equipment Owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header('Location: ../login.php');
    exit();
}

$owner_id = $_SESSION['user_id'];
$equipment_id = intval($_GET['id'] ?? 0);

if ($equipment_id <= 0) {
    header('Location: manage_equipment.php');
    exit();
}

// Fetch equipment data with category information
$equipment = null;
$stmt = $conn->prepare("
    SELECT e.*, 
           ec.Name as category_name,
           es.Subcategory_name
    FROM equipment e
    LEFT JOIN equipment_subcategories es ON e.Subcategories_id = es.Subcategory_id
    LEFT JOIN equipment_categories ec ON es.Category_id = ec.category_id
    WHERE e.Equipment_id = ? AND e.Owner_id = ?
");
$stmt->bind_param("ii", $equipment_id, $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$equipment = $result->fetch_assoc();
$stmt->close();

if (!$equipment) {
    header('Location: manage_equipment.php');
    exit();
}

// Fetch booking history for this equipment
$bookings = [];
$booking_stmt = $conn->prepare("
    SELECT eb.*, u.Name as customer_name 
    FROM equipment_bookings eb
    JOIN users u ON eb.customer_id = u.user_id
    WHERE eb.equipment_id = ?
    ORDER BY eb.booking_id DESC
    LIMIT 10
");
$booking_stmt->bind_param("i", $equipment_id);
$booking_stmt->execute();
$booking_result = $booking_stmt->get_result();
while ($booking = $booking_result->fetch_assoc()) {
    $bookings[] = $booking;
}
$booking_stmt->close();

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../assets/css/equipment.css">

<div class="main-content">
    <h1>Equipment Details</h1>
    <p style="color: #666; margin-bottom: 30px;">Detailed view of your equipment listing</p>

    <div class="quick-actions" style="margin-bottom: 30px;">
        <a href="manage_equipment.php" class="action-btn">← Back to List</a>
        <a href="edit_equipment.php?id=<?= $equipment_id ?>" class="action-btn" style="background: #28a745;">Edit Equipment</a>
    </div>

    <div class="report-sections">
        <!-- Equipment Information -->
        <div class="report-section">
            <h3>Equipment Information</h3>
            <div class="equipment-details">
                <div class="detail-row">
                    <strong>Title:</strong> <?= htmlspecialchars($equipment['Title']) ?>
                </div>
                <div class="detail-row">
                    <strong>Brand:</strong> <?= htmlspecialchars($equipment['Brand']) ?>
                </div>
                <div class="detail-row">
                    <strong>Model:</strong> <?= htmlspecialchars($equipment['Model']) ?>
                </div>
                <div class="detail-row">
                    <strong>Year:</strong> <?= htmlspecialchars($equipment['Year'] ?? 'Not specified') ?>
                </div>
                <div class="detail-row">
                    <strong>Category:</strong> <?= htmlspecialchars($equipment['Subcategory_name'] ?? 'Not specified') ?>
                </div>
                <div class="detail-row">
                    <strong>Description:</strong><br>
                    <p style="margin: 10px 0; line-height: 1.5;"><?= nl2br(htmlspecialchars($equipment['Description'])) ?></p>
                </div>
            </div>
        </div>

        <!-- Pricing and Status -->
        <div class="report-section">
            <h3>Pricing & Status</h3>
            <div class="equipment-details">
                <div class="detail-row">
                    <strong>Hourly Rate:</strong> 
                    <?= $equipment['Hourly_rate'] ? '₹' . number_format($equipment['Hourly_rate'], 2) . '/hr' : 'Not set' ?>
                </div>
                <div class="detail-row">
                    <strong>Daily Rate:</strong> 
                    <?= $equipment['Daily_rate'] ? '₹' . number_format($equipment['Daily_rate'], 2) . '/day' : 'Not set' ?>
                </div>
                <div class="detail-row">
                    <strong>Status:</strong>
                    <?php
                    $status_map = [
                        'CON' => ['status-confirmed', 'Approved'],
                        'PEN' => ['status-pending', 'Pending Admin Approval'],
                        'REJ' => ['status-rejected', 'Rejected by Admin']
                    ];
                    list($status_class, $status_text) = $status_map[$equipment['Approval_status']] ?? ['', 'Unknown'];
                    ?>
                    <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
                </div>
                <div class="detail-row">
                    <strong>Listed Date:</strong> <?= date('M j, Y g:i A', strtotime($equipment['listed_date'])) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Bookings -->
    <h2 style="margin-top: 40px;">Recent Booking History</h2>
    <?php if (count($bookings) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Hours</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $booking): 
                    $booking_status_map = [
                        'CON' => ['status-confirmed', 'Confirmed'],
                        'PEN' => ['status-pending', 'Pending'],
                        'REJ' => ['status-rejected', 'Rejected']
                    ];
                    list($booking_status_class, $booking_status_text) = $booking_status_map[$booking['status']] ?? ['', 'Unknown'];
                ?>
                    <tr>
                        <td><?= htmlspecialchars($booking['customer_name']) ?></td>
                        <td><?= date('M j, Y', strtotime($booking['start_date'])) ?></td>
                        <td><?= date('M j, Y', strtotime($booking['end_date'])) ?></td>
                        <td><?= $booking['Hours'] ?? 'N/A' ?></td>
                        <td>₹<?= number_format($booking['total_amount'], 2) ?></td>
                        <td><span class="status-badge <?= $booking_status_class ?>"><?= $booking_status_text ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="form-section" style="text-align: center; padding: 30px;">
            <p>No booking history available for this equipment yet.</p>
        </div>
    <?php endif; ?>
</div>

<?php 
    require 'ofooter.php';
?>