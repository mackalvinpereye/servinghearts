<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/config.php";



$user_id = $_SESSION['user_id'] ?? null; // ✅ correct key

$username = "Guest"; 
$avatar = BASE_URL . "/files/default-avatar.jpg"; 

if ($user_id) {
    $sql = "SELECT username FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $username = htmlspecialchars($row['username']);
    }
    $stmt->close();
}


?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,opsz,wght@0,6..12,200..1000;1,6..12,200..1000&display=swap');
    
    /* Base Styles */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: "Nunito Sans", sans-serif;
    }

    body {
        margin: 0;
    }

    .header-container {
        width: 100%;
        background-color: #ffffff;
        position: fixed;
        top: 0;
        left: 0;
        z-index: 100;
        display: flex;
        justify-content: flex-end;
        padding: 30px 30px;
    }

    .profile-dropdown {
        display: flex;
        align-items: center;
        gap: 15px;
        cursor: pointer;
        position: relative;
    }

    .profile-dropdown img {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #e62929;
    }

    .profile-dropdown span {
        font-weight: 600;
        color: #333;
        font-size: 1.3rem;
    }

    .dropdown-content {
        display: none;
        position: absolute;
        top: 100%;
        right: 0;
        background-color: white;
        min-width: 160px;
        box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        border-radius: 8px;
        z-index: 1;
        padding: 10px 0;
        margin-top: 10px;
    }

    .dropdown-content a {
        color: #333;
        padding: 12px 16px;
        text-decoration: none;
        display: block;
        font-size: 0.9rem;
        transition: all 0.3s;
    }

    .dropdown-content a:hover {
        background-color: #f5f5f5;
        color: #e62929;
    }

    .profile-dropdown:hover .dropdown-content {
        display: block;
    }

    /* Font Awesome icons */
    .dropdown-content a i {
        width: 20px;
        margin-right: 10px;
        text-align: center;
    }

    /* Responsive styles */
    @media (max-width: 768px) {
        .header-container {
            padding: 15px 20px;
        }
        
        .profile-dropdown span {
            display: none; /* Hide username on small screens */
        }
    }
</style>

<header class="header-container">
    <div class="profile-dropdown">
        <img src="<?= $avatar ?>" alt="Profile Picture">
        <span id="header-username"><?= $username ?></span>
    </div>
</header>

<!-- Font Awesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">