<?php
session_start();
include('../../ADMIN/header.php');
include('../../ADMIN/testsidebar.php');
include('../../config/db.php');

// Check DB connection
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'] ?? null;
$success = $error = "";

if ($user_id) {
    if ($_SERVER["REQUEST_METHOD"] === "POST" && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $username = $conn->real_escape_string($_POST['username']);
        $email = $conn->real_escape_string($_POST['email']);
        $phone_number = $conn->real_escape_string($_POST['phone_number']);
        $messenger = $conn->real_escape_string($_POST['messenger']);
        $password = $_POST['password'];

        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $update_sql = "UPDATE users SET username=?, email=?, phone_number=?, messenger=?, password=? WHERE id=?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("sssssi", $username, $email, $phone_number, $messenger, $hashed_password, $user_id);
        } else {
            $update_sql = "UPDATE users SET username=?, email=?, phone_number=?, messenger=? WHERE id=?";
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param("ssssi", $username, $email, $phone_number, $messenger, $user_id);
        }

        if ($stmt->execute()) {
            $success = "Profile updated successfully.";
        } else {
            $error = "Error updating profile.";
        }
        $stmt->close();
    }

    $sql = "SELECT username, email, phone_number, messenger FROM users WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
} else {
    die("User not logged in.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f8f9fa;
            margin: 0;
            padding: 0;
        }

        .settings-container {
            margin-top: 110px;
            margin-left: 350px;
            background: #fff;
            width: 75%;
            height: 42rem;
            max-width: 900px;
            padding: 15px 20px;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .settings-container h2 {
            margin-bottom: 5px;
            text-align: center;
            font-size: 26px;
            font-weight: bold;
            color: #d9534f;
        }
        
        .settings-container p {
            text-align: center;
            margin-bottom: 30px;
            color: #555;
        }

        .settings-section {
            background: #fafafa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 10px;
        }

        .section-title {
            font-weight: bold;
            font-size: 18px;
            color: #d9534f;
            margin: 0 0 15px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        
        .section-title i {
            margin-right: 10px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .input-box {
            position: relative;
        }
        
        .input-box i {
            position: absolute;
            top: 50%;
            left: 12px;
            transform: translateY(-50%);
            color: #d9534f;
            font-size: 14px;
        }
        
        .input-box input {
            width: 100%;
            padding: 10px 15px 10px 38px;
            border: 1px solid #ddd;
            border-radius: 8px;
            outline: none;
            font-size: 14px;
            background: #fff;
        }
        
        .input-box input:focus {
            border-color: #d9534f;
            box-shadow: 0 0 0 2px rgba(217, 83, 79, 0.15);
        }

        /* Password input specific styles */
        .password-wrapper {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            cursor: pointer;
            color: #888;
            background: none;
            border: none;
            padding: 0;
            font-size: 16px;
            z-index: 2;
            margin-right:30px;
        }
        
        .password-toggle:hover {
            color: #d9534f;
        }

        .password-strength {
            width: 100%;
            height: 5px;
            background: #eee;
            border-radius: 4px;
            margin-top: 6px;
            overflow: hidden;
        }
        
        .password-strength-fill {
            height: 100%;
            width: 0;
            transition: width 0.3s;
        }

        .btn {
            display: block;
            margin: 20px auto 0;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            background: #d9534f;
            color: #fff;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        
        .btn:hover {
            background: #c9302c;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(217, 83, 79, 0.3);
        }

        .alert {
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 8px;
            font-size: 14px;
            text-align: center;
        }
        
        .alert.success { 
            background: #dff0d8; 
            color: #3c763d; 
            border-left: 4px solid #3c763d;
        }
        
        .alert.error { 
            background: #f2dede; 
            color: #a94442; 
            border-left: 4px solid #a94442;
        }

        /* Auto-hide alerts */
        .alert {
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="settings-container">
        <h2>Account Settings</h2>
        <p>Manage your BloodBank System profile information</p>

        <?php if ($success): ?>
            <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <!-- Personal Info -->
            <div class="settings-section">
                <div class="section-title"><i class="fas fa-id-card"></i> Personal Information</div>

                <div class="form-group">
                    <div class="input-box">
                        <i class="fas fa-user"></i>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" placeholder="Username" required>
                    </div>
                </div>

                <div class="form-group">
                    <div class="input-box">
                        <i class="fas fa-envelope"></i>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" placeholder="Email" required>
                    </div>
                </div>

                <div class="form-group">
                    <div class="input-box">
                        <i class="fas fa-phone"></i>
                        <input type="text" name="phone_number" value="<?php echo htmlspecialchars($user['phone_number']); ?>" placeholder="Phone Number">
                    </div>
                </div>

                <div class="form-group">
                    <div class="input-box">
                        <i class="fas fa-comment"></i>
                        <input type="text" name="messenger" value="<?php echo htmlspecialchars($user['messenger']); ?>" placeholder="Messenger">
                    </div>
                </div>
            </div>

            <!-- Security -->
            <div class="settings-section">
                <div class="section-title"><i class="fas fa-shield-alt"></i> Security Settings</div>

                <div class="form-group">
                    <div class="input-box password-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" placeholder="Leave blank to keep current password">
                        <button type="button" class="password-toggle" id="passwordToggle">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                    <div class="password-strength">
                        <div class="password-strength-fill" id="passwordStrength"></div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn">Update Profile</button>
        </form>
    </div>

    <script>
        const toggle = document.getElementById("passwordToggle");
        const pass = document.getElementById("password");
        const strengthFill = document.getElementById("passwordStrength");

        // Show/hide password toggle based on input
        pass.addEventListener("input", () => {
            if (pass.value.length > 0) {
                toggle.style.display = "block";
            } else {
                toggle.style.display = "none";
                pass.type = "password";
                toggle.querySelector("i").classList.remove("fa-eye");
                toggle.querySelector("i").classList.add("fa-eye-slash");
            }

            // Password strength calculation
            let val = pass.value, strength = 0;
            if (val.length >= 8) strength += 25;
            if (/[A-Z]/.test(val)) strength += 25;
            if (/[0-9]/.test(val)) strength += 25;
            if (/[^A-Za-z0-9]/.test(val)) strength += 25;
            strengthFill.style.width = strength + "%";
            strengthFill.style.background =
                strength < 50 ? "#e53e3e" : strength < 75 ? "#d69e2e" : "#38a169";
        });

        // Toggle password visibility
        toggle.addEventListener("click", () => {
            const isHidden = pass.type === "password";
            pass.type = isHidden ? "text" : "password";
            toggle.querySelector("i").classList.toggle("fa-eye", isHidden);
            toggle.querySelector("i").classList.toggle("fa-eye-slash", !isHidden);
        });

        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 500); 
                }, 5000);
            });
        });
    </script>
</body>
</html>