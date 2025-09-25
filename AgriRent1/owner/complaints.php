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

// Handle status update
if (isset($_POST['update_status']) && isset($_POST['complaint_id'])) {
    $complaint_id = intval($_POST['complaint_id']);
    $new_status = $_POST['status'];
    
    // Update complaint status for owner's equipment
    $update_sql = "UPDATE complaints c 
                   JOIN equipment e ON c.ID = e.Equipment_id 
                   SET c.Status = ? 
                   WHERE c.Complaint_id = ? AND c.Complaint_type = 'E' AND e.Owner_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    if ($update_stmt) {
        $update_stmt->bind_param('sii', $new_status, $complaint_id, $owner_id);
        if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
            $message = 'Complaint status updated successfully.';
        } else {
            $message = 'Error updating complaint status.';
        }
        $update_stmt->close();
    }
}

// Handle adding response
if (isset($_POST['add_response']) && isset($_POST['complaint_id']) && isset($_POST['response'])) {
    $complaint_id = intval($_POST['complaint_id']);
    $response = trim($_POST['response']);
    
    if (!empty($response)) {
        $response_text = "\n\n--- OWNER RESPONSE (" . date('Y-m-d H:i:s') . ") ---\n" . $response;
        
        $response_sql = "UPDATE complaints c 
                        JOIN equipment e ON c.ID = e.Equipment_id 
                        SET c.Description = CONCAT(c.Description, ?) 
                        WHERE c.Complaint_id = ? AND c.Complaint_type = 'E' AND e.Owner_id = ?";
        $response_stmt = $conn->prepare($response_sql);
        if ($response_stmt) {
            $response_stmt->bind_param('sii', $response_text, $complaint_id, $owner_id);
            if ($response_stmt->execute() && $response_stmt->affected_rows > 0) {
                $message = 'Response added successfully.';
            }
            $response_stmt->close();
        }
    }
}

// Pagination and filtering
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;
$status_filter = $_GET['status'] ?? 'all';

// Build WHERE clause
$where_clause = "WHERE c.Complaint_type = 'E' AND e.Owner_id = ?";
$params = [$owner_id];
$param_types = "i";

if ($status_filter !== 'all') {
    $where_clause .= " AND c.Status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

// Main query - using the exact column names from your table
$sql = "SELECT c.Complaint_id, c.Description, c.Status, c.created_date,
               e.Title as equipment_title, e.Brand, e.Model,
               u.Name as customer_name, u.Phone as customer_phone, u.Email as customer_email
        FROM complaints c
        JOIN equipment e ON c.ID = e.Equipment_id
        JOIN users u ON c.User_id = u.user_id
        $where_clause
        ORDER BY c.created_date DESC
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$param_types .= "ii";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('SQL Error: ' . $conn->error);
}

$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$complaints = [];
while ($row = $result->fetch_assoc()) {
    $complaints[] = $row;
}
$stmt->close();

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total
              FROM complaints c
              JOIN equipment e ON c.ID = e.Equipment_id
              $where_clause";

$count_stmt = $conn->prepare($count_sql);
if ($count_stmt) {
    $count_params = array_slice($params, 0, -2); // Remove LIMIT and OFFSET
    $count_types = substr($param_types, 0, -2);
    
    if (!empty($count_params)) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
    $count_stmt->execute();
    $total_complaints = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    $total_complaints = 0;
}

$total_pages = ceil($total_complaints / $limit);

// Get statistics
$stats_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN c.Status = 'O' THEN 1 ELSE 0 END) as open_count,
                SUM(CASE WHEN c.Status = 'P' THEN 1 ELSE 0 END) as progress_count,
                SUM(CASE WHEN c.Status = 'R' THEN 1 ELSE 0 END) as resolved_count
              FROM complaints c
              JOIN equipment e ON c.ID = e.Equipment_id
              WHERE c.Complaint_type = 'E' AND e.Owner_id = ?";

$stats_stmt = $conn->prepare($stats_sql);
if ($stats_stmt) {
    $stats_stmt->bind_param("i", $owner_id);
    $stats_stmt->execute();
    $stats = $stats_stmt->get_result()->fetch_assoc();
    $stats_stmt->close();
} else {
    $stats = ['total' => 0, 'open_count' => 0, 'progress_count' => 0, 'resolved_count' => 0];
}

require '../header.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../admin.css">

