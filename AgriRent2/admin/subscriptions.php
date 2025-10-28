<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

// Handle subscription actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = intval($_GET['id']);
    
    if ($action == 'activate') {
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime('+30 days'));
        
        // Update subscription
        $stmt = $conn->prepare("UPDATE user_subscriptions SET Status='A', start_date=?, end_date=? WHERE subscription_id=?");
        $stmt->bind_param("ssi", $start_date, $end_date, $id);
        $stmt->execute();
        
        // Update related payment status to success
        $payment_stmt = $conn->prepare("UPDATE payments SET Status='A', payment_date=NOW() WHERE subscription_id=?");
        $payment_stmt->bind_param("i", $id);
        $payment_stmt->execute();
        
        $message = "Subscription activated";
    } elseif ($action == 'cancel') {
        // Update subscription
        $stmt = $conn->prepare("UPDATE user_subscriptions SET Status='C' WHERE subscription_id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Update related payment status to cancelled
        $payment_stmt = $conn->prepare("UPDATE payments SET Status='C' WHERE subscription_id=?");
        $payment_stmt->bind_param("i", $id);
        $payment_stmt->execute();
        
        $message = "Subscription cancelled";
    } elseif ($action == 'approve') {
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime('+30 days'));
        
        // Update subscription
        $stmt = $conn->prepare("UPDATE user_subscriptions SET Status='A', start_date=?, end_date=? WHERE subscription_id=?");
        $stmt->bind_param("ssi", $start_date, $end_date, $id);
        $stmt->execute();
        
        // Update related payment status to success and set payment date
        $payment_stmt = $conn->prepare("UPDATE payments SET Status='A', payment_date=NOW() WHERE subscription_id=?");
        $payment_stmt->bind_param("i", $id);
        $payment_stmt->execute();
        
        $message = "Subscription approved and activated";
    } elseif ($action == 'extend') {
        $days = intval($_GET['days']) ?: 30;
        $stmt = $conn->prepare("UPDATE user_subscriptions SET end_date = DATE_ADD(end_date, INTERVAL ? DAY) WHERE subscription_id=?");
        $stmt->bind_param("ii", $days, $id);
        $stmt->execute();
        $message = "Subscription extended by $days days";
    } elseif ($action == 'expire') {
        // Update subscription
        $stmt = $conn->prepare("UPDATE user_subscriptions SET Status='E', end_date=CURDATE() WHERE subscription_id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Keep payment status as is since payment was already successful
        $message = "Subscription marked as expired";
    }
    
    // Redirect to remove URL parameters
    header("Location: subscriptions.php?msg=" . urlencode($message));
    exit;
}

// Display message from redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Get all subscriptions with detailed information including payment data
$subscriptions = false;
$error_message = "";

