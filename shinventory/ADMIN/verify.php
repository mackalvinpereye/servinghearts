<?php
// find_python.php
echo "<h2>Finding Python on Your System</h2>";

// Common Python locations on Windows
$locations = [
    // Check if python command works
    'python --version' => 'python command',
    
    // Check specific paths (with quotes for spaces)
    '"C:\\Users\\A S U S\\AppData\\Local\\Programs\\Python\\Python314\\python.exe" --version' => 'Python 3.14 path',
    'C:\\Python314\\python.exe --version' => 'C:\\Python314',
    'C:\\Python\\python.exe --version' => 'C:\\Python',
    'C:\\Program Files\\Python314\\python.exe --version' => 'Program Files\\Python314',
    'C:\\Program Files\\Python\\python.exe --version' => 'Program Files\\Python',
    
    // Use where command to find python
    'where python' => 'where python command',
    'where python.exe' => 'where python.exe',
    
    // Try Windows command to list Python installations
    'dir "C:\\Users\\A S U S\\AppData\\Local\\Programs\\Python" /b' => 'List Python folders',
];

foreach ($locations as $cmd => $desc) {
    echo "<strong>$desc:</strong><br>";
    $output = shell_exec($cmd . ' 2>&1');
    if (empty($output)) {
        echo "(no output)<br><br>";
    } else {
        echo "<pre>" . htmlspecialchars($output) . "</pre><br>";
    }
}

// Test if we can actually run a Python script
echo "<h3>Test Running Python Script</h3>";
$test_script = __DIR__ . '\\py\\test_python.py';
$test_code = 'print("Hello from Python!")\nprint("Python is working!")';
file_put_contents($test_script, $test_code);

// Try different ways to run it
$test_commands = [
    'python "' . $test_script . '"' => 'python command',
    '"C:\\Users\\A S U S\\AppData\\Local\\Programs\\Python\\Python314\\python.exe" "' . $test_script . '"' => 'Full path',
];

foreach ($test_commands as $cmd => $desc) {
    echo "<strong>$desc:</strong><br>";
    $output = shell_exec($cmd . ' 2>&1');
    echo "<pre>" . htmlspecialchars($output ?: '(no output)') . "</pre><br>";
}

unlink($test_script); // Clean up
?>