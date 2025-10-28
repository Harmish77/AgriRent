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
    } elseif ($action == 'reapprove') {
        $conn->query("UPDATE product SET Approval_status='CON' WHERE product_id=$id");
        $message = "Product re-approved successfully";
    }
}

$status = isset($_GET['status']) ? $_GET['status'] : 'PEN';
$where = "WHERE p.Approval_status = '$status'";

// Updated query to include all product details and image
$products = $conn->query("SELECT p.*, u.Name as seller_name, u.Email as seller_email, 
                         u.Phone as seller_phone, i.image_url 
                         FROM product p 
                         JOIN users u ON p.seller_id = u.user_id 
                         LEFT JOIN images i ON (i.image_type = 'P' AND i.ID = p.product_id)
                         $where 
                         ORDER BY p.listed_date DESC");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Products</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <div class="tabs">
        <a href="?status=PEN" class="tab <?= $status == 'PEN' ? 'active' : '' ?>">Pending</a>
        <a href="?status=CON" class="tab <?= $status == 'CON' ? 'active' : '' ?>">Approved</a>
        <a href="?status=REJ" class="tab <?= $status == 'REJ' ? 'active' : '' ?>">Rejected</a>
    </div>

    <div class="search-box">
        <input type="text" id="liveSearch" placeholder="Search products..." style="padding: 8px; width: 1050px; margin-bottom: 10px;">
        <button type="button" id="clearSearch" class="btn" style="margin-left: 10px; width: 85px;">Clear</button>
    </div>

    <table id="productsTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Seller</th>
                <th>Price</th>
                <th>Quantity</th>
                <th>Date Added</th>
                <th>View</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($products->num_rows > 0): ?>
                <?php while($product = $products->fetch_assoc()): ?>
                <tr class="product-row">
                    <td><?= $product['product_id'] ?></td>
                    <td><?= htmlspecialchars($product['Name']) ?></td>
                    <td><?= htmlspecialchars($product['seller_name']) ?></td>
                    <td>Rs.<?= number_format($product['Price'], 2) ?></td>
                    <td><?= $product['Quantity'] ?> <?= $product['Unit'] == 'K' ? 'kg' : 'liter' ?></td>
                    <td><?= date('M d, Y', strtotime($product['listed_date'])) ?></td>
                    <td>
                        <button type="button" class="btn-view" onclick="viewProduct(<?= htmlspecialchars(json_encode($product)) ?>)">
                            View Details
                        </button>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7">No products found</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Product Details Modal -->
<div id="productModal" class="modal" style="display: none;">
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
                        <strong>Location:</strong>
                        <span id="detailLocation"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Listed Date:</strong>
                        <span id="detailListedDate"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Status:</strong>
                        <span id="detailStatus"></span>
                    </div>
                </div>
            </div>
            
            <div class="seller-details-section">
                <h3>Seller Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Seller Name:</strong>
                        <span id="detailSellerName"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Seller Email:</strong>
                        <span id="detailSellerEmail"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Seller Phone:</strong>
                        <span id="detailSellerPhone"></span>
                    </div>
                </div>
            </div>
            
            <div class="modal-actions">
                <div id="productActions">
                    <!-- Action buttons will be inserted here based on status -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Fullscreen Image Modal -->
<div id="fullscreenImageModal" class="fullscreen-modal" style="display: none;">
    <span class="fullscreen-close" onclick="closeFullscreenImage()">&times;</span>
    <img id="fullscreenImage" class="fullscreen-image" src="" alt="Fullscreen Product Image">
    <div id="fullscreenImageCaption" class="fullscreen-caption"></div>
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
    background-color: rgba(0,0,0,0.5);
}

.modal-content-large {
    background-color: #fefefe;
    margin: 2% auto;
    padding: 0;
    border: none;
    border-radius: 8px;
    width: 90%;
    max-width: 800px;
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
    padding: 20px;
}

.product-image-section, .product-details-section, .seller-details-section {
    margin-bottom: 30px;
}

.product-image-section h3, .product-details-section h3, .seller-details-section h3 {
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
    max-height: 300px;
    border-radius: 5px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    cursor: pointer;
    transition: transform 0.2s ease;
}

#productImageContainer img:hover {
    transform: scale(1.02);
}

.details-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
}

.detail-row {
    display: flex;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 4px;
   
}

.detail-row strong {
    min-width: 150px;
    color: #234a23;
}