try {
    $sql = "SELECT us.subscription_id, us.user_id, us.plan_id, us.start_date, us.end_date, us.Status,
                   u.Name as user_name, u.Email as user_email, u.User_type as user_type, u.Phone,
                   sp.Plan_name, sp.price, sp.Plan_type,
                   p.transaction_id, p.UPI_transaction_id, p.payment_date, p.Amount, p.Status as payment_status
            FROM user_subscriptions us 
            LEFT JOIN users u ON us.user_id = u.user_id 
            LEFT JOIN subscription_plans sp ON us.plan_id = sp.plan_id
            LEFT JOIN payments p ON us.subscription_id = p.subscription_id
            ORDER BY us.subscription_id DESC";
    
    $subscriptions = $conn->query($sql);
    
    if (!$subscriptions) {
        $error_message = "Database Error: " . $conn->error;
    }
    
} catch (Exception $e) {
    $error_message = "System Error: " . $e->getMessage();
}

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Subscriptions</h1>
    <button class="plan-button" onclick="openPlansModal()">Manage Plans</button>

    <?php if (isset($message)): ?>
        <div class="message" style="background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin: 10px 0;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="error-message" style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px; margin: 10px 0;">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <div class="search-box">
        <input type="text" id="subscriptionSearch" placeholder="Search subscriptions..." style="padding: 8px; width: 1050px; margin-right: 10px;">
        <button type="button" id="clearSearch" class="btn">Clear</button>
    </div>

    <table id="subscriptionsTable">
        <tr>
            <th>ID</th>
            <th>User</th>
            <th>User Type</th>
            <th>Plan</th>
            <th>Price</th>
            <th>Duration</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
        
        <?php if ($subscriptions && $subscriptions->num_rows > 0): ?>
            <?php while($sub = $subscriptions->fetch_assoc()): ?>
            <tr class="subscription-row">
                <td><?= $sub['subscription_id'] ?></td>
                <td>
                    <?= $sub['user_name'] ? htmlspecialchars($sub['user_name']) : 'Unknown User' ?><br>
                    
                </td>
                <td>
                    <?php 
                    if ($sub['user_type'] == 'O') echo 'Equipment Owner';
                    elseif ($sub['user_type'] == 'F') echo 'Farmer';
                    elseif ($sub['user_type'] == 'A') echo 'Admin';
                    else echo 'Unknown';
                    ?>
                </td>
                <td>
                    <?= $sub['Plan_name'] ? htmlspecialchars($sub['Plan_name']) : 'Unknown Plan' ?><br>
                    <small style="color: #666;"><?= $sub['Plan_type'] === 'M' ? 'Monthly' : ($sub['Plan_type'] === 'Y' ? 'Yearly' : 'Unknown') ?></small>
                </td>
                <td>Rs.<?= $sub['price'] ? number_format($sub['price'], 2) : '0.00' ?></td>
                <td>
                    <?php if($sub['start_date'] && $sub['end_date']): ?>
                        <small><?= date('M d,Y', strtotime($sub['start_date'])) ?></small><br>
                        <small>to <?= date('M d,Y', strtotime($sub['end_date'])) ?></small>
                    <?php else: ?>
                        <em>Not Set</em>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if($sub['Status'] == 'A'): ?>
                        <span style="color: green; font-weight: bold;">Active</span>
                    <?php elseif($sub['Status'] == 'P'): ?>
                        <span style="color: orange; font-weight: bold;">Pending</span>
                    <?php elseif($sub['Status'] == 'E'): ?>
                        <span style="color: red; font-weight: bold;">Expired</span>
                    <?php elseif($sub['Status'] == 'C'): ?>
                        <span style="color: red; font-weight: bold;">Cancelled</span>
                    <?php else: ?>
                        <span style="color: gray; font-weight: bold;"><?= htmlspecialchars($sub['Status']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <button onclick="showDetails(<?= $sub['subscription_id'] ?>, <?= htmlspecialchars(json_encode($sub), ENT_QUOTES) ?>)" 
                            class="btn" style="background: #234a23; color: white; padding: 5px 10px; border: none; border-radius: 3px; cursor: pointer;">
                        Details
                    </button>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="9">
                    <?php if ($error_message): ?>
                        Error loading subscriptions. Please check the database connection.
                    <?php else: ?>
                        No subscriptions found
                    <?php endif; ?>
                </td>
            </tr>
        <?php endif; ?>
    </table>
        
        <!-- Plans Management Modal -->
<div id="plansModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closePlansModal()">&times;</span>
        <h2>Subscription Plans Management</h2>
        
        <div class="btn-group">
            <button class="btn-add" onclick="showAddForm()"> Add New Plan</button>
        </div>
        
        <!-- Add/Edit Form -->
        <div id="planForm" style="display: none; background: #f8f9fa; padding: 20px; border-radius: 5px; margin: 20px 0;">
            <h3 id="formTitle">Add New Plan</h3>
            <form id="planFormElement">
                <input type="hidden" id="planId" name="plan_id">
                <input type="hidden" id="formAction" name="action" value="add">
                
                <div class="form-group">
                    <label for="planName">Plan Name:</label>
                    <input type="text" id="planName" name="plan_name" required>
                </div>
                
                <div class="form-group">
                    <label for="planType">Plan Type:</label>
                    <select id="planType" name="plan_type" required>
                        <option value="">Select Type</option>
                        <option value="M">Monthly</option>
                        <option value="Y">Yearly</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="userType">User Type:</label>
                    <select id="userType" name="user_type" required>
                        <option value="">Select User Type</option>
                        <option value="F">Farmer</option>
                        <option value="O">Equipment Owner</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="planPrice">Price (Rs.):</label>
                    <input type="number" id="planPrice" name="price" step="0.01" min="0" required>
                </div>
                
                <div class="btn-group">
                    <button type="button" class="btn-save" onclick="savePlan()"> Save</button>
                    <button type="button" class="btn-cancel" onclick="hideForm()"> Cancel</button>
                </div>
            </form>
        </div>
        
        <!-- Plans Table -->
        <table class="plans-table" id="plansTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Plan Name</th>
                    <th>Type</th>
                    <th>User Type</th>
                    <th>Price (Rs.)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="plansTableBody">
                <!-- Plans will be loaded here -->
            </tbody>
        </table>
    </div>
</div>

</div>

<!-- Subscription Details Modal -->
<div id="detailsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 10px; max-width: 700px; width: 90%; max-height: 80%; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 10px;">
            <h2 id="modalTitle" style="margin: 0; color: #333;">Subscription Details</h2>
            <button onclick="closeModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #999;">&times;</button>
        </div>
        
        <div id="modalContent" style="line-height: 1.6;">
            <!-- Content will be populated by JavaScript -->
        </div>
        
        <div id="modalActions" style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #eee; text-align: center;">
            <!-- Action buttons will be populated by JavaScript -->
        </div>
    </div>
</div>

<style>
    
    .plan-button {
    position: absolute;
    top: 20px;
    right: 20px;
    background: #234a23;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-weight: bold;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
}

.plan-button:hover {
    background: #1a3a1a;
    color: white;
    text-decoration: none;
}

.modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: white;
    margin: 2% auto;
    padding: 20px;
    border-radius: 10px;
    width: 90%;
    max-width: 1000px;
    max-height: 90%;
    overflow-y: auto;
}

