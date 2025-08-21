<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

if (isset($_GET['delete'])) {
    $user_id = $_GET['delete'];
    $conn->query("DELETE FROM users WHERE user_id = $user_id");
    $message = "User deleted";
}

$search = isset($_GET['search']) ? $_GET['search'] : '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';

$where = "WHERE 1=1";
if ($search) {
    $search = $conn->real_escape_string($search);
    $where .= " AND (Name LIKE '%$search%' OR Email LIKE '%$search%' OR Phone LIKE '%$search%')";
}
if ($filter) {
    $where .= " AND User_type = '$filter'";
}

$users = $conn->query("SELECT * FROM users $where ORDER BY user_id DESC");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Users</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <div class="search-box">
        <form method="GET">
            <input type="text" name="search" placeholder="Search users..." value="<?= $search ?>">
            <select name="filter">
                <option value="">All Users</option>
                <option value="O" <?= $filter == 'O' ? 'selected' : '' ?>>Equipment Owners</option>
                <option value="F" <?= $filter == 'F' ? 'selected' : '' ?>>Farmers</option>
                <option value="A" <?= $filter == 'A' ? 'selected' : '' ?>>Admins</option>
            </select>
            <button type="submit" class="btn">Search</button>
            <a href="users.php" class="btn">Clear</a>
        </form>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Type</th>
            <th>Actions</th>
        </tr>
        
        <?php if ($users->num_rows > 0): ?>
            <?php while($user = $users->fetch_assoc()): ?>
            <tr>
                <td>U-<?= $user['user_id'] ?></td>
                <td><?= $user['Name'] ?></td>
                <td><?= $user['Email'] ?></td>
                <td><?= $user['Phone'] ?></td>
                <td>
                    <?php if($user['User_type'] == 'O'): ?>
                        Equipment Owner
                    <?php elseif($user['User_type'] == 'F'): ?>
                        Farmer
                    <?php else: ?>
                        Admin
                    <?php endif; ?>
                </td>
                <td>
                    <a href="?delete=<?= $user['user_id'] ?>" onclick="return confirm('Delete this user?')">Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="6">No users found</td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<?php require 'footer.php'; ?>
