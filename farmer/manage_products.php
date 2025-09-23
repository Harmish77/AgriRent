<?php
// manage_products.php
session_start();
require_once('../auth/config.php'); // must set $conn = new mysqli(...);

// Only farmers allowed
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'F') {
    header('Location: ../login.php');
    exit();
}

$farmer_id = (int) $_SESSION['user_id'];

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Make sure config.php sets \$conn = new mysqli(...);");
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$msg = "";
$errors = [];

/* -------------------
   Handle Delete Product
   ------------------- */
if (isset($_GET['delete'])) {
    $product_id = (int)$_GET['delete'];
    $sql = "DELETE FROM product WHERE product_id=? AND seller_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $product_id, $farmer_id);
    if ($stmt->execute()) {
        $msg = "Product deleted successfully!";
    } else {
        $errors[] = "Error deleting product: " . $conn->error;
    }
    $stmt->close();
}

/* -------------------
   Handle Status Filter
   ------------------- */
$status_filter = $_GET['status'] ?? 'all';
$valid_statuses = ['all', 'PEN', 'CON', 'REJ'];
if (!in_array($status_filter, $valid_statuses)) {
    $status_filter = 'all';
}

/* -------------------
   Fetch Products with Filter
   ------------------- */
$where_clause = "WHERE p.seller_id = ?";
$params = [$farmer_id];
$param_types = "i";

if ($status_filter !== 'all') {
    $where_clause .= " AND p.Approval_status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

$sql = "SELECT p.product_id, p.Name, p.Description, p.Price, p.Quantity, p.Unit, 
               p.Approval_status, p.listed_date, 
               s.Subcategory_name, c.Category_name
        FROM product p
        JOIN product_subcategories s ON s.Subcategory_id = p.Subcategory_id
        JOIN product_categories c ON c.Category_id = s.Category_id
        $where_clause 
        ORDER BY p.product_id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$products = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* -------------------
   Calculate Statistics
   ------------------- */
// Get all products for statistics (without filter)
$all_products_sql = "SELECT Approval_status, Price, Quantity FROM product WHERE seller_id = ?";
$all_stmt = $conn->prepare($all_products_sql);
$all_stmt->bind_param("i", $farmer_id);
$all_stmt->execute();
$all_result = $all_stmt->get_result();
$all_products = $all_result->fetch_all(MYSQLI_ASSOC);
$all_stmt->close();

// Calculate counts
$approved = array_filter($all_products, function($p) { return $p['Approval_status'] === 'CON'; });
$pending = array_filter($all_products, function($p) { return $p['Approval_status'] === 'PEN'; });
$rejected = array_filter($all_products, function($p) { return $p['Approval_status'] === 'REJ'; });

$approved_count = count($approved);
$pending_count = count($pending);
$rejected_count = count($rejected);

// Calculate revenue statistics
$total_inventory_value = array_sum(array_map(function($p) { 
    return $p['Price'] * $p['Quantity']; 
}, $approved));

$avg_price = count($approved) > 0 ? array_sum(array_column($approved, 'Price')) / count($approved) : 0;

// Get actual sales revenue from orders
$revenue_sql = "SELECT COALESCE(SUM(po.total_price), 0) as total_revenue, COUNT(*) as total_orders
                FROM product_orders po 
                JOIN product p ON po.Product_id = p.product_id 
                WHERE p.seller_id = ? AND po.Status = 'CON'";
$revenue_stmt = $conn->prepare($revenue_sql);
$revenue_stmt->bind_param("i", $farmer_id);
$revenue_stmt->execute();
$revenue_result = $revenue_stmt->get_result();
$revenue_data = $revenue_result->fetch_assoc();
$revenue_stmt->close();

$total_revenue = $revenue_data['total_revenue'] ?? 0;
$total_orders = $revenue_data['total_orders'] ?? 0;

// Status text function
function getStatusText($status) {
    switch($status) {
        case 'PEN': return '<span style="color: orange;">Pending</span>';
        case 'CON': return '<span style="color: green;">Approved</span>';
        case 'REJ': return '<span style="color: red;">Rejected</span>';
        default: return $status;
    }
}

include 'fheader.php';
include 'farmer_nav.php';
?>

<link rel="stylesheet" href="farmer.css">

<div class="main-content">
    <h1>Manage Products</h1>
    <p>View, edit, and manage all your agricultural product listings</p>

    <?php if ($msg): ?>
        <div class="message"><?= e($msg) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach($errors as $error): ?>
                <p><?= e($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Filter Controls -->
    <div class="search-box">
        <form method="GET">
            <label for="status">Filter by Status:</label>
            <select name="status" id="status" onchange="this.form.submit()">
                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Products</option>
                <option value="PEN" <?= $status_filter === 'PEN' ? 'selected' : '' ?>>Pending</option>
                <option value="CON" <?= $status_filter === 'CON' ? 'selected' : '' ?>>Approved</option>
                <option value="REJ" <?= $status_filter === 'REJ' ? 'selected' : '' ?>>Rejected</option>
            </select>
            <a href="add_product.php" class="btn" style="margin-left: 20px;">➕ Add New Product</a>
        </form>
    </div>

    <!-- Products Table -->
    <?php if (!empty($products)): ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Quantity</th>
                    <th>Unit</th>
                    <th>Status</th>
                    <th>Listed Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($products as $p): ?>
                    <tr>
                        <td><?= e($p['product_id']) ?></td>
                        <td>
                            <strong><?= e($p['Name']) ?></strong>
                            <?php if (strlen($p['Description']) > 50): ?>
                                <br><small><?= e(substr($p['Description'], 0, 50)) ?>...</small>
                            <?php endif; ?>
                        </td>
                        <td><?= e($p['Category_name']) ?><br><small><?= e($p['Subcategory_name']) ?></small></td>
                        <td>₹<?= number_format($p['Price'], 2) ?></td>
                        <td><?= number_format($p['Quantity'], 2) ?></td>
                        <td><?= e($p['Unit']) === 'K' ? 'Kg' : (e($p['Unit']) === 'L' ? 'Liter' : 'Piece') ?></td>
                        <td><?= getStatusText($p['Approval_status']) ?></td>
                        <td><?= date('M j, Y', strtotime($p['listed_date'])) ?></td>
                        <td>
                            <a href="view_product.php?id=<?= e($p['product_id']) ?>">View</a> | 
                            <a href="edit_product.php?id=<?= e($p['product_id']) ?>">Edit</a> | 
                            <a href="?delete=<?= e($p['product_id']) ?>" onclick="return confirm('Delete this product?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="message">
            <h3><?= $status_filter !== 'all' ? 'No products found with status: ' . strtoupper($status_filter) : 'No Products Found' ?></h3>
            <p><?= $status_filter !== 'all' ? 'Try changing the filter or add new products.' : 'Start by adding your first agricultural product.' ?></p>
            <a href="add_product.php" class="btn">➕ Add Your First Product</a>
        </div>
    <?php endif; ?>

    <!-- Product Summary -->
    <div class="cards">
        <div class="card">
            <h3>Product Summary</h3>
            <div class="status-list">
                <div><strong><?= count($all_products) ?></strong> Total Products</div>
                <div><strong><?= $approved_count ?></strong> Approved Products</div>
                <div><strong><?= $pending_count ?></strong> Pending Products</div>
                <div><strong><?= $rejected_count ?></strong> Rejected Products</div>
            </div>
        </div>

        <div class="card">
            <h3>Revenue Information</h3>
            <div class="status-list">
                <div><strong>₹<?= number_format($total_revenue, 2) ?></strong> Total Sales Revenue</div>
                <div><strong>₹<?= number_format($total_inventory_value, 2) ?></strong> Total Inventory Value</div>
                <div><strong>₹<?= number_format($avg_price, 2) ?></strong> Average Product Price</div>
                <div><strong><?= $total_orders ?></strong> Total Orders Received</div>
            </div>
        </div>

        
    </div>
</div>

<?php include 'ffooter.php'; ?>

<style>
.search-box {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    margin-bottom: 20px;
}

.search-box form {
    display: flex;
    align-items: center;
    gap: 15px;
}

.search-box select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    overflow: hidden;
    margin-bottom: 30px;
}

th, td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid #eee;
    vertical-align: top;
}

