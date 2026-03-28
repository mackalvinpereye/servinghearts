<?php
// verify_paths.php
echo "<h3>Checking File Paths</h3>";

$base_path = "C:\\xampp\\htdocs\\servinghearts\\";

// Check if directory exists
echo "Base directory: " . $base_path . "<br>";
echo "Exists: " . (is_dir($base_path) ? "✅ YES" : "❌ NO") . "<br><br>";

// Check Python script
$python_script = $base_path . "py\\step2_sarimax.py";
echo "Python script: " . $python_script . "<br>";
echo "Exists: " . (file_exists($python_script) ? "✅ YES" : "❌ NO") . "<br><br>";

// Check if we can execute commands
echo "<h3>Testing Command Execution</h3>";
echo "shell_exec test: " . shell_exec('echo Hello World 2>&1') . "<br>";

// Test Python
echo "Python test: " . shell_exec('py --version 2>&1') . "<br>";
?>