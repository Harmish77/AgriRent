<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = $_GET['id'];
    
    if ($action == 'approve') {
        $conn->query("UPDATE equipment SET Approval_status='CON' WHERE Equipment_id=$id");
        $message = "Equipment approved";
    } elseif ($action == 'reject') {
        $conn->query("UPDATE equipment SET Approval_status='REJ' WHERE Equipment_id=$id");
        $message = "Equipment rejected";
    } elseif ($action == 'delete') {
        $conn->query("DELETE FROM equipment WHERE Equipment_id=$id");
        $message = "Equipment deleted";
    }
}

$status = isset($_GET['status']) ? $_GET['status'] : 'PEN';
$search = isset($_GET['search']) ? $_GET['search'] : '';

$where = "WHERE e.Approval_status = '$status'";
if ($search) {
    $search = $conn->real_escape_string($search);
    $where .= " AND (e.Title LIKE '%$search%' OR e.Brand LIKE '%$search%' OR u.Name LIKE '%$search%')";
}

// Updated query to include image
$equipment = $conn->query("SELECT e.*, u.Name as owner_name, i.image_url 
                          FROM equipment e 
                          JOIN users u ON e.Owner_id = u.user_id 
                          LEFT JOIN images i ON (i.image_type = 'E' AND i.ID = e.Equipment_id)
                          $where 
                          ORDER BY e.listed_date DESC");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Equipment</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>
    
    <div class="tabs">
        <a href="?status=PEN" class="tab <?= $status == 'PEN' ? 'active' : '' ?>">Pending</a>
        <a href="?status=CON" class="tab <?= $status == 'CON' ? 'active' : '' ?>">Approved</a>
        <a href="?status=REJ" class="tab <?= $status == 'REJ' ? 'active' : '' ?>">Rejected</a>
    </div>
    
    <div class="search-box">
        <form method="GET">
            <input type="hidden" name="status" value="<?= $status ?>">
            <input type="text" name="search" placeholder="Search equipment..." value="<?= $search ?>">
            <button type="submit" class="btn">Search</button>
            <a href="?status=<?= $status ?>" class="btn">Clear</a>
        </form>
    </div>
    
    <table>
        <tr>
            <th>ID</th>
            <th>Image</th>
            <th>Title</th>
            <th>Owner</th>
            <th>Brand/Model</th>
            <th>Rates</th>
            <th>Date Added</th>
            <th>Actions</th>
        </tr>
        
        <?php if ($equipment->num_rows > 0): ?>
            <?php while($item = $equipment->fetch_assoc()): ?>
            <tr>
                <td><?= $item['Equipment_id'] ?></td>
                <td>
                    <?php if (!empty($item['image_url'])): ?>
                        <img class="equipment-thumb" 
                             src="../<?= htmlspecialchars($item['image_url']) ?>" 
                             alt="Equipment Image" 
                             style="width:60px; height:60px; object-fit:cover; border:1px solid #ddd; cursor:pointer;"
                             onclick="openImageModal(this)">
                    <?php else: ?>
                        No Image
                    <?php endif; ?>
                </td>
                <td><?= $item['Title'] ?></td>
                <td><?= $item['owner_name'] ?></td>
                <td><?= $item['Brand'] ?> <?= $item['Model'] ?></td>
                <td>
                    Rs.<?= $item['Hourly_rate'] ?>/hr<br>
                    Rs.<?= $item['Daily_rate'] ?>/day
                </td>
                <td><?= date('M d, Y', strtotime($item['listed_date'])) ?></td>
                <td>
                    <?php if ($item['Approval_status'] == 'PEN'): ?>
                        <a href="?action=approve&id=<?= $item['Equipment_id'] ?>&status=<?= $status ?>">Approve</a> |
                        <a href="?action=reject&id=<?= $item['Equipment_id'] ?>&status=<?= $status ?>">Reject</a> |
                    <?php endif; ?>
                    <a href="?action=delete&id=<?= $item['Equipment_id'] ?>&status=<?= $status ?>" onclick="return confirm('Delete this equipment?')">Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="8">No equipment found</td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<!-- Image Modal -->
<div id="imageModal" class="modal" style="display: none;">
    <span class="close" onclick="closeImageModal()">&times;</span>
    <img class="modal-content" id="modalImage">
</div>

<style>
/* Modal Styles */
.modal {
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.9);
}

.modal-content {
    margin: auto;
    display: block;
    max-width: 90%;
    max-height: 80%;
    margin-top: 5%;
}

.close {
    position: absolute;
    top: 20px;
    right: 35px;
    color: #fff;
    font-size: 40px;
    font-weight: bold;
    cursor: pointer;
}

.close:hover {
    color: #bbb;
}

@media only screen and (max-width: 700px) {
    .modal-content {
        width: 100%;
    }
}
</style>

<script>
function openImageModal(img) {
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    
    modal.style.display = "block";
    modalImg.src = img.src;
}

function closeImageModal() {
    document.getElementById('imageModal').style.display = "none";
}

// Close modal when clicking outside the image
window.onclick = function(event) {
    const modal = document.getElementById('imageModal');
    if (event.target == modal) {
        modal.style.display = "none";
    }
}
</script>

<?php require 'footer.php'; ?>