.detail-row span {
    flex: 1;
    margin-left: 10px;
}

.modal-actions {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px solid #eee;
    text-align: center;
}

.modal-actions a {
    display: inline-block;
    margin: 0 5px;
    padding: 10px 20px;
    text-decoration: none;
    border-radius: 4px;
    font-weight: bold;
}

.btn-approve {
    background: #234a23;
    color: white;
}

.btn-reject {
    background: #dc3545;
    color: white;
}

.btn-reapprove {
    background: #ffc107;
    color: #212529;
}

.btn-approve:hover {
    background: #233a23;
    color: white;
}

.btn-reject:hover {
    background: #dc1545;
    color: white;
}

.btn-reapprove:hover {
    background: #ffc007;
    color: #212529;
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

.btn-view {
    background: #234a23;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
}

.btn-view:hover {
    background: #236a25;
}

/* Fullscreen Image Modal Styles */
.fullscreen-modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.95);
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.fullscreen-image {
    margin: auto;
    display: block;
    width: auto;
    height: auto;
    max-width: 95%;
    max-height: 90%;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(255, 255, 255, 0.1);
}

.fullscreen-close {
    position: absolute;
    top: 20px;
    right: 35px;
    color: #ffffff;
    font-size: 50px;
    font-weight: bold;
    cursor: pointer;
    transition: color 0.3s ease;
    z-index: 2001;
}

.fullscreen-close:hover {
    color: #ffcccc;
}

.fullscreen-caption {
    margin: auto;
    display: block;
    width: 80%;
    max-width: 700px;
    text-align: center;
    color: #ffffff;
    padding: 10px 0;
    position: absolute;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0, 0, 0, 0.7);
    border-radius: 4px;
    font-size: 16px;
}

/* Search Box Styling */
#liveSearch {
    font-size: 14px;
    border: 1px solid #ccc;
    border-radius: 4px;
}

#liveSearch:focus {
   outline: none;
    border-color: #234a23;
    box-shadow: 0 0 5px rgba(0,124,186,0.3);
}

.product-row.hidden {
    display: none !important;
}

@media only screen and (max-width: 768px) {
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
    
    #liveSearch {
        width: 100%;
        margin-bottom: 10px;
    }
    
    .fullscreen-close {
        top: 10px;
        right: 20px;
        font-size: 40px;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
    // Live search functionality
    $('#liveSearch').on('keyup', function() {
        var searchTerm = $(this).val().toLowerCase();
        
        $('#productsTable tbody tr.product-row').each(function() {
            var rowText = $(this).text().toLowerCase();
            
            if (rowText.indexOf(searchTerm) === -1) {
                $(this).addClass('hidden');
            } else {
                $(this).removeClass('hidden');
            }
        });
        
        var visibleRows = $('#productsTable tbody tr.product-row:not(.hidden)').length;
        
        if (visibleRows === 0 && searchTerm !== '') {
            if ($('#noResultsRow').length === 0) {
                $('#productsTable tbody').append('<tr id="noResultsRow"><td colspan="7" style="text-align: center; padding: 20px; font-style: italic;">No products found matching your search</td></tr>');
            }
        } else {
            $('#noResultsRow').remove();
        }
    });
    
    // Clear search functionality
    $('#clearSearch').on('click', function() {
        $('#liveSearch').val('');
        $('#productsTable tbody tr.product-row').removeClass('hidden');
        $('#noResultsRow').remove();
        $('#liveSearch').focus();
    });
    
    // Clear search on tab change
    $('.tab').on('click', function() {
        $('#liveSearch').val('');
    });
    
    // Auto hide success/error messages after 4 seconds
    if ($('.message').length > 0) {
        setTimeout(function() {
            $('.message').fadeOut(800, function() {
                $(this).remove();
            });
        }, 4000);
    }
});

