<?php 
session_start(); 
include('testsidebar.php');
include('../config/db.php'); 

/* ---------------- Restriction check ---------------- */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
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

/* ---------------- Success alert (only once after login) ---------------- */
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

/* ---------------- DB Setup ---------------- */
$DB = $mysqli ?? ($conn ?? null);
if (!$DB) { die("DB connection not found from ../config/db.php"); }
$DB->set_charset('utf8mb4');

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------------- Paths for Python runner ---------------- */
$PY = '"C:\\Users\\63930\\AppData\\Local\\Microsoft\\WindowsApps\\python.exe"';
$SCRIPT = 'C:\\xampp\\htdocs\\servinghearts\\py\\step2_sarimax.py';

/* ---------------- Defaults ---------------- */
$today = date('Y-m-d');
$thisMonth = date('Y-m');
$dailyDate = $today;
$dailyType = 'IN'; 
$month = $thisMonth;
$show = 'both';
$run_log = '';

/* ---------------- POST handlers ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Daily predict
    if ($action === 'predict_day') {
        $dailyType = $_POST['type'] ?? 'IN';
        $d = $_POST['day'] ?? $today;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && in_array($dailyType, ['IN','OUT'], true)) {
            $cmd = $PY.' '.escapeshellarg($SCRIPT).' --day '.escapeshellarg($d).' 2>&1';
            $run_log = shell_exec($cmd) ?: '(no output)';
            $dailyDate = $d;
        }
    }

    // Monthly predict
    if ($action === 'predict_month') {
        $show = $_POST['show'] ?? 'both';
        $m = $_POST['month'] ?? $thisMonth;
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            $cmd = $PY.' '.escapeshellarg($SCRIPT).' --month '.escapeshellarg($m).' 2>&1';
            $run_log = shell_exec($cmd) ?: '(no output)';
            $month = $m;
        }
    }
}

/* ---------------- READ: Daily ---------------- */
$dailyRow = null;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dailyDate) && in_array($dailyType, ['IN','OUT'], true)) {
    $stmt = $DB->prepare("
        SELECT ROUND(yhat) AS yhat_units, ROUND(yhat_lower) AS lo, ROUND(yhat_upper) AS hi, model, run_at 
        FROM forecast_daily 
        WHERE day = ? AND transaction_type = ? 
        ORDER BY run_at DESC LIMIT 1
    ");
    $stmt->bind_param('ss', $dailyDate, $dailyType);
    $stmt->execute();
    $dailyRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/* ---------------- READ: Monthly ---------------- */
$rowsMonth = [];
$sumMonth = [];
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $start = $month . "-01";
    $end = date('Y-m-t', strtotime($start));

    $sql = "SELECT day, transaction_type, ROUND(yhat) AS yhat_units, ROUND(yhat_lower) AS lo, ROUND(yhat_upper) AS hi 
            FROM forecast_daily 
            WHERE day BETWEEN ? AND ? ";
    $types = 'ss';
    $params = [$start, $end];

    if ($show !== 'both') {
        $sql .= " AND transaction_type = ?";
        $types .= 's';
        $params[] = $show;
    }
    $sql .= " ORDER BY day, transaction_type";

    $stmt = $DB->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rowsMonth = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $sumSql = "SELECT transaction_type, ROUND(SUM(yhat)) AS total_units 
               FROM forecast_daily 
               WHERE day BETWEEN ? AND ? " . ($show==='both' ? "" : " AND transaction_type = ?") . "
               GROUP BY transaction_type ORDER BY transaction_type";
    $stmt = $DB->prepare($sumSql);
    if ($show==='both') {
        $stmt->bind_param('ss', $start, $end);
    } else {
        $stmt->bind_param('sss', $start, $end, $show);
    }
    $stmt->execute();
    $sumMonth = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SH | Inventory System</title>
<link rel="icon" type="image/png" href="../files/emblem.png?v=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
    body { 
        background:#fff; 
        margin:0;
        display:flex; 
        flex-direction: column;
        min-height:100vh; 
        overflow-y: scroll; 
    }
    
    .main-content { 
        margin:5px 10px 10px 10px; 
        padding:10px; 
        flex:1; 
        background:#fff; 
    }
    
    .content-container { 
        width:100%; 
        max-width:1200px; 
        margin:0 auto;
    }
    
    .overview-stats { 
        background: linear-gradient(135deg, #e62929 0%, #b31616 100%); 
        padding:20px; 
        border-radius:16px; 
        margin-bottom:15px; 
        position:relative; 
        overflow:hidden; 
        box-shadow:0 10px 30px rgba(230,41,41,0.2); 
    }
    
    .overview-stats h1 { 
        color:#fff; 
        font-size:2rem; 
        margin:0 0 10px 0;
        position: relative;
        z-index: 2;
    }
    
    .overview-stats p { 
        color:#fff; 
        font-size:1rem;
        font-weight:400; 
        max-width:500px; 
        margin:0;
        position: relative;
        z-index: 2;
    }
    
    .shape-circle, .shape-triangle, .shape-square { 
        position:absolute; 
        z-index:1; 
    }
    
    .shape-circle { 
        width:180px; 
        height:180px; 
        border-radius:50%; 
        background:rgba(255,255,255,0.15); 
        right:-40px; 
        top:-40px; 
    }
    
    .shape-triangle { 
        border-left:100px solid transparent; 
        border-right:100px solid transparent; 
        border-bottom:170px solid rgba(255,255,255,0.1); 
        transform:rotate(45deg); 
        left:-50px; 
        bottom:-80px; 
    }
    
    .shape-square { 
        width:100px; 
        height:100px; 
        background:rgba(255,255,255,0.1); 
        transform:rotate(25deg); 
        right:100px; 
        bottom:30px; 
    }
    
    .cards { 
        display: grid; 
        grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); 
        gap: 20px; 
    }
    
    .card { 
        background: #fff; 
        border-radius: 12px; 
        overflow: hidden; 
        box-shadow: 0 25px 40px rgba(0,0,0,0.08); 
        display: flex; 
        flex-direction: column; 
        height: 400px; 
    }
    
    .card h3 { 
        margin:0; 
        background:#FFCDD2; 
        color:#e62929; 
        padding:12px 16px; 
        font-size:1.1rem; 
        font-weight:600; 
        display:flex; 
        align-items:center; 
        gap:8px; 
    }
    
    .forecast-box { 
        background: linear-gradient(135deg, #e62929 0%, #b31616 100%); 
        color:#fff; 
        border-radius:10px; 
        padding:20px; 
        margin:16px; 
        text-align:left; 
        font-size:1.6rem; 
        font-weight:bold; 
        position:relative; 
    }
    
    .forecast-box small { 
        display:block; 
        font-size:0.8rem; 
        margin-top:6px; 
        opacity:0.9; 
    }
    
    .forecast-box .icon { 
        position:absolute; 
        top:18px; 
        right:18px; 
        font-size:1.5rem; 
        opacity:0.9; 
    }
    
    .inline { 
        display:flex; 
        flex-wrap:wrap; 
        gap:10px; 
        padding:12px 16px; 
    }
    
    .inline input, .inline select { 
        padding:6px 10px; 
        border:1px solid #ddd; 
        border-radius:6px; 
        font-size:0.9rem; 
        flex:1;
        min-width: 120px;
    }
    
    .btn { 
        background:#e62929; 
        color:#fff; 
        border:none; 
        border-radius:6px; 
        padding:6px 14px; 
        cursor:pointer; 
        transition:0.2s; 
        font-size:0.9rem;
    }
    
    .btn:hover { 
        background: #b31616; 
    }
    
    .scrollable-table { 
        max-height:260px; 
        overflow-y:auto; 
        margin:0 16px 16px; 
        border:1px solid #eee; 
        border-radius:6px; 
    }
    
    .scrollable-table table { 
        border-collapse:collapse; 
        width:100%; 
        font-size:0.9rem; 
        min-width: 500px;
    }
    
    th { 
        background:#f9f9f9; 
        padding:8px; 
        font-weight:600; 
        border-bottom:1px solid #eee; 
        position: sticky;
        top: 0;
    }
    
    td { 
        padding:8px; 
        border-bottom:1px solid #f2f2f2; 
        text-align:center; 
    }
    
    tr:nth-child(even){
        background:#fcfcfc;
    }
    
    .totals { 
        margin:12px 16px; 
        padding:10px; 
        border-radius:8px; 
        background:#fdf3f3; 
        font-weight:600; 
        color:#b31616; 
        text-align:center; 
    }
    
    pre.log { 
        margin:12px 16px; 
        padding:10px; 
        border-radius:6px; 
        background:#fafafa; 
        border:1px dashed #ddd; 
        font-size:0.85rem; 
        white-space:pre-wrap; 
        overflow-x: auto;
    }

    /* ===== RESPONSIVE DESIGN ===== */

    /* Large tablets and small desktops (1024px to 1199px) */
    @media (max-width: 1199px) {
        .cards {
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 18px;
        }
        
        .card {
            height: 380px;
        }
        
        .overview-stats h1 {
            font-size: 1.8rem;
        }
    }

    /* Tablets (768px to 1023px) */
    @media (max-width: 1023px) {
        .main-content {
            padding: 8px;
            margin: 5px;
        }
        
        .cards {
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 15px;
        }
        
        .card {
            height: 360px;
        }
        
        .overview-stats {
            padding: 18px;
            border-radius: 14px;
        }
        
        .overview-stats h1 {
            font-size: 1.6rem;
        }
        
        .overview-stats p {
            font-size: 0.95rem;
        }
        
        .shape-circle {
            width: 150px;
            height: 150px;
            right: -30px;
            top: -30px;
        }
        
        .shape-triangle {
            border-left: 80px solid transparent;
            border-right: 80px solid transparent;
            border-bottom: 140px solid rgba(255,255,255,0.1);
            left: -40px;
            bottom: -60px;
        }
        
        .shape-square {
            width: 80px;
            height: 80px;
            right: 80px;
            bottom: 20px;
        }
        
        .forecast-box {
            font-size: 1.4rem;
            padding: 18px;
            margin: 14px;
        }
    }

    /* Large phones (576px to 767px) */
    @media (max-width: 767px) {
        body {
            margin: 0;
        }
        
        .main-content {
            padding: 5px;
            margin: 2px;
        }
        
        .content-container {
            padding: 0 5px;
        }
        
        .cards {
            grid-template-columns: 1fr;
            gap: 12px;
        }
        
        .card {
            height: auto;
            min-height: 350px;
        }
        
        .overview-stats {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 12px;
        }
        
        .overview-stats h1 {
            font-size: 1.4rem;
        }
        
        .overview-stats p {
            font-size: 0.9rem;
            max-width: 100%;
        }
        
        .shape-circle {
            width: 120px;
            height: 120px;
            right: -20px;
            top: -20px;
        }
        
        .shape-triangle {
            border-left: 60px solid transparent;
            border-right: 60px solid transparent;
            border-bottom: 100px solid rgba(255,255,255,0.1);
            left: -30px;
            bottom: -40px;
        }
        
        .shape-square {
            width: 60px;
            height: 60px;
            right: 40px;
            bottom: 10px;
        }
        
        .forecast-box {
            font-size: 1.3rem;
            padding: 15px;
            margin: 12px;
            text-align: center;
        }
        
        .forecast-box .icon {
            position: relative;
            top: auto;
            right: auto;
            display: block;
            margin-top: 8px;
        }
        
        .inline {
            flex-direction: column;
            gap: 8px;
            padding: 10px 12px;
        }
        
        .inline input, 
        .inline select {
            width: 100%;
            min-width: auto;
        }
        
        .scrollable-table {
            margin: 0 12px 12px;
            border-radius: 6px;
        }
        
        .scrollable-table table {
            min-width: 400px;
            font-size: 0.85rem;
        }
        
        th, td {
            padding: 6px;
        }
        
        .totals {
            margin: 10px 12px;
            padding: 8px;
            font-size: 0.9rem;
        }
        
        pre.log {
            margin: 10px 12px;
            padding: 8px;
            font-size: 0.8rem;
        }
    }

    /* Small phones (575px and below) */
    @media (max-width: 575px) {
        .main-content {
            padding: 3px;
            margin: 2px;
        }
        
        .content-container {
            padding: 0 3px;
        }
        
        .cards {
            gap: 10px;
        }
        
        .card {
            min-height: 320px;
            border-radius: 10px;
        }
        
        .card h3 {
            padding: 10px 12px;
            font-size: 1rem;
        }
        
        .overview-stats {
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        
        .overview-stats h1 {
            font-size: 1.2rem;
        }
        
        .overview-stats p {
            font-size: 0.85rem;
        }
        
        .shape-circle {
            width: 100px;
            height: 100px;
            right: -15px;
            top: -15px;
        }
        
        .shape-triangle {
            border-left: 40px solid transparent;
            border-right: 40px solid transparent;
            border-bottom: 70px solid rgba(255,255,255,0.1);
            left: -20px;
            bottom: -25px;
        }
        
        .shape-square {
            width: 40px;
            height: 40px;
            right: 20px;
            bottom: 5px;
        }
        
        .forecast-box {
            font-size: 1.1rem;
            padding: 12px;
            margin: 10px;
        }
        
        .forecast-box small {
            font-size: 0.75rem;
        }
        
        .inline {
            padding: 8px 10px;
            gap: 6px;
        }
        
        .inline input, 
        .inline select {
            padding: 5px 8px;
            font-size: 0.85rem;
        }
        
        .btn {
            padding: 5px 12px;
            font-size: 0.85rem;
        }
        
        .scrollable-table {
            margin: 0 10px 10px;
            max-height: 220px;
        }
        
        .scrollable-table table {
            min-width: 350px;
            font-size: 0.8rem;
        }
        
        th, td {
            padding: 5px;
        }
        
        .totals {
            margin: 8px 10px;
            padding: 6px;
            font-size: 0.85rem;
        }
        
        pre.log {
            margin: 8px 10px;
            padding: 6px;
            font-size: 0.75rem;
        }
    }

    /* Very small phones (400px and below) */
    @media (max-width: 400px) {
        .cards {
            grid-template-columns: 1fr;
        }
        
        .scrollable-table table {
            min-width: 300px;
        }
        
        .overview-stats h1 {
            font-size: 1.1rem;
        }
        
        .card h3 {
            font-size: 0.95rem;
            padding: 8px 10px;
        }
        
        .forecast-box {
            font-size: 1rem;
        }
    }

    /* Print styles */
    @media print {
        body {
            background: #fff;
            margin: 0;
        }
        
        .main-content {
            margin: 0;
            padding: 0;
        }
        
        .overview-stats {
            background: #e62929 !important;
            box-shadow: none;
            -webkit-print-color-adjust: exact;
        }
        
        .card {
            box-shadow: none;
            border: 1px solid #ddd;
            break-inside: avoid;
        }
        
        .btn {
            display: none;
        }
        
        .scrollable-table {
            max-height: none;
            overflow: visible;
        }
    }

    /* High DPI screens */
    @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
        .card {
            box-shadow: 0 15px 25px rgba(0,0,0,0.1);
        }
        
        .overview-stats {
            box-shadow: 0 8px 25px rgba(230,41,41,0.15);
        }
    }

    /* Orientation adjustments */
    @media (max-height: 600px) and (orientation: landscape) {
        .card {
            height: 300px;
        }
        
        .scrollable-table {
            max-height: 180px;
        }
    }
</style>
</head>
<body>
<main class="main-content">
<div class="content-container">

    <!-- Overview Banner -->
    <div class="overview-stats">
        <div class="shape-circle"></div>
        <div class="shape-triangle"></div>
        <div class="shape-square"></div>
        <h1>Daily Request Statistics <i class="fa-solid fa-chart-simple"></i></h1>
        <p>Forecasts are based on historical requests. Pick a date/type or a month to view predicted <strong>units</strong>.</p>
    </div>

    <div class="cards">
        <!-- Daily Forecast -->
        <div class="card">
            <h3><i class="fa-solid fa-calendar-day"></i> Daily Forecast</h3>
            <div class="forecast-box">
                <span class="icon"><i class="fa-solid fa-droplet"></i></span>
                <div><?= number_format($dailyRow['yhat_units'] ?? 0) ?></div>
                <small>Units Predicted</small>
            </div>
            <form class="inline" method="post">
                <input type="hidden" name="action" value="predict_day">
                <input type="date" name="day" value="<?=h($dailyDate)?>" required>
                <select name="type">
                    <option value="IN" <?= $dailyType==='IN'?'selected':'' ?>>IN</option>
                    <option value="OUT" <?= $dailyType==='OUT'?'selected':'' ?>>OUT</option>
                </select>
                <button class="btn" type="submit">Predict</button>
            </form>
            <?php if (!empty($run_log) && ($_POST['action'] ?? '') === 'predict_day'): ?>
                <pre class="log"><?=h($run_log)?></pre>
            <?php endif; ?>
        </div>

        <!-- Monthly Forecast -->
        <div class="card">
            <h3><i class="fa-solid fa-calendar-days"></i> Monthly Forecast</h3>
            <form class="inline" method="post">
                <input type="hidden" name="action" value="predict_month">
                <input type="month" name="month" value="<?=h($month)?>" required>
                <select name="show">
                    <option value="both" <?= $show==='both'?'selected':'' ?>>IN & OUT</option>
                    <option value="IN" <?= $show==='IN'?'selected':'' ?>>IN only</option>
                    <option value="OUT" <?= $show==='OUT'?'selected':'' ?>>OUT only</option>
                </select>
                <button class="btn" type="submit">Predict</button>
            </form>
            <?php if (!empty($run_log) && ($_POST['action'] ?? '') === 'predict_month'): ?>
                <pre class="log"><?=h($run_log)?></pre>
            <?php endif; ?>
            <?php if (!$rowsMonth): ?>
                <p class="totals">No data yet for <?=h($month)?></p>
            <?php else: ?>
                <div class="scrollable-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Type</th>
                                <th>Forecast</th>
                                <th>Range</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rowsMonth as $r): ?>
                            <tr>
                                <td><?=h($r['day'])?></td>
                                <td><?=h($r['transaction_type'])?></td>
                                <td><?=number_format($r['yhat_units'])?> units</td>
                                <td><?=number_format($r['lo'])?>–<?=number_format($r['hi'])?> units</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="totals">
                    Totals: 
                    <?php 
                    $parts = [];
                    foreach ($sumMonth as $s) { 
                        $parts[] = h($s['transaction_type']).": ".number_format($s['total_units'])." units"; 
                    }
                    echo implode(" | ", $parts); 
                    ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
</main>
</body>
</html>
