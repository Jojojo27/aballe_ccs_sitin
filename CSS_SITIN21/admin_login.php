<?php
require_once 'config.php';

// Redirect if already logged in as admin
if (isset($_SESSION['admin_id'])) {
    header("Location: admin_dashboard.php");
    exit();
}

$error = '';

// Hardcoded admin credentials
$valid_admins = [
    [
        'username' => 'admin',
        'password' => 'admin123',
        'full_name' => 'System Administrator',
        'role' => 'super_admin',
        'email' => 'admin@ccs.edu'
    ],
    [
        'username' => 'ccs_admin',
        'password' => 'admin123',
        'full_name' => 'CCS Administrator',
        'role' => 'admin',
        'email' => 'ccs.admin@ccs.edu'
    ]
];

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = "Please enter username and password";
    } else {
        // Check against hardcoded admins
        $authenticated = false;
        foreach ($valid_admins as $admin) {
            if ($admin['username'] === $username && $admin['password'] === $password) {
                // Set session variables
                $_SESSION['admin_id'] = $admin['username'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_name'] = $admin['full_name'];
                $_SESSION['admin_role'] = $admin['role'];
                $_SESSION['admin_email'] = $admin['email'];
                
                $authenticated = true;
                header("Location: admin_dashboard.php");
                exit();
            }
        }
        
        if (!$authenticated) {
            $error = "Invalid username or password";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - CCS Sit-in Monitoring</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html { font-size: 13px; zoom: 0.80; }
        body {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .admin-login-container {
            width: 100%;
            max-width: 450px;
            padding: 20px;
        }
        
        .admin-login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .admin-login-header {
            background: linear-gradient(145deg, #2c3e50, #1a2634);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .admin-login-header i {
            font-size: 60px;
            margin-bottom: 15px;
            color: #f1c40f;
        }
        
        .admin-login-header h2 {
            font-size: 28px;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .admin-login-header p {
            opacity: 0.8;
            font-size: 14px;
            margin: 0;
        }
        
        .admin-login-body {
            padding: 40px 30px;
        }
        
        .admin-login-footer {
            background: #f8f9fa;
            padding: 20px 30px;
            text-align: center;
            border-top: 1px solid #e0e7ff;
        }
        
        .admin-login-footer a {
            color: #3498db;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .admin-login-footer a:hover {
            color: #2c3e50;
            gap: 12px;
        }
        
        .input-group {
            margin-bottom: 25px;
        }
        
        .input-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .input-group .input-icon {
            position: relative;
        }
        
        .input-group .input-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #95a5a6;
            font-size: 18px;
        }
        
        .input-group .input-icon input {
            width: 100%;
            padding: 15px 15px 15px 50px;
            border: 2px solid #e0e7ff;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s;
            font-family: 'Poppins', sans-serif;
            box-sizing: border-box;
        }
        
        .input-group .input-icon input:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
        }
        
        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(145deg, #2c3e50, #1a2634);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-family: 'Poppins', sans-serif;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(44,62,80,0.3);
        }
        
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 500;
            font-size: 14px;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 25px;
            color: #95a5a6;
            font-size: 13px;
        }
        
        .security-badge i {
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="admin-login-container">
        <div class="admin-login-card">
            <div class="admin-login-header">
                <i class="fas fa-user-shield"></i>
                <h2>Admin Login</h2>
                <p>CCS Sit-in Monitoring System</p>
            </div>
            
            <div class="admin-login-body">
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> 
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="input-group">
                        <label for="username">
                            <i class="fas fa-user"></i> Username
                        </label>
                        <div class="input-icon">
                            <i class="fas fa-user"></i>
                            <input type="text" 
                                   id="username" 
                                   name="username" 
                                   placeholder="Enter admin username"
                                   value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                                   required>
                        </div>
                    </div>
                    
                    <div class="input-group">
                        <label for="password">
                            <i class="fas fa-lock"></i> Password
                        </label>
                        <div class="input-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   placeholder="Enter password"
                                   required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-login">
                        <i class="fas fa-sign-in-alt"></i>
                        Login to Dashboard
                    </button>
                </form>
                
                <div class="security-badge">
                    <i class="fas fa-shield-alt"></i>
                    <span>Secured Admin Access Only</span>
                    <i class="fas fa-shield-alt"></i>
                </div>
            </div>
            
            <div class="admin-login-footer">
                <a href="index.php">
                    <i class="fas fa-arrow-left"></i>
                    Back to Student Portal
                </a>
            </div>
        </div>
    </div>
</body>
</html>