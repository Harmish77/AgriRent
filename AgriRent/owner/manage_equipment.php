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

// Handle delete action
if (isset($_GET['delete'])) {
    $equipment_id = intval($_GET['delete']);
    $stmt = $conn->prepare('DELETE FROM equipment WHERE Equipment_id = ? AND Owner_id = ?');
    $stmt->bind_param('ii', $equipment_id, $owner_id);
    if ($stmt->execute()) {
        $message = 'Equipment deleted successfully.';
    } else {
        $message = 'Error deleting equipment.';
    }
    $stmt->close();
}

// Handle status filter
$status_filter = $_GET['status'] ?? 'all';
$where_clause = "WHERE Owner_id = ?";
$params = [$owner_id];
$param_types = "i";

if ($status_filter !== 'all') {
    $where_clause .= " AND Approval_status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

// Fetch equipment for this owner with error handling
$equipment_list = [];
try {
    $query = "SELECT Equipment_id, Title, Brand, Model, Year, Hourly_rate, Daily_rate, Approval_status, listed_date 
              FROM equipment 
              $where_clause 
              ORDER BY listed_date DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $equipment_list[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    $message = 'Error fetching equipment data.';
    error_log("Equipment fetch error: " . $e->getMessage());
}

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../assets/css/equipment.css">

<div class="main-content">
    <h1>Manage Equipment</h1>
    <p style="color: #666; margin-bottom: 30px;">View, edit, and manage all your agricultural equipment listings</p>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Quick Actions and Filter -->
    <div class="quick-actions" style="margin-bottom: 30px; display: flex; align-items: center; gap: 20px;">
        <a href="add_equipment.php" class="action-btn">➕ Add New Equipment</a>
        
        <!-- Status Filter -->
        <form method="GET" style="display: inline-block;">
            <select name="status" onchange="this.form.submit()" style="padding: 10px; border-radius: 4px; border: 1px solid #ddd; background: white;">
                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="CON" <?= $status_filter == 'CON' ? 'selected' : '' ?>>Approved</option>
                <option value="PEN" <?= $status_filter == 'PEN' ? 'selected' : '' ?>>Pending</option>
                <option value="REJ" <?= $status_filter == 'REJ' ? 'selected' : '' ?>>Rejected</option>
            </select>
        </form>

        <span style="color: #666; font-size: 14px;">
            Showing <?= count($equipment_list) ?> equipment(s)
        </span>
    </div>

    <!-- Equipment Table -->
    <?php if (count($equipment_list) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Brand</th>
                    <th>Model</th>
                    <th>Year</th>
                    <th>Hourly Rate</th>
                    <th>Daily Rate</th>
                    <th>Status</th>
                    <th>Listed Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($equipment_list as $equipment): 
                    $status_map = [
                        'CON' => ['status-confirmed', 'Approved'],
                        'PEN' => ['status-pending', 'Pending'],
                        'REJ' => ['status-rejected', 'Rejected']
                    ];
                    list($status_class, $status_text) = $status_map[$equipment['Approval_status']] ?? ['', 'Unknown'];
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($equipment['Title']) ?></strong></td>
                        <td><?= htmlspecialchars($equipment['Brand']) ?></td>
                        <td><?= htmlspecialchars($equipment['Model']) ?></td>
                        <td><?= htmlspecialchars($equipment['Year'] ?? 'N/A') ?></td>
                        <td>₹<?= number_format($equipment['Hourly_rate'] ?? 0, 2) ?>/hr</td>
                        <td>₹<?= number_format($equipment['Daily_rate'] ?? 0, 2) ?>/day</td>
                        <td><span class="status-badge <?= $status_class ?>"><?= $status_text ?></span></td>
                        <td><?= date('M j, Y', strtotime($equipment['listed_date'])) ?></td>
                        <td>
                            <a href="view_equipment.php?id=<?= $equipment['Equipment_id'] ?>" style="color: #17a2b8;">View</a> |
                            <a href="edit_equipment.php?id=<?= $equipment['Equipment_id'] ?>" style="color: #28a745;">Edit</a> |
                            <a href="?delete=<?= $equipment['Equipment_id'] ?>&status=<?= $status_filter ?>" 
                               onclick="return confirm('Are you sure you want to delete this equipment? This action cannot be undone.')" 
                               style="color: #dc3545;">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="form-section" style="text-align: center; padding: 50px; background: white; border-radius: 8px;">
            <h3 style="color: #666; margin-bottom: 15px;">
                <?= $status_filter !== 'all' ? 'No equipment found with status: ' . strtoupper($status_filter) : 'No Equipment Found' ?>
            </h3>
            <p style="color: #666; margin-bottom: 25px;">
                <?= $status_filter !== 'all' ? 'Try changing the filter or add new equipment.' : 'Start by adding your first piece of agricultural equipment.' ?>
            </p>
            <a href="add_equipment.php" class="action-btn">➕ Add Your First Equipment</a>
        </div>
    <?php endif; ?>

    <!-- Equipment Summary Statistics -->
    <?php if (count($equipment_list) > 0): ?>
        <div class="report-sections" style="margin-top: 40px;">
            <div class="report-section">
                <h3>Equipment Summary</h3>
                <div class="status-list">
                    <div>📊 Total Equipment: <strong><?= count($equipment_list) ?></strong></div>
                    <?php
                    $approved = array_filter($equipment_list, fn($eq) => $eq['Approval_status'] == 'CON');
                    $pending = array_filter($equipment_list, fn($eq) => $eq['Approval_status'] == 'PEN');
                    $rejected = array_filter($equipment_list, fn($eq) => $eq['Approval_status'] == 'REJ');
                    ?>
                    <div>✅ Approved: <strong><?= count($approved) ?></strong></div>
                    <div>⏳ Pending: <strong><?= count($pending) ?></strong></div>
                    <div>❌ Rejected: <strong><?= count($rejected) ?></strong></div>
                </div>
            </div>

            <div class="report-section">
                <h3>Revenue Information</h3>
                <div class="status-list">
                    <?php
                    $total_hourly_rate = array_sum(array_map(fn($eq) => $eq['Hourly_rate'] ?? 0, $approved));
                    $total_daily_rate = array_sum(array_map(fn($eq) => $eq['Daily_rate'] ?? 0, $approved));
                    ?>
                    <div>💰 Total Hourly Rates: <strong>₹<?= number_format($total_hourly_rate, 2) ?></strong></div>
                    <div>💰 Total Daily Rates: <strong>₹<?= number_format($total_daily_rate, 2) ?></strong></div>
                    <div>📈 Average Hourly Rate: <strong>₹<?= count($approved) > 0 ? number_format($total_hourly_rate / count($approved), 2) : '0.00' ?></strong></div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php 
    require 'ofooter.php';
?>
