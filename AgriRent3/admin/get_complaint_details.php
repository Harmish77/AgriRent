<?php
session_start();
require_once('../auth/config.php');

// Check admin access
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'A') {
    die('Unauthorized');
}

$complaint_id = intval($_GET['id'] ?? 0);

if ($complaint_id <= 0) {
    die('Invalid complaint ID');
}

// FIXED: Better query with proper data fetching
$sql = "SELECT 
            c.Complaint_id,
            c.User_id,
            c.Complaint_type,
            c.ID,
            c.Description,
            c.Status,
            u.Name as filed_by,
            u.Phone as filed_by_phone
        FROM complaints c
        JOIN users u ON c.User_id = u.user_id
        WHERE c.Complaint_id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Database error: ' . $conn->error);
}

$stmt->bind_param('i', $complaint_id);
$stmt->execute();
$complaint = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$complaint) {
    die('Complaint not found');
}

// Handle update
$message = '';
$error = '';

if (isset($_POST['update_complaint'])) {
    $status = $_POST['status'] ?? '';
    $reply = trim($_POST['reply'] ?? '');
    
    if (!empty($status)) {
        $update_sql = "UPDATE complaints SET Status = ? WHERE Complaint_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        
        if ($update_stmt) {
            $update_stmt->bind_param('si', $status, $complaint_id);
            if ($update_stmt->execute()) {
                $message = '✓ Complaint updated successfully!';
                // Refresh complaint data
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $complaint_id);
                $stmt->execute();
                $complaint = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            } else {
                $error = '✗ Error updating complaint.';
            }
            $update_stmt->close();
        }
    }
}
?>

<style>
    .modal-body {
        font-family: Arial, sans-serif;
    }
    
    .complaint-detail-box {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 6px;
        margin-bottom: 15px;
        border-left: 4px solid #234a23;
    }
    
    .detail-row {
        margin: 8px 0;
        font-size: 14px;
    }
    
    .detail-row strong {
        color: #234a23;
        display: inline-block;
        min-width: 120px;
    }
    
    .description-box {
        background: #fff3cd;
        border-left: 4px solid #ffc107;
        padding: 15px;
        border-radius: 4px;
        margin-bottom: 15px;
        white-space: pre-wrap;
        line-height: 1.6;
        word-break: break-word;
    }
    
    .form-group {
        margin-bottom: 15px;
    }
    
    .form-group label {
        display: block;
        font-weight: 600;
        color: #333;
        margin-bottom: 6px;
        font-size: 13px;
    }
    
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-family: inherit;
        font-size: 13px;
    }
    
    .form-group textarea {
        min-height: 100px;
        resize: vertical;
    }
    
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #234a23;
        box-shadow: 0 0 5px rgba(35, 74, 35, 0.2);
    }
    
    .button-group {
        display: flex;
        gap: 10px;
        margin-top: 20px;
        justify-content: flex-end;
    }
    
    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
        font-size: 14px;
        transition: all 0.3s;
    }
    
    .btn-primary {
        background: #234a23;
        color: white;
    }
    
    .btn-primary:hover {
        background: #1a371a;
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #5a6268;
    }
    
    .alert {
        padding: 12px 15px;
        border-radius: 4px;
        margin-bottom: 15px;
        font-size: 13px;
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
    
    .status-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        color: white;
        text-transform: uppercase;
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
</style>

<?php if ($message): ?>
    <div class="alert alert-success"><?= $message ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?= $error ?></div>
<?php endif; ?>

<div class="complaint-detail-box">
    <div class="detail-row">
        <strong>Complaint ID:</strong> 
        #<?= htmlspecialchars($complaint['Complaint_id']) ?>
    </div>
    <div class="detail-row">
        <strong>Type:</strong> 
        <?= $complaint['Complaint_type'] == 'E' ? '🔧 Equipment' : '📦 Product' ?>
    </div>
    <div class="detail-row">
        <strong>Filed By:</strong> 
        <?= htmlspecialchars($complaint['filed_by']) ?>
    </div>
    <div class="detail-row">
        <strong>Phone:</strong> 
        <?= htmlspecialchars($complaint['filed_by_phone']) ?>
    </div>
    <div class="detail-row">
        <strong>Status:</strong> 
        <span class="status-badge status-<?= strtolower($complaint['Status']) ?>">
            <?= $complaint['Status'] == 'O' ? 'Open' : ($complaint['Status'] == 'P' ? 'In Progress' : 'Resolved') ?>
        </span>
    </div>
</div>

<div class="description-box">
    <strong style="display: block; margin-bottom: 10px; color: #856404;">📝 Description:</strong>
    <?= htmlspecialchars($complaint['Description']) ?>
</div>

<form method="POST" action="">
    <div class="form-group">
        <label>Update Status:</label>
        <select name="status" required>
            <option value="">-- Select Status --</option>
            <option value="O" <?= $complaint['Status'] == 'O' ? 'selected' : '' ?>>Open</option>
            <option value="P" <?= $complaint['Status'] == 'P' ? 'selected' : '' ?>>In Progress</option>
            <option value="R" <?= $complaint['Status'] == 'R' ? 'selected' : '' ?>>Resolved</option>
        </select>
    </div>

    <div class="form-group">
        <label>Admin Comment (Optional):</label>
        <textarea name="reply" placeholder="Add comment..."></textarea>
    </div>

    <div class="button-group">
        <button type="submit" name="update_complaint" value="1" class="btn btn-primary">
            ✓ Update Complaint
        </button>
        <button type="button" onclick="closeComplaintModal()" class="btn btn-secondary">
            ✕ Close
        </button>
    </div>

    <input type="hidden" name="complaint_id" value="<?= $complaint['Complaint_id'] ?>">
</form>