th {
    background: #f8f9fa;
    font-weight: 600;
    color: #234a23;
}

tr:hover {
    background: #f8f9fa;
}

.cards {
    display: flex;
    flex-wrap: wrap;
    gap: 30px;
    margin-top: 50px;
    
}

.card {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    padding: 20px;
    flex: 1 1 300px;
    min-width: 300px;
    
}

.card h3 {
    margin-bottom: 15px;
    color: #4a7c59;
    font-weight: 700;
    font-size: 1.1rem;
}

.status-list {
    font-size: 17px;
    text-align: justify;
}

.status-list div {
    margin-bottom: 8px;
    padding: 5px 0;
    border-bottom: 1px solid #f0f0f0;
}

.status-list div:last-child {
    border-bottom: none;
}

.message {
    background: #d4edda;
    color: #155724;
    padding: 20px;
    border-radius: 6px;
    margin-bottom: 20px;
    border: 1px solid #c3e6cb;
    text-align: center;
}

.error {
    background: #f8d7da;
    color: #721c24;
    padding: 15px 20px;
    border-radius: 6px;
    margin-bottom: 20px;
    border: 1px solid #f5c6cb;
}

.btn {
    background: #234a23;
    color: #fff;
    padding: 8px 16px;
    text-decoration: none;
    border-radius: 4px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    display: inline-block;
    transition: background-color 0.3s ease;
}

.btn:hover {
    background: #4a7c59;
    color: #fff;
}

@media (max-width: 768px) {
    .search-box form {
        flex-direction: column;
        align-items: stretch;
    }
    
    .cards {
        flex-direction: column;
    }
    
    .card {
        min-width: auto;
    }
    
    table {
        font-size: 14px;
    }
    
    th, td {
        padding: 8px 6px;
    }
}
</style>
