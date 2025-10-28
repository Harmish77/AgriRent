<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Farmer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header('Location: ../login.php');
    exit();
}

$farmer_id = $_SESSION['user_id'];
$message = '';

// Handle product deletion
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // Delete from images table first (if you have product images)
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
    $where_clause .= " AND (p.Name LIKE ? OR p.Description LIKE ? OR c.Category_name LIKE ?)";
    $search_term = "%" . $search_query . "%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
    $param_types .= "sss";
}

// Fetch products with images and category info
$sql = "SELECT p.*, i.image_url, c.Category_name, s.Subcategory_name,
               ua.address, ua.city, ua.state, ua.Pin_code
        FROM product p 
        LEFT JOIN images i ON (i.image_type = 'P' AND i.ID = p.product_id)
        LEFT JOIN product_subcategories s ON p.Subcategory_id = s.Subcategory_id
        LEFT JOIN product_categories c ON s.Category_id = c.Category_id
        LEFT JOIN user_addresses ua ON p.address_id = ua.address_id
        $where_clause 
        ORDER BY p.listed_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$products_list = [];
while ($row = $result->fetch_assoc()) {
    $products_list[] = $row;
}
$stmt->close();

require 'oheader.php';
require 'owner_nav.php';
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
                    
                    $unit_text = $product['Unit'] === 'K' ? 'Kg' : ($product['Unit'] === 'L' ? 'Liter' : 'Piece');
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
                            <button type="button" 
                                    class="btn-details" 
                                    onclick="viewProductDetails(<?= htmlspecialchars(json_encode($product)) ?>)">
                                Details
                            </button>
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
                <h3>No products found </h3>
               
            <?php else: ?>
                <h3>No Products Found</h3>
                <p>Start by adding your first agricultural product.</p>
            <?php endif; ?>
            <a href="add_product.php" class="btn-primary"> Add Your First Product</a>
        </div>
    <?php endif; ?>
</div>

<!-- Product Details Modal -->
<div id="productDetailsModal" class="modal" style="display: none;">
    <div class="modal-content-large">
        <span class="close" onclick="closeProductModal()">&times;</span>
        <h2 id="modalTitle">Product Details</h2>
        
        <div class="modal-body">
            <div class="product-image-section">
                <h3>Product Image</h3>
                <div id="productImageContainer">
                    <!-- Image will be inserted here -->
                </div>
            </div>
            
            <div class="product-details-section">
                <h3>Product Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Product ID:</strong>
                        <span id="detailProductId"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Name:</strong>
                        <span id="detailName"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Description:</strong>
                        <span id="detailDescription"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Category:</strong>
                        <span id="detailCategory"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Subcategory:</strong>
                        <span id="detailSubcategory"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Price:</strong>
                        <span id="detailPrice"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Quantity:</strong>
                        <span id="detailQuantity"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Unit:</strong>
                        <span id="detailUnit"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Status:</strong>
                        <span id="detailStatus"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Listed Date:</strong>
                        <span id="detailListedDate"></span>
                    </div>
                </div>
            </div>
            
            <div class="location-details-section" id="locationSection" style="display: none;">
                <h3>Location Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Address:</strong>
                        <span id="detailAddress"></span>
                    </div>
                    <div class="detail-row">
                        <strong>City:</strong>
                        <span id="detailCity"></span>
                    </div>
                    <div class="detail-row">
                        <strong>State:</strong>
                        <span id="detailState"></span>
                    </div>
                    <div class="detail-row">
                        <strong>PIN Code:</strong>
                        <span id="detailPinCode"></span>
                    </div>
                </div>
            </div>
            
            <div class="modal-actions">
                <a href="#" id="editProductBtn" class="btn-edit">
                     Edit Product
                </a>
                <button type="button" id="deleteProductBtn" class="btn-delete" onclick="confirmDelete()">
                    ️ Delete Product
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Image Modal -->
<div id="imageModal" class="modal" style="display: none;">
    <span class="close" onclick="closeImageModal()">&times;</span>
    <img class="modal-content" id="modalImage">
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
    color:white;
}

.btn-details {
    background: #234a23;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-details:hover {
    background: #237a23;
    transform: translateY(-1px);
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

/* Existing Styles */
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
    background-color: rgba(0,0,0,0.5);
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
    margin: 2% auto;
    padding: 0;
    border: none;
    border-radius: 8px;
    width: 90%;
    max-width: 900px;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
}

.modal-content-large h2 {
    background: #234a23;
    color: white;
    margin: 0;
    padding: 20px;
    border-radius: 8px 8px 0 0;
}

.modal-body {
    padding: 30px;
}

.product-image-section, .product-details-section, .location-details-section {
    margin-bottom: 30px;
}

.product-image-section h3, .product-details-section h3, .location-details-section h3 {
    color: #234a23;
    margin-bottom: 15px;
    border-bottom: 2px solid #eee;
    padding-bottom: 5px;
}

#productImageContainer {
    text-align: center;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 5px;
}

#productImageContainer img {
    max-width: 100%;
    max-height: 400px;
    border-radius: 5px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    object-fit: cover;
}

.details-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}

.detail-row {
    display: flex;
    padding: 12px;
    background: #f8f9fa;
    border-radius: 6px;
    border-left: 4px solid #234a23;
}

.detail-row strong {
    min-width: 150px;
    color: #234a23;
    font-weight: 600;
}

.detail-row span {
    flex: 1;
    margin-left: 15px;
}

.modal-actions {
    margin-top: 30px;
    padding-top: 20px;
    border-top: 2px solid #eee;
    text-align: center;
    display: flex;
    gap: 15px;
    justify-content: center;
}

