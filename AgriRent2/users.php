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

// Remove server-side search and filter - jQuery will handle this
$users = $conn->query("SELECT * FROM users ORDER BY user_id DESC");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Users</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <div class="search-box">
        <input type="text" id="searchInput" placeholder="Search users..." style="padding: 8px; width: 900px; margin-right: 10px;">
        
        <select id="filterSelect" style="padding: 8px; margin-right: 10px;">
            <option value="">All Users</option>
            <option value="Equipment Owner">Equipment Owners</option>
            <option value="Farmer">Farmers</option>
            <option value="Admin">Admins</option>
        </select>
        
        <button type="button" id="clearBtn" class="btn">Clear</button>
    </div>

    <table id="usersTable">
        <thead>
            <tr class="table-header">
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Type</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($users->num_rows > 0): ?>
                <?php while($user = $users->fetch_assoc()): ?>
                <tr class="user-row">
                    <td><?= $user['user_id'] ?></td>
                    <td class="user-name"><?= $user['Name'] ?></td>
                    <td class="user-email"><?= $user['Email'] ?></td>
                    <td class="user-phone"><?= $user['Phone'] ?></td>
                    <td class="user-type">
                        <?php if($user['User_type'] == 'O'): ?>
                            Equipment Owner
                        <?php elseif($user['User_type'] == 'F'): ?>
                            Farmer
                        <?php else: ?>
                            Admin
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($user['User_type'] == 'O'): ?>
                            <a href="?delete=<?= $user['user_id'] ?>" onclick="return confirm('Delete this user?')">Delete</a>
                        <?php elseif($user['User_type'] == 'F'): ?>
                            <a href="?delete=<?= $user['user_id'] ?>" onclick="return confirm('Delete this user?')">Delete</a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr class="no-users">
                    <td colspan="6">No users found</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
/* Search Box Styling */
#searchInput, #filterSelect {
    font-size: 14px;
    border: 1px solid #ccc;
    border-radius: 4px;
}

#searchInput:focus, #filterSelect:focus {
    outline: none;
    border-color: #234a23;
    box-shadow: 0 0 5px rgba(0,124,186,0.3);
}

/* Hidden row styling */
.user-row.hidden {
    display: none !important;
}

/* Message styling */
.message {
    background: #d4edda;
    color: #155724;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 15px;
}

/* Button styling */
.btn {
    padding: 8px 15px;
    background: #234a23;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn:hover {
    background: #005a8b;
}

/* Basic table styling - keeping your original structure */
#usersTable {
    width: 100%;
    border-collapse: collapse;
}

#usersTable th, 
#usersTable td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

#usersTable th {
    background-color: #f8f9fa;
    font-weight: bold;
}

#usersTable tr:hover {
    background-color: #f5f5f5;
}

/* Responsive design - Mobile First */
@media screen and (max-width: 768px) {
    .main-content {
        padding: 10px;
    }
    
    /* Search box responsive */
    .search-box {
        display: block;
        margin-bottom: 15px;
    }
    
    #searchInput {
        width: 100% !important;
        margin: 0 0 10px 0 !important;
        box-sizing: border-box;
    }
    
    #filterSelect {
        width: 100% !important;
        margin: 0 0 10px 0 !important;
        box-sizing: border-box;
    }
    
    #clearBtn {
        width: 100%;
        margin: 0;
    }
    
    /* Mobile table - horizontal scroll */
    #usersTable {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
        min-width: 600px;
    }
    
    #usersTable thead,
    #usersTable tbody,
    #usersTable th,
    #usersTable td,
    #usersTable tr {
        display: block;
    }
    
    #usersTable thead tr {
        position: absolute;
        top: -9999px;
        left: -9999px;
    }
    
    #usersTable tr {
        border: 1px solid #ccc;
        margin-bottom: 10px;
        padding: 10px;
        background: white;
        display: block;
        position: relative;
    }
    
    #usersTable td {
        border: none;
        padding: 8px 0;
        display: block;
        text-align: left;
        white-space: normal;
        padding-left: 50%;
        position: relative;
    }
    
    #usersTable td:before {
        content: "";
        position: absolute;
        left: 6px;
        width: 45%;
        padding-right: 10px;
        white-space: nowrap;
        font-weight: bold;
    }
    
    #usersTable td:nth-of-type(1):before { content: "ID: "; }
    #usersTable td:nth-of-type(2):before { content: "Name: "; }
    #usersTable td:nth-of-type(3):before { content: "Email: "; }
    #usersTable td:nth-of-type(4):before { content: "Phone: "; }
    #usersTable td:nth-of-type(5):before { content: "Type: "; }
    #usersTable td:nth-of-type(6):before { content: "Actions: "; }
}

