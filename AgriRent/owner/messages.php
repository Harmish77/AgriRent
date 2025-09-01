<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Equipment Owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header('Location: ../login.php');
    exit();
}

$current_user_id = $_SESSION['user_id'];
$current_user_type = $_SESSION['user_type'];

// Get conversation partner if specified
$chat_with_user_id = $_GET['user'] ?? null;

// Handle sending new message
if ($_POST && isset($_POST['send_message'])) {
    $receiver_id = intval($_POST['receiver_id']);
    $content = trim($_POST['content']);
    
    if (!empty($content) && $receiver_id > 0) {
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, Content, sent_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $current_user_id, $receiver_id, $content);
        $stmt->execute();
        $stmt->close();
        
        // Redirect to avoid form resubmission
        header("Location: messages.php?user=$receiver_id");
        exit();
    }
}

// Get list of users to chat with (excluding current user)
$users_query = "SELECT user_id, Name, User_type FROM users WHERE user_id != ? ORDER BY Name";
$stmt = $conn->prepare($users_query);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$users_result = $stmt->get_result();
$available_users = [];
while ($user = $users_result->fetch_assoc()) {
    $available_users[] = $user;
}
$stmt->close();

// Get conversations (users who have exchanged messages with current user)
$conversations_query = "SELECT DISTINCT 
    CASE 
        WHEN sender_id = ? THEN receiver_id 
        ELSE sender_id 
    END as other_user_id,
    u.Name as other_user_name,
    u.User_type as other_user_type,
    (SELECT Content FROM messages 
     WHERE (sender_id = ? AND receiver_id = other_user_id) 
        OR (sender_id = other_user_id AND receiver_id = ?) 
     ORDER BY sent_at DESC LIMIT 1) as last_message,
    (SELECT sent_at FROM messages 
     WHERE (sender_id = ? AND receiver_id = other_user_id) 
        OR (sender_id = other_user_id AND receiver_id = ?) 
     ORDER BY sent_at DESC LIMIT 1) as last_message_time,
    (SELECT COUNT(*) FROM messages 
     WHERE sender_id = other_user_id AND receiver_id = ? AND is_read = FALSE) as unread_count
FROM messages m
JOIN users u ON u.user_id = CASE 
    WHEN m.sender_id = ? THEN m.receiver_id 
    ELSE m.sender_id 
END
WHERE ? IN (m.sender_id, m.receiver_id)
ORDER BY last_message_time DESC";

$stmt = $conn->prepare($conversations_query);
$stmt->bind_param("iiiiiiii", $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id, $current_user_id);
$stmt->execute();
$conversations_result = $stmt->get_result();
$conversations = [];
while ($conv = $conversations_result->fetch_assoc()) {
    $conversations[] = $conv;
}
$stmt->close();

// Get messages for selected conversation
$messages = [];
$chat_partner_info = null;

if ($chat_with_user_id) {
    // Mark messages as read
    $mark_read_stmt = $conn->prepare("UPDATE messages SET is_read = TRUE WHERE sender_id = ? AND receiver_id = ?");
    $mark_read_stmt->bind_param("ii", $chat_with_user_id, $current_user_id);
    $mark_read_stmt->execute();
    $mark_read_stmt->close();
    
    // Get chat partner info
    $partner_stmt = $conn->prepare("SELECT user_id, Name, User_type FROM users WHERE user_id = ?");
    $partner_stmt->bind_param("i", $chat_with_user_id);
    $partner_stmt->execute();
    $partner_result = $partner_stmt->get_result();
    $chat_partner_info = $partner_result->fetch_assoc();
    $partner_stmt->close();
    
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
    while ($message = $messages_result->fetch_assoc()) {
        $messages[] = $message;
    }
    $messages_stmt->close();
}

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../assets/css/equipment.css">

