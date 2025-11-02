<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Farmer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'F') {
    header('Location: ../login.php');
    exit();
}

$farmer_id = $_SESSION['user_id'];
$product_id = intval($_GET['id'] ?? 0);

if ($product_id <= 0) {
    header('Location: manage_products.php');
    exit();
}

// Fetch product data with category information and image
$product = null;
$sql = "
    SELECT p.*, 
           c.Category_name,
           s.Subcategory_name,
           i.image_url
    FROM product p
    LEFT JOIN product_subcategories s ON p.Subcategory_id = s.Subcategory_id
    LEFT JOIN product_categories c ON s.Category_id = c.Category_id
    LEFT JOIN images i ON (i.image_type = 'P' AND i.ID = p.product_id)
    WHERE p.product_id = ? AND p.seller_id = ?
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("ii", $product_id, $farmer_id);
$stmt->execute();
$result = $stmt->get_result();
$product = $result->fetch_assoc();
$stmt->close();

if (!$product) {
    header('Location: manage_products.php');
    exit();
}

// Fetch order history for this product
$orders = [];
$order_sql = "
    SELECT po.*, u.Name as customer_name 
    FROM product_orders po
    JOIN users u ON po.customer_id = u.user_id
    WHERE po.Product_id = ?
    ORDER BY po.order_id DESC
    LIMIT 10
";

$order_stmt = $conn->prepare($order_sql);
if ($order_stmt) {
    $order_stmt->bind_param("i", $product_id);
    $order_stmt->execute();
    $order_result = $order_stmt->get_result();
    while ($order = $order_result->fetch_assoc()) {
        $orders[] = $order;
    }
    $order_stmt->close();
}

require 'fheader.php';
require 'farmer_nav.php';
?>

<link rel="stylesheet" href="../admin.css">

<div class="main-content">
    <div class="page-header">
        <div class="header-left">
            <h1>Product Details</h1>
            <p style="color: #666; margin-bottom: 0;">Detailed view of your product listing</p>
        </div>
        <div class="header-right">
            <a href="manage_products.php" class="btn-secondary">← Back to List</a>
            <a href="edit_product.php?id=<?= $product_id ?>" class="btn-primary">Edit Product</a>
        </div>
    </div>

    <div class="content-grid">
        <!-- Product Image Section -->
        <?php if (!empty($product['image_url'])): ?>
        <div class="content-card">
            <h3>Product Photo</h3>
            <div class="image-container">
                <img src="../<?= htmlspecialchars($product['image_url']) ?>" 
                     alt="<?= htmlspecialchars($product['Name']) ?>" 
                     class="main-image"
                     onclick="openImageModal(this)">
                <p class="image-caption">Click image to view full size</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Product Information -->
        <div class="content-card">
            <h3>Product Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Name:</span>
                    <span class="detail-value"><?= htmlspecialchars($product['Name']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Category:</span>
                    <span class="detail-value"><?= htmlspecialchars($product['Category_name'] ?? 'Not specified') ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Subcategory:</span>
                    <span class="detail-value"><?= htmlspecialchars($product['Subcategory_name'] ?? 'Not specified') ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Unit:</span>
                    <span class="detail-value"><?= $product['Unit'] === 'K' ? 'Kg' : ($product['Unit'] === 'L' ? 'Liter' : 'Piece') ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Available Quantity:</span>
                    <span class="detail-value"><?= number_format($product['Quantity'], 2) ?> <?= $product['Unit'] === 'K' ? 'Kg' : ($product['Unit'] === 'L' ? 'Liter' : 'Piece') ?></span>
                </div>
                <div class="detail-item description">
                    <span class="detail-label">Description:</span>
                    <div class="detail-value"><?= nl2br(htmlspecialchars($product['Description'])) ?></div>
                </div>
            </div>
        </div>

        <!-- Pricing and Status -->
        <div class="content-card">
            <h3>Pricing & Status</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <span class="detail-label">Price per Unit:</span>
                    <span class="detail-value price">₹<?= number_format($product['Price'], 2) ?> per <?= $product['Unit'] === 'K' ? 'Kg' : ($product['Unit'] === 'L' ? 'Liter' : 'Piece') ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Total Inventory Value:</span>
                    <span class="detail-value price">₹<?= number_format($product['Price'] * $product['Quantity'], 2) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <?php
                        $status_map = [
                            'CON' => ['status-approved', 'Approved'],
                            'PEN' => ['status-pending', 'Pending Admin Approval'],
                            'REJ' => ['status-rejected', 'Rejected by Admin']
                        ];
                        list($status_class, $status_text) = $status_map[$product['Approval_status']] ?? ['status-pending', 'Unknown'];
                        ?>
                        <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Listed Date:</span>
                    <span class="detail-value"><?= date('M j, Y g:i A', strtotime($product['listed_date'])) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Orders Section -->
    <div class="content-card" style="margin-top: 30px;">
        <h3>Recent Order History</h3>
        <?php if (count($orders) > 0): ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Order Date</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): 
                            $order_status_map = [
                                'CON' => ['status-approved', 'Confirmed'],
                                'PEN' => ['status-pending', 'Pending'],
                                'REJ' => ['status-rejected', 'Rejected'],
                                'DEL' => ['status-approved', 'Delivered'],
                                'CAN' => ['status-rejected', 'Cancelled']
                            ];
                            list($order_status_class, $order_status_text) = $order_status_map[$order['Status']] ?? ['status-pending', 'Unknown'];
                            $unit_text = $product['Unit'] === 'K' ? 'Kg' : ($product['Unit'] === 'L' ? 'Liter' : 'Piece');
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($order['customer_name']) ?></td>
                                <td><?= date('M j, Y', strtotime($order['order_date'])) ?></td>
                                <td><?= number_format($order['quantity'], 2) ?> <?= $unit_text ?></td>
                                <td>₹<?= number_format($order['unit_price'], 2) ?></td>
                                <td>₹<?= number_format($order['total_price'], 2) ?></td>
                                <td><span class="status-badge <?= $order_status_class ?>"><?= $order_status_text ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-shopping-cart" style="font-size: 3rem; color: #ccc; margin-bottom: 15px;"></i>
                <h4>No Order History</h4>
                <p>No orders have been placed for this product yet.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Image Modal -->