.close {
    color: #aaa;
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.close:hover {
    color: black;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

.form-group input, .form-group select {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-sizing: border-box;
}

.btn-group {
    margin: 10px 0;
}

.btn-add, .btn-edit, .btn-delete, .btn-save, .btn-cancel {
    padding: 8px 15px;
    margin: 5px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-weight: bold;
}

.btn-add { background: #234a23; color: white; }
.btn-edit { background: #28a745; color: white; }
.btn-delete { background: #dc3545; color: white; }
.btn-save { background: #28a745; color: white; }
.btn-cancel { background: #6c757d; color: white; }

.plans-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.plans-table th, .plans-table td {
    border: 1px solid #ddd;
    padding: 12px;
    text-align: left;
}

.plans-table th {
    background-color: #f8f9fa;
    font-weight: bold;
}

.plans-table tr:nth-child(even) {
    background-color: #f9f9f9;
}

.subscription-row.hidden {
    display: none !important;
}

.action-btn {
    padding: 8px 15px;
    margin: 5px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-weight: bold;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
}

.btn-approve { background: #234a23; color: white; }
.btn-approve:hover { background: #218838; }

.btn-activate { background: #28a745; color: white; }
.btn-activate:hover { background: #138496; }

.btn-cancel { background: #dc3545; color: white; }
.btn-cancel:hover { background: #c82333; }

.btn-extend { background: #ffc107; color: black; }
.btn-extend:hover { background: #e0a800; }

.btn-expire { background: #6c757d; color: white; }
.btn-expire:hover { background: #5a6268; }

.detail-row {
    display: flex;
    margin-bottom: 15px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 5px;
}

.detail-label {
    font-weight: bold;
    min-width: 150px;
    color: #495057;
}

.detail-value {
    flex: 1;
    color: #212529;
}

.status-active { color: #234a23; font-weight: bold; }
.status-pending { color: #ffc107; font-weight: bold; }
.status-expired { color: #dc3545; font-weight: bold; }
.status-cancelled { color: #6c757d; font-weight: bold; }

.payment-success {
    color: #28a745;
    font-weight: bold;
}

.payment-pending {
    color: #ffc107;
    font-weight: bold;
}

.payment-failed {
    color: #dc3545;
    font-weight: bold;
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
    $('#subscriptionSearch').on('keyup', function() {
        var searchTerm = $(this).val().toLowerCase();
        
        $('#subscriptionsTable tr.subscription-row').each(function() {
            var rowText = $(this).text().toLowerCase();
            
            if (rowText.indexOf(searchTerm) === -1) {
                $(this).addClass('hidden');
            } else {
                $(this).removeClass('hidden');
            }
        });
    });
    
    $('#clearSearch').on('click', function() {
        $('#subscriptionSearch').val('');
        $('#subscriptionsTable tr.subscription-row').removeClass('hidden');
        $('#subscriptionSearch').focus();
    });
});

function showDetails(id, data) {
    document.getElementById('modalTitle').innerHTML = 'Subscription Details';
    
    // Format dates
    var startDate = data.start_date ? new Date(data.start_date).toLocaleDateString() : 'Not Set';
    var endDate = data.end_date ? new Date(data.end_date).toLocaleDateString() : 'Not Set';
    var paymentDate = data.payment_date ? new Date(data.payment_date).toLocaleDateString() : 'Not Available';
    
    // Determine subscription status class and text
    var statusClass = '';
    var statusText = '';
    switch(data.Status) {
        case 'A': statusClass = 'status-active'; statusText = 'Active'; break;
        case 'P': statusClass = 'status-pending'; statusText = 'Pending'; break;
        case 'E': statusClass = 'status-expired'; statusText = 'Expired'; break;
        case 'C': statusClass = 'status-cancelled'; statusText = 'Cancelled'; break;
        default: statusClass = ''; statusText = data.Status;
    }
    
    // Determine user type
    var userType = '';
    switch(data.user_type) {
        case 'O': userType = 'Equipment Owner'; break;
        case 'F': userType = 'Farmer'; break;
        case 'A': userType = 'Admin'; break;
        default: userType = 'Unknown';
    }
    
    // Determine payment status
    var paymentStatusClass = '';
    var paymentStatusText = '';
    if (data.payment_status) {
        switch(data.payment_status.toLowerCase()) {
            case 'success':
            case 'completed':
            case 'paid':
                paymentStatusClass = 'payment-success';
                paymentStatusText = 'Success';
                break;
            case 'pending':
            case 'p':
                paymentStatusClass = 'payment-pending';
                paymentStatusText = 'Pending';
                break;
            case 'failed':
            case 'cancelled':
                paymentStatusClass = 'payment-failed';
                paymentStatusText = 'Failed/Cancelled';
                break;
            default:
                paymentStatusClass = '';
                paymentStatusText = data.payment_status;
        }
    } else {
        paymentStatusText = 'Not Available';
    }
    
    // Build content with uniform styling for all information
    var content = `
        <div class="detail-row">
            <div class="detail-label">Subscription ID:</div>
            <div class="detail-value">${data.subscription_id}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">User Name:</div>
            <div class="detail-value">${data.user_name || 'Unknown User'}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">User Email:</div>
            <div class="detail-value">${data.user_email || 'No Email'}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Phone Number:</div>
            <div class="detail-value">${data.Phone || 'No Phone Number'}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">User Type:</div>
            <div class="detail-value">${userType}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Plan Name:</div>
            <div class="detail-value">${data.Plan_name || 'Unknown Plan'}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Plan Type:</div>
            <div class="detail-value">${data.Plan_type === 'M' ? 'Monthly' : (data.Plan_type === 'Y' ? 'Yearly' : 'Unknown Type')}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Price:</div>
            <div class="detail-value">Rs. ${parseFloat(data.price || 0).toLocaleString('en-IN', {minimumFractionDigits: 2})}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Start Date:</div>
            <div class="detail-value">${startDate}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">End Date:</div>
            <div class="detail-value">${endDate}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Status:</div>
            <div class="detail-value"><span class="${statusClass}">${statusText}</span></div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Transaction ID:</div>
            <div class="detail-value">${data.transaction_id || '<em style="color: #999;">Not Available</em>'}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">UPI Transaction ID:</div>
            <div class="detail-value">${data.UPI_transaction_id || '<em style="color: #999;">Not Available</em>'}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Payment Date:</div>
            <div class="detail-value">${paymentDate}</div>
        </div>
        <div class="detail-row">
            <div class="detail-label">Payment Amount:</div>
            <div class="detail-value">Rs. ${data.Amount ? parseFloat(data.Amount).toLocaleString('en-IN', {minimumFractionDigits: 2}) : 'Not Available'}</div>
        </div>
        
    `;
    
    document.getElementById('modalContent').innerHTML = content;
    
    // Build action buttons based on status
    var actions = '';
    
    if (data.Status == 'P') {
        actions += '<a href="?action=approve&id=' + id + '" class="action-btn btn-approve" onclick="return confirm(\'Approve this subscription?\')">Approve & Activate</a>';
        actions += '<a href="?action=cancel&id=' + id + '" class="action-btn btn-cancel" onclick="return confirm(\'Cancel this subscription?\')">Cancel Subscription</a>';
    } else if (data.Status == 'A') {
        actions += '<a href="?action=extend&id=' + id + '&days=30" class="action-btn btn-extend" onclick="return confirm(\'Extend subscription by 30 days?\')">Extend 30 Days</a>';
        actions += '<a href="?action=extend&id=' + id + '&days=90" class="action-btn btn-extend" onclick="return confirm(\'Extend subscription by 90 days?\')">Extend 90 Days</a>';
        actions += '<a href="?action=expire&id=' + id + '" class="action-btn btn-expire" onclick="return confirm(\'Mark subscription as expired?\')">Mark Expired</a>';
        actions += '<a href="?action=cancel&id=' + id + '" class="action-btn btn-cancel" onclick="return confirm(\'Cancel this subscription?\')">Cancel Subscription</a>';
    } else if (data.Status == 'E') {
        actions += '<a href="?action=activate&id=' + id + '" class="action-btn btn-activate" onclick="return confirm(\'Activate this subscription?\')">Reactivate</a>';
        actions += '<a href="?action=cancel&id=' + id + '" class="action-btn btn-cancel" onclick="return confirm(\'Cancel this subscription?\')">Cancel subscription</a>';
    } else if (data.Status == 'C') {
        actions += '<a href="?action=activate&id=' + id + '" class="action-btn btn-activate" onclick="return confirm(\'Reactivate this subscription?\')">Reactivate</a>';
    }
    
    document.getElementById('modalActions').innerHTML = actions || '<p><em>No actions available for this status</em></p>';
    
    // Show modal
    document.getElementById('detailsModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('detailsModal').style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('detailsModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

// Plans Management Functions
function openPlansModal() {
    document.getElementById('plansModal').style.display = 'block';
    loadPlans();
}

function closePlansModal() {
    document.getElementById('plansModal').style.display = 'none';
    hideForm();
}

function loadPlans() {
    fetch('plans_handler.php?action=load')
        .then(response => response.json())
        .then(data => {
            const tbody = document.getElementById('plansTableBody');
            tbody.innerHTML = '';
            
            if (data.success && data.plans.length > 0) {
                data.plans.forEach(plan => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${plan.plan_id}</td>
                        <td>${plan.Plan_name}</td>
                        <td>${plan.Plan_type === 'M' ? 'Monthly' : 'Yearly'}</td>
                        <td>${plan.user_type === 'F' ? 'Farmer' : 'Equipment Owner'}</td>
                        <td>${parseFloat(plan.price).toFixed(2)}</td>
                        <td>
                            <button class="btn-edit" onclick="editPlan(${plan.plan_id}, '${plan.Plan_name}', '${plan.Plan_type}', '${plan.user_type}', ${plan.price})">️ Edit</button>
                            <button class="btn-delete" onclick="deletePlan(${plan.plan_id}, '${plan.Plan_name}')">️ Delete</button>
                        </td>
                    `;
                    tbody.appendChild(row);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No plans found</td></tr>';
            }
        })
        .catch(error => {
            console.error('Error loading plans:', error);
            document.getElementById('plansTableBody').innerHTML = '<tr><td colspan="6" style="text-align: center; color: red;">Error loading plans</td></tr>';
        });
}

function showAddForm() {
    document.getElementById('formTitle').textContent = 'Add New Plan';
    document.getElementById('formAction').value = 'add';
    document.getElementById('planId').value = '';
    document.getElementById('planFormElement').reset();
    document.getElementById('planForm').style.display = 'block';
}

function editPlan(id, name, type, userType, price) {
    document.getElementById('formTitle').textContent = 'Edit Plan';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('planId').value = id;
    document.getElementById('planName').value = name;
    document.getElementById('planType').value = type;
    document.getElementById('userType').value = userType;
    document.getElementById('planPrice').value = price;
    document.getElementById('planForm').style.display = 'block';
}

function hideForm() {
    document.getElementById('planForm').style.display = 'none';
    document.getElementById('planFormElement').reset();
}

function savePlan() {
    const formData = new FormData(document.getElementById('planFormElement'));
    
    fetch('plans_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            hideForm();
            loadPlans();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error saving plan:', error);
        alert('Error saving plan');
    });
}

function deletePlan(id, name) {
    if (confirm(`Are you sure you want to delete the plan "${name}"?`)) {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('plan_id', id);
        
        fetch('plans_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                loadPlans();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error deleting plan:', error);
            alert('Error deleting plan');
        });
    }
}

// Close modal when clicking outside
document.getElementById('plansModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePlansModal();
    }
});


document.addEventListener('DOMContentLoaded', function() {
    const messages = document.querySelectorAll('.message, .error-message, .alert');
    
    messages.forEach(function(message) {
        setTimeout(function() {
            message.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out';
            message.style.opacity = '0';
            message.style.transform = 'translateY(-20px)';
            
            setTimeout(function() {
                message.style.display = 'none';
            }, 500);
        }, 5000);
    });
});
</script>

<?php require 'footer.php'; ?>
