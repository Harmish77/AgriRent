<?php
session_start();
require_once('../auth/config.php');

// Check admin access
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'A') {
    header('Location: ../login.php');
    exit();
}

$message = '';
$error = '';

// Handle status and reply update
if (isset($_POST['update_complaint']) && isset($_POST['complaint_id'])) {
    $complaint_id = intval($_POST['complaint_id']);
    $status = $_POST['status'] ?? '';
    $reply = trim($_POST['reply'] ?? '');
    
    if (!empty($status)) {
        $reply_text = "";
        if ($reply) {
            $reply_text = "\n\n--- ADMIN RESPONSE (" . date('Y-m-d H:i:s') . ") ---\n" . $reply;
        }
        
        // FIXED: Changed tbl_complaint to complaints
        $update_sql = "UPDATE complaints SET Status = ?, Description = CONCAT(Description, ?) WHERE Complaint_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        
        if ($update_stmt) {
            $update_stmt->bind_param('ssi', $status, $reply_text, $complaint_id);
            if ($update_stmt->execute()) {
                $message = '✓ Complaint updated successfully.';
            } else {
                $error = '✗ Error updating complaint.';
            }
            $update_stmt->close();
        }
    }
}

// Pagination and filtering
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';

// Build WHERE clause
$where_parts = [];
$params = [];
$param_types = "";

if ($status_filter !== 'all') {
    $where_parts[] = "c.Status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

if ($type_filter !== 'all') {
    $where_parts[] = "c.Complaint_type = ?";
    $params[] = $type_filter;
    $param_types .= "s";
}

$where_clause = !empty($where_parts) ? "WHERE " . implode(" AND ", $where_parts) : "";

// Main query - FIXED: Changed tbl_complaint to complaints
$sql = "SELECT c.Complaint_id as complaint_id, c.User_id as user_id, c.Complaint_type as complaint_type, 
               c.ID as id, c.Description as description, c.Status as status, NOW() as created_at,
               u.Name as filed_by, u.Phone as filed_by_phone
        FROM complaints c
        JOIN users u ON c.User_id = u.user_id
        $where_clause
        ORDER BY c.Complaint_id DESC
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$param_types .= "ii";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Prepare error: ' . $conn->error);
}
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$complaints = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get total count - FIXED: Changed tbl_complaint to complaints
$count_sql = "SELECT COUNT(*) as total FROM complaints c $where_clause";
$count_params = array_slice($params, 0, -2);
$count_types = substr($param_types, 0, -2);

$count_stmt = $conn->prepare($count_sql);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$total_complaints = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = ceil($total_complaints / $limit);

// Get statistics - FIXED: Changed tbl_complaint to complaints
$stats_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN Status = 'O' THEN 1 ELSE 0 END) as open_count,
                SUM(CASE WHEN Status = 'P' THEN 1 ELSE 0 END) as progress_count,
                SUM(CASE WHEN Status = 'R' THEN 1 ELSE 0 END) as resolved_count
              FROM complaints";

$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

require 'header.php';
require 'admin_nav.php';
?>

<!DOCTYPE html>
<html>
<head>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        .main-content {
            padding: 30px 20px;
            background: #f5f7fa;
            min-height: calc(100vh - 70px);
        }

        .page-header {
            margin-bottom: 30px;
        }

        .page-header h1 {
            color: #234a23;
            font-size: 32px;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .page-header p {
            color: #666;
            font-size: 14px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Statistics Cards */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-top: 4px solid #234a23;
        }

        .stat-card.open {
            border-top-color: #dc3545;
        }

        .stat-card.progress {
            border-top-color: #ffc107;
        }

        .stat-card.resolved {
            border-top-color: #28a745;
        }

        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .stat-card .count {
            font-size: 40px;
            font-weight: bold;
            color: #234a23;
        }

        /* Filters */
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .filter-group label {
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
            cursor: pointer;
            font-size: 14px;
        }

        .filter-group select:hover {
            border-color: #234a23;
        }

        .complaint-count {
            margin-left: auto;
            color: #666;
            font-size: 14px;
        }

        /* Complaints Table */
        .complaints-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table thead {
            background: #234a23;
            color: white;
        }

        table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        table tbody tr {
            border-bottom: 1px solid #eee;
            transition: background 0.2s;
        }

        table tbody tr:hover {
            background: #f9f9f9;
        }

        table tbody td {
            padding: 15px;
            font-size: 14px;
            color: #333;
        }

        .complaint-id {
            font-weight: 600;
            color: #234a23;
        }

        .complaint-type {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .type-equipment {
            background: #e3f2fd;
            color: #1976d2;
        }

        .type-product {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: white;
        }

        .status-o {
            background: #dc3545;
        }

        .status-p {
            background: #ffc107;
            color: #212529;
        }

        .status-r {
            background: #28a745;
        }

        .description-preview {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #666;
        }

        .user-info {
            font-size: 13px;
            color: #666;
        }

        .user-info strong {
            color: #234a23;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-view {
            background: #007bff;
            color: white;
        }

        .btn-view:hover {
            background: #0056b3;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal.show {
            display: block;
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 8px;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }

        .modal-header h2 {
            color: #234a23;
            font-size: 20px;
            margin: 0;
        }

        .modal-close {
            font-size: 28px;
            font-weight: bold;
            color: #999;
            cursor: pointer;
            border: none;
            background: none;
            padding: 0;
            width: 30px;
            height: 30px;
        }

        .modal-close:hover {
            color: #333;
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
            font-size: 14px;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
        }

        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #234a23;
            box-shadow: 0 0 5px rgba(35, 74, 35, 0.2);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 30px;
        }

        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            color: #234a23;
            text-decoration: none;
            cursor: pointer;
        }

        .pagination a:hover {
            background: #234a23;
            color: white;
        }

        .pagination a.active {
            background: #234a23;
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 8px;
            color: #999;
        }

        .empty-state h3 {
            font-size: 20px;
            color: #333;
            margin-bottom: 10px;
        }

        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-section {
                flex-direction: column;
            }

            .complaint-count {
                margin-left: 0;
            }

            .table-responsive {
                font-size: 12px;
            }

            table th, table td {
                padding: 10px;
            }

            .description-preview {
                max-width: 150px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .modal-content {
                margin: 20% auto;
                width: 90%;
            }
        }
    </style>
