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
        $conn->query("UPDATE product SET Approval_status='CON' WHERE product_id=$id");
        $message = "Product approved";
    } elseif ($action == 'reject') {
        $conn->query("UPDATE product SET Approval_status='REJ' WHERE product_id=$id");
        $message = "Product rejected";
    } elseif ($action == 'delete') {
        $conn->query("DELETE FROM product WHERE product_id=$id");
        $message = "Product deleted";
    }
}

$status = isset($_GET['status']) ? $_GET['status'] : 'PEN';
$search = isset($_GET['search']) ? $_GET['search'] : '';

$where = "WHERE p.Approval_status = '$status'";
if ($search) {
    $search = $conn->real_escape_string($search);
    $where .= " AND (p.Name LIKE '%$search%' OR u.Name LIKE '%$search%')";
}

$products = $conn->query("SELECT p.*, u.Name as seller_name FROM product p JOIN users u ON p.seller_id = u.user_id $where ORDER BY p.listed_date DESC");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Products</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <div class="tabs">
        <a href="?status=PEN" class="tab <?= $status == 'PEN' ? 'active' : '' ?>">Waiting</a>
        <a href="?status=CON" class="tab <?= $status == 'CON' ? 'active' : '' ?>">Approved</a>
        <a href="?status=REJ" class="tab <?= $status == 'REJ' ? 'active' : '' ?>">Rejected</a>
    </div>

    <div class="search-box">
        <form method="GET">
            <input type="hidden" name="status" value="<?= $status ?>">
            <input type="text" name="search" placeholder="Search products..." value="<?= $search ?>">
            <button type="submit" class="btn">Search</button>
            <a href="?status=<?= $status ?>" class="btn">Clear</a>
        </form>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Seller</th>
            <th>Price</th>
            <th>Quantity</th>
            <th>Date Added</th>
            <th>Actions</th>
        </tr>
        
        <?php if ($products->num_rows > 0): ?>
            <?php while($product = $products->fetch_assoc()): ?>
            <tr>
                <td>P-<?= $product['product_id'] ?></td>
                <td><?= $product['Name'] ?></td>
                <td><?= $product['seller_name'] ?></td>
                <td>Rs.<?= $product['Price'] ?></td>
                <td><?= $product['Quantity'] ?> <?= $product['Unit'] == 'K' ? 'kg' : 'liter' ?></td>
                <td><?= date('M d, Y', strtotime($product['listed_date'])) ?></td>
                <td>
                    <?php if ($product['Approval_status'] == 'PEN'): ?>
                        <a href="?action=approve&id=<?= $product['product_id'] ?>&status=<?= $status ?>">Approve</a><br>
                        <a href="?action=reject&id=<?= $product['product_id'] ?>&status=<?= $status ?>">Reject</a><br>
                    <?php endif; ?>
                    <a href="?action=delete&id=<?= $product['product_id'] ?>&status=<?= $status ?>" onclick="return confirm('Delete this product?')">Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="7">No products found</td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<?php require 'footer.php'; ?>