<div class="main-content">
    <h1>Complaints Management</h1>
    <p style="color: #666; margin-bottom: 30px;">Handle and resolve complaints related to your equipment</p>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="cards" style="margin-bottom: 30px;">
        <div class="card">
            <h3>Total Complaints</h3>
            <div class="count"><?= $stats['total'] ?></div>
            <small style="color: #666;">All Time</small>
        </div>
        
        <div class="card">
            <h3>Open</h3>
            <div class="count"><?= $stats['open_count'] ?></div>
            <small style="color: #dc3545;">Need Attention</small>
        </div>
        
        <div class="card">
            <h3>In Progress</h3>
            <div class="count"><?= $stats['progress_count'] ?></div>
            <small style="color: #ffc107;">Being Resolved</small>
        </div>
        
        <div class="card">
            <h3>Resolved</h3>
            <div class="count"><?= $stats['resolved_count'] ?></div>
            <small style="color: #28a745;">Completed</small>
        </div>
    </div>

    <!-- Filter Options -->
    <div class="quick-actions" style="margin-bottom: 20px;">
        <form method="GET" style="display: inline-block;">
            <select name="status" onchange="this.form.submit()" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="O" <?= $status_filter == 'O' ? 'selected' : '' ?>>Open</option>
                <option value="P" <?= $status_filter == 'P' ? 'selected' : '' ?>>In Progress</option>
                <option value="R" <?= $status_filter == 'R' ? 'selected' : '' ?>>Resolved</option>
            </select>
        </form>

        <span style="margin-left: 20px; color: #666;">
            Showing <?= count($complaints) ?> of <?= $total_complaints ?> complaint(s)
        </span>
    </div>

    <!-- Complaints List -->
    <?php if (count($complaints) > 0): ?>
        <div class="complaints-container">
            <?php foreach ($complaints as $complaint): ?>
                <div class="complaint-card">
                    <div class="complaint-header">
                        <div class="complaint-info">
                            <h4>Complaint #<?= $complaint['Complaint_id'] ?></h4>
                            <span class="equipment-info">
                                Equipment: <?= htmlspecialchars($complaint['equipment_title']) ?> 
                                (<?= htmlspecialchars($complaint['Brand']) ?> <?= htmlspecialchars($complaint['Model']) ?>)
                            </span>
                        </div>
                        <div class="complaint-status">
                            <span class="status-badge status-<?= strtolower($complaint['Status']) ?>">
                                <?= $complaint['Status'] == 'O' ? 'Open' : ($complaint['Status'] == 'P' ? 'In Progress' : 'Resolved') ?>
                            </span>
                        </div>
                    </div>

                    <div class="complaint-body">
                        <div class="customer-info">
                            <strong>Customer:</strong> <?= htmlspecialchars($complaint['customer_name']) ?>
                            <span style="margin-left: 15px;">📞 <?= htmlspecialchars($complaint['customer_phone']) ?></span>
                        </div>
                        
                        <div class="complaint-description">
                            <strong>Description:</strong>
                            <p><?= nl2br(htmlspecialchars($complaint['Description'])) ?></p>
                        </div>

                        <div class="complaint-date">
                            <strong>Submitted:</strong> <?= date('M j, Y g:i A', strtotime($complaint['created_date'])) ?>
                        </div>
                    </div>

                    <div class="complaint-actions">
                        <!-- Status Update -->
                        <form method="POST" style="display: inline-block;">
                            <input type="hidden" name="complaint_id" value="<?= $complaint['Complaint_id'] ?>">
                            <select name="status" onchange="this.form.submit()">
                                <option value="">Change Status</option>
                                <option value="O" <?= $complaint['Status'] == 'O' ? 'disabled' : '' ?>>Mark as Open</option>
                                <option value="P" <?= $complaint['Status'] == 'P' ? 'disabled' : '' ?>>Mark In Progress</option>
                                <option value="R" <?= $complaint['Status'] == 'R' ? 'disabled' : '' ?>>Mark Resolved</option>
                            </select>
                            <input type="hidden" name="update_status" value="1">
                        </form>

                        <!-- Response Button -->
                        <button onclick="toggleResponse(<?= $complaint['Complaint_id'] ?>)" class="action-btn" style="margin-left: 10px;">
                            💬 Add Response
                        </button>

                        <!-- Contact Customer -->
                        <a href="tel:<?= $complaint['customer_phone'] ?>" class="action-btn" style="background: #17a2b8; margin-left: 10px;">
                            📞 Call
                        </a>
                    </div>

                    <!-- Response Form -->
                    <div id="response-<?= $complaint['Complaint_id'] ?>" class="response-form" style="display: none;">
                        <form method="POST">
                            <input type="hidden" name="complaint_id" value="<?= $complaint['Complaint_id'] ?>">
                            <textarea name="response" placeholder="Enter your response..." rows="3" required></textarea>
                            <div style="margin-top: 10px;">
                                <button type="submit" name="add_response" class="action-btn">Send Response</button>
                                <button type="button" onclick="toggleResponse(<?= $complaint['Complaint_id'] ?>)" class="action-btn" style="background: #6c757d; margin-left: 10px;">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination" style="margin-top: 30px;">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?= $i ?>&status=<?= $status_filter ?>" class="<?= $page == $i ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="form-section" style="text-align: center; padding: 50px;">
            <h3>No Complaints Found</h3>
            <p>
                <?= $status_filter !== 'all' ? 'No complaints with selected status.' : 'No complaints have been submitted about your equipment yet.' ?>
            </p>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleResponse(complaintId) {
    const form = document.getElementById('response-' + complaintId);
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}
</script>

<style>
.complaints-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.complaint-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.complaint-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.complaint-info h4 {
    margin: 0;
    color: #234a23;
}

.equipment-info {
    color: #666;
    font-size: 14px;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.status-o { background: #dc3545; color: white; }
.status-p { background: #ffc107; color: #212529; }
.status-r { background: #28a745; color: white; }

.complaint-body {
    margin-bottom: 15px;
}

.customer-info {
    margin-bottom: 10px;
    padding: 8px;
    background: #f8f9fa;
    border-radius: 4px;
}

.complaint-description p {
    margin: 5px 0;
    line-height: 1.5;
}

.complaint-date {
    font-size: 12px;
    color: #666;
    margin-bottom: 15px;
}

.complaint-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.response-form {
    margin-top: 15px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 4px;
    border: 1px solid #dee2e6;
}

.response-form textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-family: inherit;
}

.pagination {
    display: flex;
    justify-content: center;
    gap: 5px;
}

.pagination a {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #234a23;
    text-decoration: none;
}

.pagination a:hover, .pagination a.active {
    background: #234a23;
    color: white;
}

.message {
    background: #d4edda;
    color: #155724;
    padding: 12px 20px;
    border-radius: 6px;
    margin-bottom: 20px;
    border: 1px solid #c3e6cb;
}

@media (max-width: 768px) {
    .complaint-header {
        flex-direction: column;
        gap: 10px;
    }
    
    .complaint-actions {
        flex-direction: column;
        align-items: stretch;
    }
    
    .cards {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>