/* Tablet responsive */
@media screen and (min-width: 769px) and (max-width: 1024px) {
    #searchInput {
        width: 300px !important;
    }
    
    .search-box {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
}

/* Small mobile phones */
@media screen and (max-width: 480px) {
    .main-content {
        padding: 5px;
    }
    
    h1 {
        font-size: 20px;
        text-align: center;
    }
    
    #usersTable tr {
        margin-bottom: 8px;
        padding: 8px;
    }
    
    #usersTable td {
        padding: 5px 0;
        font-size: 14px;
    }
}

/* Large screens - keep original layout */
@media screen and (min-width: 1025px) {
    .search-box {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 20px;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
    // Live search and filter functionality
    function filterUsers() {
        var searchTerm = $('#searchInput').val().toLowerCase();
        var filterType = $('#filterSelect').val();
        var visibleRows = 0;
        
        $('#usersTable tbody tr.user-row').each(function() {
            var $row = $(this);
            var name = $row.find('.user-name').text().toLowerCase();
            var email = $row.find('.user-email').text().toLowerCase();
            var phone = $row.find('.user-phone').text().toLowerCase();
            var type = $row.find('.user-type').text().trim();
            var typeLower = type.toLowerCase();
            
            // Enhanced search term matching - includes type searching
            var searchMatch = searchTerm === '' || 
                             name.includes(searchTerm) || 
                             email.includes(searchTerm) || 
                             phone.includes(searchTerm) ||
                             typeLower.includes(searchTerm) ||
                             // Additional type search keywords
                             (searchTerm.includes('farm') && typeLower.includes('farmer')) ||
                             (searchTerm.includes('equip') && typeLower.includes('equipment')) ||
                             (searchTerm.includes('owner') && typeLower.includes('owner')) ||
                             (searchTerm.includes('admin') && typeLower.includes('admin'));
            
            // Check filter type match
            var typeMatch = filterType === '' || type === filterType;
            
            if (searchMatch && typeMatch) {
                $row.removeClass('hidden');
                visibleRows++;
            } else {
                $row.addClass('hidden');
            }
        });
        
        // Handle no results message
        if (visibleRows === 0 && (searchTerm !== '' || filterType !== '')) {
            if ($('#noResultsRow').length === 0) {
                $('#usersTable tbody').append('<tr id="noResultsRow"><td colspan="6" style="text-align: center; padding: 20px; font-style: italic;">No users found matching your criteria</td></tr>');
            }
        } else {
            $('#noResultsRow').remove();
        }
        
        // Show search results count
        updateSearchCount(visibleRows, searchTerm, filterType);
    }
    
    // Update search results count
    function updateSearchCount(count, searchTerm, filterType) {
        $('#searchCount').remove();
        
        if (searchTerm !== '' || filterType !== '') {
            var countText = count + ' user' + (count !== 1 ? 's' : '') + ' found';
            $('.search-box').append('<div id="searchCount" style="margin-top: 5px; font-size: 12px; color: #666;">' + countText + '</div>');
        }
    }
    
    // Search input event
    $('#searchInput').on('keyup', function() {
        filterUsers();
    });
    
    // Filter select event
    $('#filterSelect').on('change', function() {
        filterUsers();
    });
    
    // Clear button functionality
    $('#clearBtn').on('click', function() {
        $('#searchInput').val('');
        $('#filterSelect').val('');
        $('#usersTable tbody tr.user-row').removeClass('hidden');
        $('#noResultsRow').remove();
        $('#searchCount').remove();
        $('#searchInput').focus();
    });
    
    // Focus search input on page load
    $('#searchInput').focus();
});
</script>

<?php require 'footer.php'; ?>
