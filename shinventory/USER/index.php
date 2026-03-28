<?php 
session_start(); 
include('../ADMIN/header.php');
include('../ADMIN/testsidebar.php');
include('../config/db.php'); 

// Restriction check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    echo '
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            Swal.fire({
                icon: "error",
                title: "Restricted",
                text: "You are restricted from this section!",
                confirmButtonText: "Go Back"
            }).then(() => {
                window.location.href = "../index.php";
            });
        });
    </script>';
    exit();
}

// ✅ Success alert only once after login
if (!isset($_SESSION['login_success_shown'])) {
    $_SESSION['login_success_shown'] = true; 
    echo '
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            Swal.fire({
                icon: "success",
                title: "Login Successful",
                text: "Welcome back, ' . htmlspecialchars($_SESSION['username']) . '!",
                timer: 1800,
                showConfirmButton: false
            });
        });
    </script>';
}

// --- Get Forecast Data --- //
$today = date('Y-m-d');
$today_formatted = date('F j, Y'); // More readable format
$current_time = date('g:i A'); // Current time

$DB = $mysqli ?? ($conn ?? null);
$DB->set_charset('utf8mb4');

// Fetch today's IN and OUT forecasts
$stmt = $DB->prepare("
    SELECT transaction_type, ROUND(yhat) AS yhat_units, ROUND(yhat_lower) AS lo, ROUND(yhat_upper) AS hi
    FROM forecast_daily
    WHERE day = ?
    ORDER BY transaction_type
");
$stmt->bind_param('s', $today);
$stmt->execute();
$forecast_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Separate IN and OUT forecasts
$in_forecast = null;
$out_forecast = null;

foreach ($forecast_results as $f) {
    if ($f['transaction_type'] === 'IN') {
        $in_forecast = $f;
    } elseif ($f['transaction_type'] === 'OUT') {
        $out_forecast = $f;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BloodBank | Inventory System</title>
    <link rel="icon" type="image/png" href="../files/emblem.png?v=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body { 
            background: #fff;
            margin: 0; 
            display: flex; 
            height: 100vh; 
            color: #333;
        }
        
        .main-content {  
            padding: 30px; 
            padding-bottom: 40px;
            background: #fff; 
            flex: 1; 
            min-height: calc(100vh - 70px); 
            display: flex; 
            justify-content: center; 
            background: transparent;
        }
        
        .content-container { 
            width: 100%; 
            max-width: 1200px; 
        }
        
        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid rgba(200, 0, 0, 0.1);
        }
        
        .welcome-section h1 {
            font-size: 2.2rem;
            color: #c00;
            margin-bottom: 5px;
            font-weight: 700;
        }
        
        .welcome-section p {
            font-size: 1.1rem;
            color: #666;
        }
        
        .datetime-display {
            background: linear-gradient(135deg, #c00 0%, #900 100%);
            color: white;
            padding: 15px 25px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(200, 0, 0, 0.2);
            min-width: 250px;
        }
        
        .date-large {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .time-large {
            font-size: 1.4rem;
            font-weight: 600;
            opacity: 0.9;
        }
        
        .forecast-section {
            margin-top: 30px;
        }
        
        .section-title {
            font-size: 1.8rem;
            color: #c00;
            margin-bottom: 25px;
            padding-bottom: 10px;
            border-bottom: 2px solid rgba(200, 0, 0, 0.2);
            font-weight: 600;
        }
        
        .prediction-note {
            background: #fff5f5;
            border-left: 4px solid #c00;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 0 8px 8px 0;
            font-style: italic;
            color: #900;
        }
        
        .forecast-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }
        
        .forecast-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s, box-shadow 0.3s;
            border-top: 4px solid;
            position: relative;
            overflow: hidden;
        }
        
        .forecast-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .forecast-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .forecast-card:hover::before {
            opacity: 1;
        }
        
        .forecast-card.in {
            border-top-color: #c00;
        }
        
        .forecast-card.out {
            border-top-color: #0066cc;
        }
        
        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.8rem;
        }
        
        .in .card-icon {
            background-color: rgba(200, 0, 0, 0.1);
            color: #c00;
        }
        
        .out .card-icon {
            background-color: rgba(0, 102, 204, 0.1);
            color: #0066cc;
        }
        
        .card-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: #333;
        }
        
        .card-subtitle {
            font-size: 0.9rem;
            color: #666;
            margin-top: 5px;
        }
        
        .forecast-value {
            font-size: 3.5rem;
            font-weight: 800;
            text-align: center;
            margin: 25px 0;
            padding: 20px;
            border-radius: 12px;
            background: #f9f9f9;
            position: relative;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.05);
        }
        
        .in .forecast-value {
            color: #c00;
            border: 3px dashed rgba(200, 0, 0, 0.3);
        }
        
        .out .forecast-value {
            color: #0066cc;
            border: 3px dashed rgba(0, 102, 204, 0.3);
        }
        
        .units-label {
            font-size: 1.2rem;
            font-weight: 600;
            margin-left: 10px;
            opacity: 0.7;
        }
        
        .forecast-range {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .range-item {
            text-align: center;
            flex: 1;
            padding: 0 10px;
        }
        
        .range-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .range-value {
            font-size: 1.4rem;
            font-weight: 700;
            padding: 8px;
            border-radius: 6px;
            background: #f5f5f5;
        }
        
        .in .range-value {
            color: #c00;
            background: rgba(200, 0, 0, 0.05);
        }
        
        .out .range-value {
            color: #0066cc;
            background: rgba(0, 102, 204, 0.05);
        }
        
        .no-forecast {
            text-align: center;
            padding: 40px 20px;
            color: #666;
            font-style: italic;
        }
        
        .no-forecast i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .blood-drop {
            position: absolute;
            width: 100px;
            height: 100px;
            opacity: 0.03;
            pointer-events: none;
        }
        
        .blood-drop-1 {
            top: -30px;
            right: -30px;
            color: #c00;
        }
        
        .blood-drop-2 {
            bottom: -30px;
            left: -30px;
            color: #0066cc;
            transform: rotate(180deg);
        }
        
        @media (max-width: 1024px) {
            .header-section {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .datetime-display {
                margin-top: 20px;
                align-self: flex-end;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .forecast-cards {
                grid-template-columns: 1fr;
            }
            
            .forecast-value {
                font-size: 2.8rem;
            }
            
            .date-large {
                font-size: 1.5rem;
            }
            
            .time-large {
                font-size: 1.2rem;
            }
            
            .header-section {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .datetime-display {
                margin-top: 15px;
                align-self: stretch;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <!-- Main Content -->
    <main class="main-content">
        <div class="content-container">
            <div class="header-section">
                <div class="welcome-section">
                    <h1>Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</h1>
                    <p>Blood Bank Inventory Management System</p>
                </div>
                
                <!-- Big Date Time Display -->
                <div class="datetime-display">
                    <div class="date-large"><?= htmlspecialchars($today_formatted) ?></div>
                    <div class="time-large" id="current-time"><?= htmlspecialchars($current_time) ?></div>
                    <div style="font-size: 0.9rem; margin-top: 5px; opacity: 0.8;">Prediction Date</div>
                </div>
            </div>
            
            <!-- Forecast Section -->
            <div class="forecast-section">
                <h2 class="section-title">Blood Inventory Forecast</h2>
                
                <div class="prediction-note">
                    <i class="fas fa-info-circle"></i> This forecast predicts today's expected blood donations and transfusions based on historical data.
                </div>
                
                <div class="forecast-cards">
                    <!-- IN Forecast Card (Blood Donations) -->
                    <div class="forecast-card in">
                        <i class="fas fa-tint blood-drop blood-drop-1"></i>
                        <div class="card-header">
                            <div class="card-icon">
                                <i class="fas fa-hand-holding-medical"></i>
                            </div>
                            <div>
                                <div class="card-title">Blood Donations Forecast</div>
                                <div class="card-subtitle">Expected units to be donated today</div>
                            </div>
                        </div>
                        
                        <?php if ($in_forecast): ?>
                            <div class="forecast-value">
                                <?= number_format($in_forecast['yhat_units']) ?> <span class="units-label">units</span>
                            </div>
                            
                            <div class="forecast-range">
                                <div class="range-item">
                                    <div class="range-label">Minimum Expected</div>
                                    <div class="range-value"><?= number_format($in_forecast['lo']) ?></div>
                                </div>
                                <div class="range-item">
                                    <div class="range-label">Maximum Expected</div>
                                    <div class="range-value"><?= number_format($in_forecast['hi']) ?></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="no-forecast">
                                <i class="fas fa-tint"></i>
                                <p>No donation forecast available for today</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- OUT Forecast Card (Blood Transfusions) -->
                    <div class="forecast-card out">
                        <i class="fas fa-tint blood-drop blood-drop-2"></i>
                        <div class="card-header">
                            <div class="card-icon">
                                <i class="fas fa-heartbeat"></i>
                            </div>
                            <div>
                                <div class="card-title">Blood Transfusions Forecast</div>
                                <div class="card-subtitle">Expected units to be used today</div>
                            </div>
                        </div>
                        
                        <?php if ($out_forecast): ?>
                            <div class="forecast-value">
                                <?= number_format($out_forecast['yhat_units']) ?> <span class="units-label">units</span>
                            </div>
                            
                            <div class="forecast-range">
                                <div class="range-item">
                                    <div class="range-label">Minimum Expected</div>
                                    <div class="range-value"><?= number_format($out_forecast['lo']) ?></div>
                                </div>
                                <div class="range-item">
                                    <div class="range-label">Maximum Expected</div>
                                    <div class="range-value"><?= number_format($out_forecast['hi']) ?></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="no-forecast">
                                <i class="fas fa-heartbeat"></i>
                                <p>No transfusion forecast available for today</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <script>
        // Update time in real-time
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour: 'numeric', 
                minute: '2-digit',
                hour12: true 
            });
            document.getElementById('current-time').textContent = timeString;
        }
        
        // Update time immediately and then every minute
        updateTime();
        setInterval(updateTime, 60000);
        
        // Add some subtle animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.forecast-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'opacity 0.5s, transform 0.5s';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 200);
            });
        });
    </script>
</body>
</html>