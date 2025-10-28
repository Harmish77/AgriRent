<?php
// booking_form.php
session_start();
include 'auth/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

//--- IMPROVED BOOKING VALIDATION FUNCTION ---//
function validateBooking($equipment_id, $start_date, $end_date, $start_time, $end_time, $booking_type, $exclude_booking_id = null) {
    global $conn;

    // Minimum 2-hour booking validation
    if ($booking_type === 'hourly') {
        // Check if times are within allowed range (08:00 - 20:00)
        if ($start_time < '08:00' || $start_time > '20:00' || 
            $end_time < '08:00' || $end_time > '20:00') {
            return ['valid' => false, 'message' => 'Please select times between 08:00 AM and 08:00 PM'];
        }
        
        $start_datetime = strtotime($start_date . ' ' . $start_time);
        $end_datetime = strtotime($start_date . ' ' . $end_time);
        $hours_diff = ($end_datetime - $start_datetime) / 3600;

        if ($hours_diff < 2) {
            return ['valid' => false, 'message' => 'Minimum booking duration is 2 hours'];
        }
    }

    // Create requested booking timestamp range
    if ($booking_type === 'hourly') {
        $requested_start = strtotime($start_date . ' ' . $start_time);
        $requested_end = strtotime($start_date . ' ' . $end_time);
    } else {
        // Daily booking uses full day from 8 AM to 8 PM
        $requested_start = strtotime($start_date . ' 08:00:00');
        $requested_end = strtotime($end_date . ' 20:00:00');
    }

    // Get all existing CONFIRMED bookings for this equipment
    $sql = "SELECT eb.booking_id, eb.start_date, eb.end_date, eb.time_slot, eb.Hours, eb.customer_id, u.Name as customer_name
            FROM equipment_bookings eb
            JOIN users u ON eb.customer_id = u.user_id  
            WHERE eb.equipment_id = ? 
            AND eb.status = 'CON'";

    if ($exclude_booking_id) {
        $sql .= " AND eb.booking_id != ?";
    }

    $stmt = $conn->prepare($sql);
    if ($exclude_booking_id) {
        $stmt->bind_param('ii', $equipment_id, $exclude_booking_id);
    } else {
        $stmt->bind_param('i', $equipment_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    // If no confirmed bookings found, slot is available
    if ($result->num_rows == 0) {
        return ['valid' => true, 'message' => 'Booking slot available'];
    }

    $conflicting_bookings = [];

    while ($row = $result->fetch_assoc()) {
        // For existing bookings, reconstruct their time range properly
        if ($row['Hours'] >= 12) {
            // Daily booking (12+ hours) - 8 AM to 8 PM
            $existing_start = strtotime($row['start_date'] . ' 08:00:00');
            $existing_end = strtotime($row['end_date'] . ' 20:00:00');
        } else {
            // Hourly booking - parse time_slot field (format: "HH:MM-HH:MM")
            if (!empty($row['time_slot']) && strpos($row['time_slot'], '-') !== false) {
                list($slot_start_time, $slot_end_time) = explode('-', $row['time_slot']);
                $existing_start = strtotime($row['start_date'] . ' ' . $slot_start_time);
                $existing_end = strtotime($row['start_date'] . ' ' . $slot_end_time);
            } else {
                // Fallback: assume starts at 8:00 AM and calculate end time
                $existing_start = strtotime($row['start_date'] . ' 08:00:00');
                $existing_end = $existing_start + ($row['Hours'] * 3600);
            }
        }

        // Check for overlap: A overlaps B if (A_start < B_end AND A_end > B_start)
        if ($requested_start < $existing_end && $requested_end > $existing_start) {
            $conflicting_bookings[] = [
                'booking_id' => $row['booking_id'],
                'customer_name' => $row['customer_name']
            ];
        }
    }

    if (!empty($conflicting_bookings)) {
        return ['valid' => false, 'message' => 'Equipment is already booked during your selected time.<br><span>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>Please choose a different time slot.'];
    }

    return ['valid' => true, 'message' => 'Booking slot available'];
}

//--- DUPLICATE CHECK FUNCTION ---//
function checkDuplicatePendingBooking($equipment_id, $customer_id, $start_date, $end_date, $time_slot = null) {
    global $conn;

    // Check if this customer already has a pending booking for the same equipment and overlapping time
    if ($time_slot) {
        // For hourly bookings, check exact time slot match
        $sql = "SELECT booking_id FROM equipment_bookings 
                WHERE equipment_id = ? AND customer_id = ? 
                AND start_date = ? AND end_date = ? 
                AND time_slot = ? AND status = 'PEN'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iisss', $equipment_id, $customer_id, $start_date, $end_date, $time_slot);
    } else {
        // For daily bookings, check date overlap
        $sql = "SELECT booking_id FROM equipment_bookings 
                WHERE equipment_id = ? AND customer_id = ? 
                AND ((start_date <= ? AND end_date >= ?) OR (start_date <= ? AND end_date >= ?))
                AND status = 'PEN'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iissss', $equipment_id, $customer_id, $end_date, $start_date, $start_date, $end_date);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

//--- GET EQUIPMENT ID FROM URL ---//
$equipment_id = null;
if (isset($_GET['equipment_id']) && !empty($_GET['equipment_id'])) {
    $equipment_id = intval($_GET['equipment_id']);
} elseif (isset($_GET['id']) && !empty($_GET['id'])) {
    $equipment_id = intval($_GET['id']);
} elseif (isset($_POST['equipment_id']) && !empty($_POST['equipment_id'])) {
    $equipment_id = intval($_POST['equipment_id']);
}

// Validate equipment_id
if (!$equipment_id || $equipment_id <= 0) {
    echo "<div class='alert alert-danger'>Equipment ID not provided or invalid!</div>";
    echo "<a href='equipment_list.php' class='btn btn-secondary'>← Back to Equipment List</a>";
    exit;
}

// Get equipment details
$sql = "SELECT e.*, u.Name as owner_name 
        FROM equipment e 
        JOIN users u ON e.Owner_id = u.user_id 
        WHERE e.Equipment_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $equipment_id);
$stmt->execute();
$equipment = $stmt->get_result()->fetch_assoc();

if (!$equipment) {
    echo "<div class='alert alert-danger'>Equipment not found!</div>";
    echo "<a href='equipment_list.php' class='btn btn-secondary'>← Back to Equipment List</a>";
    exit;
}

//--- IMPROVED BOOKING REQUEST PROCESSING ---//
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_equipment') {
    $customer_id = $_SESSION['user_id'];
    $equipment_id = intval($_POST['equipment_id']);
    $booking_type = $_POST['booking_type'];
    $start_date = $_POST['start_date'];

    // Validate required fields
    if (empty($start_date) || empty($booking_type)) {
        $error_message = "Please fill in all required fields.";
    } else {
        if ($booking_type === 'hourly') {
            $end_date = $start_date;
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];
            $time_slot = $start_time . '-' . $end_time; // Store as "HH:MM-HH:MM"

            if (empty($start_time) || empty($end_time)) {
                $error_message = "Please select start time and end time for hourly booking.";
            } else {
                // Check if times are within allowed range (08:00 - 20:00)
                if ($start_time < '08:00' || $start_time > '20:00' || 
                    $end_time < '08:00' || $end_time > '20:00') {
                    $error_message = "Please select times between 08:00 AM and 08:00 PM";
                } else {
                    $start_datetime = strtotime($start_date . ' ' . $start_time);
                    $end_datetime = strtotime($start_date . ' ' . $end_time);
                    // Round hours to nearest 0.5 (half hour)
                    $hours = round(($end_datetime - $start_datetime) / 3600 * 2) / 2;
                    
                    if ($hours < 2) {
                        $error_message = "Minimum booking duration is 2 hours";
                    }
                }
            }
        } else {
            $end_date = $_POST['end_date'];
            $start_time = '08:00:00';
            $end_time = '20:00:00';
            $time_slot = '8AM - 8PM'; // Daily bookings use fixed 8AM-8PM slot

            if (empty($end_date)) {
                $error_message = "Please select end date for daily booking.";
            } else {
                $start = new DateTime($start_date);
                $end = new DateTime($end_date);
                $days = $start->diff($end)->days + 1;
                $hours = $days * 12; // 1 day = 12 hours
            }
        }

        // Only proceed if no validation errors
        if (!isset($error_message)) {
            // Check if owner is trying to book their own equipment
            if ($equipment['Owner_id'] == $customer_id) {
                $error_message = "You cannot book your own equipment";
            } else {
                // Step 1: Check for duplicate pending request
                if (checkDuplicatePendingBooking($equipment_id, $customer_id, $start_date, $end_date, $time_slot)) {
                    $error_message = "You already have a pending booking request for this equipment and time slot. Please wait for owner approval.";
                } else {
                    // Step 2: Validate booking for conflicts with confirmed bookings
                    $validation = validateBooking($equipment_id, $start_date, $end_date, $start_time, $end_time, $booking_type);

                    if (!$validation['valid']) {
                        $error_message = $validation['message'];
                    } else {
                        // Step 3: Calculate total amount
                        $rate_sql = "SELECT Hourly_rate, Daily_rate FROM equipment WHERE Equipment_id = ?";
                        $rate_stmt = $conn->prepare($rate_sql);
                        $rate_stmt->bind_param('i', $equipment_id);
                        $rate_stmt->execute();
                        $rates = $rate_stmt->get_result()->fetch_assoc();

                        if ($booking_type === 'hourly') {
                            if (empty($rates['Hourly_rate']) || $rates['Hourly_rate'] <= 0) {
                                $error_message = "Hourly rate not set for this equipment. Please contact the owner.";
                            } else {
                                $total_amount = $hours * $rates['Hourly_rate']; // Using rounded hours
                            }
                        } else {
                            if (empty($rates['Daily_rate']) || $rates['Daily_rate'] <= 0) {
                                $error_message = "Daily rate not set for this equipment. Please contact the owner.";
                            } else {
                                $total_amount = $days * $rates['Daily_rate']; // Daily rate per day
                            }
                        }

                        // Step 4: Insert booking request if amount is valid
                        if (!isset($error_message) && $total_amount > 0) {
                            // Begin transaction to ensure atomicity
                            $conn->begin_transaction();

                            try {
                                if ($time_slot) {
                                    $sql = "INSERT INTO equipment_bookings 
                                            (equipment_id, customer_id, start_date, end_date, time_slot, Hours, total_amount, status) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?, 'PEN')";
                                    $stmt = $conn->prepare($sql);
                                    $stmt->bind_param('iisssdd',
                                            $equipment_id, $customer_id, $start_date, $end_date, $time_slot, $hours, $total_amount
                                    );
                                } else {
                                    $sql = "INSERT INTO equipment_bookings 
                                            (equipment_id, customer_id, start_date, end_date, Hours, total_amount, status) 
                                            VALUES (?, ?, ?, ?, ?, ?, 'PEN')";
                                    $stmt = $conn->prepare($sql);
                                    $stmt->bind_param('iissdd',
                                            $equipment_id, $customer_id, $start_date, $end_date, $hours, $total_amount
                                    );
                                }

                                if ($stmt->execute()) {
                                    // Send notification to equipment owner
                                    $time_info = $time_slot ? " (" . $time_slot . ")" : "";
                                    $message = "New booking request received for your equipment: " . $equipment['Title'] . " from " . date('M j, Y', strtotime($start_date)) . " to " . date('M j, Y', strtotime($end_date)) . $time_info;
                                    $sql = "INSERT INTO messages (sender_id, receiver_id, Content) VALUES (?, ?, ?)";
                                    $msg_stmt = $conn->prepare($sql);
                                    $msg_stmt->bind_param('iis', $customer_id, $equipment['Owner_id'], $message);
                                    $msg_stmt->execute();

                                    $conn->commit();
                                    $success_message = "Booking request sent successfully! Waiting for owner approval.";
                                } else {
                                    throw new Exception("Database insert failed");
                                }
                            } catch (Exception $e) {
                                $conn->rollback();
                                $error_message = "Please try again later.";
                            }
                        }
                    }
                }
            }
        }
    }
}