<div id="imageModal" class="modal" style="display: none;">
    <span class="close" onclick="closeImageModal()">&times;</span>
    <img class="modal-content" id="modalImage">
</div>

<style>
/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #f0f0f0;
}

.header-left h1 {
    margin: 0 0 5px 0;
    color: #234a23;
    font-size: 2rem;
}

.header-right {
    display: flex;
    gap: 10px;
    flex-shrink: 0;
}

/* Buttons */
.btn-primary, .btn-secondary {
    padding: 12px 24px;
    text-decoration: none;
    border-radius: 6px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
    border: none;
    cursor: pointer;
}

.btn-primary {
    background: #234a23;
    color: white;
}

.btn-primary:hover {
    background: #1a3a1a;
    transform: translateY(-1px);
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
    transform: translateY(-1px);
}

/* Content Grid */
.content-grid {
    display: grid;
    gap: 30px;
    margin-bottom: 30px;
}

.content-card {
    background: white;
    border-radius: 8px;
    padding: 25px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    border: 1px solid #e9ecef;
}

.content-card h3 {
    color: #234a23;
    margin-bottom: 20px;
    font-size: 1.3rem;
    font-weight: 600;
}

/* Image Container */
.image-container {
    text-align: center;
    padding: 20px;
}

.main-image {
    max-width: 100%;
    height: auto;
    max-height: 400px;
    border: 1px solid #ddd;
    border-radius: 8px;
    cursor: pointer;
    transition: transform 0.2s ease;
}

.main-image:hover {
    transform: scale(1.02);
}

.image-caption {
    text-align: center;
    margin-top: 10px;
    color: #666;
    font-size: 12px;
}

/* Detail Grid */
.detail-grid {
    display: grid;
    gap: 15px;
}

.detail-item {
    display: flex;
    align-items: flex-start;
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f0;
}

.detail-item:last-child {
    border-bottom: none;
}

.detail-item.description {
    flex-direction: column;
    align-items: stretch;
}

.detail-item.description .detail-value {
    margin-top: 10px;
    line-height: 1.6;
}

.detail-label {
    font-weight: 600;
    color: #234a23;
    min-width: 180px;
    flex-shrink: 0;
}

.detail-value {
    flex: 1;
    color: #495057;
}

.detail-value.price {
    font-weight: 600;
    color: #28a745;
    font-size: 1.1rem;
}

/* Status Badges */
.status-badge {
    padding: 6px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-approved {
    background: #d4edda;
    color: #155724;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-rejected {
    background: #f8d7da;
    color: #721c24;
}

/* Table */
.table-responsive {
    overflow-x: auto;
    margin-top: 20px;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: white;
}

th, td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

th {
    background: #f8f9fa;
    font-weight: 600;
    color: #234a23;
}

tr:hover {
    background: #f8f9fa;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #666;
}

.empty-state h4 {
    margin-bottom: 10px;
    color: #495057;
}

/* Modal */
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

/* Responsive */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 15px;
        align-items: stretch;
    }
    
    .header-right {
        flex-direction: column;
    }
    
    .btn-primary, .btn-secondary {
        text-align: center;
        justify-content: center;
    }
    
    .detail-label {
        min-width: auto;
        margin-bottom: 5px;
    }
    
    .detail-item:not(.description) {
        flex-direction: column;
        align-items: stretch;
    }
    
    .main-image {
        max-height: 250px;
    }
    
    table {
        font-size: 12px;
    }
    
    th, td {
        padding: 8px 6px;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
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

<?php 
    require 'ffooter.php';
?>
