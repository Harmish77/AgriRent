<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

$status = isset($_GET['status']) ? $_GET['status'] : 'PEN';

$orders = $conn->query("
    SELECT po.*, p.Name as product_name, p.Unit,
           buyer.Name as buyer_name, buyer.Email as buyer_email,
           seller.Name as seller_name
    FROM product_orders po
    JOIN product p ON po.Product_id = p.product_id
    JOIN users buyer ON po.buyer_id = buyer.user_id
    JOIN users seller ON p.seller_id = seller.user_id
    WHERE po.Status = '$status'
    ORDER BY po.order_date DESC
");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Orders (View Only)</h1>

    <div class="tabs">
        <a href="?status=PEN" class="tab <?= $status == 'PEN' ? 'active' : '' ?>">Waiting</a>
        <a href="?status=CON" class="tab <?= $status == 'CON' ? 'active' : '' ?>">Confirmed</a>
        <a href="?status=CAN" class="tab <?= $status == 'CAN' ? 'active' : '' ?>">Cancelled</a>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>Product</th>
            <th>Buyer</th>
            <th>Seller</th>
            <th>Quantity</th>
            <th>Total Price</th>
            <th>Order Date</th>
            <th>Status</th>
        </tr>
        
        <?php if ($orders->num_rows > 0): ?>
            <?php while($order = $orders->fetch_assoc()): ?>
            <tr>
                <td>O-<?= $order['Order_id'] ?></td>
                <td><?= $order['product_name'] ?></td>
                <td>
                    <?= $order['buyer_name'] ?><br>
                    <small><?= $order['buyer_email'] ?></small>
                </td>
                <td><?= $order['seller_name'] ?></td>
                <td><?= $order['quantity'] ?> <?= $order['Unit'] == 'K' ? 'kg' : 'liter' ?></td>
                <td>Rs.<?= $order['total_price'] ?></td>
                <td><?= date('M d, Y', strtotime($order['order_date'])) ?></td>
                <td>
                    <?php if ($order['Status'] == 'PEN'): ?>
                        <span style="color: orange;">Waiting</span>
                    <?php elseif ($order['Status'] == 'CON'): ?>
                        <span style="color: green;">Confirmed</span>
                    <?php else: ?>
                        <span style="color: red;">Cancelled</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="8">No orders found</td>
            </tr>
        <?php endif; ?>
    </table>
    
    <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 5px;">
        <strong>Note:</strong> Admin can only view orders. Product sellers and buyers handle order processing directly.
    </div>
</div>

<?php require 'footer.php'; ?>