</head>
<body>

<div class="main-content">
    <!-- Page Header -->
    <div class="page-header">
        <h1>📋 Complaints Management</h1>
        <p>Review and respond to all user complaints in the system</p>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-success"><?= $message ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="stats-container">
        <div class="stat-card">
            <h3>Total Complaints</h3>
            <div class="count"><?= $stats['total'] ?? 0 ?></div>
        </div>

        <div class="stat-card open">
            <h3>Open</h3>
            <div class="count"><?= $stats['open_count'] ?? 0 ?></div>
        </div>

        <div class="stat-card progress">
            <h3>In Progress</h3>
            <div class="count"><?= $stats['progress_count'] ?? 0 ?></div>
        </div>

        <div class="stat-card resolved">
            <h3>Resolved</h3>
            <div class="count"><?= $stats['resolved_count'] ?? 0 ?></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" style="display: flex; gap: 20px; flex-wrap: wrap; align-items: center; width: 100%;">
            <div class="filter-group">
                <label>Type:</label>
                <select name="type" onchange="this.form.submit()">
                    <option value="all" <?= $type_filter == 'all' ? 'selected' : '' ?>>All Types</option>
                    <option value="E" <?= $type_filter == 'E' ? 'selected' : '' ?>>Equipment</option>
                    <option value="P" <?= $type_filter == 'P' ? 'selected' : '' ?>>Product</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Status:</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
                    <option value="O" <?= $status_filter == 'O' ? 'selected' : '' ?>>Open</option>
                    <option value="P" <?= $status_filter == 'P' ? 'selected' : '' ?>>In Progress</option>
                    <option value="R" <?= $status_filter == 'R' ? 'selected' : '' ?>>Resolved</option>
                </select>
            </div>

            <span class="complaint-count"><?= count($complaints) ?> of <?= $total_complaints ?> complaint(s)</span>
        </form>
    </div>

    <!-- Complaints Table -->
    <?php if (count($complaints) > 0): ?>
        <div class="complaints-table">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Filed By</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaints as $complaint): ?>
                            <tr>
                                <td class="complaint-id">#<?= $complaint['complaint_id'] ?></td>
                                <td>
                                    <span class="complaint-type <?= $complaint['complaint_type'] == 'E' ? 'type-equipment' : 'type-product' ?>">
                                        <?= $complaint['complaint_type'] == 'E' ? '🔧 Equipment' : '📦 Product' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="user-info">
                                        <strong><?= htmlspecialchars($complaint['filed_by']) ?></strong><br>
                                        <?= htmlspecialchars($complaint['filed_by_phone']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="description-preview" title="<?= htmlspecialchars($complaint['description']) ?>">
                                        <?= htmlspecialchars(substr($complaint['description'], 0, 50)) ?>...
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= strtolower($complaint['status']) ?>">
                                        <?= $complaint['status'] == 'O' ? 'Open' : ($complaint['status'] == 'P' ? 'In Progress' : 'Resolved') ?>
                                    </span>
                                </td>
                                <td><?= date('M d, Y', strtotime($complaint['created_at'])) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-view" onclick="openComplaintModal(<?= $complaint['complaint_id'] ?>)">
                                            View & Reply
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?= $i ?>&status=<?= $status_filter ?>&type=<?= $type_filter ?>" 
                       <?= $page == $i ? 'class="active"' : '' ?>>
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="empty-state">
            <h3>No Complaints Found</h3>
            <p>There are no complaints matching your filters.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Complaint Detail Modal -->
<div id="complaintModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Complaint Details</h2>
            <button class="modal-close" onclick="closeComplaintModal()">&times;</button>
        </div>
        <div id="modalBody" class="modal-body">
            <!-- Loaded dynamically -->
        </div>
    </div>
</div>

<script>
function openComplaintModal(complaintId) {
    const modal = document.getElementById('complaintModal');
    const modalBody = document.getElementById('modalBody');
    
    // Fetch complaint details
    fetch('get_complaint_details.php?id=' + complaintId)
        .then(response => response.text())
        .then(data => {
            modalBody.innerHTML = data;
            modal.classList.add('show');
        })
        .catch(error => console.error('Error:', error));
}

function closeComplaintModal() {
    const modal = document.getElementById('complaintModal');
    modal.classList.remove('show');
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('complaintModal');
    if (event.target == modal) {
        modal.classList.remove('show');
    }
}
</script>

</body>
</html>

<?php require 'footer.php'; ?>