//--- OWNER APPROVAL/REJECTION SYSTEM ---//
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_booking', 'reject_booking'])) {
    $booking_id = intval($_POST['booking_id']);
    $action = $_POST['action'];
    $owner_id = $_SESSION['user_id'];

    // Verify owner owns the equipment
    $sql = "SELECT eb.*, e.Owner_id, e.Title as equipment_title
            FROM equipment_bookings eb 
            JOIN equipment e ON eb.equipment_id = e.Equipment_id 
            WHERE eb.booking_id = ? AND e.Owner_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $booking_id, $owner_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    if (!$booking) {
        $error_message = "Invalid booking request";
    } else {
        if ($action === 'approve_booking') {
            $status = 'CON';
            $message = "Your booking request for " . $booking['equipment_title'] . " has been approved!";
        } else {
            $status = 'REJ';
            $message = "Your booking request for " . $booking['equipment_title'] . " has been rejected.";
        }

        // Update booking status
        $sql = "UPDATE equipment_bookings SET status = ? WHERE booking_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $status, $booking_id);

        if ($stmt->execute()) {
            // Send notification to customer
            $sql = "INSERT INTO messages (sender_id, receiver_id, Content) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iis', $owner_id, $booking['customer_id'], $message);
            $stmt->execute();

            $success_message = "Booking request " . ($action === 'approve_booking' ? 'approved' : 'rejected') . " successfully";
        }
    }
}

