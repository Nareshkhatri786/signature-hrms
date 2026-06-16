<?php
// Set default timezone to Indian Standard Time (IST)
date_default_timezone_set('Asia/Kolkata');
// ONE-TIME password setter for employees
// DELETE THIS FILE after use!
// Usage: visit https://hrms.diyacrm.in/set_emp_password.php?key=HrmsSetup2026

$secret_key = 'HrmsSetup2026';
if (($_GET['key'] ?? '') !== $secret_key) {
    http_response_code(403);
    die('Forbidden');
}

$pdo = new PDO("mysql:host=localhost;dbname=signature_hrms;charset=utf8mb4", 'hrms_user', 'HrmsSign@2026!');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$updates = [
    // [employee_code, new_password]
    ['EMP-101', 'Megha@123'],
];

echo "<pre>\n";
foreach ($updates as [$code, $password]) {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE employees SET password = ? WHERE code = ?");
    $stmt->execute([$hash, $code]);
    $rows = $stmt->rowCount();
    echo "Updated $code → password='$password' → rows affected: $rows\n";
}

// Also set a fallback default for any employee with no password
$default_hash = password_hash('Signature@2026', PASSWORD_BCRYPT);
$stmt2 = $pdo->exec("UPDATE employees SET password = '$default_hash' WHERE password IS NULL OR password = ''");
echo "Default password set for $stmt2 employees with no password.\n";

echo "\nDone! Please delete this file from the server now.\n";
echo "</pre>";