// View product details function
function viewProduct(product) {
    // Populate product details
    document.getElementById('detailProductId').textContent = product.product_id;
    document.getElementById('detailName').textContent = product.Name || 'N/A';
    document.getElementById('detailDescription').textContent = product.Description || 'N/A';
    document.getElementById('detailCategory').textContent = product.Category || 'N/A';
    document.getElementById('detailPrice').textContent = 'Rs.' + parseFloat(product.Price).toLocaleString();
    document.getElementById('detailQuantity').textContent = product.Quantity || 'N/A';
    document.getElementById('detailUnit').textContent = product.Unit == 'K' ? 'Kilogram (kg)' : 'Liter';
    document.getElementById('detailLocation').textContent = product.Location || 'N/A';
    document.getElementById('detailListedDate').textContent = new Date(product.listed_date).toLocaleDateString();
    
    // Set status with color
    const statusSpan = document.getElementById('detailStatus');
    if (product.Approval_status === 'PEN') {
        statusSpan.innerHTML = '<span style="color: orange;">Pending</span>';
    } else if (product.Approval_status === 'CON') {
        statusSpan.innerHTML = '<span style="color: green;">Approved</span>';
    } else {
        statusSpan.innerHTML = '<span style="color: red;">Rejected</span>';
    }
    
    // Populate seller details
    document.getElementById('detailSellerName').textContent = product.seller_name || 'N/A';
    document.getElementById('detailSellerEmail').textContent = product.seller_email || 'N/A';
    document.getElementById('detailSellerPhone').textContent = product.seller_phone || 'N/A';
    
    // Handle product image with fullscreen capability
    const imageContainer = document.getElementById('productImageContainer');
    if (product.image_url) {
        imageContainer.innerHTML = '<img src="../' + product.image_url + '" alt="Product Image" onclick="openFullscreenImage(this, \'' + product.Name + ' - Product Image\')" title="Click to view fullscreen">';
    } else {
        imageContainer.innerHTML = '<div style="padding: 50px; background: #f0f0f0; border-radius: 5px; color: #666;"><i class="fas fa-image" style="font-size: 48px; margin-bottom: 10px;"></i><br>No Image Available</div>';
    }
    
    // Handle action buttons based on status
    const actionsContainer = document.getElementById('productActions');
    let actionsHTML = '';
    
    const currentStatus = new URLSearchParams(window.location.search).get('status') || 'PEN';
    
    if (product.Approval_status === 'PEN') {
        // Pending: Show Approve and Reject buttons
        actionsHTML += '<a href="?action=approve&id=' + product.product_id + '&status=' + currentStatus + '" class="btn-approve">Approve Product</a>';
        actionsHTML += '<a href="?action=reject&id=' + product.product_id + '&status=' + currentStatus + '" class="btn-reject">Reject Product</a>';
    } else if (product.Approval_status === 'CON') {
        // Approved: Show Reject button to allow reverting approval
        actionsHTML += '<a href="?action=reject&id=' + product.product_id + '&status=' + currentStatus + '" class="btn-reject" onclick="return confirm(\'Are you sure you want to reject this approved product?\')">Reject Product</a>';
    } else if (product.Approval_status === 'REJ') {
        // Rejected: Show Re-Approval button
        actionsHTML += '<a href="?action=reapprove&id=' + product.product_id + '&status=' + currentStatus + '" class="btn-reapprove" onclick="return confirm(\'Re-approve this rejected product?\')">Re-Approve Product</a>';
    }
    
    actionsContainer.innerHTML = actionsHTML;
    
    // Show modal
    document.getElementById('productModal').style.display = 'block';
}

function closeProductModal() {
    document.getElementById('productModal').style.display = 'none';
}

// Fullscreen Image Functions (Exact same as equipment.php)
function openFullscreenImage(imgElement, caption) {
    const modal = document.getElementById('fullscreenImageModal');
    const fullscreenImg = document.getElementById('fullscreenImage');
    const captionElement = document.getElementById('fullscreenImageCaption');
    
    modal.style.display = 'block';
    fullscreenImg.src = imgElement.src;
    captionElement.innerHTML = caption || 'Product Image';
    
    // Prevent body scrolling
    document.body.style.overflow = 'hidden';
}

function closeFullscreenImage() {
    const modal = document.getElementById('fullscreenImageModal');
    modal.style.display = 'none';
    
    // Restore body scrolling
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const productModal = document.getElementById('productModal');
    const fullscreenModal = document.getElementById('fullscreenImageModal');
    
    if (event.target == productModal) {
        productModal.style.display = 'none';
    }
    if (event.target == fullscreenModal) {
        closeFullscreenImage();
    }
}

// Keyboard navigation
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const productModal = document.getElementById('productModal');
        const fullscreenModal = document.getElementById('fullscreenImageModal');
        
        if (fullscreenModal.style.display === 'block') {
            closeFullscreenImage();
        } else if (productModal.style.display === 'block') {
            closeProductModal();
        }
    }
});
</script>

<?php require 'footer.php'; ?>
