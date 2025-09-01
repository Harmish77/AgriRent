<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Equipment Owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header('Location: ../login.php');
    exit();
}

$owner_id = $_SESSION['user_id'];
$message = '';

// Handle booking actions (approve/reject)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $booking_id = intval($_GET['id']);
    $action = $_GET['action'];

    if (in_array($action, ['approve', 'reject'])) {
        $new_status = ($action == 'approve') ? 'CON' : 'REJ';
        
        // Verify booking belongs to this owner before updating
        $verify_stmt = $conn->prepare("SELECT eb.booking_id FROM equipment_bookings eb 
                                      JOIN equipment e ON eb.equipment_id = e.Equipment_id 
                                      WHERE eb.booking_id = ? AND e.Owner_id = ?");
        $verify_stmt->bind_param('ii', $booking_id, $owner_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows > 0) {
            $update_stmt = $conn->prepare('UPDATE equipment_bookings SET status = ? WHERE booking_id = ?');
            $update_stmt->bind_param('si', $new_status, $booking_id);
            
            if ($update_stmt->execute()) {
                $message = 'Booking ' . ($action == 'approve' ? 'approved' : 'rejected') . ' successfully.';
            } else {
                $message = 'Error updating booking status.';
            }
            $update_stmt->close();
        }
        $verify_stmt->close();
        
        header('Location: equipment_bookings.php' . ($message ? '?msg=' . urlencode($message) : ''));
        exit();
    }
}

// Display message from redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Filter by status
$status_filter = $_GET['status'] ?? 'all';
$where_clause = "WHERE e.Owner_id = ?";
$params = [$owner_id];
$param_types = "i";

