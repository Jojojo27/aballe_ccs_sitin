<?php
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    header("Location: student_dashboard.php");
    exit();
}
if (isset($_SESSION['admin_id'])) {
    header("Location: admin_dashboard.php");
    exit();
}

$error = '';

$admins = [
    ['username' => 'admin', 'password' => 'admin123', 'name' => 'System Administrator'],
    ['username' => 'ccs_admin', 'password' => 'admin123', 'name' => 'CCS Administrator']
];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = "Please enter username/ID and password";
    } else {
        $is_admin = false;

        foreach ($admins as $admin) {
            if ($admin['username'] === $username && $admin['password'] === $password) {
                $_SESSION['admin_id'] = $admin['username'];
                $_SESSION['admin_name'] = $admin['name'];
                header("Location: admin_dashboard.php");
                exit();
            }
        }

        if (!$is_admin) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id_number = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['first_name'] = $user['first_name'];
                header("Location: student_dashboard.php");
                exit();
            } else {
                $error = "Invalid credentials";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Login - CCS Sit-in Monitoring</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
    html { font-size: 13px; zoom: 0.80; }
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body::before {
    content: "";
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;

    background: url('unilogo.png') no-repeat center;
    background-size: cover;

    filter: blur(5px);   /* 🔥 THIS IS THE BLUR */
    transform: scale(1.1); /* prevents edges from showing */

    z-index: -1;
}

/* NAVBAR */
.navbar {
    position: fixed;
    top: 0;
    width: 100%;
    height: 70px;
    background: linear-gradient(135deg, #2c3e50, #1a2634);
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 40px;
    z-index: 1000;
    box-shadow: 0 4px 20px rgba(0,0,0,0.2);
}

.nav-left {
    display: flex;
    align-items: center;
    gap: 15px;
}

/* Avatar Circle with Glow */
.nav-avatar {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 0 2px rgba(255,255,255,0.3), 0 0 0 5px #f39c12, 0 0 15px rgba(243,156,18,0.5);
    transition: all 0.3s ease;
}

.nav-avatar:hover {
    transform: scale(1.05);
    box-shadow: 0 0 0 2px rgba(255,255,255,0.4), 0 0 0 6px #f39c12, 0 0 25px rgba(243,156,18,0.7);
}

.nav-avatar img {
    width: 32px;
    height: 32px;
    object-fit: contain;
}

.nav-title {
    color: white;
}

.nav-title strong {
    font-size: 1.3rem;
    display: block;
    line-height: 1.2;
}

.nav-title span {
    font-size: 0.7rem;
    opacity: 0.8;
}

.nav-links {
    display: flex;
    gap: 25px;
}

.nav-links a {
    color: white;
    text-decoration: none;
    font-weight: 500;
    transition: 0.3s;
    display: flex;
    align-items: center;
    gap: 5px;
}

.nav-links a:hover {
    color: #f39c12;
}

/* CENTER CONTAINER */
.container {
    display: flex;
    width: 950px;
    border-radius: 25px;
    overflow: hidden;
    box-shadow: 0 25px 60px rgba(0,0,0,0.3);
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
}

/* LEFT PANEL */
.left {
    width: 45%;
    background: linear-gradient(145deg, #2c3e50, #1a2634);
    color: white;
    text-align: center;
    padding: 50px 30px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

/* Avatar Circle in Left Panel */
.left-avatar {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 25px;
    box-shadow: 0 0 0 4px rgba(255,255,255,0.3), 0 0 0 8px #f39c12, 0 0 30px rgba(243,156,18,0.5);
    transition: all 0.3s ease;
}

.left-avatar:hover {
    transform: scale(1.02);
    box-shadow: 0 0 0 4px rgba(255,255,255,0.4), 0 0 0 12px #f39c12, 0 0 40px rgba(243,156,18,0.7);
}

.left-avatar img {
    width: 95px;
    height: 95px;
    object-fit: contain;
}

.left h2 {
    font-size: 1.8rem;
    margin-bottom: 15px;
    font-weight: 600;
}

.left p {
    font-size: 0.9rem;
    opacity: 0.9;
    line-height: 1.6;
    max-width: 250px;
}

/* RIGHT PANEL */
.right {
    width: 55%;
    background: white;
    padding: 50px 40px;
}

.right h2 {
    font-size: 1.8rem;
    color: #2c3e50;
    margin-bottom: 25px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.right h2 i {
    color: #3498db;
}

.form-group {
    margin-bottom: 20px;
}

.form-group input {
    width: 100%;
    padding: 14px 16px;
    border: 2px solid #e0e7ff;
    border-radius: 12px;
    font-family: 'Poppins', sans-serif;
    font-size: 1rem;
    transition: all 0.3s;
}

.form-group input:focus {
    border-color: #3498db;
    outline: none;
    box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
}

.btn-login {
    width: 100%;
    padding: 14px;
    background: linear-gradient(145deg, #3498db, #2980b9);
    border: none;
    border-radius: 12px;
    color: white;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    margin-top: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-login:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(52,152,219,0.3);
}

.error-message {
    background: #f8d7da;
    color: #721c24;
    padding: 12px;
    border-radius: 10px;
    margin-bottom: 20px;
    border: 1px solid #f5c6cb;
    font-size: 0.9rem;
}

.register-link {
    text-align: center;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 1px solid #e0e7ff;
}

.register-link a {
    color: #3498db;
    text-decoration: none;
    font-weight: 500;
}

.register-link a:hover {
    text-decoration: underline;
}

/* Responsive */
@media (max-width: 900px) {
    .container {
        width: 90%;
        flex-direction: column;
        margin-top: 80px;
    }
    
    .left, .right {
        width: 100%;
    }
    
    .left {
        padding: 40px;
    }
    
    .left-avatar {
        width: 120px;
        height: 120px;
    }
    
    .left-avatar img {
        width: 80px;
        height: 80px;
    }
    
    .navbar {
        padding: 0 20px;
    }
    
    .nav-links {
        gap: 15px;
    }
    
    .nav-links a {
        font-size: 0.9rem;
    }
}

@media (max-width: 600px) {
    .navbar {
        flex-direction: column;
        height: auto;
        padding: 12px 20px;
        gap: 10px;
    }
    
    .nav-left {
        justify-content: center;
    }
    
    .nav-links {
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .container {
        margin-top: 100px;
    }
    
    .right {
        padding: 30px 25px;
    }
}
</style>
</head>

<body>

<!-- NAVBAR -->
<div class="navbar">
    <div class="nav-left">
        <div class="nav-avatar">
            <img src="ccsmainlogo.png" alt="CCS Logo">
        </div>
        <div class="nav-title">
            <strong>CCS</strong>
            <span>Sit-in Monitoring</span>
        </div>
    </div>

    <div class="nav-links">
        <a href="index.php"><i class="fas fa-home"></i> Home</a>
        <a href="login.php" class="active"><i class="fas fa-sign-in-alt"></i> Login</a>
        <a href="register.php"><i class="fas fa-user-plus"></i> Register</a>
    </div>
</div>

<!-- LOGIN CARD -->
<div class="container">
    <div class="left">
        <div class="left-avatar">
            <img src="ccsmainlogo.png" alt="CCS Logo">
        </div>
        <h2>Welcome Back!</h2>
        <p>Track your computer laboratory sittings and manage your sessions efficiently.</p>
    </div>

    <div class="right">
        <h2>
            <i class="fas fa-sign-in-alt"></i> 
            LOGIN
        </h2>

        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <input type="text" name="username" placeholder="Enter Student ID or Email" required>
            </div>
            <div class="form-group">
                <input type="password" name="password" placeholder="Enter Password" required>
            </div>
            <button type="submit" class="btn-login">
                <span>Login</span>
                <i class="fas fa-arrow-right"></i>
            </button>
        </form>

        <div class="register-link">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>
</div>

</body>
</html>