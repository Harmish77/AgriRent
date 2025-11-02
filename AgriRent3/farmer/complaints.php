<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Farmer/Customer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'F') {
    header('Location: ../login.php');
    exit();
}

$farmer_id = $_SESSION['user_id'];
$message = '';

// Handle adding response to complaint
if (isset($_POST['add_response']) && isset($_POST['complaint_id']) && isset($_POST['response'])) {
    $complaint_id = intval($_POST['complaint_id']);
    $response = trim($_POST['response']);
    
    if (!empty($response)) {
        $response_text = "\n\n--- CUSTOMER RESPONSE (" . date('Y-m-d H:i:s') . ") ---\n" . $response;
        
        $response_sql = "UPDATE complaints 
                        SET Description = CONCAT(Description, ?) 
                        WHERE Complaint_id = ? AND User_id = ?";
        $response_stmt = $conn->prepare($response_sql);
        if ($response_stmt) {
            $response_stmt->bind_param('sii', $response_text, $complaint_id, $farmer_id);
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
$where_clause = "WHERE c.User_id = ?";
$params = [$farmer_id];
$param_types = "i";

if ($status_filter !== 'all') {
    $where_clause .= " AND c.Status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

// Get complaints submitted by this farmer
$sql = "SELECT c.Complaint_id, c.Description, c.Status, c.Complaint_type,
               c.ID, 
               CASE 
                   WHEN c.Complaint_type = 'E' THEN e.Title
                   WHEN c.Complaint_type = 'P' THEN p.Name
               END as item_name,
               CASE 
                   WHEN c.Complaint_type = 'E' THEN CONCAT(e.Brand, ' ', e.Model)
                   WHEN c.Complaint_type = 'P' THEN 'Product'
               END as item_details
        FROM complaints c
        LEFT JOIN equipment e ON c.Complaint_type = 'E' AND c.ID = e.Equipment_id
        LEFT JOIN product p ON c.Complaint_type = 'P' AND c.ID = p.product_id
        $where_clause
        ORDER BY c.Complaint_id DESC
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
              WHERE c.User_id = ?";

$stats_stmt = $conn->prepare($stats_sql);
if ($stats_stmt) {
    $stats_stmt->bind_param("i", $farmer_id);
    $stats_stmt->execute();
    $stats = $stats_stmt->get_result()->fetch_assoc();
    $stats_stmt->close();
} else {
    $stats = ['total' => 0, 'open_count' => 0, 'progress_count' => 0, 'resolved_count' => 0];
}

require 'fheader.php';
require 'farmer_nav.php';
?>

<link rel="stylesheet" href="../admin.css">

<div class="main-content">
    <h1>My Complaints</h1>
    <p style="color: #666; margin-bottom: 30px;">View and track complaints you've submitted</p>

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
            <small style="color: #dc3545;">Awaiting Response</small>
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
                                <?= $complaint['Complaint_type'] == 'E' ? 'Equipment' : 'Product' ?>: 
                                <?= htmlspecialchars($complaint['item_name']) ?> 
                                (<?= htmlspecialchars($complaint['item_details']) ?>)
                            </span>
                        </div>
                        <div class="complaint-status">
                            <span class="status-badge status-<?= strtolower($complaint['Status']) ?>">
                                <?= $complaint['Status'] == 'O' ? 'Open' : ($complaint['Status'] == 'P' ? 'In Progress' : 'Resolved') ?>
                            </span>
                        </div>
                    </div>

                    <div class="complaint-body">
                        <div class="complaint-description">
                            <strong>Your Complaint:</strong>
                            <p><?= nl2br(htmlspecialchars($complaint['Description'])) ?></p>
                        </div>
                    </div>

                    <div class="complaint-actions">
                        <!-- Response Button -->
                        <button onclick="toggleResponse(<?= $complaint['Complaint_id'] ?>)" class="action-btn" style="background: #28a745;">
                            💬 Add Response
                        </button>

                        <!-- View Status Info -->
                        <span style="padding: 8px 12px; background: #f0f0f0; border-radius: 4px; color: #666;">
                            <?php 
                            $status_text = '';
                            if ($complaint['Status'] == 'O') {
                                $status_text = 'Awaiting response from owner';
                            } elseif ($complaint['Status'] == 'P') {
                                $status_text = 'Owner is working on resolving this';
                            } else {
                                $status_text = 'This complaint has been resolved';
                            }
                            echo $status_text;
                            ?>
                        </span>
                    </div>

                    <!-- Response Form -->
                    <div id="response-<?= $complaint['Complaint_id'] ?>" class="response-form" style="display: none;">
                        <form method="POST">
                            <input type="hidden" name="complaint_id" value="<?= $complaint['Complaint_id'] ?>">
                            <textarea name="response" placeholder="Add more details or your reply..." rows="3" required></textarea>
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
                <?= $status_filter !== 'all' ? 'No complaints with selected status.' : 'You haven\'t submitted any complaints yet.' ?>
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

.complaint-description p {
    margin: 5px 0;
    line-height: 1.5;
}

.complaint-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.action-btn {
    padding: 8px 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-weight: bold;
    color: white;
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

.card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.card h3 {
    margin: 0 0 10px 0;
    color: #666;
}

.card .count {
    font-size: 32px;
    font-weight: bold;
    color: #234a23;
}

.cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
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

<?php require 'ffooter.php'; ?>
