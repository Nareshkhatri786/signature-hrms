<?php
declare(strict_types=1);

// Security Key
define('SETUP_KEY', 'setup_signature_2026');

$key = $_GET['key'] ?? $_POST['key'] ?? '';
if ($key !== SETUP_KEY) {
    http_response_code(403);
    die("Unauthorized access. Please specify the correct setup key (?key=...)");
}

$db_host = $_POST['db_host'] ?? 'localhost';
$db_user = $_POST['db_user'] ?? 'root';
$db_pass = $_POST['db_pass'] ?? '';
$db_name = $_POST['db_name'] ?? 'signature_hrms';

$status_message = "";
$status_type = "info";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_setup'])) {
    try {
        // First connect without DB name to create DB
        $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create DB
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Reconnect to the database
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create users table
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            name VARCHAR(100),
            role ENUM('super_admin','manager') DEFAULT 'super_admin',
            project_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        // Create projects table
        $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            address TEXT,
            lat DECIMAL(10,8) DEFAULT 0.0,
            lng DECIMAL(11,8) DEFAULT 0.0,
            radius INT DEFAULT 100,
            shift_start TIME DEFAULT '10:00:00',
            shift_end TIME DEFAULT '19:00:00',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        // Create employees table
        $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(20) UNIQUE NOT NULL,
            name VARCHAR(100) NOT NULL,
            role ENUM('Telecaller','Sales Executive','Asst. Sales Manager','Sales Manager','Sr. Sales Manager') NOT NULL,
            project_id INT NULL,
            salary DECIMAL(10,2) DEFAULT 0.0,
            petrol DECIMAL(10,2) DEFAULT 0.0,
            phone VARCHAR(20),
            status ENUM('Active','Inactive') DEFAULT 'Active',
            shift_type ENUM('staff','manager') DEFAULT 'staff',
            join_date DATE NULL,
            notes TEXT,
            incentive_config JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        // Create attendance table
        $pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            emp_id INT NOT NULL,
            project_id INT NULL,
            attendance_date DATE NOT NULL,
            check_in_time TIME NULL,
            check_out_time TIME NULL,
            distance_metres INT DEFAULT 0,
            face_verified TINYINT(1) DEFAULT 0,
            gps_verified TINYINT(1) DEFAULT 0,
            status ENUM('On Time','Late','Major Late','Half Day','Absent','Field Visit') DEFAULT 'Absent',
            late_minutes INT DEFAULT 0,
            early_departure_mins INT DEFAULT 0,
            working_minutes INT DEFAULT 0,
            deduction_rs DECIMAL(10,2) DEFAULT 0.0,
            grace_used TINYINT(1) DEFAULT 0,
            regularization_status ENUM('none','pending','approved','rejected') DEFAULT 'none',
            regularization_reason VARCHAR(500) NULL,
            regularization_submitted_at TIMESTAMP NULL,
            manager_remark TEXT NULL,
            reviewed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_emp_date (emp_id, attendance_date),
            FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");

        // Create advances table
        $pdo->exec("CREATE TABLE IF NOT EXISTS advances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            emp_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            deduction_month VARCHAR(7) NOT NULL,
            reason TEXT,
            approved TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        // Create attendance_policy table
        $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_policy (
            id INT PRIMARY KEY DEFAULT 1,
            staff_shift_start TIME DEFAULT '10:00:00',
            staff_shift_end TIME DEFAULT '19:00:00',
            staff_grace_mins INT DEFAULT 10,
            mgr_shift_start TIME DEFAULT '10:15:00',
            mgr_shift_end TIME DEFAULT '19:00:00',
            mgr_grace_mins INT DEFAULT 10,
            lunch_minutes INT DEFAULT 60,
            payable_days INT DEFAULT 26,
            paid_hours INT DEFAULT 8,
            grace_free_incidents INT DEFAULT 3
        ) ENGINE=InnoDB");

        // Create activity_log table
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            icon VARCHAR(20) DEFAULT 'check',
            message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        // Insert Default Policy
        $stmt = $pdo->query("SELECT COUNT(*) FROM attendance_policy");
        if ($stmt->fetchColumn() == 0) {
            $pdo->exec("INSERT INTO attendance_policy (id, staff_shift_start, staff_shift_end, staff_grace_mins, mgr_shift_start, mgr_shift_end, mgr_grace_mins, lunch_minutes, payable_days, paid_hours, grace_free_incidents) 
                       VALUES (1, '10:00:00', '19:00:00', 10, '10:15:00', '19:00:00', 10, 60, 26, 8, 3)");
        }

        // Insert Admin User
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute(['admin']);
        if ($stmt->fetchColumn() == 0) {
            $hashed_pass = password_hash('Signature@2026', PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, name, role) VALUES (?, ?, ?, ?)");
            $stmt->execute(['admin', $hashed_pass, 'Super Admin', 'super_admin']);
        }

        // Create database user if needed (Optional, depending on privileges)
        try {
            $pdo->exec("CREATE USER IF NOT EXISTS 'hrms_user'@'localhost' IDENTIFIED BY 'HrmsSign@2026!'");
            $pdo->exec("GRANT ALL PRIVILEGES ON `$db_name`.* TO 'hrms_user'@'localhost'");
            $pdo->exec("FLUSH PRIVILEGES");
        } catch (PDOException $ex) {
            // Might fail if not root, that's fine
        }

        $status_message = "Setup executed successfully! Database and all tables are created. Default administrator user 'admin' created with password 'Signature@2026'.";
        $status_type = "success";

    } catch (PDOException $e) {
        $status_message = "Database Error: " . $e->getMessage();
        $status_type = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signature Properties HRMS - Database Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f5f6f2; color: #18312b; font-family: sans-serif; }
        .setup-card { background: white; border-radius: 15px; border: 1px solid #e4e8e2; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .btn-green { background-color: #123e35; color: white; }
        .btn-green:hover { background-color: #1d584b; color: white; }
    </style>
</head>
<body>
    <div class="container my-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="setup-card p-5">
                    <h2 class="mb-4 text-center">Signature Properties HRMS Setup</h2>
                    <p class="text-muted text-center mb-4">Initial database schema creator and admin account initializer.</p>
                    
                    <?php if ($status_message): ?>
                        <div class="alert alert-<?= $status_type ?>" role="alert">
                            <?= htmlspecialchars($status_message) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Database Host</label>
                            <input type="text" class="form-label form-control" name="db_host" value="<?= htmlspecialchars($db_host) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Database User (with privileges to create DB/Tables)</label>
                            <input type="text" class="form-label form-control" name="db_user" value="<?= htmlspecialchars($db_user) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Database Password</label>
                            <input type="password" class="form-label form-control" name="db_pass" value="<?= htmlspecialchars($db_pass) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Database Name</label>
                            <input type="text" class="form-label form-control" name="db_name" value="<?= htmlspecialchars($db_name) ?>" required>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" name="run_setup" class="btn btn-green btn-lg">Run Database Setup</button>
                        </div>
                    </form>
                    
                    <div class="mt-4 text-center">
                        <small class="text-danger"><strong>Warning:</strong> Delete this file from the server after successful database creation to secure the application.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
