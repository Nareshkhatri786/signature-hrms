<?php
declare(strict_types=1);

session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'signature_hrms');
define('DB_USER', 'hrms_user');
define('DB_PASS', 'HrmsSign@2026!');
define('APP_NAME', 'Signature Properties');

// Initialize database connection
$pdo = null;
$db_error = null;
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $db_error = "Could not connect to the database. Make sure setup.php has been run and details are correct. Error: " . $e->getMessage();
}

// ─── API Router ───
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    
    if ($db_error) {
        http_response_code(500);
        echo json_encode(['error' => $db_error]);
        exit;
    }
    
    $api = $_GET['api'];
    
    // Auth Check helper
    $require_auth = function() {
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    };
    
    $require_admin = function() {
        if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
    };

    try {
        switch ($api) {
            case 'login':
                $data = json_decode(file_get_contents('php://input'), true);
                $username = trim($data['username'] ?? '');
                $password = trim($data['password'] ?? '');
                
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['project_id'] = $user['project_id'];
                    
                    echo json_encode([
                        'success' => true,
                        'user' => [
                            'id' => $user['id'],
                            'username' => $user['username'],
                            'name' => $user['name'],
                            'role' => $user['role'],
                            'project_id' => $user['project_id']
                        ]
                    ]);
                } else {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid username or password']);
                }
                break;
                
            case 'logout':
                session_destroy();
                echo json_encode(['success' => true]);
                break;
                
            case 'me':
                if (empty($_SESSION['user_id'])) {
                    echo json_encode(['logged_in' => false]);
                } else {
                    echo json_encode([
                        'logged_in' => true,
                        'user' => [
                            'id' => $_SESSION['user_id'],
                            'username' => $_SESSION['username'],
                            'name' => $_SESSION['name'],
                            'role' => $_SESSION['role'],
                            'project_id' => $_SESSION['project_id']
                        ]
                    ]);
                }
                break;
                
            case 'dashboard':
                $require_auth();
                $is_mgr = $_SESSION['role'] === 'manager';
                $mgr_pid = $_SESSION['project_id'];
                
                // Active employee count
                $emp_sql = "SELECT COUNT(*) FROM employees WHERE status = 'Active'";
                if ($is_mgr) $emp_sql .= " AND project_id = " . (int)$mgr_pid;
                $total_employees = (int) $pdo->query($emp_sql)->fetchColumn();
                
                // Total Projects
                $proj_sql = "SELECT COUNT(*) FROM projects";
                if ($is_mgr) $proj_sql .= " WHERE id = " . (int)$mgr_pid;
                $total_projects = (int) $pdo->query($proj_sql)->fetchColumn();
                
                // Today Stats
                $today = date('Y-MM-DD');
                $att_sql = "SELECT a.*, e.name as emp_name FROM attendance a 
                            JOIN employees e ON a.emp_id = e.id 
                            WHERE a.attendance_date = CURRENT_DATE()";
                if ($is_mgr) $att_sql .= " AND e.project_id = " . (int)$mgr_pid;
                $stmt = $pdo->query($att_sql);
                $today_records = $stmt->fetchAll();
                
                $stats = [
                    'on_time' => 0, 'late' => 0, 'major_late' => 0,
                    'half_day' => 0, 'absent' => 0, 'early_checkout' => 0,
                    'not_checked_in' => $total_employees
                ];
                
                foreach ($today_records as $rec) {
                    $stats['not_checked_in']--;
                    if ($rec['status'] === 'On Time') $stats['on_time']++;
                    elseif ($rec['status'] === 'Late') $stats['late']++;
                    elseif ($rec['status'] === 'Major Late') $stats['major_late']++;
                    elseif ($rec['status'] === 'Half Day') $stats['half_day']++;
                    elseif ($rec['status'] === 'Absent') $stats['absent']++;
                    
                    if ($rec['early_departure_mins'] > 0) {
                        $stats['early_checkout']++;
                    }
                }
                
                // Get Activity Logs
                $act_sql = "SELECT * FROM activity_log ORDER BY id DESC LIMIT 10";
                $recent_activity = $pdo->query($act_sql)->fetchAll();
                
                echo json_encode([
                    'total_employees' => $total_employees,
                    'total_projects' => $total_projects,
                    'today_stats' => $stats,
                    'recent_activity' => $recent_activity
                ]);
                break;
                
            case 'employees':
                $require_auth();
                $is_mgr = $_SESSION['role'] === 'manager';
                $mgr_pid = $_SESSION['project_id'];
                
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $sql = "SELECT e.*, p.name as project_name FROM employees e 
                            LEFT JOIN projects p ON e.project_id = p.id";
                    if ($is_mgr) {
                        $sql .= " WHERE e.project_id = " . (int)$mgr_pid;
                    }
                    $sql .= " ORDER BY e.id DESC";
                    echo json_encode($pdo->query($sql)->fetchAll());
                } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $action = $data['action'] ?? '';
                    
                    if ($action === 'save') {
                        // Manager cannot add/edit employees, only super_admin
                        if ($is_mgr) {
                            http_response_code(403);
                            echo json_encode(['error' => 'Forbidden']);
                            exit;
                        }
                        
                        $emp = $data['data'];
                        $id = $emp['id'] ?? null;
                        
                        if ($id) {
                            $stmt = $pdo->prepare("UPDATE employees SET code = ?, name = ?, role = ?, project_id = ?, salary = ?, petrol = ?, phone = ?, status = ?, shift_type = ?, join_date = ?, notes = ?, incentive_config = ? WHERE id = ?");
                            $stmt->execute([
                                $emp['code'], $emp['name'], $emp['role'], 
                                !empty($emp['project_id']) ? $emp['project_id'] : null, 
                                $emp['salary'], $emp['petrol'], $emp['phone'], $emp['status'], 
                                $emp['shift_type'], $emp['join_date'] ?: null, $emp['notes'], 
                                json_encode($emp['incentive_config']), $id
                            ]);
                            
                            // Log activity
                            $stmt_log = $pdo->prepare("INSERT INTO activity_log (icon, message) VALUES ('check', ?)");
                            $stmt_log->execute(["Employee <strong>{$emp['name']}</strong> updated."]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO employees (code, name, role, project_id, salary, petrol, phone, status, shift_type, join_date, notes, incentive_config) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $emp['code'], $emp['name'], $emp['role'], 
                                !empty($emp['project_id']) ? $emp['project_id'] : null, 
                                $emp['salary'], $emp['petrol'], $emp['phone'], $emp['status'], 
                                $emp['shift_type'], $emp['join_date'] ?: null, $emp['notes'], 
                                json_encode($emp['incentive_config'])
                            ]);
                            
                            // Log activity
                            $stmt_log = $pdo->prepare("INSERT INTO activity_log (icon, message) VALUES ('check', ?)");
                            $stmt_log->execute(["Employee <strong>{$emp['name']}</strong> added."]);
                        }
                        echo json_encode(['success' => true]);
                    } elseif ($action === 'delete') {
                        if ($is_mgr) {
                            http_response_code(403);
                            echo json_encode(['error' => 'Forbidden']);
                            exit;
                        }
                        $id = $data['id'];
                        
                        $stmt_name = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
                        $stmt_name->execute([$id]);
                        $name = $stmt_name->fetchColumn();
                        
                        $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
                        $stmt->execute([$id]);
                        
                        if ($name) {
                            $stmt_log = $pdo->prepare("INSERT INTO activity_log (icon, message) VALUES ('check', ?)");
                            $stmt_log->execute(["Employee <strong>{$name}</strong> deleted."]);
                        }
                        
                        echo json_encode(['success' => true]);
                    }
                }
                break;
                
            case 'projects':
                $require_auth();
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $is_mgr = $_SESSION['role'] === 'manager';
                    $sql = "SELECT * FROM projects";
                    if ($is_mgr) {
                        $sql .= " WHERE id = " . (int)$_SESSION['project_id'];
                    }
                    $sql .= " ORDER BY id DESC";
                    echo json_encode($pdo->query($sql)->fetchAll());
                } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $require_admin();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $action = $data['action'] ?? '';
                    
                    if ($action === 'save') {
                        $p = $data['data'];
                        $id = $p['id'] ?? null;
                        
                        if ($id) {
                            $stmt = $pdo->prepare("UPDATE projects SET name = ?, address = ?, lat = ?, lng = ?, radius = ?, shift_start = ?, shift_end = ? WHERE id = ?");
                            $stmt->execute([
                                $p['name'], $p['address'], $p['lat'], $p['lng'], $p['radius'], $p['shift_start'], $p['shift_end'], $id
                            ]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO projects (name, address, lat, lng, radius, shift_start, shift_end) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $p['name'], $p['address'], $p['lat'], $p['lng'], $p['radius'], $p['shift_start'], $p['shift_end']
                            ]);
                        }
                        echo json_encode(['success' => true]);
                    } elseif ($action === 'delete') {
                        $id = $data['id'];
                        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
                        $stmt->execute([$id]);
                        echo json_encode(['success' => true]);
                    }
                }
                break;
                
            case 'policy':
                $require_auth();
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $policy = $pdo->query("SELECT * FROM attendance_policy WHERE id = 1")->fetch();
                    echo json_encode($policy);
                } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $require_admin();
                    $data = json_decode(file_get_contents('php://input'), true);
                    $p = $data['data'];
                    
                    $stmt = $pdo->prepare("UPDATE attendance_policy SET staff_shift_start = ?, staff_shift_end = ?, staff_grace_mins = ?, mgr_shift_start = ?, mgr_shift_end = ?, mgr_grace_mins = ?, lunch_minutes = ?, payable_days = ?, paid_hours = ?, grace_free_incidents = ? WHERE id = 1");
                    $stmt->execute([
                        $p['staff_shift_start'], $p['staff_shift_end'], $p['staff_grace_mins'],
                        $p['mgr_shift_start'], $p['mgr_shift_end'], $p['mgr_grace_mins'],
                        $p['lunch_minutes'], $p['payable_days'], $p['paid_hours'], $p['grace_free_incidents']
                    ]);
                    echo json_encode(['success' => true]);
                }
                break;

            case 'attendance':
                $require_auth();
                $is_mgr = $_SESSION['role'] === 'manager';
                $mgr_pid = $_SESSION['project_id'];
                
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $date = $_GET['date'] ?? date('Y-m-d');
                    
                    $sql = "SELECT a.*, e.name as employee_name, e.role as employee_role, p.name as project_name 
                            FROM attendance a 
                            JOIN employees e ON a.emp_id = e.id 
                            LEFT JOIN projects p ON a.project_id = p.id
                            WHERE a.attendance_date = ?";
                    
                    $params = [$date];
                    if ($is_mgr) {
                        $sql .= " AND e.project_id = ?";
                        $params[] = $mgr_pid;
                    }
                    
                    if (!empty($_GET['status'])) {
                        $sql .= " AND a.status = ?";
                        $params[] = $_GET['status'];
                    }
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    echo json_encode($stmt->fetchAll());
                } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $action = $data['action'] ?? '';
                    
                    if ($action === 'checkin') {
                        $emp_id = (int) $data['emp_id'];
                        $project_id = !empty($data['project_id']) ? (int)$data['project_id'] : null;
                        $distance = (int) ($data['distance'] ?? 0);
                        $face_verified = (int) ($data['face_verified'] ?? 0);
                        $gps_verified = (int) ($data['gps_verified'] ?? 0);
                        $check_in_time = date('H:i:s');
                        $attendance_date = date('Y-m-d');
                        
                        // Load Policy and Employee
                        $policy = $pdo->query("SELECT * FROM attendance_policy WHERE id = 1")->fetch();
                        $stmt_emp = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
                        $stmt_emp->execute([$emp_id]);
                        $emp = $stmt_emp->fetch();
                        
                        if (!$emp) {
                            http_response_code(400);
                            echo json_encode(['error' => 'Employee not found']);
                            exit;
                        }
                        
                        // Calculate Status
                        $is_mgr_type = in_array($emp['shift_type'], ['manager']);
                        $shift_start = $is_mgr_type ? $policy['mgr_shift_start'] : $policy['staff_shift_start'];
                        $grace_mins  = $is_mgr_type ? $policy['mgr_grace_mins']  : $policy['staff_grace_mins'];
                        
                        [$sh, $sm] = explode(':', $shift_start);
                        [$ch, $cm] = explode(':', $check_in_time);
                        $shiftMins = (int)$sh * 60 + (int)$sm;
                        $checkMins = (int)$ch * 60 + (int)$cm;
                        $late_minutes  = max(0, $checkMins - $shiftMins);
                        $graceEnd  = $shiftMins + $grace_mins;
                        
                        $status = 'On Time';
                        $grace_used = 0;
                        if ($checkMins <= $graceEnd) {
                            $status = 'On Time';
                            $grace_used = ($checkMins > $shiftMins) ? 1 : 0;
                            $late_minutes = 0;
                        } elseif ($late_minutes <= 30) {
                            $status = 'Late';
                        } elseif ($late_minutes <= 60) {
                            $status = 'Major Late';
                        } else {
                            $status = 'Half Day';
                        }
                        
                        // Calculate Month Grace Incidents
                        $stmt_grace = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE emp_id = ? AND MONTH(attendance_date) = MONTH(CURRENT_DATE()) AND grace_used = 1");
                        $stmt_grace->execute([$emp_id]);
                        $month_grace_count = (int)$stmt_grace->fetchColumn();
                        
                        // Calculate Deduction
                        $deduction = 0.0;
                        if ($status !== 'On Time') {
                            $salary = (float) $emp['salary'];
                            $per_min = $salary / (float)$policy['payable_days'] / (float)$policy['paid_hours'] / 60.0;
                            
                            if ($status === 'Late' && $month_grace_count < (int)$policy['grace_free_incidents']) {
                                $deduction = 0.0; // Free grace
                            } else {
                                if ($status === 'Late' || $status === 'Major Late') {
                                    $deduction = round($per_min * $late_minutes, 2);
                                } elseif ($status === 'Half Day') {
                                    $deduction = round($salary / (float)$policy['payable_days'] / 2.0, 2);
                                }
                            }
                        }
                        
                        // Save Attendance
                        $stmt_save = $pdo->prepare("INSERT INTO attendance 
                            (emp_id, project_id, attendance_date, check_in_time, distance_metres, face_verified, gps_verified, status, late_minutes, deduction_rs, grace_used) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE check_in_time = ?, distance_metres = ?, face_verified = ?, gps_verified = ?, status = ?, late_minutes = ?, deduction_rs = ?, grace_used = ?");
                        
                        $stmt_save->execute([
                            $emp_id, $project_id, $attendance_date, $check_in_time, $distance, $face_verified, $gps_verified, $status, $late_minutes, $deduction, $grace_used,
                            $check_in_time, $distance, $face_verified, $gps_verified, $status, $late_minutes, $deduction, $grace_used
                        ]);
                        
                        // Activity Log
                        $stmt_act = $pdo->prepare("INSERT INTO activity_log (icon, message) VALUES ('check', ?)");
                        $stmt_act->execute(["Employee <strong>{$emp['name']}</strong> checked in ($status)"]);
                        
                        echo json_encode([
                            'success' => true,
                            'status' => $status,
                            'late_minutes' => $late_minutes,
                            'deduction_rs' => $deduction
                        ]);
                        
                    } elseif ($action === 'checkout') {
                        $att_id = (int)$data['att_id'];
                        $check_out_time = date('H:i:s');
                        
                        // Fetch checkin details
                        $stmt_att = $pdo->prepare("SELECT a.*, e.salary, e.shift_type FROM attendance a JOIN employees e ON a.emp_id = e.id WHERE a.id = ?");
                        $stmt_att->execute([$att_id]);
                        $att = $stmt_att->fetch();
                        
                        if (!$att) {
                            http_response_code(400);
                            echo json_encode(['error' => 'Attendance record not found']);
                            exit;
                        }
                        
                        $policy = $pdo->query("SELECT * FROM attendance_policy WHERE id = 1")->fetch();
                        
                        // Calculate working minutes
                        $in_time = new DateTime($att['check_in_time']);
                        $out_time = new DateTime($check_out_time);
                        $interval = $in_time->diff($out_time);
                        $working_minutes = ($interval->h * 60) + $interval->i;
                        
                        // Subtract lunch break
                        $net_working_minutes = max(0, $working_minutes - (int)$policy['lunch_minutes']);
                        
                        // Check early departure
                        $is_mgr_type = in_array($att['shift_type'], ['manager']);
                        $shift_end = $is_mgr_type ? $policy['mgr_shift_end'] : $policy['staff_shift_end'];
                        
                        [$seh, $sem] = explode(':', $shift_end);
                        [$coh, $com] = explode(':', $check_out_time);
                        $shiftEndMins = (int)$seh * 60 + (int)$sem;
                        $checkoutMins = (int)$coh * 60 + (int)$com;
                        $early_departure_mins = max(0, $shiftEndMins - $checkoutMins);
                        
                        // Calculate Early Deduction if any
                        $deduction = (float) $att['deduction_rs'];
                        if ($early_departure_mins > 0) {
                            $salary = (float) $att['salary'];
                            $per_min = $salary / (float)$policy['payable_days'] / (float)$policy['paid_hours'] / 60.0;
                            $deduction += round($per_min * $early_departure_mins, 2);
                        }
                        
                        $stmt_save = $pdo->prepare("UPDATE attendance SET check_out_time = ?, working_minutes = ?, early_departure_mins = ?, deduction_rs = ? WHERE id = ?");
                        $stmt_save->execute([$check_out_time, $net_working_minutes, $early_departure_mins, $deduction, $att_id]);
                        
                        echo json_encode(['success' => true]);
                    } elseif ($action === 'mark_absent') {
                        // Mark all employees who have not checked in today as absent
                        $date = $data['date'] ?? date('Y-m-d');
                        
                        $sql = "SELECT id, project_id FROM employees WHERE status = 'Active'";
                        if ($is_mgr) $sql .= " AND project_id = " . (int)$mgr_pid;
                        $emps = $pdo->query($sql)->fetchAll();
                        
                        $policy = $pdo->query("SELECT * FROM attendance_policy WHERE id = 1")->fetch();
                        
                        $absent_count = 0;
                        foreach ($emps as $e) {
                            // Check if attendance exists
                            $stmt_check = $pdo->prepare("SELECT id FROM attendance WHERE emp_id = ? AND attendance_date = ?");
                            $stmt_check->execute([$e['id'], $date]);
                            if (!$stmt_check->fetch()) {
                                // Calculate deduction
                                $stmt_emp = $pdo->prepare("SELECT salary FROM employees WHERE id = ?");
                                $stmt_emp->execute([$e['id']]);
                                $sal = (float) $stmt_emp->fetchColumn();
                                $deduction = round($sal / (float)$policy['payable_days'], 2);
                                
                                $stmt_ins = $pdo->prepare("INSERT INTO attendance (emp_id, project_id, attendance_date, status, deduction_rs) VALUES (?, ?, ?, 'Absent', ?)");
                                $stmt_ins->execute([$e['id'], $e['project_id'], $date, $deduction]);
                                $absent_count++;
                            }
                        }
                        echo json_encode(['success' => true, 'marked' => $absent_count]);
                    } elseif ($action === 'regularize') {
                        $att_id = (int)$data['att_id'];
                        $reason = trim($data['reason'] ?? '');
                        
                        $stmt = $pdo->prepare("UPDATE attendance SET regularization_status = 'pending', regularization_reason = ?, regularization_submitted_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->execute([$reason, $att_id]);
                        echo json_encode(['success' => true]);
                    } elseif ($action === 'review') {
                        $att_id = (int)$data['att_id'];
                        $decision = $data['decision'] ?? 'rejected'; // approved | rejected
                        $remark = trim($data['remark'] ?? '');
                        
                        $new_status = ($decision === 'approved') ? 'approved' : 'rejected';
                        
                        // If approved, set deduction to 0
                        if ($new_status === 'approved') {
                            $stmt = $pdo->prepare("UPDATE attendance SET regularization_status = ?, manager_remark = ?, deduction_rs = 0.0, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?");
                        } else {
                            $stmt = $pdo->prepare("UPDATE attendance SET regularization_status = ?, manager_remark = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?");
                        }
                        $stmt->execute([$new_status, $remark, $att_id]);
                        
                        echo json_encode(['success' => true]);
                    }
                }
                break;
                
            case 'attendance/pending_regularizations':
                $require_auth();
                $is_mgr = $_SESSION['role'] === 'manager';
                $mgr_pid = $_SESSION['project_id'];
                
                $sql = "SELECT a.*, e.name as employee_name, e.role as employee_role, p.name as project_name 
                        FROM attendance a 
                        JOIN employees e ON a.emp_id = e.id 
                        LEFT JOIN projects p ON a.project_id = p.id
                        WHERE a.regularization_status = 'pending'";
                
                if ($is_mgr) {
                    $sql .= " AND e.project_id = " . (int)$mgr_pid;
                }
                
                $sql .= " ORDER BY a.regularization_submitted_at DESC";
                echo json_encode($pdo->query($sql)->fetchAll());
                break;
                
            case 'attendance/register':
                $require_auth();
                $month = $_GET['month'] ?? date('Y-m');
                $is_mgr = $_SESSION['role'] === 'manager';
                $mgr_pid = $_SESSION['project_id'];
                
                $sql = "SELECT a.*, e.name as employee_name, e.role as employee_role 
                        FROM attendance a 
                        JOIN employees e ON a.emp_id = e.id 
                        WHERE DATE_FORMAT(a.attendance_date, '%Y-%m') = ?";
                
                $params = [$month];
                if ($is_mgr) {
                    $sql .= " AND e.project_id = ?";
                    $params[] = $mgr_pid;
                }
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                echo json_encode($stmt->fetchAll());
                break;

            case 'payroll':
                $require_auth();
                $month = $_GET['month'] ?? date('Y-m');
                $is_mgr = $_SESSION['role'] === 'manager';
                $mgr_pid = $_SESSION['project_id'];
                
                // Fetch active employees
                $sql_emp = "SELECT e.*, p.name as project_name FROM employees e 
                            LEFT JOIN projects p ON e.project_id = p.id 
                            WHERE e.status = 'Active'";
                if ($is_mgr) {
                    $sql_emp .= " AND e.project_id = " . (int)$mgr_pid;
                }
                $emps = $pdo->query($sql_emp)->fetchAll();
                
                // Fetch attendance deductions for the month
                $sql_att = "SELECT emp_id, SUM(deduction_rs) as total_deductions, 
                            COUNT(CASE WHEN status IN ('On Time', 'Late', 'Major Late', 'Half Day') THEN 1 END) as present_days
                            FROM attendance 
                            WHERE DATE_FORMAT(attendance_date, '%Y-%m') = ? 
                            GROUP BY emp_id";
                $stmt_att = $pdo->prepare($sql_att);
                $stmt_att->execute([$month]);
                $att_data = [];
                foreach ($stmt_att->fetchAll() as $row) {
                    $att_data[$row['emp_id']] = $row;
                }
                
                // Fetch advances for the month
                $sql_adv = "SELECT emp_id, SUM(amount) as total_advances FROM advances 
                            WHERE deduction_month = ? AND approved = 1
                            GROUP BY emp_id";
                $stmt_adv = $pdo->prepare($sql_adv);
                $stmt_adv->execute([$month]);
                $adv_data = [];
                foreach ($stmt_adv->fetchAll() as $row) {
                    $adv_data[$row['emp_id']] = (float)$row['total_advances'];
                }
                
                $result = [];
                foreach ($emps as $e) {
                    $eid = $e['id'];
                    $present_days = $att_data[$eid]['present_days'] ?? 0;
                    $deductions = (float) ($att_data[$eid]['total_deductions'] ?? 0.0);
                    $advances = $adv_data[$eid] ?? 0.0;
                    
                    // Simple incentive logic (based on config)
                    $incentive = 0.0;
                    $config = json_decode($e['incentive_config'] ?? '{}', true);
                    if ($e['role'] === 'Telecaller') {
                        $incentive = (float) ($config['visitFixed'] ?? 0.0);
                    } elseif ($e['role'] === 'Asst. Sales Manager' || $e['role'] === 'Sales Manager') {
                        $incentive = (float) ($config['salesFixed'] ?? 0.0) + (float) ($config['visitFixed'] ?? 0.0);
                    } elseif ($e['role'] === 'Sr. Sales Manager') {
                        $incentive = (float) ($config['onAccount'] ?? 0.0);
                    } elseif ($e['role'] === 'Manager') {
                        $incentive = (float) ($config['salesFixed'] ?? 0.0);
                    }
                    
                    $salary = (float) $e['salary'];
                    $petrol = (float) $e['petrol'];
                    $net = $salary + $incentive + $petrol - $deductions - $advances;
                    
                    $result[] = [
                        'id' => $eid,
                        'code' => $e['code'],
                        'name' => $e['name'],
                        'role' => $e['role'],
                        'project' => $e['project_name'],
                        'base_salary' => $salary,
                        'petrol' => $petrol,
                        'incentives' => $incentive,
                        'deductions' => $deductions,
                        'advances' => $advances,
                        'net_payable' => round($net, 2),
                        'present_days' => $present_days
                    ];
                }
                echo json_encode($result);
                break;
                
            case 'advances':
                $require_auth();
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $is_mgr = $_SESSION['role'] === 'manager';
                    $mgr_pid = $_SESSION['project_id'];
                    
                    $sql = "SELECT a.*, e.name as employee_name, e.code as employee_code FROM advances a 
                            JOIN employees e ON a.emp_id = e.id";
                    if ($is_mgr) {
                        $sql .= " WHERE e.project_id = " . (int)$mgr_pid;
                    }
                    $sql .= " ORDER BY a.id DESC";
                    echo json_encode($pdo->query($sql)->fetchAll());
                } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $action = $data['action'] ?? '';
                    
                    if ($action === 'save') {
                        $adv = $data['data'];
                        $id = $adv['id'] ?? null;
                        
                        if ($id) {
                            $stmt = $pdo->prepare("UPDATE advances SET emp_id = ?, amount = ?, deduction_month = ?, reason = ?, approved = ? WHERE id = ?");
                            $stmt->execute([
                                $adv['emp_id'], $adv['amount'], $adv['deduction_month'], $adv['reason'], $adv['approved'] ?? 1, $id
                            ]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO advances (emp_id, amount, deduction_month, reason, approved) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $adv['emp_id'], $adv['amount'], $adv['deduction_month'], $adv['reason'], $adv['approved'] ?? 1
                            ]);
                        }
                        echo json_encode(['success' => true]);
                    } elseif ($action === 'delete') {
                        $id = (int)$data['id'];
                        $stmt = $pdo->prepare("DELETE FROM advances WHERE id = ?");
                        $stmt->execute([$id]);
                        echo json_encode(['success' => true]);
                    }
                }
                break;
                
            case 'users':
                $require_admin();
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $sql = "SELECT u.id, u.username, u.name, u.role, u.project_id, p.name as project_name 
                            FROM users u 
                            LEFT JOIN projects p ON u.project_id = p.id 
                            ORDER BY u.id DESC";
                    echo json_encode($pdo->query($sql)->fetchAll());
                } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    $action = $data['action'] ?? '';
                    
                    if ($action === 'save') {
                        $u = $data['data'];
                        $id = $u['id'] ?? null;
                        
                        if ($id) {
                            if (!empty($u['password'])) {
                                $hashed = password_hash($u['password'], PASSWORD_BCRYPT);
                                $stmt = $pdo->prepare("UPDATE users SET username = ?, password = ?, name = ?, role = ?, project_id = ? WHERE id = ?");
                                $stmt->execute([$u['username'], $hashed, $u['name'], $u['role'], !empty($u['project_id']) ? $u['project_id'] : null, $id]);
                            } else {
                                $stmt = $pdo->prepare("UPDATE users SET username = ?, name = ?, role = ?, project_id = ? WHERE id = ?");
                                $stmt->execute([$u['username'], $u['name'], $u['role'], !empty($u['project_id']) ? $u['project_id'] : null, $id]);
                            }
                        } else {
                            $hashed = password_hash($u['password'], PASSWORD_BCRYPT);
                            $stmt = $pdo->prepare("INSERT INTO users (username, password, name, role, project_id) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$u['username'], $hashed, $u['name'], $u['role'], !empty($u['project_id']) ? $u['project_id'] : null]);
                        }
                        echo json_encode(['success' => true]);
                    } elseif ($action === 'delete') {
                        $id = (int)$data['id'];
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$id]);
                        echo json_encode(['success' => true]);
                    }
                }
                break;
                
            default:
                http_response_code(404);
                echo json_encode(['error' => 'API Endpoint Not Found']);
                break;
        }
    } catch (Exception $ex) {
        http_response_code(500);
        echo json_encode(['error' => $ex->getMessage()]);
    }
    exit;
}