<div class="main-content">
    <h1>Messages</h1>
    <p style="color: #666; margin-bottom: 30px;">Chat with farmers, other equipment owners, and administrators</p>

    <div class="chat-container">
        <!-- Left Sidebar: Conversations List -->
        <div class="chat-sidebar">
            <h3>Conversations</h3>
            
            <!-- New Chat Button -->
            <div class="new-chat-section">
                <button onclick="showNewChatModal()" class="action-btn" style="width: 100%; margin-bottom: 15px;">
                    ➕ Start New Chat
                </button>
            </div>
            
            <!-- Conversations List -->
            <div class="conversations-list">
                <?php if (count($conversations) > 0): ?>
                    <?php foreach ($conversations as $conv): ?>
                        <div class="conversation-item <?= $chat_with_user_id == $conv['other_user_id'] ? 'active' : '' ?>" 
                             onclick="window.location.href='messages.php?user=<?= $conv['other_user_id'] ?>'">
                            <div class="conv-avatar">
                                <?= strtoupper(substr($conv['other_user_name'], 0, 2)) ?>
                            </div>
                            <div class="conv-info">
                                <div class="conv-name">
                                    <?= htmlspecialchars($conv['other_user_name']) ?>
                                    <span class="user-type-badge user-type-<?= strtolower($conv['other_user_type']) ?>">
                                        <?= $conv['other_user_type'] == 'F' ? 'Farmer' : ($conv['other_user_type'] == 'O' ? 'Owner' : 'Admin') ?>
                                    </span>
                                </div>
                                <div class="conv-last-message">
                                    <?= htmlspecialchars(substr($conv['last_message'], 0, 50)) ?><?= strlen($conv['last_message']) > 50 ? '...' : '' ?>
                                </div>
                                <div class="conv-time">
                                    <?= date('M j, g:i A', strtotime($conv['last_message_time'])) ?>
                                </div>
                            </div>
                            <?php if ($conv['unread_count'] > 0): ?>
                                <div class="unread-count"><?= $conv['unread_count'] ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-conversations">
                        <p>No conversations yet.</p>
                        <p>Start chatting with farmers or administrators!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right Panel: Chat Area -->
        <div class="chat-area">
            <?php if ($chat_partner_info): ?>
                <!-- Chat Header -->
                <div class="chat-header">
                    <div class="chat-partner-info">
                        <div class="partner-avatar">
                            <?= strtoupper(substr($chat_partner_info['Name'], 0, 2)) ?>
                        </div>
                        <div class="partner-details">
                            <div class="partner-name"><?= htmlspecialchars($chat_partner_info['Name']) ?></div>
                            <div class="partner-type">
                                <?= $chat_partner_info['User_type'] == 'F' ? 'Farmer' : ($chat_partner_info['User_type'] == 'O' ? 'Equipment Owner' : 'Administrator') ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages Area -->
                <div class="messages-area" id="messagesArea">
                    <?php if (count($messages) > 0): ?>
                        <?php foreach ($messages as $message): ?>
                            <div class="message <?= $message['sender_id'] == $current_user_id ? 'message-sent' : 'message-received' ?>">
                                <div class="message-content">
                                    <?= nl2br(htmlspecialchars($message['Content'])) ?>
                                </div>
                                <div class="message-time">
                                    <?= date('M j, g:i A', strtotime($message['sent_at'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-messages">
                            <p>No messages yet. Start the conversation!</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Message Input -->
                <div class="message-input-area">
                    <form method="POST" onsubmit="return validateMessage()">
                        <input type="hidden" name="receiver_id" value="<?= $chat_partner_info['user_id'] ?>">
                        <div class="input-group">
                            <textarea name="content" id="messageInput" placeholder="Type your message here..." rows="2" required></textarea>
                            <button type="submit" name="send_message" class="send-btn">📤 Send</button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- Welcome Screen -->
                <div class="chat-welcome">
                    <h3>Welcome to AgriRent Messages!</h3>
                    <p>Select a conversation from the left or start a new chat to begin messaging.</p>
                    <button onclick="showNewChatModal()" class="action-btn">➕ Start New Chat</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Chat Modal -->
<div id="newChatModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close" onclick="hideNewChatModal()">&times;</span>
        <h3>Start New Chat</h3>
        <div class="users-list">
            <?php foreach ($available_users as $user): ?>
                <div class="user-item" onclick="startChatWith(<?= $user['user_id'] ?>)">
                    <div class="user-avatar">
                        <?= strtoupper(substr($user['Name'], 0, 2)) ?>
                    </div>
                    <div class="user-info">
                        <div class="user-name"><?= htmlspecialchars($user['Name']) ?></div>
                        <div class="user-type">
                            <?= $user['User_type'] == 'F' ? 'Farmer' : ($user['User_type'] == 'O' ? 'Equipment Owner' : 'Administrator') ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function showNewChatModal() {
    document.getElementById('newChatModal').style.display = 'block';
}

function hideNewChatModal() {
    document.getElementById('newChatModal').style.display = 'none';
}

function startChatWith(userId) {
    window.location.href = 'messages.php?user=' + userId;
}

function validateMessage() {
    const content = document.getElementById('messageInput').value.trim();
    if (content === '') {
        alert('Please enter a message.');
        return false;
    }
    return true;
}

// Auto-scroll to bottom of messages
document.addEventListener('DOMContentLoaded', function() {
    const messagesArea = document.getElementById('messagesArea');
    if (messagesArea) {
        messagesArea.scrollTop = messagesArea.scrollHeight;
    }
});

// Auto-refresh messages every 5 seconds (basic real-time simulation)
<?php if ($chat_with_user_id): ?>
setInterval(function() {
    // Simple refresh for new messages - in production, use AJAX
    const currentScrollHeight = document.getElementById('messagesArea').scrollHeight;
    const currentScrollTop = document.getElementById('messagesArea').scrollTop;
    
    fetch('get_messages.php?user=<?= $chat_with_user_id ?>')
        .then(response => response.text())
        .then(data => {
            const messagesArea = document.getElementById('messagesArea');
            const wasAtBottom = (currentScrollTop + messagesArea.clientHeight >= currentScrollHeight - 10);
            
            messagesArea.innerHTML = data;
            
            if (wasAtBottom) {
                messagesArea.scrollTop = messagesArea.scrollHeight;
            }
        });
}, 5000);
<?php endif; ?>
</script>

<?php 
    require 'ofooter.php';
?>