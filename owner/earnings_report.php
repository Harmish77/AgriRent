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

// Date filter parameters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$period = $_GET['period'] ?? 'all';

// Set date ranges based on period selection
if ($period === 'today') {
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d');
} elseif ($period === 'week') {
    $start_date = date('Y-m-d', strtotime('-7 days'));
    $end_date = date('Y-m-d');
} elseif ($period === 'month') {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
} elseif ($period === 'year') {
    $start_date = date('Y-01-01');
    $end_date = date('Y-12-31');
}

// Build query with date filters
$where_clause = "WHERE e.Owner_id = ?";
$params = [$owner_id];
$param_types = "i";

if ($start_date) {
    $where_clause .= " AND DATE(eb.start_date) >= ?";
    $params[] = $start_date;
    $param_types .= "s";
}
if ($end_date) {
    $where_clause .= " AND DATE(eb.end_date) <= ?";
    $params[] = $end_date;
    $param_types .= "s";
}

// Fetch earnings data
$earnings_data = [];
try {
    $query = "SELECT eb.booking_id, e.Title as equipment_title, e.Brand, e.Model,
                     eb.start_date, eb.end_date, eb.Hours, eb.total_amount, eb.status,
                     u.Name as customer_name
              FROM equipment_bookings eb 
              JOIN equipment e ON eb.equipment_id = e.Equipment_id 
              JOIN users u ON eb.customer_id = u.user_id 
              $where_clause 
              ORDER BY eb.start_date DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $earnings_data[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Earnings fetch error: " . $e->getMessage());
    $message = "Error fetching earnings data.";
}

// Calculate statistics
$total_bookings = count($earnings_data);
$confirmed_bookings = array_filter($earnings_data, fn($booking) => $booking['status'] == 'CON');
$pending_bookings = array_filter($earnings_data, fn($booking) => $booking['status'] == 'PEN');

$total_earnings = array_sum(array_map(fn($booking) => $booking['status'] == 'CON' ? $booking['total_amount'] : 0, $earnings_data));
$pending_earnings = array_sum(array_map(fn($booking) => $booking['status'] == 'PEN' ? $booking['total_amount'] : 0, $earnings_data));

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../assets/css/equipment.css">

<div class="main-content">
    <h1>Earnings Report</h1>
    <p style="color: #666; margin-bottom: 30px;">Comprehensive analysis of your equipment rental earnings</p>

    <?php if ($message): ?>
        <div class="error"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="form-section" style="margin-bottom: 30px;">
        <h3 style="margin-bottom: 15px;"> Filter Reports by Date</h3>
        
        <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: end;">
            <!-- Quick Period Filters -->
            <div class="form-group" style="margin-bottom: 0;">
                <label><strong>Quick Filter:</strong></label>
                <select name="period" onchange="this.form.submit()" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="all" <?= $period == 'all' ? 'selected' : '' ?>>All Time</option>
                    <option value="today" <?= $period == 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week" <?= $period == 'week' ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="month" <?= $period == 'month' ? 'selected' : '' ?>>This Month</option>
                    <option value="year" <?= $period == 'year' ? 'selected' : '' ?>>This Year</option>
                </select>
            </div>

            <!-- Custom Date Range -->
            <div class="form-group" style="margin-bottom: 0;">
                <label><strong>From Date:</strong></label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label><strong>To Date:</strong></label>
                <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <button type="submit" class="btn" style="background: #28a745;"> Apply Filter</button>
                <a href="earnings_report.php" class="btn" style="background: #6c757d; margin-left: 10px;"> Clear</a>
            </div>
        </form>

        <!-- Show Current Filter Info -->
        <?php if ($start_date || $end_date || $period != 'all'): ?>
            <div style="margin-top: 15px; padding: 10px; background: #e3f2fd; border-radius: 4px; color: #1976d2;">
                <strong>Current Filter:</strong>
                <?php if ($period != 'all'): ?>
                    <?= ucfirst($period) ?> 
                <?php endif; ?>
                <?php if ($start_date && $end_date): ?>
                    (<?= date('M j, Y', strtotime($start_date)) ?> to <?= date('M j, Y', strtotime($end_date)) ?>)
                <?php elseif ($start_date): ?>
                    (From <?= date('M j, Y', strtotime($start_date)) ?>)
                <?php elseif ($end_date): ?>
                    (Until <?= date('M j, Y', strtotime($end_date)) ?>)
                <?php endif; ?>
                - Showing <?= count($earnings_data) ?> booking(s)
            </div>
        <?php endif; ?>
    </div>

    <!-- Export Options - POSITIONED HERE AFTER FILTERS -->
    <?php if (count($earnings_data) > 0): ?>
        <div style="margin-bottom: 20px; text-align: center; padding: 15px; background: #f8f9fa; border-radius: 8px;">
            <h4 style="margin-bottom: 15px; color: #234a23;">📥 Export Your Earnings Report</h4>
            <a href="export_earnings.php?format=excel&<?= http_build_query($_GET) ?>" class="action-btn" style="background: #28a745; margin-right: 15px;">
                 Export to Excel
            </a>
            <a href="export_earnings.php?format=pdf&<?= http_build_query($_GET) ?>" class="action-btn" style="background: #dc3545;">
                 Export to PDF
            </a>
        </div>
    <?php endif; ?>

    <!-- Earnings Summary Cards -->
    <div class="cards" style="margin-bottom: 40px;">
        <div class="card">
            <h3>Total Earnings</h3>
            <div class="count">₹<?= number_format($total_earnings, 2) ?></div>
            <small style="color: #28a745;">Confirmed Bookings</small>
        </div>
        
        <div class="card">
            <h3>Pending Earnings</h3>
            <div class="count">₹<?= number_format($pending_earnings, 2) ?></div>
            <small style="color: #ffc107;">Awaiting Confirmation</small>
        </div>
        
        <div class="card">
            <h3>Total Bookings</h3>
            <div class="count"><?= $total_bookings ?></div>
            <small style="color: #666;">All Status</small>
        </div>
        
        <div class="card">
            <h3>Confirmed Bookings</h3>
            <div class="count"><?= count($confirmed_bookings) ?></div>
            <small style="color: #28a745;">Revenue Generated</small>
        </div>
        
        <div class="card">
            <h3>Average Booking</h3>
            <div class="count">₹<?= count($confirmed_bookings) > 0 ? number_format($total_earnings / count($confirmed_bookings), 2) : '0.00' ?></div>
            <small style="color: #666;">Per Confirmed Booking</small>
        </div>
        
        <div class="card">
            <h3>Success Rate</h3>
            <div class="count"><?= $total_bookings > 0 ? round((count($confirmed_bookings) / $total_bookings) * 100, 1) : 0 ?>%</div>
            <small style="color: #666;">Booking Confirmation</small>
        </div>
    </div>

    <!-- Detailed Earnings Table -->
    <h2>Earnings Details</h2>
    <?php if (count($earnings_data) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Booking ID</th>
                    <th>Equipment</th>
                    <th>Customer</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Hours</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($earnings_data as $booking): 
                    $status_map = [
                        'CON' => ['status-confirmed', 'Confirmed'],
                        'PEN' => ['status-pending', 'Pending'],
                        'REJ' => ['status-rejected', 'Rejected']
                    ];
                    list($status_class, $status_text) = $status_map[$booking['status']] ?? ['', 'Unknown'];
                ?>
                    <tr>
                        <td>#<?= $booking['booking_id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($booking['equipment_title']) ?></strong><br>
                            <small><?= htmlspecialchars($booking['Brand']) ?> <?= htmlspecialchars($booking['Model']) ?></small>
                        </td>
                        <td><?= htmlspecialchars($booking['customer_name']) ?></td>
                        <td><?= date('M j, Y', strtotime($booking['start_date'])) ?></td>
                        <td><?= date('M j, Y', strtotime($booking['end_date'])) ?></td>
                        <td><?= htmlspecialchars($booking['Hours'] ?? 'N/A') ?></td>
                        <td>
                            <span style="font-weight: bold; color: <?= $booking['status'] == 'CON' ? '#28a745' : '#666' ?>;">
                                ₹<?= number_format($booking['total_amount'], 2) ?>
                            </span>
                        </td>
                        <td><span class="status-badge <?= $status_class ?>"><?= $status_text ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f8f9fa; font-weight: bold; font-size: 16px;">
                    <td colspan="6" style="text-align: right; color: #234a23;">TOTAL CONFIRMED EARNINGS:</td>
                    <td style="color: #28a745;">₹<?= number_format($total_earnings, 2) ?></td>
                    <td>-</td>
                </tr>
            </tfoot>
        </table>
    <?php else: ?>
        <div class="form-section" style="text-align: center; padding: 50px;">
            <h3 style="color: #666; margin-bottom: 15px;">No Earnings Data Found</h3>
            <p style="color: #666; margin-bottom: 25px;">
                <?= ($start_date || $end_date) ? 'No bookings found in the selected date range. Try adjusting your filters.' : 'Start by adding equipment and getting your first bookings!' ?>
            </p>
            <?php if ($start_date || $end_date): ?>
                <a href="earnings_report.php" class="action-btn" style="margin-right: 10px;">🔄 Clear Filters</a>
            <?php endif; ?>
            <a href="add_equipment.php" class="action-btn"> Add Equipment</a>
        </div>
    <?php endif; ?>
</div>

<?php 
    require 'ofooter.php';
?>