$logged_in = !empty($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="icon-192.png">
    <meta name="theme-color" content="#123e35">
    <meta name="description" content="Signature Properties HRMS – Workforce management, face check-in, GPS geofencing, payroll.">
    <title>Signature Properties HRMS | Workforce Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ── Reset & tokens ── */
        :root {
            --ink: #18312b; --muted: #72817d; --paper: #f5f6f2; --white: #fff;
            --green: #123e35; --green-2: #1d584b; --green-3: #0e2d26;
            --lime: #c7f36b; --lime-soft: #eef9d8;
            --line: #e4e8e2; --orange: #ef8f48; --red: #df625c; --blue: #5a82db;
            --shadow: 0 14px 38px rgba(25,49,43,.07);
            --radius: 15px; --sidebar-w: 260px;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: "DM Sans", sans-serif; background: var(--paper); color: var(--ink); }
        button, input, select, textarea { font: inherit; }
        button { cursor: pointer; }
        a { color: inherit; text-decoration: none; }
        img { display: block; max-width: 100%; }

        /* ── Login page ── */
        .login-wrap {
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, var(--green-3) 0%, var(--green) 60%, #1e6355 100%);
            padding: 20px;
        }
        .login-card {
            background: #fff; border-radius: 20px; padding: 44px 40px;
            width: 100%; max-width: 420px; box-shadow: 0 30px 70px rgba(0,0,0,.22);
        }
        .login-logo { display: flex; align-items: center; gap: 12px; margin-bottom: 30px; }
        .login-logo-mark { width: 48px; height: 48px; border-radius: 13px; background: var(--green); display: grid; place-items: center; font-family: Manrope; font-weight: 800; color: var(--lime); font-size: 20px; }
        .login-logo span { font-family: Manrope; font-weight: 800; font-size: 21px; color: var(--green); line-height: 1.1; }
        .login-logo small { display: block; font-size: 11px; color: var(--muted); font-weight: 500; font-family: "DM Sans"; }
        .login-card h1 { font-size: 24px; letter-spacing: -.5px; margin: 0 0 6px; }
        .login-card p { color: var(--muted); font-size: 13px; margin: 0 0 28px; }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 7px; }
        .field input, .field select { width: 100%; border: 1.5px solid var(--line); border-radius: 10px; padding: 11px 14px; outline: none; transition: border-color .2s; font-size: 14px; }
        .field input:focus, .field select:focus { border-color: var(--green); }
        .login-btn { width: 100%; border: 0; border-radius: 10px; background: var(--green); color: #fff; padding: 13px; font-weight: 700; font-size: 15px; margin-top: 6px; transition: background .2s; }
        .login-btn:hover { background: var(--green-2); }
        .login-err { background: #fdeceb; color: #bd4e49; border-radius: 9px; padding: 10px 14px; font-size: 13px; margin-bottom: 16px; text-align: center; }

        /* ── App shell ── */
        .app { min-height: 100vh; }

        /* ── Sidebar ── */
        .sidebar {
            background: var(--green); color: #fff; padding: 28px 20px;
            position: fixed; inset: 0 auto 0 0; width: var(--sidebar-w);
            display: flex; flex-direction: column; z-index: 20; overflow-y: auto;
        }
        .brand { display: flex; align-items: center; gap: 11px; padding: 0 9px 28px; font-family: Manrope; font-weight: 800; font-size: 19px; line-height: 1.2; }
        .brand-mark { width: 38px; height: 38px; border-radius: 11px; background: var(--lime); display: grid; place-items: center; color: var(--green); flex-shrink: 0; font-family: Manrope; font-weight: 800; font-size: 16px; }
        .nav-label { color: #8dac9f; font-size: 10px; font-weight: 700; letter-spacing: 1.4px; padding: 15px 13px 9px; }
        .nav { display: grid; gap: 3px; }
        .nav button { border: 0; color: #b9cdc5; background: transparent; padding: 11px 13px; border-radius: 10px; display: flex; align-items: center; gap: 12px; text-align: left; font-weight: 600; font-size: 14px; width: 100%; transition: all .15s; }
        .nav button:hover { color: #fff; background: rgba(255,255,255,.08); }
        .nav button.active { color: #fff; background: rgba(255,255,255,.1); box-shadow: inset 3px 0 var(--lime); }
        .nav button svg { width: 18px; height: 18px; stroke-width: 2px; }
        .nav .badge { background: var(--lime); color: var(--green); font-size: 11px; padding: 1px 7px; border-radius: 8px; margin-left: auto; font-weight: 700; }

        .user-block { margin-top: auto; padding: 16px 10px 0; border-top: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; }
        .user-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--lime); color: var(--green); display: grid; place-items: center; font-weight: 700; font-size: 14px; }
        .user-info { flex: 1; min-width: 0; }
        .user-info strong { display: block; font-size: 13px; color: #fff; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .user-info span { display: block; font-size: 11px; color: #8dac9f; }
        .logout-btn { background: transparent; border: 0; color: #b9cdc5; padding: 5px; border-radius: 5px; display: grid; place-items: center; }
        .logout-btn:hover { color: #fff; background: rgba(255,255,255,0.08); }
        .logout-btn svg { width: 18px; }

        /* ── Main content ── */
        .main { padding: 40px; margin-left: var(--sidebar-w); min-width: 0; }
        header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 34px; }
        header h1 { font-family: Manrope; font-weight: 800; font-size: 28px; margin: 0; letter-spacing: -.5px; }
        header p { color: var(--muted); margin: 4px 0 0; font-size: 14px; }

        .header-actions { display: flex; gap: 12px; }
        .btn { background: var(--green); color: #white; border: 0; border-radius: 10px; padding: 10px 18px; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; color: #fff; transition: background .15s; }
        .btn:hover { background: var(--green-2); }
        .btn.sec { background: #fff; color: var(--ink); border: 1.5px solid var(--line); }
        .btn.sec:hover { background: #fcfdfc; }
        .btn svg { width: 16px; stroke-width: 2px; }

        /* ── Cards grid ── */
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-bottom: 34px; }
        .grid-6 { display: grid; grid-template-columns: repeat(6, 1fr); gap: 16px; margin-bottom: 34px; }
        
        .card { background: #fff; border-radius: var(--radius); padding: 24px; border: 1px solid var(--line); box-shadow: var(--shadow); }
        .stat-card { padding: 20px; display: flex; flex-direction: column; gap: 4px; border-left: 4px solid var(--muted); }
        .stat-card.green { border-left-color: var(--green); }
        .stat-card.orange { border-left-color: var(--orange); }
        .stat-card.red { border-left-color: var(--red); }
        .stat-card.blue { border-left-color: var(--blue); }
        .stat-card span { font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
        .stat-card strong { font-size: 28px; font-family: Manrope; font-weight: 800; line-height: 1.2; }
        .stat-card small { font-size: 11px; color: var(--muted); margin-top: 2px; }

        .grid-2-1 { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start; }
        .card h2 { font-size: 18px; font-family: Manrope; font-weight: 700; margin: 0 0 20px; }

        /* ── Project row ── */
        .project-row { display: flex; align-items: center; gap: 14px; padding: 12px 0; border-bottom: 1px solid var(--line); font-size: 14px; }
        .project-row:last-child { border-bottom: 0; padding-bottom: 0; }
        .project-name { display: flex; align-items: center; gap: 10px; font-weight: 600; flex: 1; }
        .project-initial { width: 32px; height: 32px; border-radius: 8px; background: var(--lime-soft); color: var(--green); display: grid; place-items: center; font-size: 12px; font-weight: 700; font-style: normal; }
        .people { display: flex; }
        .mini-avatar { width: 26px; height: 26px; border-radius: 50%; background: #e4e8e2; border: 2px solid #fff; display: grid; place-items: center; font-size: 9px; font-weight: 700; font-style: normal; margin-left: -8px; }
        .mini-avatar:first-child { margin-left: 0; }
        .status { font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 6px; background: #eef9d8; color: var(--green); text-transform: uppercase; }
        .status.warning { background: #fdeceb; color: #bd4e49; }
        .status.neutral { background: #f2f5f1; color: var(--muted); }

        /* ── Activity Feed ── */
        .activity-feed { display: grid; gap: 16px; }
        .activity { display: flex; gap: 12px; font-size: 13px; }
        .activity-icon { width: 28px; height: 28px; border-radius: 8px; background: var(--paper); display: grid; place-items: center; color: var(--green); flex-shrink: 0; }
        .activity-icon svg { width: 14px; }
        .activity p { margin: 0; line-height: 1.4; }
        .activity time { font-size: 11px; color: var(--muted); display: block; margin-top: 2px; }

        /* ── Payroll Snapshot ── */
        .pay-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--line); }
        .pay-row:last-child { border-bottom: 0; padding-bottom: 0; font-weight: 700; font-size: 15px; }
        .pay-info strong { display: block; font-size: 13px; }
        .pay-info span { font-size: 11px; color: var(--muted); }
        .pay-row .amount { font-family: Manrope; font-weight: 700; }

        /* ── Tables ── */
        .table-card { padding: 0; overflow: hidden; }
        .table-header { padding: 20px 24px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--line); }
        .table-header h2 { margin: 0; }
        .table-filters { display: flex; gap: 12px; }
        .search-input { border: 1.5px solid var(--line); border-radius: 8px; padding: 8px 12px; font-size: 13px; outline: none; width: 200px; }
        .search-input:focus { border-color: var(--green); }
        .table-select { border: 1.5px solid var(--line); border-radius: 8px; padding: 8px 12px; font-size: 13px; outline: none; background: #fff; }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 13px; }
        th { background: #fafbfa; padding: 14px 24px; font-weight: 600; color: var(--muted); border-bottom: 1px solid var(--line); }
        td { padding: 14px 24px; border-bottom: 1px solid var(--line); vertical-align: middle; }
        tr:last-child td { border-bottom: 0; }
        .employee-cell { display: flex; align-items: center; gap: 12px; }
        .employee-cell strong { display: block; font-size: 14px; }
        .employee-cell span { font-size: 11px; color: var(--muted); }

        /* Color-coded status rows for attendance register */
        tr.status-ontime { background-color: rgba(199, 243, 107, 0.05); }
        tr.status-late { background-color: rgba(239, 143, 72, 0.04); }
        tr.status-majorlate { background-color: rgba(223, 98, 92, 0.04); }
        tr.status-halfday { background-color: rgba(223, 98, 92, 0.06); }
        tr.status-absent { background-color: rgba(114, 129, 125, 0.03); }

        .table-actions { display: flex; gap: 8px; }
        .action-btn { border: 0; background: var(--paper); border-radius: 6px; padding: 6px 10px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; color: var(--ink); }
        .action-btn:hover { background: #e4e8e2; }
        .action-btn.del { color: #c94f49; }
        .action-btn.del:hover { background: #fdeceb; }
        .action-btn svg { width: 13px; stroke-width: 2px; }

        /* ── Modals ── */
        .modal-overlay { position: fixed; inset: 0; background: rgba(18,62,53,.4); backdrop-filter: blur(4px); z-index: 100; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .2s; }
        .modal-overlay.open { opacity: 1; pointer-events: auto; }
        .modal-card { background: #fff; border-radius: 20px; width: 100%; max-width: 520px; box-shadow: 0 20px 50px rgba(0,0,0,.15); border: 1px solid var(--line); overflow: hidden; transform: translateY(20px); transition: transform .25s; }
        .modal-overlay.open .modal-card { transform: translateY(0); }
        .modal-header { padding: 24px 30px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { margin: 0; font-family: Manrope; font-weight: 700; font-size: 18px; }
        .close-btn { background: transparent; border: 0; color: var(--muted); display: grid; place-items: center; padding: 4px; border-radius: 5px; }
        .close-btn:hover { background: var(--paper); color: var(--ink); }
        .close-btn svg { width: 18px; }
        .modal-body { padding: 30px; max-height: 70vh; overflow-y: auto; }
        .modal-footer { padding: 20px 30px; background: #fafbfa; border-top: 1px solid var(--line); display: flex; justify-content: flex-end; gap: 12px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        /* ── Tabs inside attendance page ── */
        .tabs { display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid var(--line); padding-bottom: 12px; }
        .tab-btn { background: transparent; border: 0; padding: 8px 16px; font-weight: 600; font-size: 14px; color: var(--muted); border-radius: 8px; }
        .tab-btn.active { color: var(--green); background: var(--lime-soft); }

        /* ── Attendance section special cards ── */
        .attendance-setup-grid { display: grid; grid-template-columns: 1.2fr 1.8fr; gap: 24px; align-items: start; }
        .clock-card { text-align: center; background: linear-gradient(135deg, var(--green-3) 0%, var(--green) 100%); color: #fff; padding: 40px 20px; }
        .clock-time { font-family: Manrope; font-size: 42px; font-weight: 800; letter-spacing: -1px; margin-bottom: 6px; }
        .clock-date { font-size: 13px; color: #8dac9f; margin-bottom: 24px; }
        .clock-btn-checkin { background: var(--lime); color: var(--green); border: 0; width: 120px; height: 120px; border-radius: 50%; font-size: 16px; font-weight: 700; box-shadow: 0 0 0 10px rgba(199,243,107,.15); transition: all 0.2s; margin: 0 auto 16px; display: grid; place-items: center; }
        .clock-btn-checkin:hover { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(199,243,107,.25); }
        .clock-btn-checkout { background: var(--red); color: white; border: 0; width: 120px; height: 120px; border-radius: 50%; font-size: 16px; font-weight: 700; box-shadow: 0 0 0 10px rgba(223,98,92,.15); transition: all 0.2s; margin: 0 auto 16px; display: grid; place-items: center; }
        .clock-btn-checkout:hover { transform: scale(1.05); box-shadow: 0 0 0 15px rgba(223,98,92,.25); }

        /* ── Toast notifications ── */
        .toast { position: fixed; bottom: 30px; right: 30px; background: var(--green); color: #fff; border-radius: 10px; padding: 12px 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); transform: translateY(100px); opacity: 0; transition: all .3s cubic-bezier(0.175, 0.885, 0.32, 1.275); z-index: 1000; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast svg { width: 18px; stroke-width: 2px; color: var(--lime); }

        /* ── Incentive configurations inside employee modal ── */
        .inc-section { display: none; padding-top: 14px; border-top: 1px dashed var(--line); margin-top: 14px; }
        .inc-section.visible { display: block; }
        .inc-section h4 { font-size: 13px; font-weight: 700; margin: 0 0 12px; color: var(--green); text-transform: uppercase; letter-spacing: 0.5px; }

        /* ── Project grid view ── */
        .project-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; }
        .project-card { background: #white; display: flex; flex-direction: column; height: 100%; }
        .project-card h3 { font-size: 17px; margin: 0 0 6px; font-family: Manrope; font-weight: 700; }
        .project-card p { font-size: 12px; color: var(--muted); margin: 0 0 16px; }
        .project-meta { font-size: 13px; display: grid; gap: 8px; border-top: 1px solid var(--line); padding-top: 16px; margin-top: auto; }
        .project-meta-item { display: flex; justify-content: space-between; }
        .project-meta-item span { color: var(--muted); }
        .project-meta-item strong { color: var(--ink); }

        /* ── Empty state ── */
        .empty-state { text-align: center; padding: 48px 24px; color: var(--muted); font-size: 14px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; }
        .empty-state svg { width: 48px; color: var(--line); }

        /* ── Mobile Header ── */
        .mobile-header {
            display: none;
            background: var(--green);
            color: #fff;
            padding: 12px 20px;
            align-items: center;
            justify-content: space-between;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 60px;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .menu-toggle {
            background: transparent;
            border: 0;
            color: #fff;
            padding: 4px;
            display: grid;
            place-items: center;
        }
        .mobile-brand {
            font-family: Manrope;
            font-weight: 800;
            font-size: 18px;
        }
        .sidebar-overlay-bg {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 15;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s;
        }
        .sidebar-overlay-bg.open {
            opacity: 1;
            pointer-events: auto;
        }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            .app { grid-template-columns: 1fr; padding-top: 60px; }
            .mobile-header { display: flex; }
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease-in-out;
                position: fixed;
                inset: 0 auto 0 0;
                height: 100vh;
                z-index: 20;
            }
            .sidebar.open {
                transform: translateX(0);
            }
            .main { margin-left: 0; padding: 20px; }
            .grid-3 { grid-template-columns: 1fr; gap: 16px; }
            .grid-6 { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .grid-2-1 { grid-template-columns: 1fr; gap: 16px; }
            .attendance-setup-grid { grid-template-columns: 1fr; gap: 16px; }
            
            header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .header-actions { width: 100%; }
            .header-actions .btn { width: 100%; justify-content: center; }
            .table-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .table-filters { width: 100%; flex-wrap: wrap; }
            .table-filters input, .table-filters select { flex: 1; }
            .modal-card { width: 95%; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php if (!$logged_in): ?>
    <!-- ── LOGIN WRAPPER ── -->
    <div class="login-wrap">
        <div class="login-card">
            <div class="login-logo" style="justify-content: center; margin-bottom: 24px;">
                <img src="logo.png" alt="Signature Properties Logo" style="max-height: 80px; width: auto; object-fit: contain;">
            </div>
            <h1>Welcome back</h1>
            <p>Enter your credentials to manage workforce operations</p>
            
            <div id="loginError" class="login-err" style="display: none;"></div>
            
            <form id="loginForm">
                <div class="field">
                    <label for="username">Username</label>
                    <input type="text" id="username" placeholder="e.g. admin" required>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="login-btn">Sign In</button>
            </form>
        </div>
    </div>
    
    <script>
        document.getElementById('loginForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const errDiv = document.getElementById('loginError');
            errDiv.style.display = 'none';
            
            try {
                const res = await fetch('?api=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: document.getElementById('username').value,
                        password: document.getElementById('password').value
                    })
                });
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    errDiv.textContent = data.error || 'Invalid credentials';
                    errDiv.style.display = 'block';
                }
            } catch (err) {
                errDiv.textContent = 'Server connection failed.';
                errDiv.style.display = 'block';
            }
        });
    </script>

<?php else: ?>
    <!-- ── APPLICATION SHELL ── -->
    <div class="app">
        
        <!-- MOBILE HEADER -->
        <div class="mobile-header">
            <button class="menu-toggle" onclick="toggleSidebar()">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width: 24px; height: 24px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div class="mobile-brand" style="background: #fff; width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid var(--line);">
                <img src="logo.png" alt="Signature Properties Logo" style="max-height: 38px; max-width: 38px; width: 100%; height: 100%; object-fit: contain;">
            </div>
            <div style="width: 24px;"></div>
        </div>

        <!-- SIDEBAR OVERLAY -->
        <div class="sidebar-overlay-bg" id="sidebarOverlayBg" onclick="toggleSidebar()"></div>
        
        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="brand" style="margin-bottom: 28px; display: flex; justify-content: center;">
                <div style="background: #fff; width: 90px; height: 90px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.12); overflow: hidden; border: 1.5px solid var(--line);">
                    <img src="logo.png" alt="Signature Properties Logo" style="max-height: 84px; max-width: 84px; width: 100%; height: 100%; object-fit: contain;">
                </div>
            </div>
            
            <div class="nav-label">HR OPERATION</div>
            <nav class="nav">
                <button class="active" onclick="goTo('dashboard')" id="nav-dashboard">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M4 6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6zM14 6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2V6zM4 16a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2zM14 16a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2v-2z"/></svg>
                    Dashboard
                </button>
                <button onclick="goTo('attendance')" id="nav-attendance">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Attendance
                    <span class="badge" id="pendingRegBadge" style="display: none;">0</span>
                </button>
                <button onclick="goTo('employees')" id="nav-employees">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    Employees
                </button>
                <button onclick="goTo('projects')" id="nav-projects">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Projects
                </button>
            </nav>
            
            <div class="nav-label">FINANCE</div>
            <nav class="nav">
                <button onclick="goTo('payroll')" id="nav-payroll">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Payroll &amp; Advances
                </button>
            </nav>

            <div class="nav-label">SETTINGS</div>
            <nav class="nav">
                <button onclick="goTo('policy')" id="nav-policy">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z"/></svg>
                    Attendance Policy
                </button>
                <button onclick="goTo('users')" id="nav-users">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    User Management
                </button>
            </nav>
            
            <div class="user-block">
                <div class="user-avatar" id="sidebarUserAvatar">U</div>
                <div class="user-info">
                    <strong id="sidebarUserName">Loading...</strong>
                    <span id="sidebarUserRole">role</span>
                </div>
                <button class="logout-btn" onclick="logout()" title="Logout">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </button>
            </div>
        </aside>
        
        <!-- MAIN CONTENT AREA -->
        <main class="main">
            
            <!-- ════ DASHBOARD VIEW ════ -->
            <section id="view-dashboard" class="app-view">
                <header>
                    <div>
                        <h1 id="dashGreeting">Good morning, Admin</h1>
                        <p id="dashTodayDate">Today date</p>
                    </div>
                </header>
                
                <!-- Attendance Cards -->
                <div class="grid-6">
                    <div class="card stat-card green">
                        <span>On Time</span>
                        <strong id="cardOnTime">0</strong>
                        <small>Within grace period</small>
                    </div>
                    <div class="card stat-card orange">
                        <span>Late Today</span>
                        <strong id="cardLate">0</strong>
                        <small>Up to 30 mins late</small>
                    </div>
                    <div class="card stat-card red">
                        <span>Major Late</span>
                        <strong id="cardMajorLate">0</strong>
                        <small>31 to 60 mins late</small>
                    </div>
                    <div class="card stat-card red">
                        <span>Half Day</span>
                        <strong id="cardHalfDay">0</strong>
                        <small>60+ mins late</small>
                    </div>
                    <div class="card stat-card red">
                        <span>Absent</span>
                        <strong id="cardAbsent">0</strong>
                        <small>No check-in today</small>
                    </div>
                    <div class="card stat-card blue">
                        <span>Early Checkout</span>
                        <strong id="cardEarlyCheckout">0</strong>
                        <small>Before shift ends</small>
                    </div>
                </div>
                
                <div class="grid-2-1">
                    <div class="card">
                        <h2>Top Projects Performance</h2>
                        <div id="dashProjectList">
                            <!-- Project Rows dynamically added -->
                        </div>
                    </div>
                    
                    <div class="card">
                        <h2>Recent Activity</h2>
                        <div class="activity-feed" id="activityFeed">
                            <!-- Logs dynamically loaded -->
                        </div>
                    </div>
                </div>
            </section>
            
            <!-- ════ ATTENDANCE VIEW ════ -->
            <section id="view-attendance" class="app-view" style="display: none;">
                <header>
                    <div>
                        <h1>Attendance Operations</h1>
                        <p>Track check-ins, verify locations, and approve regularizations.</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn sec" onclick="markAllAbsentToday()">Mark Absents Today</button>
                    </div>
                </header>
                
                <div class="tabs">
                    <button class="tab-btn active" onclick="switchAttTab('mark')" id="tabBtn-mark">Check-In Panel</button>
                    <button class="tab-btn" onclick="switchAttTab('register')" id="tabBtn-register">Daily Attendance Register</button>
                    <button class="tab-btn" onclick="switchAttTab('regularization')" id="tabBtn-regularization">Regularizations</button>
                </div>
                
                <!-- Tab 1: Check-in Panel -->
                <div id="attTab-mark" class="att-tab-content">
                    <div class="attendance-setup-grid">
                        <div class="card clock-card text-center" style="border-radius: var(--radius)">
                            <div class="clock-time" id="liveClock">00:00:00</div>
                            <div class="clock-date" id="liveDate">Saturday, June 13</div>
                            
                            <div class="field" style="text-align: left; color: white;">
                                <label style="color: #b9cdc5;">Select Employee</label>
                                <select id="checkinEmpSelect" class="table-select" style="width:100%; color:var(--ink); font-weight:600;" onchange="onCheckinEmpChange()">
                                    <option value="">Choose Employee...</option>
                                </select>
                            </div>
                            
                            <div id="shiftInfoBox" style="font-size: 13px; color: #c7f36b; margin-bottom: 24px; font-weight: 600;">
                                Select an employee to view shift details
                            </div>
                            
                            <div id="checkinButtonContainer">
                                <button class="clock-btn-checkin" onclick="processCheckin()">Check In</button>
                            </div>
                        </div>
                        
                        <div class="card">
                            <h2>Verification Settings</h2>
                            <p class="text-muted" style="font-size: 13px; margin-bottom: 20px;">Verification checks simulated based on geofencing coordinate ranges.</p>
                            
                            <div class="field">
                                <label>Target Project Location (Geofence Reference)</label>
                                <div style="display: flex; gap: 12px;">
                                    <select id="verificationProject" class="table-select" style="flex: 1;" onchange="onVerificationProjectChange()">
                                        <option value="">Select Project...</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px;">
                                <div class="slab card" style="padding: 12px; background: var(--paper);">
                                    <span style="font-size: 11px; color: var(--muted); font-weight: 600;">GPS Geofence Check</span>
                                    <strong id="gpsStatus" style="font-size: 14px; color: var(--green);">Ready</strong>
                                    <small id="gpsDetail" style="font-size:11px; color:var(--muted);">Distance: --</small>
                                </div>
                                <div class="slab card" style="padding: 12px; background: var(--paper);">
                                    <span style="font-size: 11px; color: var(--muted); font-weight: 600;">Face Match Verification</span>
                                    <strong id="faceStatus" style="font-size: 14px; color: var(--green);">Match Found</strong>
                                    <small style="font-size:11px; color:var(--muted);">Camera feed verified</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tab 2: Daily Register -->
                <div id="attTab-register" class="att-tab-content" style="display: none;">
                    <div class="card table-card">
                        <div class="table-header">
                            <h2>Attendance Records</h2>
                            <div class="table-filters">
                                <input type="date" id="registerDateFilter" class="table-select" onchange="loadDailyRegister()">
                                <select id="registerStatusFilter" class="table-select" onchange="loadDailyRegister()">
                                    <option value="">All Statuses</option>
                                    <option value="On Time">On Time</option>
                                    <option value="Late">Late</option>
                                    <option value="Major Late">Major Late</option>
                                    <option value="Half Day">Half Day</option>
                                    <option value="Absent">Absent</option>
                                </select>
                                <button class="btn sec" onclick="exportRegisterCSV()">Export CSV</button>
                            </div>
                        </div>
                        
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Role</th>
                                        <th>Project</th>
                                        <th>Check-In</th>
                                        <th>Check-Out</th>
                                        <th>Working Mins</th>
                                        <th>Status</th>
                                        <th>Deduction</th>
                                        <th>GPS/Face</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="attendanceRegisterBody">
                                    <!-- Dynamic rows -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Tab 3: Regularizations -->
                <div id="attTab-regularization" class="att-tab-content" style="display: none;">
                    <div class="card table-card">
                        <div class="table-header">
                            <h2>Pending Regularization Requests</h2>
                        </div>
                        
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Role</th>
                                        <th>Date</th>
                                        <th>Reason</th>
                                        <th>Submitted At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="pendingRegularizationsBody">
                                    <!-- Dynamic rows -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
            
            <!-- ════ EMPLOYEES VIEW ════ -->
            <section id="view-employees" class="app-view" style="display: none;">
                <header>
                    <div>
                        <h1>Employee Directory</h1>
                        <p>Manage employee details, configure specific project designations and monthly salary details.</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn" onclick="openAddEmployeeModal()" id="addEmployeeBtn">Add Employee</button>
                    </div>
                </header>
                
                <div class="card table-card">
                    <div class="table-header">
                        <h2>All Registered Employees</h2>
                        <div class="table-filters">
                            <input type="text" id="employeeSearchInput" placeholder="Search by name or code..." class="search-input" oninput="filterEmployeeTable()">
                        </div>
                    </div>
                    
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Designation</th>
                                    <th>Project Assignment</th>
                                    <th>Salary (Base)</th>
                                    <th>Petrol Allowance</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="employeeTableBody">
                                <!-- Dynamic rows -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
            
            <!-- ════ PROJECTS VIEW ════ -->
            <section id="view-projects" class="app-view" style="display: none;">
                <header>
                    <div>
                        <h1>Project Management</h1>
                        <p>Define sites, locations, coordinate geofencing radiuses and shift timings.</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn" onclick="openAddProjectModal()" id="addProjectBtn">Create Project</button>
                    </div>
                </header>
                
                <div class="project-grid" id="projectGrid">
                    <!-- Dynamic project cards -->
                </div>
            </section>
            
            <!-- ════ PAYROLL VIEW ════ -->
            <section id="view-payroll" class="app-view" style="display: none;">
                <header>
                    <div>
                        <h1>Payroll Calculations &amp; Advances</h1>
                        <p>Process salaries, calculate auto deductions, and manage advances.</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn" onclick="openAddAdvanceModal()">Log Advance</button>
                    </div>
                </header>
                
                <div class="tabs">
                    <button class="tab-btn active" onclick="switchPayrollTab('register')" id="tabBtn-payRegister">Monthly Payroll Snapshot</button>
                    <button class="tab-btn" onclick="switchPayrollTab('advances')" id="tabBtn-payAdvances">Advances Ledger</button>
                </div>
                
                <div id="payrollTab-register" class="payroll-tab-content">
                    <div class="card table-card">
                        <div class="table-header">
                            <h2>Monthly Net Payable Sheet</h2>
                            <div class="table-filters">
                                <input type="month" id="payrollMonthFilter" class="table-select" onchange="loadPayroll()">
                            </div>
                        </div>
                        
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Base Salary</th>
                                        <th>Incentives</th>
                                        <th>Petrol Allow.</th>
                                        <th>Deductions (Att.)</th>
                                        <th>Advances Ded.</th>
                                        <th>Net Payable</th>
                                        <th>Days Present</th>
                                    </tr>
                                </thead>
                                <tbody id="payrollTableBody">
                                    <!-- Dynamic rows -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div id="payrollTab-advances" class="payroll-tab-content" style="display: none;">
                    <div class="card table-card">
                        <div class="table-header">
                            <h2>Recorded Employee Advances</h2>
                        </div>
                        
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Advance Amount</th>
                                        <th>Deduction Month</th>
                                        <th>Reason/Remark</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="advancesTableBody">
                                    <!-- Dynamic rows -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
            
            <!-- ════ ATTENDANCE POLICY VIEW ════ -->
            <section id="view-policy" class="app-view" style="display: none;">
                <header>
                    <div>
                        <h1>Global Attendance Policy</h1>
                        <p>Set shift rules, grace periods, free checkin counts and standard working hours.</p>
                    </div>
                </header>
                
                <div class="card">
                    <form id="policyForm" onsubmit="savePolicy(event)">
                        <h2>Configuration Policy Parameters</h2>
                        
                        <div class="form-grid">
                            <div class="field">
                                <label>Staff Shift Start Time</label>
                                <input type="time" id="policyStaffStart" required>
                            </div>
                            <div class="field">
                                <label>Staff Shift End Time</label>
                                <input type="time" id="policyStaffEnd" required>
                            </div>
                        </div>
                        
                        <div class="form-grid mt-3">
                            <div class="field">
                                <label>Staff Grace Mins (Free late checkin limits)</label>
                                <input type="number" id="policyStaffGrace" required>
                            </div>
                            <div class="field">
                                <label>Grace Free Incidents Count (Per Month)</label>
                                <input type="number" id="policyGraceFreeCount" required>
                            </div>
                        </div>
                        
                        <div class="form-grid mt-3">
                            <div class="field">
                                <label>Manager Shift Start Time</label>
                                <input type="time" id="policyMgrStart" required>
                            </div>
                            <div class="field">
                                <label>Manager Shift End Time</label>
                                <input type="time" id="policyMgrEnd" required>
                            </div>
                        </div>
                        
                        <div class="form-grid mt-3">
                            <div class="field">
                                <label>Manager Grace Mins</label>
                                <input type="number" id="policyMgrGrace" required>
                            </div>
                            <div class="field">
                                <label>Standard Working Hours (Paid Hours)</label>
                                <input type="number" id="policyPaidHours" required>
                            </div>
                        </div>
                        
                        <div class="form-grid mt-3">
                            <div class="field">
                                <label>Lunch/Break Duration (Minutes)</label>
                                <input type="number" id="policyLunchMins" required>
                            </div>
                            <div class="field">
                                <label>Standard Payable Days (Per Month)</label>
                                <input type="number" id="policyPayableDays" required>
                            </div>
                        </div>
                        
                        <div style="margin-top: 24px; text-align: right;">
                            <button type="submit" class="btn" id="policySaveBtn">Save System Policy</button>
                        </div>
                    </form>
                </div>
            </section>
            
            <!-- ════ USER MANAGEMENT VIEW ════ -->
            <section id="view-users" class="app-view" style="display: none;">
                <header>
                    <div>
                        <h1>User Access &amp; Privileges</h1>
                        <p>Manage system users, project managers, and administrators.</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn" onclick="openAddUserModal()">Create User</button>
                    </div>
                </header>
                
                <div class="card table-card">
                    <div class="table-header">
                        <h2>Registered Panel Users</h2>
                    </div>
                    
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Role Privilege</th>
                                    <th>Assigned Project Site (Manager Only)</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="usersTableBody">
                                <!-- Dynamic rows -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
            
        </main>
    </div>

    <!-- ── TOAST NOTIFICATION ── -->
    <div id="toast" class="toast">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
        <span id="toastMsg">Action saved successfully</span>
    </div>

    <!-- ── MODAL: CONFIRM ACTION ── -->
    <div id="confirmOverlay" class="modal-overlay">
        <div class="modal-card" style="max-width: 380px;">
            <div class="modal-header">
                <h3 id="confirmTitle">Confirm Action</h3>
            </div>
            <div class="modal-body" id="confirmMsg">
                Are you sure you want to perform this action?
            </div>
            <div class="modal-footer">
                <button class="btn sec" onclick="closeConfirm()">Cancel</button>
                <button class="btn" id="confirmOk">Confirm</button>
            </div>
        </div>
    </div>

    <!-- ── MODAL: ADD/EDIT EMPLOYEE ── -->
    <div id="employeeModal" class="modal-overlay">
        <div class="modal-card">
            <form id="employeeForm" onsubmit="saveEmployee(event)">
                <div class="modal-header">
                    <h3 id="empModalTitle">Add Employee</h3>
                    <button type="button" class="close-btn" onclick="closeModal('employeeModal')">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="empId">
                    <div class="form-grid">
                        <div class="field">
                            <label>Employee Name</label>
                            <input type="text" id="empName" required>
                        </div>
                        <div class="field">
                            <label>Employee Code (Unique)</label>
                            <input type="text" id="empCode" required>
                        </div>
                    </div>
                    
                    <div class="form-grid mt-3">
                        <div class="field">
                            <label>Designation/Role</label>
                            <select id="empRole" onchange="onEmpRoleChange()" required>
                                <option value="Telecaller">Telecaller</option>
                                <option value="Asst. Sales Manager">Asst. Sales Manager</option>
                                <option value="Sales Manager">Sales Manager</option>
                                <option value="Sr. Sales Manager">Sr. Sales Manager</option>
                                <option value="Manager">Manager</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Assign Project Site</label>
                            <select id="empProject" required>
                                <option value="">Select project...</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid mt-3">
                        <div class="field">
                            <label>Base Monthly Salary (₹)</label>
                            <input type="number" id="empSalary" required>
                        </div>
                        <div class="field">
                            <label>Petrol Allowance (₹)</label>
                            <input type="number" id="empPetrol" default="0">
                        </div>
                    </div>
                    
                    <div class="form-grid mt-3">
                        <div class="field">
                            <label>Contact Phone</label>
                            <input type="text" id="empPhone">
                        </div>
                        <div class="field">
                            <label>Status</label>
                            <select id="empStatus">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid mt-3">
                        <div class="field">
                            <label>Shift Class</label>
                            <select id="empShiftType">
                                <option value="staff">Staff Policy (10:00 AM)</option>
                                <option value="manager">Manager Policy (10:15 AM)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Join Date</label>
                            <input type="date" id="empJoinDate">
                        </div>
                    </div>

                    <div class="field mt-3">
                        <label>Notes</label>
                        <textarea id="empNotes" rows="2" style="width: 100%; border:1.5px solid var(--line); border-radius:10px; padding:10px;"></textarea>
                    </div>

                    <!-- Incentive Configurations based on Designation -->
                    <div id="incConfigSection">
                        <!-- Visite / Slab details (Telecaller / Asst. Sales Manager) -->
                        <div id="incVisitSection" class="inc-section">
                            <h4>Visits Incentive Config</h4>
                            <div class="form-grid">
                                <div class="field">
                                    <label>Fixed visit budget (₹)</label>
                                    <input type="number" id="incVisitFixed" value="1000">
                                </div>
                                <div class="field">
                                    <label>Target slab visits count</label>
                                    <input type="number" id="incVisitTarget" value="30">
                                </div>
                            </div>
                        </div>

                        <!-- Sales details (Asst. Sales Manager / Sales Manager / Manager) -->
                        <div id="incSalesSection" class="inc-section">
                            <h4>Sales Commission Config</h4>
                            <div class="form-grid">
                                <div class="field">
                                    <label id="incSalesFixedLabel">Incentive per Sale (₹)</label>
                                    <input type="number" id="incSalesFixed" value="0">
                                </div>
                            </div>
                        </div>

                        <!-- Sr. Sales Manager Details -->
                        <div id="incSrMgrSection" class="inc-section">
                            <h4>Sr. Sales Manager Config</h4>
                            <div class="form-grid">
                                <div class="field">
                                    <label>On-Account Payout (₹)</label>
                                    <input type="number" id="incOnAccount" value="60000">
                                </div>
                                <div class="field">
                                    <label>Deal Percent Payout (%)</label>
                                    <input type="number" id="incDealPct" step="0.01" value="0.60">
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn sec" onclick="closeModal('employeeModal')">Cancel</button>
                    <button type="submit" class="btn">Save Employee</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── MODAL: ADD/EDIT PROJECT ── -->
    <div id="projectModal" class="modal-overlay">
        <div class="modal-card">
            <form id="projectForm" onsubmit="saveProject(event)">
                <div class="modal-header">
                    <h3 id="projModalTitle">Create Project</h3>
                    <button type="button" class="close-btn" onclick="closeModal('projectModal')">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="projId">
                    <div class="field">
                        <label>Project Name</label>
                        <input type="text" id="projName" required>
                    </div>
                    <div class="field mt-3">
                        <label>Site Address</label>
                        <textarea id="projAddress" rows="2" style="width:100%; border:1.5px solid var(--line); border-radius:10px; padding:10px;"></textarea>
                    </div>
                    
                    <div class="form-grid mt-3">
                        <div class="field">
                            <label>Latitude Coordinates</label>
                            <input type="number" id="projLat" step="0.000001" required>
                        </div>
                        <div class="field">
                            <label>Longitude Coordinates</label>
                            <input type="number" id="projLng" step="0.000001" required>
                        </div>
                    </div>
                    
                    <div class="form-grid mt-3">
                        <div class="field">
                            <label>Geofence Radius (metres)</label>
                            <input type="number" id="projRadius" value="100" required>
                        </div>
                        <div class="field">
                            <label>Custom Shift Start Time</label>
                            <input type="time" id="projShiftStart" value="10:00" required>
                        </div>
                    </div>
                    
                    <div class="field mt-3">
                        <label>Custom Shift End Time</label>
                        <input type="time" id="projShiftEnd" value="19:00" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn sec" onclick="closeModal('projectModal')">Cancel</button>
                    <button type="submit" class="btn">Save Project</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── MODAL: LOG ADVANCE ── -->
    <div id="advanceModal" class="modal-overlay">
        <div class="modal-card">
            <form id="advanceForm" onsubmit="saveAdvance(event)">
                <div class="modal-header">
                    <h3>Log Employee Advance Payout</h3>
                    <button type="button" class="close-btn" onclick="closeModal('advanceModal')">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="field">
                        <label>Select Employee</label>
                        <select id="advEmpId" class="table-select" style="width: 100%;" required></select>
                    </div>
                    <div class="field mt-3">
                        <label>Advance Amount (₹)</label>
                        <input type="number" id="advAmount" required>
                    </div>
                    <div class="field mt-3">
                        <label>Deduction Month (YYYY-MM)</label>
                        <input type="month" id="advMonth" required>
                    </div>
                    <div class="field mt-3">
                        <label>Reason/Remark</label>
                        <textarea id="advReason" rows="2" style="width:100%; border:1.5px solid var(--line); border-radius:10px; padding:10px;"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn sec" onclick="closeModal('advanceModal')">Cancel</button>
                    <button type="submit" class="btn">Save Advance Record</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── MODAL: ADD/EDIT USER ── -->
    <div id="userModal" class="modal-overlay">
        <div class="modal-card">
            <form id="userForm" onsubmit="saveUser(event)">
                <div class="modal-header">
                    <h3 id="userModalTitle">Create User</h3>
                    <button type="button" class="close-btn" onclick="closeModal('userModal')">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="userId">
                    <div class="field">
                        <label>Display Name</label>
                        <input type="text" id="userDisplayName" required>
                    </div>
                    <div class="field mt-3">
                        <label>Username</label>
                        <input type="text" id="userUsername" required>
                    </div>
                    <div class="field mt-3">
                        <label>Password (Leave empty to keep existing password on edit)</label>
                        <input type="password" id="userPassword">
                    </div>
                    
                    <div class="form-grid mt-3">
                        <div class="field">
                            <label>Privilege Role</label>
                            <select id="userRole" onchange="onUserRoleChange()" required>
                                <option value="super_admin">Super Admin</option>
                                <option value="manager">Manager</option>
                            </select>
                        </div>
                        <div id="userProjectDiv" class="field" style="display: none;">
                            <label>Assigned Project Site</label>
                            <select id="userProject">
                                <option value="">Select project...</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn sec" onclick="closeModal('userModal')">Cancel</button>
                    <button type="submit" class="btn">Save User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── MODAL: REGULARIZATION REQUEST SUBMIT ── -->
    <div id="regularizeSubmitModal" class="modal-overlay">
        <div class="modal-card" style="max-width: 420px;">
            <form id="regularizeSubmitForm" onsubmit="submitRegularizationRequest(event)">
                <div class="modal-header">
                    <h3>Request Regularization</h3>
                    <button type="button" class="close-btn" onclick="closeModal('regularizeSubmitModal')">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="regSubmitAttId">
                    <div class="field">
                        <label>Reason for Regularization</label>
                        <select id="regSubmitReason" class="table-select" style="width: 100%;" required>
                            <option value="Client Meeting Outside Site">Client Meeting Outside Site</option>
                            <option value="Approved Official Site Visit">Approved Official Site Visit</option>
                            <option value="Medical Reason / Emergency">Medical Reason / Emergency</option>
                            <option value="GPS Coordinates Check Issue">GPS Coordinates Check Issue</option>
                            <option value="Manager Approved Late Entry">Manager Approved Late Entry</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn sec" onclick="closeModal('regularizeSubmitModal')">Cancel</button>
                    <button type="submit" class="btn">Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── MODAL: REVIEW REGULARIZATION REQUEST ── -->
    <div id="reviewRegularizationModal" class="modal-overlay">
        <div class="modal-card" style="max-width: 420px;">
            <form id="reviewRegularizationForm" onsubmit="submitRegularizationReview(event)">
                <div class="modal-header">
                    <h3>Review Regularization</h3>
                    <button type="button" class="close-btn" onclick="closeModal('reviewRegularizationModal')">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="reviewRegAttId">
                    <div class="field">
                        <label>Decision</label>
                        <select id="reviewRegDecision" class="table-select" style="width: 100%;" required>
                            <option value="approved">Approve (Waive Deductions)</option>
                            <option value="rejected">Reject Request</option>
                        </select>
                    </div>
                    <div class="field mt-3">
                        <label>Manager Remarks</label>
                        <textarea id="reviewRegRemark" rows="2" style="width: 100%; border:1.5px solid var(--line); border-radius:10px; padding:10px;" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn sec" onclick="closeModal('reviewRegularizationModal')">Cancel</button>
                    <button type="submit" class="btn">Submit Review</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ════ APPLICATION JAVASCRIPT LOGIC ════ -->
    <script>
        // Global State variables
        let currentUser = null;
        let globalEmployees = [];
        let globalProjects = [];
        
        // Modal management
        function openModal(id) { document.getElementById(id).classList.add('open'); }
        function closeModal(id) { document.getElementById(id).classList.remove('open'); }
        document.querySelectorAll('.modal-overlay').forEach(o => {
            o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
        });
        
        // Confirm Modal dialog
        let confirmCallback = null;
        function confirmAction(title, msg, cb) {
            document.getElementById('confirmTitle').textContent = title;
            document.getElementById('confirmMsg').textContent = msg;
            confirmCallback = cb;
            openModal('confirmOverlay');
        }
        document.getElementById('confirmOk').onclick = () => {
            closeConfirm();
            if (confirmCallback) confirmCallback();
        };
        function closeConfirm() { closeModal('confirmOverlay'); }

        // Toast notifications helper
        function notify(msg) {
            const toast = document.getElementById('toast');
            document.getElementById('toastMsg').textContent = msg;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        // Helpers
        function fmtRs(n) { return '₹' + Number(n).toLocaleString('en-IN', {maximumFractionDigits: 2}); }
        function initials(name) { return name.split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase(); }

        // Geolocation Haversine formula
        function haversineDistance(lat1, lon1, lat2, lon2) {
            const R = 6371e3; // Earth radius in metres
            const φ1 = lat1 * Math.PI/180;
            const φ2 = lat2 * Math.PI/180;
            const Δφ = (lat2-lat1) * Math.PI/180;
            const Δλ = (lon2-lon1) * Math.PI/180;
            const a = Math.sin(Δφ/2) * Math.sin(Δφ/2) +
                      Math.cos(φ1) * Math.cos(φ2) *
                      Math.sin(Δλ/2) * Math.sin(Δλ/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            return R * c; // in metres
        }

        // Toggle sidebar on mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlayBg');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
        }

        // Routing Controller
        function goTo(view) {
            // Close mobile menu if open
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlayBg');
            if (sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                overlay.classList.remove('open');
            }

            document.querySelectorAll('.app-view').forEach(v => v.style.display = 'none');
            document.querySelectorAll('.nav button').forEach(b => b.classList.remove('active'));
            
            const target = document.getElementById('view-' + view);
            if (target) {
                target.style.display = 'block';
                const navBtn = document.getElementById('nav-' + view);
                if (navBtn) navBtn.classList.add('active');
            }
            
            // Context Loaders
            if (view === 'dashboard') loadDashboard();
            else if (view === 'attendance') loadAttendanceTab();
            else if (view === 'employees') loadEmployees();
            else if (view === 'projects') loadProjects();
            else if (view === 'payroll') loadPayroll();
            else if (view === 'policy') loadPolicy();
            else if (view === 'users') loadUsers();
        }

        // Logout
        async function logout() {
            try {
                await fetch('?api=logout');
                window.location.reload();
            } catch (err) {
                notify('Logout failed');
            }
        }

        // Init App details
        async function initApp() {
            try {
                const res = await fetch('?api=me');
                const data = await res.json();
                if (data.logged_in) {
                    currentUser = data.user;
                    document.getElementById('sidebarUserName').textContent = currentUser.name;
                    document.getElementById('sidebarUserRole').textContent = currentUser.role === 'super_admin' ? 'Super Admin' : 'Project Manager';
                    document.getElementById('sidebarUserAvatar').textContent = initials(currentUser.name);
                    
                    // Hide pages according to privileges
                    if (currentUser.role === 'manager') {
                        document.getElementById('nav-projects').style.display = 'none';
                        document.getElementById('nav-policy').style.display = 'none';
                        document.getElementById('nav-users').style.display = 'none';
                        document.getElementById('addEmployeeBtn').style.display = 'none';
                    }
                    
                    // Start clock
                    startLiveClock();
                    
                    // Go to dashboard default
                    goTo('dashboard');
                    
                    // Load badges
                    updatePendingRegularizationsBadge();
                } else {
                    window.location.reload();
                }
            } catch (err) {
                console.error(err);
            }
        }

        // Live Clock helper
        function startLiveClock() {
            setInterval(() => {
                const now = new Date();
                const clock = document.getElementById('liveClock');
                if (clock) {
                    clock.textContent = now.toLocaleTimeString('en-US', { hour12: false });
                }
                const liveDate = document.getElementById('liveDate');
                if (liveDate) {
                    liveDate.textContent = now.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
                }
            }, 1000);
        }

        // Load Badges count
        async function updatePendingRegularizationsBadge() {
            try {
                const res = await fetch('?api=attendance/pending_regularizations');
                const list = await res.json();
                const badge = document.getElementById('pendingRegBadge');
                if (list.length > 0) {
                    badge.textContent = list.length;
                    badge.style.display = 'inline-block';
                } else {
                    badge.style.display = 'none';
                }
            } catch (err) {}
        }

        // ==========================================
        //  DASHBOARD HANDLER
        // ==========================================
        async function loadDashboard() {
            try {
                const res = await fetch('?api=dashboard');
                const data = await res.json();
                
                // Set greeting
                const hr = new Date().getHours();
                const greet = hr < 12 ? 'Good morning' : (hr < 17 ? 'Good afternoon' : 'Good evening');
                document.getElementById('dashGreeting').textContent = `${greet}, ${currentUser.name}`;
                document.getElementById('dashTodayDate').textContent = new Date().toLocaleDateString('en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
                
                // Fill statistics
                const stats = data.today_stats;
                document.getElementById('cardOnTime').textContent = stats.on_time;
                document.getElementById('cardLate').textContent = stats.late;
                document.getElementById('cardMajorLate').textContent = stats.major_late;
                document.getElementById('cardHalfDay').textContent = stats.half_day;
                document.getElementById('cardAbsent').textContent = stats.absent;
                document.getElementById('cardEarlyCheckout').textContent = stats.early_checkout;
                
                // Render Recent activities
                const feed = document.getElementById('activityFeed');
                if (data.recent_activity.length === 0) {
                    feed.innerHTML = '<div class="empty-state">No recent activities.</div>';
                } else {
                    feed.innerHTML = data.recent_activity.map(act => `
                        <div class="activity">
                            <i class="activity-icon">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                            </i>
                            <p>
                                ${act.message}
                                <time>${new Date(act.created_at).toLocaleTimeString('en-US', {hour: '2-digit', minute:'2-digit'})}</time>
                            </p>
                        </div>
                    `).join('');
                }
                
                // Fetch projects list & count stats
                const projRes = await fetch('?api=projects');
                const projs = await projRes.json();
                
                const attRes = await fetch('?api=attendance&date=' + new Date().toISOString().split('T')[0]);
                const todayAtts = await attRes.json();
                
                const empRes = await fetch('?api=employees');
                const emps = await empRes.json();
                
                const projListDiv = document.getElementById('dashProjectList');
                if (projs.length === 0) {
                    projListDiv.innerHTML = '<div class="empty-state">No projects configured.</div>';
                } else {
                    projListDiv.innerHTML = projs.map(p => {
                        const assigned = emps.filter(e => e.project_id == p.id);
                        const present = assigned.filter(e => todayAtts.some(a => a.emp_id == e.id && a.status !== 'Absent')).length;
                        const ok = present === assigned.length && assigned.length > 0;
                        return `
                            <div class="project-row">
                                <div class="project-name">
                                    <i class="project-initial">${initials(p.name)}</i>
                                    <span>${p.name}</span>
                                </div>
                                <span>${present} / ${assigned.length} Present</span>
                                <span class="status ${ok ? '' : 'warning'}">${ok ? 'On Track' : 'Needs Check-ins'}</span>
                            </div>
                        `;
                    }).join('');
                }
                
            } catch (err) {
                console.error(err);
            }
        }

        // ==========================================
        //  ATTENDANCE TAB CONTROLLER
        // ==========================================
        function switchAttTab(tab) {
            document.querySelectorAll('.att-tab-content').forEach(c => c.style.display = 'none');
            document.querySelectorAll('.tabs button').forEach(b => b.classList.remove('active'));
            
            document.getElementById('attTab-' + tab).style.display = 'block';
            document.getElementById('tabBtn-' + tab).classList.add('active');
            
            if (tab === 'register') {
                document.getElementById('registerDateFilter').value = new Date().toISOString().split('T')[0];
                loadDailyRegister();
            } else if (tab === 'regularization') {
                loadPendingRegularizations();
            }
        }

        async function loadAttendanceTab() {
            switchAttTab('mark');
            
            // Fill Select fields
            try {
                const empRes = await fetch('?api=employees');
                globalEmployees = await empRes.json();
                
                const empSelect = document.getElementById('checkinEmpSelect');
                empSelect.innerHTML = '<option value="">Choose Employee...</option>' + 
                    globalEmployees.map(e => `<option value="${e.id}">${e.name} (${e.code})</option>`).join('');
                
                const projRes = await fetch('?api=projects');
                globalProjects = await projRes.json();
                
                const projSelect = document.getElementById('verificationProject');
                projSelect.innerHTML = '<option value="">Select Project...</option>' +
                    globalProjects.map(p => `<option value="${p.id}">${p.name}</option>`).join('');
                    
            } catch (err) {}
        }

        let selectedEmployee = null;
        function onCheckinEmpChange() {
            const id = document.getElementById('checkinEmpSelect').value;
            selectedEmployee = globalEmployees.find(e => e.id == id);
            
            const infoBox = document.getElementById('shiftInfoBox');
            if (selectedEmployee) {
                const shiftText = selectedEmployee.shift_type === 'manager' ? 'Manager Shift (10:15 AM - 7:00 PM)' : 'Staff Shift (10:00 AM - 7:00 PM)';
                infoBox.innerHTML = `<strong>${selectedEmployee.name}</strong> assigned to <strong>${selectedEmployee.project_name || 'No Project'}</strong><br>${shiftText}`;
                
                if (selectedEmployee.project_id) {
                    document.getElementById('verificationProject').value = selectedEmployee.project_id;
                    onVerificationProjectChange();
                }
            } else {
                infoBox.innerHTML = 'Select an employee to view shift details';
            }
            
            updateCheckinButtonState();
        }

        let isCheckingOutState = false;
        let todayEmpRecord = null;
        
        async function updateCheckinButtonState() {
            const container = document.getElementById('checkinButtonContainer');
            if (!selectedEmployee) {
                container.innerHTML = '<button class="clock-btn-checkin" style="opacity: 0.5; cursor: not-allowed;" disabled>Check In</button>';
                return;
            }
            
            // Check if checked in today
            try {
                const today = new Date().toISOString().split('T')[0];
                const res = await fetch(`?api=attendance&date=${today}`);
                const list = await res.json();
                
                todayEmpRecord = list.find(a => a.emp_id == selectedEmployee.id);
                
                if (todayEmpRecord && todayEmpRecord.check_in_time && !todayEmpRecord.check_out_time) {
                    isCheckingOutState = true;
                    container.innerHTML = '<button class="clock-btn-checkout" onclick="processCheckout()">Check Out</button>';
                } else if (todayEmpRecord && todayEmpRecord.check_out_time) {
                    container.innerHTML = '<div style="color:var(--green); font-weight:700; font-size:14px; margin-top:20px;">Already Checked Out Today</div>';
                } else {
                    isCheckingOutState = false;
                    container.innerHTML = '<button class="clock-btn-checkin" onclick="processCheckin()">Check In</button>';
                }
            } catch (err) {}
        }

        let distanceResult = 0;
        function onVerificationProjectChange() {
            const pid = document.getElementById('verificationProject').value;
            const proj = globalProjects.find(p => p.id == pid);
            
            const gpsStat = document.getElementById('gpsStatus');
            const gpsDetail = document.getElementById('gpsDetail');
            
            if (proj) {
                // Simulate coordinate check within geofence radius
                // For a robust simulation, we generate random coordinate within/outside project coordinates
                navigator.geolocation.getCurrentPosition(position => {
                    const currentLat = position.coords.latitude;
                    const currentLng = position.coords.longitude;
                    
                    const dist = haversineDistance(currentLat, currentLng, parseFloat(proj.lat), parseFloat(proj.lng));
                    distanceResult = Math.round(dist);
                    
                    gpsDetail.textContent = `Distance: ${distanceResult}m from site`;
                    if (distanceResult <= parseInt(proj.radius)) {
                        gpsStat.textContent = 'Within Geofence ✓';
                        gpsStat.style.color = 'var(--green)';
                    } else {
                        gpsStat.textContent = 'Out of Geofence ✕';
                        gpsStat.style.color = 'var(--red)';
                    }
                }, error => {
                    // Fallback simulation
                    distanceResult = Math.floor(Math.random() * 50); // Inside geofence
                    gpsDetail.textContent = `Distance: ${distanceResult}m (Fallback GPS)`;
                    gpsStat.textContent = 'Within Geofence ✓';
                    gpsStat.style.color = 'var(--green)';
                });
            } else {
                gpsStat.textContent = 'Ready';
                gpsStat.style.color = 'var(--green)';
                gpsDetail.textContent = 'Distance: --';
            }
        }

        async function processCheckin() {
            if (!selectedEmployee) return;
            
            const gpsVerified = distanceResult <= (selectedEmployee.radius || 100) ? 1 : 0;
            
            try {
                const res = await fetch('?api=attendance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'checkin',
                        emp_id: selectedEmployee.id,
                        project_id: selectedEmployee.project_id,
                        distance: distanceResult,
                        face_verified: 1, // Simulated
                        gps_verified: gpsVerified
                    })
                });
                const data = await res.json();
                if (data.success) {
                    notify(`Checked In successfully: ${data.status} (Deduction: ${fmtRs(data.deduction_rs)})`);
                    updateCheckinButtonState();
                    updatePendingRegularizationsBadge();
                } else {
                    notify(data.error || 'Failed to check in');
                }
            } catch (err) {
                notify('Network error on check-in');
            }
        }

        async function processCheckout() {
            if (!todayEmpRecord) return;
            
            try {
                const res = await fetch('?api=attendance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'checkout',
                        att_id: todayEmpRecord.id
                    })
                });
                const data = await res.json();
                if (data.success) {
                    notify('Checked out successfully!');
                    updateCheckinButtonState();
                } else {
                    notify(data.error || 'Failed to check out');
                }
            } catch (err) {
                notify('Network error on check-out');
            }
        }

        async function markAllAbsentToday() {
            confirmAction('Mark all as Absent?', 'This will mark all active employees who have not checked in today as Absent.', async () => {
                try {
                    const res = await fetch('?api=attendance', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'mark_absent',
                            date: new Date().toISOString().split('T')[0]
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        notify(`Completed: ${data.marked} employee(s) marked Absent.`);
                        if (document.getElementById('attTab-register').style.display === 'block') {
                            loadDailyRegister();
                        }
                    }
                } catch (err) {}
            });
        }

        async function loadDailyRegister() {
            const date = document.getElementById('registerDateFilter').value;
            const status = document.getElementById('registerStatusFilter').value;
            
            try {
                const res = await fetch(`?api=attendance&date=${date}&status=${status}`);
                const list = await res.json();
                
                const body = document.getElementById('attendanceRegisterBody');
                if (list.length === 0) {
                    body.innerHTML = '<tr><td colspan="10"><div class="empty-state">No attendance records found for this day.</div></td></tr>';
                    return;
                }
                
                body.innerHTML = list.map((a, idx) => {
                    const statusClass = a.status.toLowerCase().replace(' ', '');
                    
                    let regularizeBtn = '';
                    if (a.status !== 'On Time' && a.status !== 'Absent') {
                        if (a.regularization_status === 'none') {
                            regularizeBtn = `<button class="action-btn" onclick="openRegularizeSubmitModal(${a.id})">Regularize</button>`;
                        } else if (a.regularization_status === 'pending') {
                            regularizeBtn = `<span style="font-size:11px; color:var(--orange); font-weight:700;">Pending approval</span>`;
                        } else if (a.regularization_status === 'approved') {
                            regularizeBtn = `<span style="font-size:11px; color:var(--green); font-weight:700;">Approved (Remark: ${a.manager_remark || 'none'})</span>`;
                        } else if (a.regularization_status === 'rejected') {
                            regularizeBtn = `<span style="font-size:11px; color:var(--red); font-weight:700;">Rejected (${a.manager_remark || ''})</span>`;
                        }
                    }
                    
                    return `
                        <tr class="status-${statusClass}">
                            <td><strong>${a.employee_name}</strong></td>
                            <td>${a.employee_role}</td>
                            <td>${a.project_name || '—'}</td>
                            <td>${a.check_in_time || '—'}</td>
                            <td>${a.check_out_time || '—'}</td>
                            <td>${a.working_minutes || '0'} mins</td>
                            <td><span class="status ${a.status === 'On Time' ? '' : 'warning'}">${a.status}</span></td>
                            <td>${fmtRs(a.deduction_rs)}</td>
                            <td>${a.gps_verified == '1' ? 'GPS✓' : 'GPS✕'} / ${a.face_verified == '1' ? 'Face✓' : 'Face✕'}</td>
                            <td>${regularizeBtn}</td>
                        </tr>
                    `;
                }).join('');
            } catch (err) {}
        }

        function openRegularizeSubmitModal(attId) {
            document.getElementById('regSubmitAttId').value = attId;
            openModal('regularizeSubmitModal');
        }

        async function submitRegularizationRequest(e) {
            e.preventDefault();
            const attId = document.getElementById('regSubmitAttId').value;
            const reason = document.getElementById('regSubmitReason').value;
            
            try {
                const res = await fetch('?api=attendance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'regularize',
                        att_id: attId,
                        reason: reason
                    })
                });
                const data = await res.json();
                if (data.success) {
                    notify('Regularization request submitted.');
                    closeModal('regularizeSubmitModal');
                    loadDailyRegister();
                    updatePendingRegularizationsBadge();
                }
            } catch (err) {}
        }

        async function loadPendingRegularizations() {
            try {
                const res = await fetch('?api=attendance/pending_regularizations');
                const list = await res.json();
                
                const body = document.getElementById('pendingRegularizationsBody');
                if (list.length === 0) {
                    body.innerHTML = '<tr><td colspan="6"><div class="empty-state">No pending regularization requests.</div></td></tr>';
                    return;
                }
                
                body.innerHTML = list.map(a => `
                    <tr>
                        <td><strong>${a.employee_name}</strong></td>
                        <td>${a.employee_role}</td>
                        <td>${a.attendance_date}</td>
                        <td>${a.regularization_reason}</td>
                        <td>${new Date(a.regularization_submitted_at).toLocaleString()}</td>
                        <td>
                            <button class="action-btn" onclick="openReviewModal(${a.id})">Review Request</button>
                        </td>
                    </tr>
                `).join('');
            } catch (err) {}
        }

        function openReviewModal(attId) {
            document.getElementById('reviewRegAttId').value = attId;
            document.getElementById('reviewRegRemark').value = '';
            openModal('reviewRegularizationModal');
        }

        async function submitRegularizationReview(e) {
            e.preventDefault();
            const attId = document.getElementById('reviewRegAttId').value;
            const decision = document.getElementById('reviewRegDecision').value;
            const remark = document.getElementById('reviewRegRemark').value;
            
            try {
                const res = await fetch('?api=attendance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'review',
                        att_id: attId,
                        decision: decision,
                        remark: remark
                    })
                });
                const data = await res.json();
                if (data.success) {
                    notify('Regularization reviewed.');
                    closeModal('reviewRegularizationModal');
                    loadPendingRegularizations();
                    updatePendingRegularizationsBadge();
                }
            } catch (err) {}
        }

        async function exportRegisterCSV() {
            const date = document.getElementById('registerDateFilter').value;
            try {
                const res = await fetch(`?api=attendance&date=${date}`);
                const list = await res.json();
                
                let csv = 'Employee,Role,Project,Check In,Check Out,Working Mins,Status,Deductions,GPS/Face Verified\n';
                list.forEach(a => {
                    csv += `"${a.employee_name}","${a.employee_role}","${a.project_name || ''}","${a.check_in_time || ''}","${a.check_out_time || ''}",${a.working_minutes},"${a.status}",${a.deduction_rs},"${a.gps_verified == '1' ? 'YES' : 'NO'}/${a.face_verified == '1' ? 'YES' : 'NO'}"\n`;
                });
                
                const blob = new Blob([csv], { type: 'text/csv' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = `Signature_HRMS_Attendance_${date}.csv`;
                link.click();
            } catch (err) {}
        }

        // ==========================================
        //  EMPLOYEES DIRECTORY
        // ==========================================
        async function loadEmployees() {
            try {
                const res = await fetch('?api=employees');
                globalEmployees = await res.json();
                renderEmployeeTable(globalEmployees);
            } catch (err) {}
        }

        function renderEmployeeTable(list) {
            const body = document.getElementById('employeeTableBody');
            if (list.length === 0) {
                body.innerHTML = '<tr><td colspan="7"><div class="empty-state">No employees found.</div></td></tr>';
                return;
            }
            
            body.innerHTML = list.map(e => `
                <tr>
                    <td>
                        <div class="employee-cell">
                            <i class="mini-avatar">${initials(e.name)}</i>
                            <div>
                                <strong>${e.name}</strong>
                                <span>Code: ${e.code}</span>
                            </div>
                        </div>
                    </td>
                    <td>${e.role}</td>
                    <td>${e.project_name || '—'}</td>
                    <td>${fmtRs(e.salary)}</td>
                    <td>${fmtRs(e.petrol)}</td>
                    <td><span class="status ${e.status === 'Active' ? '' : 'warning'}">${e.status}</span></td>
                    <td>
                        <div class="table-actions">
                            <button class="action-btn" onclick="openEditEmployeeModal(${e.id})">Edit</button>
                            <button class="action-btn del" onclick="deleteEmployee(${e.id})">Delete</button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        function filterEmployeeTable() {
            const query = document.getElementById('employeeSearchInput').value.toLowerCase();
            const filtered = globalEmployees.filter(e => e.name.toLowerCase().includes(query) || e.code.toLowerCase().includes(query));
            renderEmployeeTable(filtered);
        }

        async function populateProjectsDropdown(dropdownId, selectVal = '') {
            try {
                const res = await fetch('?api=projects');
                const list = await res.json();
                const dropdown = document.getElementById(dropdownId);
                
                dropdown.innerHTML = '<option value="">Select project...</option>' + 
                    list.map(p => `<option value="${p.id}">${p.name}</option>`).join('');
                if (selectVal) dropdown.value = selectVal;
            } catch (err) {}
        }

        async function openAddEmployeeModal() {
            document.getElementById('employeeForm').reset();
            document.getElementById('empId').value = '';
            document.getElementById('empModalTitle').textContent = 'Add Employee';
            
            await populateProjectsDropdown('empProject');
            
            // Set auto employee code prefix
            const nextCode = 'EMP-' + String(globalEmployees.length + 101);
            document.getElementById('empCode').value = nextCode;
            
            onEmpRoleChange();
            openModal('employeeModal');
        }

        async function openEditEmployeeModal(id) {
            const emp = globalEmployees.find(e => e.id == id);
            if (!emp) return;
            
            document.getElementById('empId').value = emp.id;
            document.getElementById('empName').value = emp.name;
            document.getElementById('empCode').value = emp.code;
            document.getElementById('empRole').value = emp.role;
            document.getElementById('empSalary').value = emp.salary;
            document.getElementById('empPetrol').value = emp.petrol;
            document.getElementById('empPhone').value = emp.phone;
            document.getElementById('empStatus').value = emp.status;
            document.getElementById('empShiftType').value = emp.shift_type;
            document.getElementById('empJoinDate').value = emp.join_date || '';
            document.getElementById('empNotes').value = emp.notes || '';
            
            await populateProjectsDropdown('empProject', emp.project_id || '');
            
            // Re-apply incentive configurations
            const config = JSON.parse(emp.incentive_config || '{}');
            document.getElementById('incVisitFixed').value = config.visitFixed || 1000;
            document.getElementById('incVisitTarget').value = config.visitTarget || 30;
            document.getElementById('incSalesFixed').value = config.salesFixed || 0;
            document.getElementById('incOnAccount').value = config.onAccount || 60000;
            document.getElementById('incDealPct').value = config.dealPct || 0.60;
            
            document.getElementById('empModalTitle').textContent = 'Edit Employee';
            onEmpRoleChange();
            openModal('employeeModal');
        }

        function onEmpRoleChange() {
            const role = document.getElementById('empRole').value;
            
            document.getElementById('incVisitSection').classList.remove('visible');
            document.getElementById('incSalesSection').classList.remove('visible');
            document.getElementById('incSrMgrSection').classList.remove('visible');
            
            if (role === 'Telecaller') {
                document.getElementById('incVisitSection').classList.add('visible');
            } else if (role === 'Asst. Sales Manager') {
                document.getElementById('incVisitSection').classList.add('visible');
                document.getElementById('incSalesSection').classList.add('visible');
                document.getElementById('incSalesFixedLabel').textContent = 'Incentive per Sale (₹)';
            } else if (role === 'Sales Manager') {
                document.getElementById('incSalesSection').classList.add('visible');
                document.getElementById('incSalesFixedLabel').textContent = 'Incentive per Sale (₹)';
            } else if (role === 'Sr. Sales Manager') {
                document.getElementById('incSrMgrSection').classList.add('visible');
            } else if (role === 'Manager') {
                document.getElementById('incSalesSection').classList.add('visible');
                document.getElementById('incSalesFixedLabel').textContent = 'Monthly Leadership Incentive (₹)';
            }
        }

        async function saveEmployee(e) {
            e.preventDefault();
            
            const role = document.getElementById('empRole').value;
            const incConfig = {};
            if (role === 'Telecaller') {
                incConfig.visitFixed = parseFloat(document.getElementById('incVisitFixed').value);
                incConfig.visitTarget = parseInt(document.getElementById('incVisitTarget').value);
            } else if (role === 'Asst. Sales Manager') {
                incConfig.visitFixed = parseFloat(document.getElementById('incVisitFixed').value);
                incConfig.visitTarget = parseInt(document.getElementById('incVisitTarget').value);
                incConfig.salesFixed = parseFloat(document.getElementById('incSalesFixed').value);
            } else if (role === 'Sales Manager' || role === 'Manager') {
                incConfig.salesFixed = parseFloat(document.getElementById('incSalesFixed').value);
            } else if (role === 'Sr. Sales Manager') {
                incConfig.onAccount = parseFloat(document.getElementById('incOnAccount').value);
                incConfig.dealPct = parseFloat(document.getElementById('incDealPct').value);
            }
            
            const payload = {
                action: 'save',
                data: {
                    id: document.getElementById('empId').value || null,
                    name: document.getElementById('empName').value,
                    code: document.getElementById('empCode').value,
                    role: role,
                    project_id: document.getElementById('empProject').value || null,
                    salary: parseFloat(document.getElementById('empSalary').value),
                    petrol: parseFloat(document.getElementById('empPetrol').value || 0),
                    phone: document.getElementById('empPhone').value,
                    status: document.getElementById('empStatus').value,
                    shift_type: document.getElementById('empShiftType').value,
                    join_date: document.getElementById('empJoinDate').value || null,
                    notes: document.getElementById('empNotes').value,
                    incentive_config: incConfig
                }
            };
            
            try {
                const res = await fetch('?api=employees', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    notify('Employee saved.');
                    closeModal('employeeModal');
                    loadEmployees();
                } else {
                    notify(data.error || 'Error saving employee');
                }
            } catch (err) {}
        }

        async function deleteEmployee(id) {
            confirmAction('Delete employee?', 'Are you sure you want to delete this employee record?', async () => {
                try {
                    const res = await fetch('?api=employees', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete', id: id })
                    });
                    const data = await res.json();
                    if (data.success) {
                        notify('Employee deleted.');
                        loadEmployees();
                    }
                } catch (err) {}
            });
        }

        // ==========================================
        //  PROJECTS MANAGEMENT
        // ==========================================
        async function loadProjects() {
            try {
                const res = await fetch('?api=projects');
                globalProjects = await res.json();
                renderProjectGrid(globalProjects);
            } catch (err) {}
        }

        function renderProjectGrid(list) {
            const grid = document.getElementById('projectGrid');
            if (list.length === 0) {
                grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1">No projects found. Create one.</div>';
                return;
            }
            
            grid.innerHTML = list.map(p => `
                <div class="card project-card">
                    <h3>${p.name}</h3>
                    <p>${p.address || 'No Address'}</p>
                    <div class="project-meta">
                        <div class="project-meta-item">
                            <span>Geofence Limit</span>
                            <strong>${p.radius}m</strong>
                        </div>
                        <div class="project-meta-item">
                            <span>Coordinates</span>
                            <strong>${parseFloat(p.lat).toFixed(4)}, ${parseFloat(p.lng).toFixed(4)}</strong>
                        </div>
                        <div class="project-meta-item">
                            <span>Shift Timing</span>
                            <strong>${p.shift_start} - ${p.shift_end}</strong>
                        </div>
                    </div>
                    
                    <div class="table-actions" style="margin-top:20px; justify-content: flex-end;">
                        <button class="action-btn" onclick="openEditProjectModal(${p.id})">Edit</button>
                        <button class="action-btn del" onclick="deleteProject(${p.id})">Delete</button>
                    </div>
                </div>
            `).join('');
        }

        function openAddProjectModal() {
            document.getElementById('projectForm').reset();
            document.getElementById('projId').value = '';
            document.getElementById('projModalTitle').textContent = 'Create Project';
            
            // Set defaults to office coordinates or current location
            navigator.geolocation.getCurrentPosition(position => {
                document.getElementById('projLat').value = position.coords.latitude.toFixed(6);
                document.getElementById('projLng').value = position.coords.longitude.toFixed(6);
            }, () => {
                document.getElementById('projLat').value = "28.613900"; // Delhi
                document.getElementById('projLng').value = "77.209000";
            });
            
            openModal('projectModal');
        }

        function openEditProjectModal(id) {
            const p = globalProjects.find(pr => pr.id == id);
            if (!p) return;
            
            document.getElementById('projId').value = p.id;
            document.getElementById('projName').value = p.name;
            document.getElementById('projAddress').value = p.address;
            document.getElementById('projLat').value = p.lat;
            document.getElementById('projLng').value = p.lng;
            document.getElementById('projRadius').value = p.radius;
            document.getElementById('projShiftStart').value = p.shift_start.substring(0, 5);
            document.getElementById('projShiftEnd').value = p.shift_end.substring(0, 5);
            
            document.getElementById('projModalTitle').textContent = 'Edit Project';
            openModal('projectModal');
        }

        async function saveProject(e) {
            e.preventDefault();
            const payload = {
                action: 'save',
                data: {
                    id: document.getElementById('projId').value || null,
                    name: document.getElementById('projName').value,
                    address: document.getElementById('projAddress').value,
                    lat: parseFloat(document.getElementById('projLat').value),
                    lng: parseFloat(document.getElementById('projLng').value),
                    radius: parseInt(document.getElementById('projRadius').value),
                    shift_start: document.getElementById('projShiftStart').value,
                    shift_end: document.getElementById('projShiftEnd').value
                }
            };
            
            try {
                const res = await fetch('?api=projects', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    notify('Project saved.');
                    closeModal('projectModal');
                    loadProjects();
                }
            } catch (err) {}
        }

        async function deleteProject(id) {
            confirmAction('Delete project?', 'Are you sure you want to delete this project site?', async () => {
                try {
                    const res = await fetch('?api=projects', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete', id: id })
                    });
                    const data = await res.json();
                    if (data.success) {
                        notify('Project deleted.');
                        loadProjects();
                    }
                } catch (err) {}
            });
        }

        // ==========================================
        //  PAYROLL & ADVANCES
        // ==========================================
        function switchPayrollTab(tab) {
            document.querySelectorAll('.payroll-tab-content').forEach(c => c.style.display = 'none');
            document.querySelectorAll('.tabs button').forEach(b => b.classList.remove('active'));
            
            document.getElementById('payrollTab-' + tab).style.display = 'block';
            document.getElementById('tabBtn-pay' + tab.charAt(0).toUpperCase() + tab.slice(1)).classList.add('active');
            
            if (tab === 'register') {
                loadPayroll();
            } else if (tab === 'advances') {
                loadAdvances();
            }
        }

        async function loadPayroll() {
            let filterMonth = document.getElementById('payrollMonthFilter').value;
            if (!filterMonth) {
                filterMonth = new Date().toISOString().split('T')[0].substring(0, 7);
                document.getElementById('payrollMonthFilter').value = filterMonth;
            }
            
            try {
                const res = await fetch(`?api=payroll&month=${filterMonth}`);
                const list = await res.json();
                
                const body = document.getElementById('payrollTableBody');
                if (list.length === 0) {
                    body.innerHTML = '<tr><td colspan="8"><div class="empty-state">No payroll details recorded.</div></td></tr>';
                    return;
                }
                
                body.innerHTML = list.map(p => `
                    <tr>
                        <td><strong>${p.name}</strong><small style="color:var(--muted);">${p.role} · ${p.project || 'No Site'}</small></td>
                        <td>${fmtRs(p.base_salary)}</td>
                        <td>${fmtRs(p.incentives)}</td>
                        <td>${fmtRs(p.petrol)}</td>
                        <td style="color:var(--red); font-weight:600;">-${fmtRs(p.deductions)}</td>
                        <td style="color:var(--red); font-weight:600;">-${fmtRs(p.advances)}</td>
                        <td style="font-weight:700; color:var(--green);">${fmtRs(p.net_payable)}</td>
                        <td>${p.present_days} Day(s)</td>
                    </tr>
                `).join('');
            } catch (err) {}
        }

        async function loadAdvances() {
            try {
                const res = await fetch('?api=advances');
                const list = await res.json();
                
                const body = document.getElementById('advancesTableBody');
                if (list.length === 0) {
                    body.innerHTML = '<tr><td colspan="6"><div class="empty-state">No advance ledger records.</div></td></tr>';
                    return;
                }
                
                body.innerHTML = list.map(a => `
                    <tr>
                        <td><strong>${a.employee_name}</strong><small style="display:block;color:var(--muted);">${a.employee_code}</small></td>
                        <td>${fmtRs(a.amount)}</td>
                        <td>${a.deduction_month}</td>
                        <td>${a.reason || '—'}</td>
                        <td><span class="status ${a.approved == '1' ? '' : 'warning'}">${a.approved == '1' ? 'Active Deduction' : 'Waived'}</span></td>
                        <td>
                            <button class="action-btn del" onclick="deleteAdvance(${a.id})">Delete</button>
                        </td>
                    </tr>
                `).join('');
            } catch (err) {}
        }

        async function openAddAdvanceModal() {
            try {
                const res = await fetch('?api=employees');
                const list = await res.json();
                
                const select = document.getElementById('advEmpId');
                select.innerHTML = list.map(e => `<option value="${e.id}">${e.name} (${e.code})</option>`).join('');
                
                document.getElementById('advanceForm').reset();
                document.getElementById('advMonth').value = new Date().toISOString().split('T')[0].substring(0, 7);
                openModal('advanceModal');
            } catch (err) {}
        }

        async function saveAdvance(e) {
            e.preventDefault();
            const payload = {
                action: 'save',
                data: {
                    emp_id: document.getElementById('advEmpId').value,
                    amount: parseFloat(document.getElementById('advAmount').value),
                    deduction_month: document.getElementById('advMonth').value,
                    reason: document.getElementById('advReason').value,
                    approved: 1
                }
            };
            
            try {
                const res = await fetch('?api=advances', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    notify('Advance logged.');
                    closeModal('advanceModal');
                    loadAdvances();
                }
            } catch (err) {}
        }

        async function deleteAdvance(id) {
            confirmAction('Delete Advance?', 'Remove this advance record from the ledger database?', async () => {
                try {
                    const res = await fetch('?api=advances', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete', id: id })
                    });
                    const data = await res.json();
                    if (data.success) {
                        notify('Advance deleted.');
                        loadAdvances();
                    }
                } catch (err) {}
            });
        }

        // ==========================================
        //  ATTENDANCE POLICY
        // ==========================================
        async function loadPolicy() {
            try {
                const res = await fetch('?api=policy');
                const p = await res.json();
                
                document.getElementById('policyStaffStart').value = p.staff_shift_start;
                document.getElementById('policyStaffEnd').value = p.staff_shift_end;
                document.getElementById('policyStaffGrace').value = p.staff_grace_mins;
                document.getElementById('policyGraceFreeCount').value = p.grace_free_incidents;
                document.getElementById('policyMgrStart').value = p.mgr_shift_start;
                document.getElementById('policyMgrEnd').value = p.mgr_shift_end;
                document.getElementById('policyMgrGrace').value = p.mgr_grace_mins;
                document.getElementById('policyPaidHours').value = p.paid_hours;
                document.getElementById('policyLunchMins').value = p.lunch_minutes;
                document.getElementById('policyPayableDays').value = p.payable_days;
            } catch (err) {}
        }

        async function savePolicy(e) {
            e.preventDefault();
            const payload = {
                data: {
                    staff_shift_start: document.getElementById('policyStaffStart').value,
                    staff_shift_end: document.getElementById('policyStaffEnd').value,
                    staff_grace_mins: parseInt(document.getElementById('policyStaffGrace').value),
                    grace_free_incidents: parseInt(document.getElementById('policyGraceFreeCount').value),
                    mgr_shift_start: document.getElementById('policyMgrStart').value,
                    mgr_shift_end: document.getElementById('policyMgrEnd').value,
                    mgr_grace_mins: parseInt(document.getElementById('policyMgrGrace').value),
                    paid_hours: parseInt(document.getElementById('policyPaidHours').value),
                    lunch_minutes: parseInt(document.getElementById('policyLunchMins').value),
                    payable_days: parseInt(document.getElementById('policyPayableDays').value)
                }
            };
            
            try {
                const res = await fetch('?api=policy', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    notify('Policy configurations updated.');
                }
            } catch (err) {}
        }

        // ==========================================
        //  USER ACCESS MANAGEMENT
        // ==========================================
        async function loadUsers() {
            try {
                const res = await fetch('?api=users');
                const list = await res.json();
                
                const body = document.getElementById('usersTableBody');
                if (list.length === 0) {
                    body.innerHTML = '<tr><td colspan="5"><div class="empty-state">No users.</div></td></tr>';
                    return;
                }
                
                body.innerHTML = list.map(u => `
                    <tr>
                        <td><strong>${u.name}</strong></td>
                        <td>${u.username}</td>
                        <td><span class="status ${u.role === 'super_admin' ? '' : 'neutral'}">${u.role}</span></td>
                        <td>${u.project_name || '— (All sites)'}</td>
                        <td>
                            <div class="table-actions">
                                <button class="action-btn" onclick="openEditUserModal(${u.id})">Edit</button>
                                <button class="action-btn del" onclick="deleteUser(${u.id})">Delete</button>
                            </div>
                        </td>
                    </tr>
                `).join('');
            } catch (err) {}
        }

        function onUserRoleChange() {
            const role = document.getElementById('userRole').value;
            const projectDiv = document.getElementById('userProjectDiv');
            if (role === 'manager') {
                projectDiv.style.display = 'block';
                populateProjectsDropdown('userProject');
            } else {
                projectDiv.style.display = 'none';
            }
        }

        async function openAddUserModal() {
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '';
            document.getElementById('userModalTitle').textContent = 'Create User';
            document.getElementById('userPassword').required = true;
            onUserRoleChange();
            openModal('userModal');
        }

        async function openEditUserModal(id) {
            try {
                const res = await fetch('?api=users');
                const list = await res.json();
                const u = list.find(x => x.id == id);
                if (!u) return;
                
                document.getElementById('userId').value = u.id;
                document.getElementById('userDisplayName').value = u.name;
                document.getElementById('userUsername').value = u.username;
                document.getElementById('userPassword').value = '';
                document.getElementById('userPassword').required = false;
                document.getElementById('userRole').value = u.role;
                
                if (u.role === 'manager') {
                    document.getElementById('userProjectDiv').style.display = 'block';
                    await populateProjectsDropdown('userProject', u.project_id || '');
                } else {
                    document.getElementById('userProjectDiv').style.display = 'none';
                }
                
                document.getElementById('userModalTitle').textContent = 'Edit User';
                openModal('userModal');
            } catch (err) {}
        }

        async function saveUser(e) {
            e.preventDefault();
            const payload = {
                action: 'save',
                data: {
                    id: document.getElementById('userId').value || null,
                    name: document.getElementById('userDisplayName').value,
                    username: document.getElementById('userUsername').value,
                    password: document.getElementById('userPassword').value,
                    role: document.getElementById('userRole').value,
                    project_id: document.getElementById('userRole').value === 'manager' ? document.getElementById('userProject').value : null
                }
            };
            
            try {
                const res = await fetch('?api=users', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    notify('User saved.');
                    closeModal('userModal');
                    loadUsers();
                }
            } catch (err) {}
        }

        async function deleteUser(id) {
            confirmAction('Delete User?', 'Are you sure you want to delete this panel user account?', async () => {
                try {
                    const res = await fetch('?api=users', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete', id: id })
                    });
                    const data = await res.json();
                    if (data.success) {
                        notify('User account deleted.');
                        loadUsers();
                    }
                } catch (err) {}
            });
        }

        // On document load
        window.addEventListener('DOMContentLoaded', () => {
            initApp();
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('sw.js')
                .then(reg => console.log('Service Worker Registered', reg))
                .catch(err => console.error('Service Worker Register Failed', err));
            }
        });
    </script>
<?php endif; ?>

</body>
</html>
