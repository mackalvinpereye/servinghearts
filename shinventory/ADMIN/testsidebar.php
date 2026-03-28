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

// Get current role (default to "user" if not set)
$role = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : 'user'; 
$currentPath = $_SERVER['REQUEST_URI']; 

// Determine active item
$activeItem = '';
if (strpos($currentPath, 'INVENTORY') !== false) {
    $activeItem = 'inventory';
} elseif (strpos($currentPath, 'THINGS') !== false) {
    $activeItem = 'things';
} elseif (strpos($currentPath, 'ASSETS') !== false) {
    $activeItem = 'assets';
} elseif (strpos($currentPath, 'SETTINGS') !== false) {
    $activeItem = 'settings';
} else {
    $activeItem = 'dashboard';
}

// ✅ Base path depends on role
$basePath = ($role === 'admin') ? BASE_URL . "/ADMIN" : BASE_URL . "/USER";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
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
            display: flex;
            background-color: #f8f9fa;
            margin: 0;
            padding-top: 80px; /* Space for fixed header */
            padding-left: 280px; /* Space for fixed sidebar */
            min-height: 100vh;
        }

        /* Header Styles */
        .header-container {
            width: 100%;
            background-color: #fff;
            color: black;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            height: 80px;
        }

        .header-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-title i {
            color: #e62929;
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
            border: 3px solid #e62929;
            box-shadow: 0 2px 8px rgba(230, 41, 41, 0.3);
            transition: all 0.3s ease;
        }

        .profile-dropdown img:hover {
            border-color: #c82333;
            box-shadow: 0 4px 12px rgba(230, 41, 41, 0.4);
        }

        /* Sidebar Styles */
        .sidebar {
            width: 260px;
            background: #7a0000ff;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            padding: 20px 0;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 1000;
            overflow-y: auto;
            padding-top: 90px; /* Space for header */
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
        }

        .logo {
            padding: 2rem;
            margin-bottom: 20px;
            margin-top: -70px;
            display: flex;
            justify-content: center;
        }

        .logo img {
            height: 140px;
            width: auto;
            transition: transform 0.3s ease;
        }

        .logo img:hover {
            transform: scale(1.05);
        }

        .main-nav {
            width: 100%;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .main-nav ul {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 5px;
            padding: 0 15px;
            align-items: center;
        }

         .main-nav li {
            width: 100%;
            display: flex;
            justify-content: center;
        }

        .main-nav a {
            text-decoration: none;
            color: #fff;
            font-weight: 600;
            font-size: 1.1rem;
            position: relative;
            padding: 15px 20px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            width: 100%;
            max-width: 240px;
            justify-content: flex-start;
            border-radius: 8px;
            overflow: hidden;
        }

        .main-nav a i {
            width: 24px;
            margin-right: 12px;
            color: #fff;
            transition: all 0.3s ease;
        }

        .main-nav a::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background-color: #e62929;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .main-nav a:hover {
            color: #fff;
            background-color: rgba(230, 41, 41, 0.08);
            transform: translateX(5px);
        }

        .main-nav a:hover i {
            color: #fff;
            transform: scale(1.1);
        }

        /* ACTIVE STATE ENHANCEMENTS */
        .main-nav a.active {
            color: #fff;
            background: linear-gradient(to right, rgba(230, 41, 41, 0.1), rgba(230, 41, 41, 0.05));
            box-shadow: 0 4px 12px rgba(230, 41, 41, 0.15);
            transform: translateX(5px);
        }

        .main-nav a.active i {
            color: #fff;
            transform: scale(1.1);
        }

        .main-nav a.active::before {
            opacity: 1;
        }

        .main-nav a.active::after {
            content: '';
            position: absolute;
            right: 15px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #e62929;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(230, 41, 41, 0.4);
            }
            70% {
                box-shadow: 0 0 0 6px rgba(230, 41, 41, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(230, 41, 41, 0);
            }
        }

        .logout-container {
            margin-top: auto;
            padding: 20px;
            width: 100%;
            display: flex;
            justify-content: center;
        }

        .logout-btn {
            background: linear-gradient(135deg, #e62929 0%, #d11a2a 100%);
            color: white;
            border: none;
            padding: 14px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            max-width: 200px;
            box-shadow: 
                0 4px 8px rgba(230, 41, 41, 0.3),
                0 0 0 1px rgba(255, 255, 255, 0.1) inset,
                0 1px 0 rgba(255, 255, 255, 0.2) inset;
            position: relative;
            overflow: hidden;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .logout-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, 
                transparent, 
                rgba(255, 255, 255, 0.2), 
                transparent);
            transition: left 0.7s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        .logout-btn:hover {
            background: linear-gradient(135deg, #d11a2a 0%, #c21c1c 100%);
            transform: translateY(-3px) scale(1.02);
            box-shadow: 
                0 8px 20px rgba(230, 41, 41, 0.5),
                0 0 0 1px rgba(255, 255, 255, 0.15) inset,
                0 1px 0 rgba(255, 255, 255, 0.25) inset;
        }

        .logout-btn:hover::before {
            left: 100%;
        }

        .logout-btn:active {
            transform: translateY(1px) scale(0.98);
            box-shadow: 
                0 2px 5px rgba(230, 41, 41, 0.4),
                0 0 0 1px rgba(255, 255, 255, 0.05) inset,
                0 1px 0 rgba(255, 255, 255, 0.1) inset;
        }

        .logout-btn i {
            margin-right: 12px;
            transition: transform 0.3s ease;
            font-size: 1.1rem;
        }

        .logout-btn:hover i {
            transform: translateX(-3px);
        }

        /* Optional: Add a subtle pulse animation for attention */
        @keyframes subtlePulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.03); }
            100% { transform: scale(1); }
        }

        .logout-btn {
            animation: subtlePulse 3s infinite;
        }

        .logout-btn:hover {
            animation: none; /* Stop pulse animation on hover */
        }

        .mobile-menu-btn {
            display: none;
            background: #e62929;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: white;
            padding: 12px 14px;
            position: fixed;
            left: 250px;
            top: 90px;
            z-index: 10;
            border-radius: 0 5px 5px 0;
            box-shadow: 2px 2px 8px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
        }

        .mobile-menu-btn:hover {
            background: #c82333;
            padding-left: 16px;
        }

        /* Main Content Area */
        .main-content {
            flex: 1;
            padding: 20px;
            margin-left: 0;
        }

        /* Responsive Styles */
        @media (max-width: 1024px) {
            body {
                padding-left: 0;
            }
            
            .sidebar {
                left: -280px;
            }
            
            .sidebar.active {
                left: 0;
                box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
            }
            
            .mobile-menu-btn {
                display: block;
                position: fixed;
                left: 0;
                top: 90px;
                z-index: 1001;
            }
            
            .sidebar.active + .mobile-menu-btn {
                left: 280px;
            }
        }

        @media (max-width: 768px) {
            .header-container {
                padding: 15px 20px;
            }
            
            .header-title span {
                display: none;
            }
            
            .header-title {
                font-size: 1.2rem;
            }
            
            .profile-dropdown span {
                display: none;
            }
            
            body {
                padding-top: 70px;
            }
            
            .sidebar {
                padding-top: 80px;
                width: 250px;
            }
            
            .main-nav a {
                max-width: 220px;
                font-size: 1rem;
            }
            
            .logo img {
                height: 120px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header-container">
        <div class="header-title">
            <i class="fas fa-heart"></i>
            
        </div>
        <div class="profile-dropdown">
            <img src="<?= $avatar ?>" alt="Profile Picture">
            <span><?= $username ?></span>
        </div>
    </header>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="logo">
            <img src="/servinghearts/images/shlogo.png" alt="Serving Hearts Logo">
        </div>
        <nav class="main-nav">
            <ul>
                <li>
                    <a href="<?php echo $basePath; ?>/index.php" class="<?php echo $activeItem === 'dashboard' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-compass"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="<?php echo $basePath; ?>/INVENTORY/index.php" class="<?php echo $activeItem === 'inventory' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-droplet"></i> Blood Bank
                    </a>
                </li>
                <li>
                    <a href="<?php echo $basePath; ?>/THINGS/index.php" class="<?php echo $activeItem === 'things' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-box"></i> Inventory
                    </a>
                </li>
                <li>
                    <a href="<?php echo $basePath; ?>/ASSETS/index.php" class="<?php echo $activeItem === 'assets' ? 'active' : ''; ?>">
                        <i class="fa-solid fa-warehouse"></i> Assets
                    </a>
                </li>
                <li>
                    <a href="<?php echo $basePath; ?>/SETTINGS/index.php" class="<?php echo $activeItem === 'settings' ? 'active' : ''; ?>">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                </li>
            </ul>
            <div class="logout-container">
                <button class="logout-btn" id="logoutBtn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </nav>
        <button class="mobile-menu-btn" id="mobileMenuBtn">☰</button>
    </aside>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Mobile menu toggle functionality
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
            
            // Update button position when sidebar is active
            if (sidebar.classList.contains('active')) {
                this.style.left = '280px';
                this.innerHTML = '✕';
            } else {
                this.style.left = '0';
                this.innerHTML = '☰';
            }
        });

        // Logout SweetAlert Confirmation
        document.getElementById('logoutBtn').addEventListener('click', function() {
            Swal.fire({
                title: "Are you sure?",
                text: "You will be logged out of your session.",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#e62929",
                cancelButtonColor: "#6c757d",
                confirmButtonText: "Yes, logout",
                cancelButtonText: "Cancel"
            }).then((result) => {
                if (result.isConfirmed) {
                    // Redirect to logout
                    window.location.href = "/servinghearts/shinventory/index.php";
                }
            });
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            
            if (window.innerWidth <= 1024 && 
                !sidebar.contains(event.target) && 
                event.target !== mobileMenuBtn &&
                !mobileMenuBtn.contains(event.target)) {
                sidebar.classList.remove('active');
                mobileMenuBtn.style.left = '0';
                mobileMenuBtn.innerHTML = '☰';
            }
        });
    </script>
</body>
</html>