// Get owner bookings if user is owner
$owner_bookings = [];
if ($_SESSION['user_id'] == $equipment['Owner_id']) {
    $sql = "SELECT eb.*, e.Title as equipment_title, u.Name as customer_name 
            FROM equipment_bookings eb 
            JOIN equipment e ON eb.equipment_id = e.Equipment_id 
            JOIN users u ON eb.customer_id = u.user_id 
            WHERE e.Owner_id = ? 
            ORDER BY eb.booking_id DESC 
            LIMIT 10";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $owner_bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get equipment image
$image_sql = "SELECT image_url FROM images WHERE image_type = 'E' AND ID = ? LIMIT 1";
$image_stmt = $conn->prepare($image_sql);
$image_stmt->bind_param('i', $equipment_id);
$image_stmt->execute();
$image_result = $image_stmt->get_result();
$equipment_image = $image_result->fetch_assoc();
$equipment_image_url = $equipment_image ? $equipment_image['image_url'] : 'assets/img/default-equipment.jpg';

?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Book Equipment - <?php echo htmlspecialchars($equipment['Title']); ?> | AgriRent</title>

        <!-- Bootstrap CSS -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

        <!-- Font Awesome -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

        <!-- Custom CSS -->
        <style>
    /* Modern Agricultural Equipment Booking Form Styling */
    :root {
        --primary-green: #2d4a22;
        --secondary-green: #2d4a22;
        --accent-orange: #f57c00;
        --light-gray: #f8f9fa;
        --border-color: #e9ecef;
        --text-dark: #333;
        --text-muted: #666;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, var(--light-gray) 0%, var(--border-color) 100%);
        min-height: 100vh;
    }

    .equipment-rental-container {
        min-height: 100vh;
    }

    /* Header Styling */
    .booking-header {
        background: linear-gradient(90deg, var(--primary-green) 0%, var(--secondary-green) 100%);
        color: white;
        padding: 2rem 0;
        margin-bottom: 0;
    }

    .booking-title {
        font-size: 2.5rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
    }

    .booking-subtitle {
        font-size: 1.2rem;
        opacity: 0.9;
    }

    /* Breadcrumb */
    .breadcrumb-section {
        background: rgba(46, 125, 50, 0.1);
        padding: 1rem 0;
    }

    .breadcrumb {
        background: transparent;
        margin-bottom: 0;
    }

    .breadcrumb-item a {
        color: var(--primary-green);
        text-decoration: none;
    }

    .breadcrumb-item.active {
        color: var(--text-muted);
    }

    /* Main Content */
    .booking-main-section {
        padding: 3rem 0;
    }

    /* Equipment Summary Card */
    .equipment-summary-card {
        background: white;
        border-radius: 20px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        position: sticky;
        top: 2rem;
        border: none;
    }

    .equipment-summary-card .card-header {
        background: linear-gradient(135deg, var(--primary-green) 0%, var(--secondary-green) 100%);
        color: white;
        padding: 1.5rem 2rem;
        border: none;
    }

    .equipment-summary-card .card-body {
        padding: 2rem;
    }

    .equipment-image {
        position: relative;
        overflow: hidden;
        border-radius: 15px;
        margin-bottom: 1.5rem;
    }

    .equipment-image img {
        width: 100%;
        height: 250px;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    .equipment-image:hover img {
        transform: scale(1.05);
    }

    .equipment-title {
        color: var(--primary-green);
        font-weight: 700;
        font-size: 1.4rem;
        margin-bottom: 1rem;
    }

    .equipment-details {
        margin-bottom: 1.5rem;
    }

    .detail-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--border-color);
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    .detail-label {
        font-weight: 600;
        color: var(--text-muted);
    }

    .detail-value {
        color: var(--text-dark);
        font-weight: 500;
    }

    /* Pricing Info */
    .pricing-info {
        background: linear-gradient(135deg, #e8f5e8 0%, #f1f8e9 100%);
        padding: 1.5rem;
        border-radius: 15px;
        margin-bottom: 1.5rem;
    }

    .price-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .price-item:last-child {
        margin-bottom: 0;
    }

    .price-item i {
        font-size: 1.2rem;
    }

    /* Booking Summary Display */
    .booking-summary-display {
        background: white;
        border: 3px solid var(--primary-green);
        border-radius: 15px;
        padding: 1.5rem;
    }

    .summary-title {
        color: var(--primary-green);
        font-weight: 700;
        font-size: 1.2rem;
        margin-bottom: 1rem;
        text-align: center;
    }

    .summary-content {
        space-y: 0.5rem;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 0;
        border-bottom: 1px solid var(--border-color);
    }

    .summary-row:last-child {
        border-bottom: none;
    }

    .summary-row.total-amount {
        border-top: 3px solid var(--primary-green);
        margin-top: 1rem;
        padding-top: 1rem;
        font-weight: 700;
        font-size: 1.3rem;
        color: var(--primary-green);
    }

    /* Booking Form Card */
    .booking-form-card {
        background: white;
        border-radius: 20px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        border: none;
    }

    .booking-form-card .card-header {
        background: linear-gradient(135deg, var(--primary-green) 0%, var(--secondary-green) 100%);
        color: white;
        padding: 1.5rem 2rem;
        border: none;
    }

    .booking-form-card .card-body {
        padding: 2rem;
    }

    /* Form Styling */
    .equipment-booking-form .form-label {
        font-weight: 600;
        color: var(--text-dark);
        margin-bottom: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .equipment-booking-form .form-control {
        border: 2px solid var(--border-color);
        border-radius: 12px;
        padding: 0.875rem 1.25rem;
        font-size: 1rem;
        transition: all 0.3s ease;
    }

    .equipment-booking-form .form-control:focus {
        border-color: var(--primary-green);
        box-shadow: 0 0 0 0.2rem rgba(46, 125, 50, 0.25);
        transform: translateY(-1px);
    }

    /* Booking Type Options with Unified Hover Effects */
    .booking-type-options {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .booking-option .btn {
        width: 100%;
        padding: 1.25rem;
        border: 3px solid var(--border-color);
        border-radius: 15px;
        transition: all 0.3s ease;
        text-align: center;
        background: white;
        color: var(--text-dark);
    }

    /* Unified hover effect for both hourly and daily booking buttons */
    .booking-option .btn:hover,
    .booking-option .btn-outline-success:hover,
    .booking-option .btn-outline-primary:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        border-color: var(--primary-green);
        background-color: rgba(45, 74, 34, 0.05);
        color: var(--primary-green);
    }

    /* Active/Checked state for both button types */
    .booking-option .btn-check:checked + .btn-outline-success,
    .booking-option .btn-check:checked + .btn-outline-primary {
        background-color: var(--primary-green) !important;
        border-color: var(--primary-green) !important;
        color: white !important;
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(46, 125, 50, 0.3);
    }

    /* Ensure white text in checked state */
    .booking-option .btn-check:checked + .btn-outline-success *,
    .booking-option .btn-check:checked + .btn-outline-primary * {
        color: white !important;
    }

    /* Focus states for accessibility */
    .booking-option .btn:focus,
    .booking-option .btn-outline-success:focus,
    .booking-option .btn-outline-primary:focus {
        box-shadow: 0 0 0 0.2rem rgba(46, 125, 50, 0.25);
        outline: none;
    }

    /* Form Field Groups */
    .booking-time-fields,
    .booking-date-fields {
        background: linear-gradient(135deg, #f0f4ff 0%, #e8f2ff 100%);
        padding: 1.5rem;
        border-radius: 15px;
        margin-bottom: 1.5rem;
    }

    /* Form Actions */
    .form-actions .btn {
        border-radius: 12px;
        padding: 1rem 2rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        transition: all 0.3s ease;
        font-size: 1.1rem;
        background-color: #2d4a22;
        color: white;
    }

    .form-actions .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
    }

    .form-footer {
        text-align: center;
        margin-top: 1.5rem;
        padding: 1rem;
        background: var(--light-gray);
        border-radius: 10px;
    }

    /* Owner Bookings Card */
    .owner-bookings-card {
        background: white;
        border-radius: 20px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        border: none;
    }

    .owner-bookings-card .card-header {
        background: linear-gradient(135deg, var(--accent-orange) 0%, #ff9800 100%);
        color: white;
        padding: 1.5rem 2rem;
        border: none;
    }

    /* Table Styling */
    .table-hover tbody tr:hover {
        background-color: rgba(46, 125, 50, 0.05);
        transform: translateX(5px);
        transition: all 0.3s ease;
    }

    .table th {
        background-color: var(--light-gray);
        border: none;
        font-weight: 600;
        color: var(--text-dark);
    }

    .table td {
        border: none;
        border-bottom: 1px solid var(--border-color);
        vertical-align: middle;
    }

    /* Alert Styling */
    .alert {
        border-radius: 12px;
        border: none;
        padding: 1rem 1.5rem;
        margin-bottom: 1.5rem;
    }

    .alert-success {
        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
        color: #155724;
    }

    .alert-danger {
        background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
        color: #721c24;
    }

    .alert-info {
        background: linear-gradient(135deg, #d1ecf1 0%, #b6e3f0 100%);
        color: #0c5460;
    }

    /* Badge Styling */
    .badge {
        font-size: 0.85rem;
        padding: 0.5rem 0.75rem;
        border-radius: 8px;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .booking-title {
            font-size: 2rem;
        }

        .booking-main-section {
            padding: 2rem 0;
        }

        .equipment-summary-card {
            position: static;
            margin-bottom: 2rem;
        }

        .equipment-summary-card .card-body,
        .booking-form-card .card-body {
            padding: 1.5rem;
        }

        .booking-time-fields,
        .booking-date-fields {
            padding: 1rem;
        }

        .booking-type-options {
            grid-template-columns: 1fr;
        }
    }

    /* Loading Animation */
    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }
        100% {
            transform: rotate(360deg);
        }
    }

    .spinner-border {
        animation: spin 1s linear infinite;
    }

    /* Card Hover Effects */
    .equipment-summary-card,
    .booking-form-card,
    .owner-bookings-card {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .equipment-summary-card:hover,
    .booking-form-card:hover,
    .owner-bookings-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
    }

    /* Button Group Styling */
    .btn-group .btn {
        margin: 0 2px;
    }
</style>
    </head>

    <body>
        <?php
include 'includes/header.php';
include 'includes/navigation.php';
?>
        <div class="equipment-rental-container">
            <!-- Main Booking Section -->
            <div class="booking-main-section">
                <div class="container">
                    <nav style="margin-bottom: 20px;">
                        <a href="index.php" style="color: #666; text-decoration: none;">Home</a> › 
                        <a href="equipments.php" style="color: #666; text-decoration: none;">Equipment</a> ›
                        <a href="equipment_details.php?id=<?= urlencode($equipment['Equipment_id']) ?>" 
                           style="color: #666; text-decoration: none;">
                               <?= htmlspecialchars($equipment['Title']) ?>
                        </a> ›
                        <span style="color: #234a23; font-weight: bold;">Booking Form</span>
                    </nav>
                    <div class="row">
                        <!-- Left Side - Equipment Summary -->
                        <div class="col-lg-5 col-md-12 mb-4">
                            <div class="equipment-summary-card card">
                                <div class="card-header">
                                    <h4 class="mb-0">
                                        
                                        Equipment Summary
                                    </h4>
                                </div>
                                <div class="card-body">
                                    <div class="equipment-info">
                                        <div class="equipment-image">
                                            <img src="<?php echo htmlspecialchars($equipment_image_url); ?>" 
                                                 alt="<?php echo htmlspecialchars($equipment['Title']); ?>" 
                                                 class="img-fluid">
                                        </div>

                                        <h5 class="equipment-title"><?php echo htmlspecialchars($equipment['Title']); ?></h5>

                                        <div class="equipment-details">
                                            <div class="detail-row">
                                                <span class="detail-label">Brand:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($equipment['Brand']); ?></span>
                                            </div>
                                            <div class="detail-row">
                                                <span class="detail-label">Model:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($equipment['Model']); ?></span>
                                            </div>
                                            <div class="detail-row">
                                                <span class="detail-label">Year:</span>
                                                <span class="detail-value"><?php echo $equipment['Year'] ? $equipment['Year'] : 'N/A'; ?></span>
                                            </div>
                                            <div class="detail-row">
                                                <span class="detail-label">Owner:</span>
                                                <span class="detail-value"><?php echo htmlspecialchars($equipment['owner_name']); ?></span>
                                            </div>
                                        </div>

                                        <div class="pricing-info">
                                            <h6 class="mb-3 text-center text-success">
                                                <i class="fas fa-tag me-2"></i>
                                                Pricing Information
                                            </h6>
                                            <?php if ($equipment['Hourly_rate'] && $equipment['Hourly_rate'] > 0): ?>
                                                <div class="price-item">
                                                    <i class="fas fa-clock text-success"></i>
                                                    <span>Hourly: <strong>₹<?php echo number_format($equipment['Hourly_rate'], 2); ?>/hour</strong> (8AM-8PM)</span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($equipment['Daily_rate'] && $equipment['Daily_rate'] > 0): ?>
                                                <div class="price-item">
                                                    <i class="fas fa-calendar-day text-primary"></i>
                                                    <span>Daily: <strong>₹<?php echo number_format($equipment['Daily_rate'], 2); ?>/day</strong> (8AM-8PM)</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Booking Summary Display -->
                                        <div class="booking-summary-display">
                                            <h6 class="summary-title">
                                                <i class="fas fa-calculator me-2"></i>
                                                Booking Summary
                                            </h6>
                                            <div class="summary-content">
                                                <div class="summary-row">
                                                    <span><i class="fas fa-hourglass-half me-2"></i>Duration:</span>
                                                    <span id="duration-display" class="fw-bold">-</span>
                                                </div>
                                                <div class="summary-row">
                                                    <span><i class="fas fa-money-bill-wave me-2"></i>Rate:</span>
                                                    <span id="rate-display" class="fw-bold">-</span>
                                                </div>
                                                <div class="summary-row total-amount">
                                                    <span><i class="fas fa-rupee-sign me-2"></i>Total Amount:</span>
                                                    <span id="total-amount-display" class="fw-bold">₹0.00</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Side - Booking Form -->
                        <div class="col-lg-7 col-md-12">
                            <div class="booking-form-card card">
                                <div class="card-header">
                                    <h4 class="mb-0">
                                        
                                        Book This Equipment
                                    </h4>
                                </div>
                                <div class="card-body">
                                    <!-- Success/Error Messages -->
                                    <?php if (isset($success_message)): ?>
                                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                                            <i class="fas fa-check-circle me-2"></i>
                                            <?php echo $success_message; ?>
                                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (isset($error_message)): ?>
                                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            <?php echo $error_message; ?>
                                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Booking Form -->
                                    <?php if ($_SESSION['user_id'] != $equipment['Owner_id'] && $equipment['Approval_status'] == 'CON'): ?>
                                        <form id="bookingForm" method="POST" action="" class="equipment-booking-form">
                                            <input type="hidden" name="action" value="book_equipment">
                                            <input type="hidden" name="equipment_id" value="<?php echo $equipment_id; ?>">

                                            <!-- Booking Type Selection -->
                                            <div class="form-group mb-4">
                                                <label class="form-label">
                                                    <i class="fas fa-clock me-2"></i>
                                                    Select Booking Type
                                                </label>
                                                <div class="booking-type-options">
                                                    <?php if ($equipment['Hourly_rate'] && $equipment['Hourly_rate'] > 0): ?>
                                                        <div class="booking-option">
                                                            <input type="radio" class="btn-check" name="booking_type" id="hourly" value="hourly" checked>
                                                            <label class="btn btn-outline-success" for="hourly">
                                                                <i class="fas fa-clock d-block mb-2" style="font-size: 1.5rem;"></i>
                                                                <strong>Hourly Booking</strong>
                                                                <small class="d-block mt-1">₹<?php echo number_format($equipment['Hourly_rate'], 2); ?>/hour</small>
                                                                <small class="text-muted d-block">8:00 AM - 8:00 PM only</small>
                                                            </label>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($equipment['Daily_rate'] && $equipment['Daily_rate'] > 0): ?>
                                                        <div class="booking-option">
                                                            <input type="radio" class="btn-check" name="booking_type" id="daily" value="daily">
                                                            <label class="btn btn-outline-primary" for="daily">
                                                                <i class="fas fa-calendar-day d-block mb-2" style="font-size: 1.5rem;"></i>
                                                                <strong>Daily Booking</strong>
                                                                <small class="d-block mt-1">₹<?php echo number_format($equipment['Daily_rate'], 2); ?>/day</small>
                                                                <small class="text-muted d-block">8:00 AM - 8:00 PM (12 hrs)</small>
                                                            </label>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Start Date -->
                                            <div class="form-group mb-3">
                                                <label for="start_date" class="form-label">
                                                    <i class="fas fa-calendar-alt me-2"></i>
                                                    Start Date
                                                </label>
                                                <input type="date" 
                                                       class="form-control" 
                                                       name="start_date" 
                                                       id="start_date" 
                                                       required 
                                                       min="<?php echo date('Y-m-d'); ?>">
                                            </div>

                                            <!-- Hourly Fields -->
                                            <div id="hourly-fields" class="booking-time-fields">
                                                <div class="row">
                                                    <div class="col-md-6 mb-3">
                                                        <label for="start_time" class="form-label">
                                                            <i class="fas fa-clock me-2"></i>
                                                            Start Time
                                                        </label>
                                                        <input type="time" class="form-control" name="start_time" id="start_time" min="08:00" max="20:00">
                                                    </div>
                                                    <div class="col-md-6 mb-3">
                                                        <label for="end_time" class="form-label">
                                                            <i class="fas fa-clock me-2"></i>
                                                            End Time
                                                        </label>
                                                        <input type="time" class="form-control" name="end_time" id="end_time" min="08:00" max="20:00">
                                                    </div>
                                                </div>
                                                <div class="alert alert-info mb-0">
                                                    <i class="fas fa-info-circle me-2"></i>
                                                    <small><strong>Available from 08:00 AM to 08:00 PM only</strong> - Minimum 2 hours duration</small>
                                                </div>
                                            </div>

                                            <!-- Daily Fields -->
                                            <div id="daily-fields" class="booking-date-fields" style="display: none;">
                                                <div class="form-group mb-3">
                                                    <label for="end_date" class="form-label">
                                                        <i class="fas fa-calendar-check me-2"></i>
                                                        End Date
                                                    </label>
                                                    <input type="date" 
                                                           class="form-control" 
                                                           name="end_date" 
                                                           id="end_date" 
                                                           min="<?php echo date('Y-m-d'); ?>">
                                                </div>
                                                <div class="alert alert-info mb-0">
                                                    <i class="fas fa-info-circle me-2"></i>
                                                    <small><strong>Daily booking: 8:00 AM - 8:00 PM (12 hours)</strong> - Each day includes full 12-hour usage</small>
                                                </div>
                                            </div>

                                            <!-- Submit Button -->
                                            <div class="form-actions mt-4">
                                                <button type="submit" id="submitBtn" class="btn btn-success btn-lg w-100">
                                                    <i class="fas fa-paper-plane me-2"></i>
                                                    Send Booking Request
                                                    <span class="spinner-border spinner-border-sm ms-2 d-none" id="loading-spinner" role="status" aria-hidden="true"></span>
                                                </button>
                                            </div>

                                            <div class="form-footer">
                                                <p class="text-muted mb-0">
                                                    <i class="fas fa-shield-alt me-2"></i>
                                                    Your booking request will be sent to the equipment owner for approval. 
                                                    You will receive a notification once the owner responds to your request.
                                                </p>
                                            </div>
                                        </form>

                                    <?php elseif ($_SESSION['user_id'] == $equipment['Owner_id']): ?>
                                        <div class="alert alert-info text-center">
                                            <i class="fas fa-info-circle me-2 fs-4"></i>
                                            <h5 class="mt-2">You own this equipment</h5>
                                            <p>You cannot book your own equipment. <br>Check your dashboard for booking requests from other users.</p>
                                            <a href="owner_dashboard.php" class="btn btn-primary mt-2">
                                                <i class="fas fa-tachometer-alt me-2"></i>
                                                Go to Dashboard
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-warning text-center">
                                            <i class="fas fa-exclamation-triangle me-2 fs-4"></i>
                                            <h5 class="mt-2">Equipment Not Available</h5>
                                            <p>This equipment is currently not available for booking. <br>Please contact the owner for more information.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Owner Booking Requests (if owner) -->
                            <?php if (!empty($owner_bookings)): ?>
                                <div class="owner-bookings-card card mt-4">
                                    <div class="card-header">
                                        <h5 class="mb-0">
                                            <i class="fas fa-list-alt me-2"></i>
                                            Recent Booking Requests
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Customer</th>
                                                        <th>Date Range</th>
                                                        <th>Duration</th>
                                                        <th>Amount</th>
                                                        <th>Status</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($owner_bookings as $booking): ?>
                                                        <?php
                                                        $status_class = [
                                                            'CON' => 'bg-success',
                                                            'PEN' => 'bg-warning text-dark',
                                                            'REJ' => 'bg-danger'
                                                                ][$booking['status']] ?? 'bg-secondary';

                                                        $status_text = [
                                                            'CON' => 'Confirmed',
                                                            'PEN' => 'Pending',
                                                            'REJ' => 'Rejected'
                                                                ][$booking['status']] ?? 'Unknown';

                                                        $date_display = date('M j, Y', strtotime($booking['start_date']));
                                                        if ($booking['start_date'] !== $booking['end_date']) {
                                                            $date_display .= ' - ' . date('M j, Y', strtotime($booking['end_date']));
                                                        }
                                                        ?>
                                                        <tr>
                                                            <td>
                                                                <strong><?php echo htmlspecialchars($booking['customer_name']); ?></strong>
                                                            </td>
                                                            <td><?php echo $date_display; ?></td>
                                                            <td>
                                                                <?php if ($booking['time_slot']): ?>
                                                                    <?php echo $booking['time_slot']; ?>
                                                                <?php else: ?>
                                                                    <?php echo $booking['Hours']; ?> hrs
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><strong>₹<?php echo number_format($booking['total_amount'], 2); ?></strong></td>
                                                            <td>
                                                                <span class="badge <?php echo $status_class; ?>">
                                                                    <?php echo $status_text; ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <?php if ($booking['status'] === 'PEN'): ?>
                                                                    <div class="btn-group" role="group" aria-label="Booking actions">
                                                                        <form method="POST" style="display: inline;">
                                                                            <input type="hidden" name="booking_id" value="<?php echo $booking['booking_id']; ?>">
                                                                            <input type="hidden" name="action" value="approve_booking">
                                                                            <button type="submit" 
                                                                                    class="btn btn-sm btn-success" 
                                                                                    onclick="return confirm('Are you sure you want to approve this booking?')"
                                                                                    title="Approve Booking">
                                                                                <i class="fas fa-check"></i>
                                                                            </button>
                                                                        </form>
                                                                        <form method="POST" style="display: inline;">
                                                                            <input type="hidden" name="booking_id" value="<?php echo $booking['booking_id']; ?>">
                                                                            <input type="hidden" name="action" value="reject_booking">
                                                                            <button type="submit" 
                                                                                    class="btn btn-sm btn-danger" 
                                                                                    onclick="return confirm('Are you sure you want to reject this booking?')"
                                                                                    title="Reject Booking">
                                                                                <i class="fas fa-times"></i>
                                                                            </button>
                                                                        </form>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <span class="text-muted">
                                                                        <i class="fas fa-check-circle"></i>
                                                                        Processed
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bootstrap JS -->
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

        <!-- Custom JavaScript -->
        <script>
            let isSubmitting = false;

            // Time range validation (8 AM to 8 PM)
            function validateTimeRange(inputElement) {
                const val = inputElement.value;
                if (val && (val < '08:00' || val > '20:00')) {
                    alert('Please select a time between 08:00 AM and 08:00 PM');
                    inputElement.value = '';
                    inputElement.focus();
                    return false;
                }
                return true;
            }

            // Round to nearest half hour (0.5)
            function roundToHalf(num) {
                return Math.round(num * 2) / 2;
            }

            // Auto calculate amount when inputs change
            function autoCalculateAmount() {
                const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;
                const startTime = document.getElementById('start_time').value;
                const endTime = document.getElementById('end_time').value;

                if (!startDate) return;

                if (bookingType === 'hourly') {
                    if (!startTime || !endTime) return;

                    const startDT = new Date(`${startDate}T${startTime}:00`);
                    const endDT = new Date(`${startDate}T${endTime}:00`);
                    let diffHrs = (endDT - startDT) / (1000 * 60 * 60);

                    if (diffHrs <= 0) {
                        document.getElementById('duration-display').textContent = 'Invalid time';
                        document.getElementById('total-amount-display').textContent = '₹0.00';
                        document.getElementById('rate-display').textContent = '-';
                        return;
                    }

                    const roundedHrs = roundToHalf(diffHrs);
                    document.getElementById('duration-display').textContent = roundedHrs + ' hrs';
                    document.getElementById('rate-display').textContent = '₹<?php echo number_format($equipment['Hourly_rate'], 2); ?>/hour';

                    const hourlyRate = <?php echo $equipment['Hourly_rate'] ?? 0; ?>;
                    const totalAmount = roundedHrs * hourlyRate;
                    document.getElementById('total-amount-display').textContent = '₹' + totalAmount.toFixed(2);

                } else {
                    if (!endDate) return;

                    const startDT = new Date(startDate);
                    const endDT = new Date(endDate);
                    let diffDays = Math.ceil((endDT - startDT) / (1000 * 60 * 60 * 24)) + 1;

                    if (diffDays <= 0) {
                        document.getElementById('duration-display').textContent = 'Invalid dates';
                        document.getElementById('total-amount-display').textContent = '₹0.00';
                        document.getElementById('rate-display').textContent = '-';
                        return;
                    }

                    const totalHrs = diffDays * 12;
                    document.getElementById('duration-display').textContent = totalHrs + ' hrs (' + diffDays + ' day(s))';
                    document.getElementById('rate-display').textContent = '₹<?php echo number_format($equipment['Daily_rate'], 2); ?>/day';

                    const dailyRate = <?php echo $equipment['Daily_rate'] ?? 0; ?>;
                    const totalAmount = diffDays * dailyRate;
                    document.getElementById('total-amount-display').textContent = '₹' + totalAmount.toFixed(2);
                }
            }

            function toggleBookingFields() {
                const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
                const hourlyFields = document.getElementById('hourly-fields');
                const dailyFields = document.getElementById('daily-fields');

                if (bookingType === 'hourly') {
                    hourlyFields.style.display = 'block';
                    dailyFields.style.display = 'none';
                } else {
                    hourlyFields.style.display = 'none';
                    dailyFields.style.display = 'block';
                }

                // Reset displays
                document.getElementById('duration-display').textContent = '-';
                document.getElementById('total-amount-display').textContent = '₹0.00';
                document.getElementById('rate-display').textContent = '-';
            }

            // Add event listeners for time validation
            document.getElementById('start_time').addEventListener('change', function() {
                if (validateTimeRange(this)) {
                    autoCalculateAmount();
                }
            });

            document.getElementById('end_time').addEventListener('change', function() {
                if (validateTimeRange(this)) {
                    const bookingType = document.querySelector('input[name="booking_type"]:checked').value;
                    if (bookingType === 'hourly') {
                        const startTime = document.getElementById('start_time').value;
                        const endTime = this.value;

                        if (startTime && endTime) {
                            const start = new Date('2000-01-01T' + startTime + ':00');
                            const end = new Date('2000-01-01T' + endTime + ':00');
                            const hours = roundToHalf((end - start) / (1000 * 60 * 60));

                            if (hours <= 0) {
                                alert('End time must be after start time');
                                this.value = '';
                                return;
                            }

                            if (hours < 2) {
                                alert('Minimum booking duration is 2 hours');
                                this.value = '';
                                return;
                            }
                        }
                    }
                    autoCalculateAmount();
                }
            });

            document.getElementById('start_date').addEventListener('change', function() {
                document.getElementById('end_date').min = this.value;
                autoCalculateAmount();
            });

            document.getElementById('end_date').addEventListener('change', function() {
                const startDate = document.getElementById('start_date').value;
                const endDate = this.value;

                if (startDate && endDate) {
                    if (new Date(endDate) < new Date(startDate)) {
                        alert('End date cannot be before start date');
                        this.value = '';
                        return;
                    }
                }
                autoCalculateAmount();
            });

            document.querySelectorAll('input[name="booking_type"]').forEach(radio => {
                radio.addEventListener('change', function () {
                    toggleBookingFields();
                    autoCalculateAmount();
                });
            });

            // Form submission handling
            document.getElementById('bookingForm').addEventListener('submit', function (e) {
                if (isSubmitting) {
                    e.preventDefault();
                    return false;
                }

                isSubmitting = true;
                const submitBtn = document.getElementById('submitBtn');
                const spinner = document.getElementById('loading-spinner');

                submitBtn.disabled = true;
                spinner.classList.remove('d-none');
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing Request...';
            });

            // Set default time values within allowed range
            document.addEventListener('DOMContentLoaded', function() {
                const startTimeInput = document.getElementById('start_time');
                const endTimeInput = document.getElementById('end_time');
                
                // Set default start time to 9:00 AM and end time to 11:00 AM (2-hour minimum)
                if (!startTimeInput.value) {
                    startTimeInput.value = '09:00';
                }
                if (!endTimeInput.value) {
                    endTimeInput.value = '11:00';
                }
                
                // Initialize
                toggleBookingFields();
                autoCalculateAmount();
            });

            // Auto-hide alerts after 5 seconds
            setTimeout(function () {
                const alerts = document.querySelectorAll('.alert.fade.show');
                alerts.forEach(function (alert) {
                    const closeBtn = alert.querySelector('.btn-close');
                    if (closeBtn) {
                        closeBtn.click();
                    }
                });
            }, 5000);
        </script>
    </body>
</html>

<?php include 'includes/footer.php'; ?>
