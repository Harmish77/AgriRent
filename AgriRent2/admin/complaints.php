<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

// Handle complaint status changes
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = $_GET['id'];
    
    if ($action == 'start') {
        $conn->query("UPDATE complaints SET Status='INP' WHERE Complaint_id=$id");
        $message = "Complaint status updated to In Progress";
    } elseif ($action == 'solve') {
        $conn->query("UPDATE complaints SET Status='SOL' WHERE Complaint_id=$id");
        $message = "Complaint marked as Solved";
    }
}

// Get all complaints
$complaints = $conn->query("SELECT c.*, u.Name as user_name, u.Email as user_email 
                          FROM complaints c 
                          JOIN users u ON c.user_id = u.user_id 
                          ORDER BY c.Complaint_id DESC");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Complaints</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <div class="search-box">
        <input type="text" id="complaintSearch" placeholder="Search complaints..." style="padding: 8px; width: 300px; margin-right: 10px;">
        <button type="button" id="clearSearch" class="btn">Clear</button>
    </div>

    <table id="complaintsTable">
        <tr>
            <th>ID</th>
            <th>User</th>
            <th>Type</th>
            <th>Related ID</th>
            <th>Description</th>
            <th>Actions</th>
        </tr>
        
        <?php if ($complaints->num_rows > 0): ?>
            <?php while($complaint = $complaints->fetch_assoc()): ?>
            <tr class="complaint-row">
                <td>C-<?= $complaint['Complaint_id'] ?></td>
                <td><?= $complaint['user_name'] ?><br><?= $complaint['user_email'] ?></td>
                <td><?= $complaint['Complaint_type'] == 'E' ? 'Equipment' : 'Product' ?></td>
                <td><?= $complaint['Complaint_type'] == 'E' ? 'EQ-' : 'P-' ?><?= $complaint['ID'] ?></td>
                <td><?= substr($complaint['Description'], 0, 50) ?>...</td>
                <td>
                    <?php if ($complaint['Status'] == 'PEN'): ?>
                        <a href="?action=start&id=<?= $complaint['Complaint_id'] ?>">Start Work</a>
                    <?php elseif ($complaint['Status'] == 'INP'): ?>
                        <a href="?action=solve&id=<?= $complaint['Complaint_id'] ?>">Solve</a>
                    <?php else: ?>
                        <span style="color: green;">Solved</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="6">No complaints found</td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<style>
.complaint-row.hidden {
    display: none !important;
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
    $('#complaintSearch').on('keyup', function() {
        var searchTerm = $(this).val().toLowerCase();
        
        $('#complaintsTable tr.complaint-row').each(function() {
            var rowText = $(this).text().toLowerCase();
            
            if (rowText.indexOf(searchTerm) === -1) {
                $(this).addClass('hidden');
            } else {
                $(this).removeClass('hidden');
            }
        });
    });
    
    $('#clearSearch').on('click', function() {
        $('#complaintSearch').val('');
        $('#complaintsTable tr.complaint-row').removeClass('hidden');
        $('#complaintSearch').focus();
    });
});
</script>

<?php require 'footer.php'; ?>
