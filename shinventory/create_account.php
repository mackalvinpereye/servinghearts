<?php
session_start();
include('config/db.php'); // Your existing database connection

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $username = trim($conn->real_escape_string($_POST['username']));
    $email = trim($conn->real_escape_string($_POST['email']));
    $phone = trim($conn->real_escape_string($_POST['phone']));
    $messenger = trim($conn->real_escape_string($_POST['messenger']));
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role'] ?? 'user'; // Default to 'user'

    // Validation
    if (empty($username) || empty($password)) {
        $error = 'Username and password are required.';
    } elseif (strlen($username) > 50) {
        $error = 'Username must be 50 characters or less.';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } else {
        // Check if username already exists
        $check_sql = "SELECT id FROM users WHERE username = '$username'";
        $check_result = $conn->query($check_sql);
        
        if ($check_result && $check_result->num_rows > 0) {
            $error = 'Username already exists.';
        } else {
            // Hash password using bcrypt (same format as your example)
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
            
            // Prepare values for SQL (handle empty optional fields)
            $email_value = empty($email) ? "NULL" : "'$email'";
            $phone_value = empty($phone) ? "NULL" : "'$phone'";
            $messenger_value = empty($messenger) ? "NULL" : "'$messenger'";
            
            // Insert into database
            $sql = "INSERT INTO users (username, email, phone_number, messenger, password, role, created_at) 
                    VALUES ('$username', $email_value, $phone_value, $messenger_value, '$hashedPassword', '$role', NOW())";
            
            if ($conn->query($sql) === TRUE) {
                $success = 'Account created successfully!';
                // Clear form
                $_POST = array();
            } else {
                $error = 'Error: ' . $conn->error;
            }
        }
        
        if ($check_result) {
            $check_result->free();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 500px; margin: 50px auto; padding: 20px; }
        .container { background: #f9f9f9; padding: 30px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
        .btn { background: #4CAF50; color: white; padding: 12px 20px; border: none; border-radius: 5px; cursor: pointer; width: 100%; }
        .btn:hover { background: #45a049; }
        .error { background: #ffebee; color: #c62828; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .success { background: #e8f5e9; color: #2e7d32; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
        .note { font-size: 12px; color: #666; margin-top: 5px; }
        .optional { color: #999; }
        .password-strength { margin-top: 5px; font-size: 12px; }
        .strength-weak { color: #e74c3c; }
        .strength-medium { color: #f39c12; }
        .strength-strong { color: #27ae60; }
    </style>
    <script>
        function validatePassword() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const strength = document.getElementById('password-strength');
            const submitBtn = document.querySelector('.btn');
            
            // Password strength indicator
            let strengthText = '';
            let strengthClass = '';
            
            if (password.length === 0) {
                strengthText = '';
            } else if (password.length < 8) {
                strengthText = 'Weak (min 8 characters)';
                strengthClass = 'strength-weak';
            } else if (password.length < 12) {
                strengthText = 'Medium';
                strengthClass = 'strength-medium';
            } else {
                // Check for complexity
                const hasUpper = /[A-Z]/.test(password);
                const hasLower = /[a-z]/.test(password);
                const hasNumbers = /\d/.test(password);
                const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(password);
                
                let score = (hasUpper ? 1 : 0) + (hasLower ? 1 : 0) + (hasNumbers ? 1 : 0) + (hasSpecial ? 1 : 0);
                
                if (score >= 3 && password.length >= 12) {
                    strengthText = 'Strong';
                    strengthClass = 'strength-strong';
                } else if (score >= 2) {
                    strengthText = 'Medium';
                    strengthClass = 'strength-medium';
                } else {
                    strengthText = 'Weak';
                    strengthClass = 'strength-weak';
                }
            }
            
            strength.textContent = strengthText;
            strength.className = 'password-strength ' + strengthClass;
            
            // Check if passwords match
            if (confirm.length > 0 && password !== confirm) {
                document.getElementById('confirm-error').textContent = 'Passwords do not match';
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.6';
            } else {
                document.getElementById('confirm-error').textContent = '';
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
            }
        }
        
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            field.type = field.type === 'password' ? 'text' : 'password';
        }
    </script>
</head>
<body>
    <div class="container">
        <h1>Create Account</h1>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="" onsubmit="return validateForm()">
            <div class="form-group">
                <label for="username">Username *</label>
                <input type="text" id="username" name="username" 
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                       maxlength="50" required>
                <div class="note">Maximum 50 characters</div>
            </div>
            
            <div class="form-group">
                <label for="email">Email <span class="optional">(Optional)</span></label>
                <input type="email" id="email" name="email" 
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="phone">Phone Number <span class="optional">(Optional)</span></label>
                <input type="tel" id="phone" name="phone" 
                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="messenger">Messenger (Telegram, WhatsApp, etc.) <span class="optional">(Optional)</span></label>
                <input type="text" id="messenger" name="messenger" 
                       value="<?php echo htmlspecialchars($_POST['messenger'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="password">Password *</label>
                <div style="position: relative;">
                    <input type="password" id="password" name="password" 
                           onkeyup="validatePassword()" required>
                    <button type="button" onclick="togglePassword('password')" 
                            style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); 
                                   background: none; border: none; cursor: pointer;">
                        👁️
                    </button>
                </div>
                <div id="password-strength" class="password-strength"></div>
                <div class="note">Minimum 8 characters. Include uppercase, lowercase, numbers, and special characters for better security.</div>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password *</label>
                <div style="position: relative;">
                    <input type="password" id="confirm_password" name="confirm_password" 
                           onkeyup="validatePassword()" required>
                    <button type="button" onclick="togglePassword('confirm_password')" 
                            style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); 
                                   background: none; border: none; cursor: pointer;">
                        👁️
                    </button>
                </div>
                <div id="confirm-error" class="note" style="color: #e74c3c;"></div>
            </div>
            
            <div class="form-group">
                <label for="role">Role *</label>
                <select id="role" name="role">
                    <option value="user" <?php echo ($_POST['role'] ?? 'user') === 'user' ? 'selected' : ''; ?>>User</option>
                    <option value="admin" <?php echo ($_POST['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                </select>
            </div>
            
            <button type="submit" class="btn">Create Account</button>
        </form>
        
        <div style="margin-top: 20px; text-align: center;">
            <a href="login.php" style="color: #4CAF50; text-decoration: none;">Already have an account? Login here</a>
        </div>
    </div>
    
    <script>
        function validateForm() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            
            if (password !== confirm) {
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 8) {
                alert('Password must be at least 8 characters long!');
                return false;
            }
            
            return true;
        }
        
        // Initialize validation on page load
        document.addEventListener('DOMContentLoaded', validatePassword);
    </script>
</body>
</html>