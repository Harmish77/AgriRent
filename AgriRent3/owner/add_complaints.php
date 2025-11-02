<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $complaint_against = intval($_POST['complaint_against'] ?? 0);
    $complaint_type = $_POST['complaint_type'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    
    // Validate inputs
    if (empty($description) || $complaint_against <= 0 || $id <= 0 || empty($complaint_type)) {
        $error = '✗ Please fill all required fields.';
    } else {
        // Fixed: Use complaints table
        $sql = "INSERT INTO complaints (User_id, Complaint_type, ID, Description, Status) 
                VALUES (?, ?, ?, ?, 'O')";
        
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            $error = '✗ Database error: ' . $conn->error;
        } else {
            $stmt->bind_param('isis', $user_id, $complaint_type, $id, $description);
            
            if ($stmt->execute()) {
                $message = '✓ Complaint submitted successfully!';
                $_POST = array();
            } else {
                $error = '✗ Error submitting complaint: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// Get products owned by this owner (to complain against farmers/buyers)
$product_list = [];
$product_sql = "SELECT p.product_id, p.Name, p.seller_id, u.Name as seller_name
                FROM product p
                JOIN users u ON p.seller_id = u.user_id
                WHERE p.seller_id = ?
                ORDER BY p.Name";

$prod_stmt = $conn->prepare($product_sql);
if ($prod_stmt) {
    $prod_stmt->bind_param('i', $user_id);
    $prod_stmt->execute();
    $product_list = $prod_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $prod_stmt->close();
}

require 'oheader.php';
require 'owner_nav.php';
?>

<!DOCTYPE html>
<html>
<head>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        .main-content {
            padding: 30px 20px;
            background: #f5f7fa;
            min-height: calc(100vh - 70px);
            max-width: 800px;
            margin: 0 auto;
        }

        .page-header {
            margin-bottom: 30px;
        }

        .page-header h1 {
            color: #234a23;
            font-size: 32px;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .page-header p {
            color: #666;
            font-size: 14px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .form-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .form-group label span {
            color: #dc3545;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
            font-size: 14px;
            transition: border 0.3s;
        }

        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #234a23;
            box-shadow: 0 0 5px rgba(35, 74, 35, 0.2);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 150px;
        }

        .submit-btn {
            background: #234a23;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
            transition: background 0.3s;
            width: 100%;
        }

        .submit-btn:hover {
            background: #1a371a;
        }

        .view-link {
            display: inline-block;
            margin-top: 15px;
            color: #234a23;
            text-decoration: none;
            font-weight: 600;
        }

        .view-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 20px 15px;
            }

            .form-container {
                padding: 20px;
            }
        }
    </style>
</head>
<body>

<div class="main-content">
    <div class="page-header">
        <h1>📝 File a Complaint</h1>
        <p>Report any issues with product buyers or other concerns</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= $message ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>

    <div class="form-container">
        <form method="POST" action="">
            <div class="form-group">
                <label>Complaint Type <span>*</span></label>
                <select name="complaint_type" id="complaint_type" required onchange="updateItemList()">
                    <option value="">-- Select Type --</option>
                    <option value="P">📦 Product Issue</option>
                </select>
            </div>

            <div class="form-group" id="product_section" style="display: none;">
                <label>Select Product <span>*</span></label>
                <select name="id" id="product_id" onchange="setComplaintAgainst()">
                    <option value="">-- Select Product --</option>
                    <?php foreach ($product_list as $prod): ?>
                        <option value="<?= $prod['product_id'] ?>" data-seller="<?= $prod['seller_id'] ?>">
                            <?= htmlspecialchars($prod['Name']) ?> (Buyer: <?= htmlspecialchars($prod['seller_name']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <input type="hidden" name="complaint_against" id="complaint_against" value="0">

            <div class="form-group">
                <label>Describe Your Issue <span>*</span></label>
                <textarea name="description" placeholder="Please describe your complaint in detail..." required></textarea>
            </div>

            <button type="submit" class="submit-btn">📤 Submit Complaint</button>
        </form>

        <a href="view_complaints.php" class="view-link">→ View My Complaints</a>
    </div>
</div>

<script>
function updateItemList() {
    const type = document.getElementById('complaint_type').value;
    const prodSection = document.getElementById('product_section');
    const prodSelect = document.getElementById('product_id');
    
    if (type === 'P') {
        prodSection.style.display = 'block';
        prodSelect.required = true;
    } else {
        prodSection.style.display = 'none';
        prodSelect.required = false;
        document.getElementById('complaint_against').value = '0';
    }
}

function setComplaintAgainst() {
    const selected = document.getElementById('product_id');
    const option = selected.options[selected.selectedIndex];
    const against = option.getAttribute('data-seller') || 0;
    document.getElementById('complaint_against').value = against;
}
</script>

</body>
</html>

<?php require 'ofooter.php'; ?>
