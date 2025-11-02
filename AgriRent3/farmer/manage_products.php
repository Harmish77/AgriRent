<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Farmer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'F') {
    header('Location: ../login.php');
    exit();
}

$farmer_id = $_SESSION['user_id'];
$message = '';

// Handle product deletion
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // Delete from images table first
    $conn->query("DELETE FROM images WHERE image_type='P' AND ID=$delete_id");
    
    // Delete product
    if ($conn->query("DELETE FROM product WHERE product_id=$delete_id AND seller_id=$farmer_id")) {
        $message = 'Product deleted successfully.';
    } else {
        $message = 'Error deleting product.';
    }
}

// Filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build WHERE clause
$where_clause = "WHERE p.seller_id = ?";
$params = [$farmer_id];
$param_types = "i";

if ($status_filter !== 'all') {
    $where_clause .= " AND p.Approval_status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

// Add search functionality
if (!empty($search_query)) {
    $where_clause .= " AND (p.Name LIKE ? OR p.Description LIKE ?)";
    $search_term = "%" . $search_query . "%";
    $params = array_merge($params, [$search_term, $search_term]);
    $param_types .= "ss";
}

// Fetch products with images and category info
$sql = "SELECT p.*, i.image_url, c.Category_name, s.Subcategory_name 
        FROM product p 
        LEFT JOIN images i ON (i.image_type = 'P' AND i.ID = p.product_id)
        LEFT JOIN product_subcategories s ON p.Subcategory_id = s.Subcategory_id
        LEFT JOIN product_categories c ON s.Category_id = c.Category_id
        $where_clause 
        ORDER BY p.listed_date DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param($param_types, ...$params);
if (!$stmt->execute()) {
    die("Execute failed: " . $stmt->error);
}

$result = $stmt->get_result();

$products_list = [];
while ($row = $result->fetch_assoc()) {
    $products_list[] = $row;
}
$stmt->close();

// CORRECTED Helper function to convert unit code to display text
function getUnitDisplay($unit) {
    if (empty($unit)) return 'Piece';
    $units = ['K' => 'Kg', 'L' => 'Liter', 'P' => 'Piece'];
    return $units[strtoupper(trim($unit))] ?? 'Piece';
}

require 'fheader.php';
require 'farmer_nav.php';
?>

<link rel="stylesheet" href="../admin.css">

<div class="main-content">
    <div class="page-header">
        <div class="header-left">
            <h1>Manage Products</h1>
            <p style="color: #666; margin-bottom: 0;">View, edit, and manage all your agricultural product listings</p>
        </div>
        <div class="header-right">
            <a href="add_product.php" class="btn-primary">
               Add New Product
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Search and Filter Section -->
    <div class="controls-section">
        <!-- Search Bar -->
        <div class="search-container">
            <form method="GET" class="search-form">
                <input type="text" 
                       name="search" 
                       id="productSearch"
                       placeholder="Search by name, description, or category..." 
                       value="<?= htmlspecialchars($search_query) ?>"
                       class="search-input">
                <button type="submit" class="search-btn">
                    <i class="icon"></i> Search
                </button>
                <?php if (!empty($search_query)): ?>
                    <a href="?<?= $status_filter !== 'all' ? 'status=' . $status_filter : '' ?>" class="clear-search">Clear</a>
                <?php endif; ?>
                <!-- Preserve status filter in search -->
                <?php if ($status_filter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?= $status_filter ?>">
                <?php endif; ?>
            </form>
        </div>

        <!-- Filter Tabs -->
        <div class="tabs">
            <a href="?<?= !empty($search_query) ? 'search=' . urlencode($search_query) . '&' : '' ?>status=all" 
               class="tab <?= $status_filter == 'all' ? 'active' : '' ?>">All Products</a>
            <a href="?<?= !empty($search_query) ? 'search=' . urlencode($search_query) . '&' : '' ?>status=PEN" 
               class="tab <?= $status_filter == 'PEN' ? 'active' : '' ?>">Pending</a>
            <a href="?<?= !empty($search_query) ? 'search=' . urlencode($search_query) . '&' : '' ?>status=CON" 
               class="tab <?= $status_filter == 'CON' ? 'active' : '' ?>">Approved</a>
            <a href="?<?= !empty($search_query) ? 'search=' . urlencode($search_query) . '&' : '' ?>status=REJ" 
               class="tab <?= $status_filter == 'REJ' ? 'active' : '' ?>">Rejected</a>
        </div>
    </div>

    <!-- Results Summary -->
    <?php if (!empty($search_query)): ?>
        <div class="search-results-info">
            Found <?= count($products_list) ?> product(s) matching "<?= htmlspecialchars($search_query) ?>"
        </div>
    <?php endif; ?>

    <?php if (count($products_list) > 0): ?>
        <table id="productTable">
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Product Name</th>
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
                <?php foreach ($products_list as $product): ?>
                    <?php
                    $status_text = '';
                    $status_class = '';
                    switch ($product['Approval_status']) {
                        case 'PEN':
                            $status_text = 'Pending';
                            $status_class = 'status-pending';
                            break;
                        case 'CON':
                            $status_text = 'Approved';
                            $status_class = 'status-approved';
                            break;
                        case 'REJ':
                            $status_text = 'Rejected';
                            $status_class = 'status-rejected';
                            break;
                    }
                    
                    // Use helper function for unit display
                    $unit_text = getUnitDisplay($product['Unit']);
                    ?>
                    <tr class="product-row">
                        <td>
                            <?php if (!empty($product['image_url'])): ?>
                                <img src="../<?= htmlspecialchars($product['image_url']) ?>" 
                                     alt="Product Image" 
                                     style="width:60px; height:60px; object-fit:cover; border:1px solid #ddd; cursor:pointer;"
                                     onclick="openImageModal(this)">
                            <?php else: ?>
                                <div style="width:60px; height:60px; background:#f5f5f5; display:flex; align-items:center; justify-content:center; border:1px solid #ddd; font-size:10px; text-align:center;">
                                    No Image
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($product['Name']) ?></strong>
                            <?php if (strlen($product['Description']) > 50): ?>
                                <br><small style="color: #666;"><?= htmlspecialchars(substr($product['Description'], 0, 50)) ?>...</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($product['Category_name'] ?? 'N/A') ?>
                            <?php if (!empty($product['Subcategory_name'])): ?>
                                <br><small style="color: #666;"><?= htmlspecialchars($product['Subcategory_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>₹<?= number_format($product['Price'], 2) ?></td>
                        <td><?= number_format($product['Quantity'], 2) ?></td>
                        <td><?= $unit_text ?></td>
                        <td><span class="<?= $status_class ?>"><?= $status_text ?></span></td>
                        <td><?= date('M j, Y', strtotime($product['listed_date'])) ?></td>
                        <td>
                            <button type="button" class="view-btn" onclick="viewProduct(<?= htmlspecialchars(json_encode($product)) ?>)">View</button> |
                            <a href="edit_product.php?id=<?= $product['product_id'] ?>" style="color: #28a745; text-decoration: none;">Edit</a> | 
                            <a href="?delete_id=<?= $product['product_id'] ?>&status=<?= $status_filter ?><?= !empty($search_query) ? '&search=' . urlencode($search_query) : '' ?>" 
                               style="color: #dc3545; text-decoration: none;" 
                               onclick="return confirm('Are you sure you want to delete this product?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-equipment">
            <?php if (!empty($search_query)): ?>
                <h3>No Products Found</h3>
                <p>No products match your search "<?= htmlspecialchars($search_query) ?>"</p>
                <p>Try searching with different keywords or <a href="?<?= $status_filter !== 'all' ? 'status=' . $status_filter : '' ?>">clear your search</a></p>
            <?php elseif ($status_filter !== 'all'): ?>
                <h3>No products found with status: <?= strtoupper($status_filter) ?></h3>
                <p>Try changing the filter or add new products.</p>
            <?php else: ?>
                <h3>No Products Found</h3>
            <?php endif; ?>
            <a href="add_product.php" class="btn-primary"> Add Your First Product</a>
        </div>
    <?php endif; ?>
</div>

<!-- Image Modal -->
<div id="imageModal" class="modal" style="display: none;">
    <span class="close" onclick="closeImageModal()">&times;</span>
    <img class="modal-content" id="modalImage">
</div>

<!-- Product Details Modal (Admin Style) -->
<div id="productModal" class="modal" style="display: none;">
    <div class="modal-content-large" style="position: relative;">
        <span class="close" onclick="closeProductModal()" style="position: absolute; top: 15px; right: 25px; z-index: 1001;">&times;</span>
        
        <h2 style="background: #234a23; color: white; padding: 20px; margin: 0; border-radius: 8px 8px 0 0;">Product Details</h2>

        <div style="padding: 20px;">
            <!-- Product Image -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #234a23; margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px;">Product Image</h3>
                <div style="text-align: center; background: #f8f9fa; padding: 15px; border-radius: 6px;">
                    <img id="detailProductImage" src="" alt="Product" style="max-width: 100%; max-height: 350px; border-radius: 6px; object-fit: cover;">
                </div>
            </div>

            <!-- Product Information -->
            <div style="margin-bottom: 25px;">
                <h3 style="color: #234a23; margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px;">Product Information</h3>
                
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 10px; background: #f8f9fa; border: 1px solid #eee; font-weight: bold; color: #234a23; width: 30%;">Product ID</td>
                        <td style="padding: 10px; border: 1px solid #eee;" id="detailProductId"></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; background: #f8f9fa; border: 1px solid #eee; font-weight: bold; color: #234a23; width: 30%;">Product Name</td>
                        <td style="padding: 10px; border: 1px solid #eee;" id="detailProductName"></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; background: #f8f9fa; border: 1px solid #eee; font-weight: bold; color: #234a23; width: 30%;">Description</td>
                        <td style="padding: 10px; border: 1px solid #eee;" id="detailProductDescription"></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; background: #f8f9fa; border: 1px solid #eee; font-weight: bold; color: #234a23; width: 30%;">Category</td>
                        <td style="padding: 10px; border: 1px solid #eee;" id="detailProductCategory"></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; background: #f8f9fa; border: 1px solid #eee; font-weight: bold; color: #234a23; width: 30%;">Subcategory</td>
                        <td style="padding: 10px; border: 1px solid #eee;" id="detailProductSubcategory"></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; background: #f8f9fa; border: 1px solid #eee; font-weight: bold; color: #234a23; width: 30%;">Price per Unit</td>
                        <td style="padding: 10px; border: 1px solid #eee;" id="detailProductPrice"></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; background: #f8f9fa; border: 1px solid #eee; font-weight: bold; color: #234a23; width: 30%;">Quantity</td>
                        <td style="padding: 10px; border: 1px solid #eee;" id="detailProductQuantity"></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; background: #f8f9fa; border: 1px solid #eee; font-weight: bold; color: #234a23; width: 30%;">Unit</td>
                        <td style="padding: 10px; border: 1px solid #eee;" id="detailProductUnit"></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; background: #f8f9fa; border: 1px solid #eee; font-weight: bold; color: #234a23; width: 30%;">Status</td>
                        <td style="padding: 10px; border: 1px solid #eee;" id="detailProductStatus"></td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; background: #f8f9fa; border: 1px solid #eee; font-weight: bold; color: #234a23; width: 30%;">Listed Date</td>
                        <td style="padding: 10px; border: 1px solid #eee;" id="detailProductDate"></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Modal Actions -->
        <div style="padding: 15px 20px; border-top: 1px solid #eee; text-align: center; background: #f8f9fa; border-radius: 0 0 8px 8px;">
            <button type="button" onclick="location.href='edit_product.php?id=' + currentProductId" style="background: #234a23; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; margin-right: 10px;">Edit Product</button>
            <button type="button" onclick="closeProductModal()" style="background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Close</button>
        </div>
    </div>
</div>

<style>
/* Page Header Styles */
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
}

.header-right {
    flex-shrink: 0;
}

/* Button Styles */
.btn-primary {
    background: #234a23;
    color: white;
    padding: 12px 24px;
    text-decoration: none;
    border-radius: 6px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background-color 0.3s;
    border: none;
    cursor: pointer;
}

.btn-primary:hover {
    background: #1a3a1a;
}

.view-btn {
    background: #17a2b8;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 500;
    transition: background-color 0.3s;
}

.view-btn:hover {
    background: #138496;
}

/* Controls Section */
.controls-section {
    margin-bottom: 25px;
}

/* Search Styles */
.search-container {
    margin-bottom: 20px;
}

.search-form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.search-input {
    flex: 1;
    min-width: 300px;
    padding: 12px 16px;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
}

.search-input:focus {
    outline: none;
    border-color: #234a23;
}

.search-btn {
    background: #234a23;
    color: white;
    padding: 12px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
    transition: background-color 0.3s;
}

.search-btn:hover {
    background: #1a3a1a;
}

.clear-search {
    color: #666;
    text-decoration: none;
    padding: 12px 16px;
    border: 1px solid #ddd;
    border-radius: 6px;
    transition: all 0.3s;
}

.clear-search:hover {
    background: #f5f5f5;
    color: #234a23;
}

/* Search Results Info */
.search-results-info {
    background: #e8f4fd;
    color: #0c5460;
    padding: 10px 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-size: 14px;
}

/* Hidden row styling for jQuery */
.product-row.hidden {
    display: none !important;
}

/* Tabs */
.tabs {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.tab {
    padding: 10px 20px;
    text-decoration: none;
    color: #666;
    border: 1px solid #ddd;
    border-radius: 4px;
    transition: all 0.3s;
}

.tab.active,
.tab:hover {
    background: #234a23;
    color: white;
}

/* Table Styles */
table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

th, td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

th {
    background: #f8f9fa;
    font-weight: bold;
    color: #234a23;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
}

.status-approved {
    background: #d4edda;
    color: #155724;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
}

.status-rejected {
    background: #f8d7da;
    color: #721c24;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
}

.no-equipment {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 8px;
    color: #666;
}

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

.modal-content-large {
    background-color: #fefefe;
    margin: 3% auto;
    border: none;
    border-radius: 8px;
    width: 90%;
    max-width: 700px;
    max-height: 85vh;
    overflow-y: auto;
    box-shadow: 0 4px 20px rgba(0,0,0,0.2);
}

.close {
    color: #fff;
    font-size: 40px;
    font-weight: bold;
    cursor: pointer;
}

.close:hover {
    color: #bbb;
}

.message {
    background: #d4edda;
    color: #155724;
    padding: 12px 20px;
    border-radius: 6px;
    margin-bottom: 20px;
    border: 1px solid #c3e6cb;
}

/* Responsive Design */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 20px;
        align-items: stretch;
    }
    
    .search-input {
        min-width: auto;
        width: 100%;
    }
    
    .search-form {
        flex-direction: column;
    }
    
    .search-btn, .clear-search {
        width: 100%;
        justify-content: center;
    }
    
    table {
        font-size: 12px;
    }
    
    th, td {
        padding: 8px 4px;
    }
    
    .tabs {
        flex-wrap: wrap;
    }

    .modal-content-large {
        width: 95%;
        margin: 10% auto;
    }
}

@media (max-width: 480px) {
    .btn-primary {
        width: 100%;
        justify-content: center;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
let currentProductId = null;

// CORRECTED Helper function to convert unit code to display text
function getUnitDisplay(unit) {
    if (!unit) return 'Piece';
    const units = {'K': 'Kg', 'L': 'Liter', 'P': 'Piece'};
    return units[String(unit).toUpperCase().trim()] || 'Piece';
}

// View Product Modal Function
function viewProduct(product) {
    currentProductId = product.product_id;
    
    document.getElementById('detailProductId').textContent = product.product_id;
    document.getElementById('detailProductName').textContent = product.Name || 'N/A';
    document.getElementById('detailProductDescription').textContent = product.Description || 'N/A';
    document.getElementById('detailProductCategory').textContent = product.Category_name || 'N/A';
    document.getElementById('detailProductSubcategory').textContent = product.Subcategory_name || 'N/A';
    document.getElementById('detailProductPrice').textContent = '₹' + parseFloat(product.Price).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    
    const unitDisplay = getUnitDisplay(product.Unit);
    document.getElementById('detailProductQuantity').textContent = parseFloat(product.Quantity).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + unitDisplay;
    document.getElementById('detailProductUnit').textContent = unitDisplay;
    
    // Status with styling
    let statusHTML = '';
    if (product.Approval_status === 'PEN') {
        statusHTML = '<span class="status-pending">Pending</span>';
    } else if (product.Approval_status === 'CON') {
        statusHTML = '<span class="status-approved">Approved</span>';
    } else if (product.Approval_status === 'REJ') {
        statusHTML = '<span class="status-rejected">Rejected</span>';
    } else {
        statusHTML = '<span class="status-pending">Unknown</span>';
    }
    document.getElementById('detailProductStatus').innerHTML = statusHTML;
    
    document.getElementById('detailProductDate').textContent = new Date(product.listed_date).toLocaleDateString('en-IN', { year: 'numeric', month: 'long', day: 'numeric' });
    
    // Product Image
    if (product.image_url) {
        document.getElementById('detailProductImage').src = '../' + product.image_url;
    } else {
        document.getElementById('detailProductImage').src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="300" height="300"%3E%3Crect fill="%23f5f5f5" width="300" height="300"/%3E%3Ctext x="50%" y="50%" text-anchor="middle" dy=".3em" fill="%23999" font-size="18" font-family="Arial"%3ENo Image Available%3C/text%3E%3C/svg%3E';
    }
    
    document.getElementById('productModal').style.display = 'block';
}

// Close Product Modal
function closeProductModal() {
    document.getElementById('productModal').style.display = 'none';
}

// Image Modal Functions
function openImageModal(img) {
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    
    modal.style.display = "block";
    modalImg.src = img.src;
}

function closeImageModal() {
    document.getElementById('imageModal').style.display = "none";
}

// Close modal when clicking outside
window.onclick = function(event) {
    const imageModal = document.getElementById('imageModal');
    const productModal = document.getElementById('productModal');
    
    if (event.target == imageModal) {
        imageModal.style.display = "none";
    }
    if (event.target == productModal) {
        productModal.style.display = "none";
    }
}

// Live search functionality
$(document).ready(function() {
    $('#productSearch').on('keyup', function() {
        var searchTerm = $(this).val().toLowerCase();
        
        $('#productTable tbody tr.product-row').each(function() {
            var rowText = $(this).text().toLowerCase();
            
            if (rowText.indexOf(searchTerm) === -1) {
                $(this).addClass('hidden');
            } else {
                $(this).removeClass('hidden');
            }
        });
    });
});

// Auto-submit search form on Enter key
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.querySelector('.search-input');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.closest('form').submit();
            }
        });
    }
});
</script>

<?php require 'ffooter.php'; ?>
