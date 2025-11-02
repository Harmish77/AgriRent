<?php
session_start();
require_once '../auth/config.php';

// Check if admin is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        case 'load':
            $sql = "SELECT * FROM subscription_plans ORDER BY plan_id DESC";
            $result = $conn->query($sql);
            $plans = [];
            
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $plans[] = $row;
                }
            }
            
            echo json_encode(['success' => true, 'plans' => $plans]);
            break;
            
        case 'add':
            $name = trim($_POST['plan_name']);
            $type = $_POST['plan_type'];
            $userType = $_POST['user_type'];
            $price = floatval($_POST['price']);
            
            if (empty($name) || empty($type) || empty($userType) || $price <= 0) {
                echo json_encode(['success' => false, 'message' => 'All fields are required']);
                break;
            }
            
            $stmt = $conn->prepare("INSERT INTO subscription_plans (Plan_name, Plan_type, user_type, price) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssd", $name, $type, $userType, $price);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Plan added successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error adding plan']);
            }
            $stmt->close();
            break;
            
        case 'edit':
            $id = intval($_POST['plan_id']);
            $name = trim($_POST['plan_name']);
            $type = $_POST['plan_type'];
            $userType = $_POST['user_type'];
            $price = floatval($_POST['price']);
            
            if ($id <= 0 || empty($name) || empty($type) || empty($userType) || $price <= 0) {
                echo json_encode(['success' => false, 'message' => 'All fields are required']);
                break;
            }
            
            $stmt = $conn->prepare("UPDATE subscription_plans SET Plan_name = ?, Plan_type = ?, user_type = ?, price = ? WHERE plan_id = ?");
            $stmt->bind_param("sssdi", $name, $type, $userType, $price, $id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Plan updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error updating plan']);
            }
            $stmt->close();
            break;
            
        case 'delete':
            $id = intval($_POST['plan_id']);
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid plan ID']);
                break;
            }
            
            // Check if plan is being used
            $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM user_subscriptions WHERE plan_id = ? AND Status = 'A'");
            $checkStmt->bind_param("i", $id);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['count'] > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete plan with active subscriptions']);
                $checkStmt->close();
                break;
            }
            $checkStmt->close();
            
            $stmt = $conn->prepare("DELETE FROM subscription_plans WHERE plan_id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Plan deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error deleting plan']);
            }
            $stmt->close();
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>