.btn-edit {
    background: #28a745;
    color: white;
    padding: 12px 24px;
    text-decoration: none;
    border-radius: 6px;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-edit:hover {
    background: #218038;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
}

.btn-delete {
    background: #dc3545;
    color: white;
    padding: 12px 24px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-delete:hover {
    background: #c82333;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}

.close {
    position: absolute;
    top: 15px;
    right: 25px;
    color: white;
    font-size: 35px;
    font-weight: bold;
    cursor: pointer;
    z-index: 1001;
}

.close:hover {
    color: #ccc;
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
        margin: 1% auto;
    }
    
    .detail-row {
        flex-direction: column;
    }
    
    .detail-row strong {
        min-width: auto;
        margin-bottom: 5px;
    }
    
    .detail-row span {
        margin-left: 0;
    }
    
    .modal-actions {
        flex-direction: column;
        gap: 10px;
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

// View product details function
function viewProductDetails(product) {
    currentProductId = product.product_id;
    
    // Populate product details
    document.getElementById('detailProductId').textContent = '#' + product.product_id;
    document.getElementById('detailName').textContent = product.Name || 'N/A';
    document.getElementById('detailDescription').textContent = product.Description || 'N/A';
    document.getElementById('detailCategory').textContent = product.Category_name || 'N/A';
    document.getElementById('detailSubcategory').textContent = product.Subcategory_name || 'N/A';
    document.getElementById('detailPrice').textContent = '₹' + parseFloat(product.Price).toLocaleString();
    document.getElementById('detailQuantity').textContent = parseFloat(product.Quantity).toLocaleString();
    
    // Set unit
    let unitText = '';
    switch(product.Unit) {
        case 'K': unitText = 'Kilogram (Kg)'; break;
        case 'L': unitText = 'Liter (L)'; break;
        case 'P': unitText = 'Piece (P)'; break;
        default: unitText = product.Unit;
    }
    document.getElementById('detailUnit').textContent = unitText;
    
    // Set status with color
    const statusSpan = document.getElementById('detailStatus');
    let statusHtml = '';
    switch(product.Approval_status) {
        case 'PEN':
            statusHtml = '<span class="status-pending">Pending</span>';
            break;
        case 'CON':
            statusHtml = '<span class="status-approved">Approved</span>';
            break;
        case 'REJ':
            statusHtml = '<span class="status-rejected">Rejected</span>';
            break;
        default:
            statusHtml = product.Approval_status;
    }
    statusSpan.innerHTML = statusHtml;
    
    // Set listed date
    const listedDate = new Date(product.listed_date);
    document.getElementById('detailListedDate').textContent = listedDate.toLocaleDateString('en-US', {
        year: 'numeric', month: 'long', day: 'numeric'
    });
    
    // Handle product image
    const imageContainer = document.getElementById('productImageContainer');
    if (product.image_url) {
        imageContainer.innerHTML = '<img src="../' + product.image_url + '" alt="Product Image">';
    } else {
        imageContainer.innerHTML = '<div style="padding: 60px; background: #f0f0f0; border-radius: 5px; color: #999; text-align: center;"><i class="fas fa-image" style="font-size: 48px; margin-bottom: 10px; display: block;"></i>No Image Available</div>';
    }
    
    // Handle location information
    const locationSection = document.getElementById('locationSection');
    if (product.address || product.city || product.state || product.Pin_code) {
        document.getElementById('detailAddress').textContent = product.address || 'N/A';
        document.getElementById('detailCity').textContent = product.city || 'N/A';
        document.getElementById('detailState').textContent = product.state || 'N/A';
        document.getElementById('detailPinCode').textContent = product.Pin_code || 'N/A';
        locationSection.style.display = 'block';
    } else {
        locationSection.style.display = 'none';
    }
    
    // Set action buttons
    document.getElementById('editProductBtn').href = 'edit_product.php?id=' + product.product_id;
    
    // Show modal
    document.getElementById('productDetailsModal').style.display = 'block';
}

function closeProductModal() {
    document.getElementById('productDetailsModal').style.display = 'none';
    currentProductId = null;
}

function confirmDelete() {
    if (!currentProductId) return;
    
    if (confirm('Are you sure you want to delete this product? This action cannot be undone.')) {
        const currentUrl = new URL(window.location.href);
        const params = new URLSearchParams(currentUrl.search);
        
        // Preserve current filters
        let deleteUrl = '?delete_id=' + currentProductId;
        if (params.get('status')) {
            deleteUrl += '&status=' + params.get('status');
        }
        if (params.get('search')) {
            deleteUrl += '&search=' + encodeURIComponent(params.get('search'));
        }
        
        window.location.href = deleteUrl;
    }
}

// Image modal functions
function openImageModal(img) {
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    
    modal.style.display = "block";
    modalImg.src = img.src;
}

function closeImageModal() {
    document.getElementById('imageModal').style.display = "none";
}

// Close modals when clicking outside
window.onclick = function(event) {
    const imageModal = document.getElementById('imageModal');
    const productModal = document.getElementById('productDetailsModal');
    
    if (event.target == imageModal) {
        imageModal.style.display = "none";
    }
    if (event.target == productModal) {
        productModal.style.display = "none";
    }
}

// Keyboard navigation
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const productModal = document.getElementById('productDetailsModal');
        const imageModal = document.getElementById('imageModal');
        
        if (productModal.style.display === 'block') {
            closeProductModal();
        }
        if (imageModal.style.display === 'block') {
            closeImageModal();
        }
    }
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
    
    // Auto hide success/error messages after 4 seconds
    if ($('.message').length > 0) {
        setTimeout(function() {
            $('.message').fadeOut(800, function() {
                $(this).remove();
            });
        }, 4000);
    }
});
</script>

<?php require 'ofooter.php'; ?>
