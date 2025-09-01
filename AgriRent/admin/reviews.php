<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $conn->query("DELETE FROM reviews WHERE Review_id=$id");
    $message = "Review deleted";
}

$reviews = $conn->query("
    SELECT r.*, u.Name as reviewer_name, u.Email as reviewer_email
    FROM reviews r
    JOIN users u ON r.Reviewer_id = u.user_id
    ORDER BY r.created_date DESC
");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Reviews</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <table>
        <tr>
            <th>ID</th>
            <th>Reviewer</th>
            <th>Type</th>
            <th>Item ID</th>
            <th>Rating</th>
            <th>Comment</th>
            <th>Date</th>
            <th>Actions</th>
        </tr>
        
        <?php if ($reviews->num_rows > 0): ?>
            <?php while($review = $reviews->fetch_assoc()): ?>
            <tr>
                <td>R-<?= $review['Review_id'] ?></td>
                <td>
                    <?= $review['reviewer_name'] ?><br>
                    <small><?= $review['reviewer_email'] ?></small>
                </td>
                <td><?= $review['Review_type'] == 'E' ? 'Equipment' : 'Product' ?></td>
                <td><?= $review['Review_type'] == 'E' ? 'EQ-' : 'P-' ?><?= $review['ID'] ?></td>
                <td>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span style="color: <?= $i <= $review['Rating'] ? '#ffc107' : '#ddd' ?>">*</span>
                    <?php endfor; ?>
                    (<?= $review['Rating'] ?>/5)
                </td>
                <td>
                    <?php if ($review['comment']): ?>
                        <?= substr($review['comment'], 0, 50) ?>...
                    <?php else: ?>
                        No comment
                    <?php endif; ?>
                </td>
                <td><?= date('M d, Y', strtotime($review['created_date'])) ?></td>
                <td>
                    <a href="?delete=<?= $review['Review_id'] ?>" onclick="return confirm('Delete this review?')">Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="8">No reviews found</td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<?php require 'footer.php'; ?>