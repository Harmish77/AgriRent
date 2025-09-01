<?php
session_start();
require_once('../auth/config.php');

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    exit();
}

$current_user_id = $_SESSION['user_id'];
$chat_with_user_id = $_GET['user'] ?? null;

if (!$chat_with_user_id) {
    exit();
}

// Get messages between current user and selected user
$messages_stmt = $conn->prepare("SELECT m.*, u.Name as sender_name 
    FROM messages m 
    JOIN users u ON m.sender_id = u.user_id 
    WHERE (m.sender_id = ? AND m.receiver_id = ?) 
       OR (m.sender_id = ? AND m.receiver_id = ?) 
    ORDER BY m.sent_at ASC");
$messages_stmt->bind_param("iiii", $current_user_id, $chat_with_user_id, $chat_with_user_id, $current_user_id);
$messages_stmt->execute();
$messages_result = $messages_stmt->get_result();

if ($messages_result->num_rows > 0) {
    while ($message = $messages_result->fetch_assoc()) {
        $message_class = $message['sender_id'] == $current_user_id ? 'message-sent' : 'message-received';
        echo '<div class="message ' . $message_class . '">';
        echo '<div class="message-content">' . nl2br(htmlspecialchars($message['Content'])) . '</div>';
        echo '<div class="message-time">' . date('M j, g:i A', strtotime($message['sent_at'])) . '</div>';
        echo '</div>';
    }
} else {
    echo '<div class="no-messages"><p>No messages yet. Start the conversation!</p></div>';
}

$messages_stmt->close();
?>
