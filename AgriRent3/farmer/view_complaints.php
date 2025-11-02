<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Farmer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'F') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;
$status_filter = $_GET['status'] ?? 'all';

// Get complaints
$sql = "SELECT c.Complaint_id, c.Complaint_type, c.ID, c.Description, c.Status,
               CASE 
                   WHEN c.Complaint_type = 'E' THEN e.Title
                   WHEN c.Complaint_type = 'P' THEN p.Name
               END as item_name
        FROM complaints c
        LEFT JOIN equipment e ON c.Complaint_type = 'E' AND c.ID = e.Equipment_id
        LEFT JOIN product p ON c.Complaint_type = 'P' AND c.ID = p.product_id
        WHERE c.User_id = ?";

$params = [$user_id];
$param_types = "i";

if ($status_filter !== 'all') {
    $sql .= " AND c.Status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

$sql .= " ORDER BY c.Complaint_id DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$param_types .= "ii";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Database error: ' . $conn->error);
}

$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$complaints = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM complaints WHERE User_id = ?";
if ($status_filter !== 'all') {
    $count_sql .= " AND Status = ?";
}

$count_stmt = $conn->prepare($count_sql);
if (!$count_stmt) {
    die('Database error: ' . $conn->error);
}

if ($status_filter !== 'all') {
    $count_stmt->bind_param('is', $user_id, $status_filter);
} else {
    $count_stmt->bind_param('i', $user_id);
}

$count_stmt->execute();
$total_complaints = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = ceil($total_complaints / $limit);

// Get statistics
$stats_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN Status = 'O' THEN 1 ELSE 0 END) as open_count,
                SUM(CASE WHEN Status = 'P' THEN 1 ELSE 0 END) as progress_count,
                SUM(CASE WHEN Status = 'R' THEN 1 ELSE 0 END) as resolved_count
              FROM complaints
              WHERE User_id = ?";

$stats_stmt = $conn->prepare($stats_sql);
if (!$stats_stmt) {
    die('Database error: ' . $conn->error);
}

$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

require 'fheader.php';
require 'farmer_nav.php';
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

        /* Filter Section */
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
            justify-content: space-between;
        }

        .filter-section form {
            display: flex;
            gap: 10px;
        }

        .filter-section select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .add-btn {
            background: #234a23;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 600;
            transition: background 0.3s;
        }

        .add-btn:hover {
            background: #1a371a;
        }

        /* Table */
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
        }

        .type-e {
            background: #e3f2fd;
            color: #1976d2;
        }

        .type-p {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
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

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 30px;
        }

        .pagination a {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            color: #234a23;
            text-decoration: none;
            transition: all 0.3s;
        }

        .pagination a:hover {
            background: #234a23;
            color: white;
        }

        .pagination a.active {
            background: #234a23;
            color: white;
        }

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

        .empty-state a {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #234a23;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-section {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-section form {
                width: 100%;
            }

            .add-btn {
                text-align: center;
                width: 100%;
            }

            table {
                font-size: 12px;
            }

            table th, table td {
                padding: 10px;
            }

            .description-preview {
                max-width: 150px;
            }
        }
    </style>
</head>
<body>

<div class="main-content">
    <div class="page-header">
        <h1>📋 My Complaints</h1>
        <p>Track all your submitted complaints and their status</p>
    </div>

    <!-- Statistics -->
    <div class="stats-container">
        <div class="stat-card">
            <h3>Total</h3>
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
        <form method="GET">
            <select name="status" onchange="this.form.submit()">
                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="O" <?= $status_filter == 'O' ? 'selected' : '' ?>>🔴 Open</option>
                <option value="P" <?= $status_filter == 'P' ? 'selected' : '' ?>>🟡 In Progress</option>
                <option value="R" <?= $status_filter == 'R' ? 'selected' : '' ?>>🟢 Resolved</option>
            </select>
        </form>

        <a href="add_complaint.php" class="add-btn">➕ File New Complaint</a>
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
                            <th>Item</th>
                            <th>Description</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaints as $complaint): ?>
                            <tr>
                                <td class="complaint-id">#<?= $complaint['Complaint_id'] ?></td>
                                <td>
                                    <span class="complaint-type type-<?= strtolower($complaint['Complaint_type']) ?>">
                                        <?= $complaint['Complaint_type'] == 'E' ? '🔧 Equipment' : '📦 Product' ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($complaint['item_name'] ?? 'N/A') ?></td>
                                <td>
                                    <div class="description-preview" title="<?= htmlspecialchars($complaint['Description']) ?>">
                                        <?= htmlspecialchars(substr($complaint['Description'], 0, 50)) ?>...
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= strtolower($complaint['Status']) ?>">
                                        <?= $complaint['Status'] == 'O' ? 'Open' : ($complaint['Status'] == 'P' ? 'In Progress' : 'Resolved') ?>
                                    </span>
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
                    <a href="?page=<?= $i ?>&status=<?= $status_filter ?>" <?= $page == $i ? 'class="active"' : '' ?>>
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="empty-state">
            <h3>No Complaints Found</h3>
            <p>
                <?= $status_filter !== 'all' ? 'No complaints with selected status.' : 'You haven\'t filed any complaints yet.' ?>
            </p>
            <a href="add_complaint.php">➕ File Your First Complaint</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>

<?php require 'ffooter.php'; ?>