if ($status_filter !== 'all') {
    $where_clause .= " AND eb.status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

// Fetch bookings for this owner's equipment
$bookings = [];
try {
    $query = "SELECT eb.booking_id, eb.equipment_id, eb.customer_id, eb.start_date, eb.end_date, 
                     eb.Hours, eb.total_amount, eb.status, e.Title as equipment_title, 
                     u.Name as customer_name, u.Phone as customer_phone
              FROM equipment_bookings eb 
              JOIN equipment e ON eb.equipment_id = e.Equipment_id 
              JOIN users u ON eb.customer_id = u.user_id 
              $where_clause 
              ORDER BY eb.booking_id DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Bookings fetch error: " . $e->getMessage());
}

// Calculate statistics
$total_bookings = count($bookings);
$pending_bookings = count(array_filter($bookings, fn($b) => $b['status'] == 'PEN'));
$confirmed_bookings = count(array_filter($bookings, fn($b) => $b['status'] == 'CON'));
$total_revenue = array_sum(array_map(fn($b) => $b['status'] == 'CON' ? $b['total_amount'] : 0, $bookings));

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../admin.css">

<div class="main-content">
    <h1>Equipment Booking Requests</h1>
    <p style="color: #666; margin-bottom: 30px;">Manage booking requests for your equipment</p>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="cards" style="margin-bottom: 30px;">
        <div class="card">
            <h3>Total Bookings</h3>
            <div class="count"><?= $total_bookings ?></div>
        </div>
        <div class="card">
            <h3>Pending Requests</h3>
            <div class="count"><?= $pending_bookings ?></div>
        </div>
        <div class="card">
            <h3>Confirmed Bookings</h3>
            <div class="count"><?= $confirmed_bookings ?></div>
        </div>
        <div class="card">
            <h3>Total Revenue</h3>
            <div class="count">₹<?= number_format($total_revenue, 2) ?></div>
        </div>
    </div>

    <!-- Filter and Actions -->
    <div class="quick-actions" style="margin-bottom: 30px; display: flex; align-items: center; gap: 20px;">
        <form method="GET" style="display: inline-block;">
            <select name="status" onchange="this.form.submit()" style="padding: 10px; border-radius: 4px; border: 1px solid #ddd; background: white;">
                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Bookings</option>
                <option value="PEN" <?= $status_filter == 'PEN' ? 'selected' : '' ?>>Pending</option>
                <option value="CON" <?= $status_filter == 'CON' ? 'selected' : '' ?>>Confirmed</option>
                <option value="REJ" <?= $status_filter == 'REJ' ? 'selected' : '' ?>>Rejected</option>
            </select>
        </form>

        <span style="color: #666; font-size: 14px;">
            Showing <?= count($bookings) ?> booking(s)
        </span>
    </div>

    <!-- Bookings Table -->
    <?php if (count($bookings) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Equipment</th>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Hours</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $booking): 
                    $status_map = [
                        'CON' => ['status-confirmed', 'Confirmed'],
                        'PEN' => ['status-pending', 'Pending'],
                        'REJ' => ['status-rejected', 'Rejected']
                    ];
                    list($status_class, $status_text) = $status_map[$booking['status']] ?? ['', 'Unknown'];
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($booking['equipment_title']) ?></strong></td>
                        <td><?= htmlspecialchars($booking['customer_name']) ?></td>
                        <td><?= htmlspecialchars($booking['customer_phone']) ?></td>
                        <td><?= date('M j, Y', strtotime($booking['start_date'])) ?></td>
                        <td><?= date('M j, Y', strtotime($booking['end_date'])) ?></td>
                        <td><?= htmlspecialchars($booking['Hours'] ?? 'N/A') ?></td>
                        <td>₹<?= number_format($booking['total_amount'], 2) ?></td>
                        <td><span class="status-badge <?= $status_class ?>"><?= $status_text ?></span></td>
                        <td>
                            <?php if ($booking['status'] == 'PEN'): ?>
                                <a href="?action=approve&id=<?= $booking['booking_id'] ?>&status=<?= $status_filter ?>" 
                                   onclick="return confirm('Approve this booking request?')" 
                                   style="color: #28a745;">Approve</a> |
                                <a href="?action=reject&id=<?= $booking['booking_id'] ?>&status=<?= $status_filter ?>" 
                                   onclick="return confirm('Reject this booking request?')" 
                                   style="color: #dc3545;">Reject</a>
                            <?php else: ?>
                                <a href="booking_details.php?id=<?= $booking['booking_id'] ?>" style="color: #17a2b8;">View Details</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="form-section" style="text-align: center; padding: 50px; background: white; border-radius: 8px;">
            <h3 style="color: #666; margin-bottom: 15px;">
                <?= $status_filter !== 'all' ? 'No bookings found with status: ' . strtoupper($status_filter) : 'No Booking Requests Yet' ?>
            </h3>
            <p style="color: #666; margin-bottom: 25px;">
                <?= $status_filter !== 'all' ? 'Try changing the filter or wait for new bookings.' : 'When farmers book your equipment, they will appear here for your approval.' ?>
            </p>
            <a href="add_equipment.php" class="action-btn">➕ Add More Equipment</a>
        </div>
    <?php endif; ?>

    <!-- Recent Activity Summary -->
    <?php if (count($bookings) > 0): ?>
        <div class="report-sections" style="margin-top: 40px;">
            <div class="report-section">
                <h3>Booking Summary</h3>
                <div class="status-list">
                    <div>📊 Total Bookings: <strong><?= $total_bookings ?></strong></div>
                    <div>⏳ Pending Approval: <strong><?= $pending_bookings ?></strong></div>
                    <div>✅ Confirmed: <strong><?= $confirmed_bookings ?></strong></div>
                    <div>❌ Rejected: <strong><?= count(array_filter($bookings, fn($b) => $b['status'] == 'REJ')) ?></strong></div>
                </div>
            </div>

            <div class="report-section">
                <h3>Revenue Summary</h3>
                <div class="status-list">
                    <div>💰 Total Revenue: <strong>₹<?= number_format($total_revenue, 2) ?></strong></div>
                    <div>💵 Average Booking: <strong>₹<?= $confirmed_bookings > 0 ? number_format($total_revenue / $confirmed_bookings, 2) : '0.00' ?></strong></div>
                    <div>📈 Confirmation Rate: <strong><?= $total_bookings > 0 ? round(($confirmed_bookings / $total_bookings) * 100, 1) : 0 ?>%</strong></div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php 
    require 'ofooter.php';
?>
