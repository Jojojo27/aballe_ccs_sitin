<?php
session_start();
?>

<!DOCTYPE html>
<html>
<head>
<title>CCS Sit-in Monitoring</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
    html { font-size: 13px; zoom: 0.80; }
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Poppins', sans-serif;
    height: 100vh;
    position: relative;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

/* BODY BLUR BACKGROUND */
body::before {
    content: "";
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: url('unilogo.png') no-repeat center;
    background-size: cover;
    filter: blur(8px);
    transform: scale(1.1);
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

.logo-area {
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
    box-shadow: 0 0 0 2px rgba(255,255,255,0.4), 0 0 0 8px #f39c12, 0 0 25px rgba(243,156,18,0.7);
}

.nav-avatar img {
    width: 32px;
    height: 32px;
    object-fit: contain;
}

.logo-text {
    color: white;
    font-weight: 600;
    font-size: 1.1rem;
    letter-spacing: 0.5px;
}

.logo-text span {
    font-size: 0.7rem;
    font-weight: 400;
    opacity: 0.8;
    display: block;
}

.nav-links {
    display: flex;
    gap: 25px;
}

.nav-links a {
    text-decoration: none;
    color: white;
    font-weight: 500;
    transition: 0.3s;
    display: flex;
    align-items: center;
    gap: 5px;
}

.nav-links a:hover {
    color: #f39c12;
}

/* HERO SECTION */
.hero {
    height: calc(100vh - 70px);
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
    margin-top: 70px;
}

.hero-box {
    background: rgba(255, 255, 255, 0.98);
    padding: 45px 40px;
    border-radius: 30px;
    text-align: center;
    width: 550px;
    box-shadow: 0 25px 60px rgba(0,0,0,0.25);
    transition: all 0.3s ease;
    backdrop-filter: blur(2px);
}

.hero-box:hover {
    transform: translateY(-5px);
    box-shadow: 0 30px 70px rgba(0,0,0,0.3);
}

/* Avatar Circle in Hero Box */
.hero-avatar {
    width: 130px;
    height: 130px;
    border-radius: 50%;
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    box-shadow: 0 0 0 4px rgba(52,152,219,0.2), 0 0 0 8px #f39c12, 0 0 30px rgba(243,156,18,0.5);
    transition: all 0.3s ease;
}

.hero-avatar:hover {
    transform: scale(1.02);
    box-shadow: 0 0 0 4px rgba(52,152,219,0.3), 0 0 0 12px #f39c12, 0 0 45px rgba(243,156,18,0.7);
}

.hero-avatar img {
    width: 95px;
    height: 95px;
    object-fit: contain;
}

.hero-box h1 {
    font-size: 1.8rem;
    color: #2c3e50;
    margin-bottom: 15px;
    font-weight: 700;
}

.hero-box p {
    color: #666;
    margin-bottom: 30px;
    font-size: 1rem;
    line-height: 1.6;
}

/* BUTTONS */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 28px;
    border-radius: 50px;
    text-decoration: none;
    margin: 5px;
    font-weight: 600;
    transition: all 0.3s ease;
    font-size: 0.95rem;
}

.btn-login {
    background: linear-gradient(145deg, #3498db, #2980b9);
    color: white;
    box-shadow: 0 4px 15px rgba(52,152,219,0.3);
}

.btn-register {
    background: linear-gradient(145deg, #27ae60, #219a52);
    color: white;
    box-shadow: 0 4px 15px rgba(39,174,96,0.3);
}

.btn:hover {
    transform: translateY(-2px);
    filter: brightness(1.05);
}

.btn-login:hover {
    box-shadow: 0 8px 25px rgba(52,152,219,0.4);
}

.btn-register:hover {
    box-shadow: 0 8px 25px rgba(39,174,96,0.4);
}

/* Admin Link */
.admin-link {
    margin-top: 25px;
    padding-top: 20px;
    border-top: 1px solid #e0e7ff;
}

.admin-link a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.8rem;
    color: #7f8c8d;
    text-decoration: none;
    transition: 0.3s;
}

.admin-link a:hover {
    color: #f39c12;
}

/* FOOTER */
.footer {
    text-align: center;
    padding: 12px;
    background: #2c3e50;
    color: white;
    font-size: 12px;
    position: fixed;
    bottom: 0;
    width: 100%;
}

/* Responsive */
@media (max-width: 768px) {
    .navbar {
        flex-direction: column;
        height: auto;
        padding: 12px 20px;
        gap: 10px;
    }
    
    .logo-area {
        justify-content: center;
    }
    
    .nav-links {
        justify-content: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .hero {
        margin-top: 100px;
        padding: 15px;
    }
    
    .hero-box {
        width: 90%;
        padding: 30px 25px;
    }
    
    .hero-avatar {
        width: 100px;
        height: 100px;
    }
    
    .hero-avatar img {
        width: 70px;
        height: 70px;
    }
    
    .hero-box h1 {
        font-size: 1.4rem;
    }
    
    .btn {
        padding: 10px 20px;
        font-size: 0.85rem;
    }
}

@media (max-width: 480px) {
    .hero-box h1 {
        font-size: 1.2rem;
    }
    
    .hero-box p {
        font-size: 0.85rem;
    }
    
    .btn {
        padding: 8px 16px;
        font-size: 0.8rem;
    }
}
</style>
</head>

<body>

<!-- NAVBAR -->
<div class="navbar">
    <div class="logo-area">
        <div class="nav-avatar">
            <img src="ccsmainlogo.png" alt="CCS Logo">
        </div>
        <div class="logo-text">
            CCS Sit-in Monitoring
            <span>Track | Manage | Succeed</span>
        </div>
    </div>

    <div class="nav-links">
        <a href="index.php"><i class="fas fa-home"></i> Home</a>
        <a href="login.php"><i class="fas fa-sign-in-alt"></i> Login</a>
        <a href="register.php"><i class="fas fa-user-plus"></i> Register</a>
    </div>
</div>

<!-- HERO SECTION -->
<div class="hero">
    <div class="hero-box">
        <div class="hero-avatar">
            <img src="ccsmainlogo.png" alt="CCS Logo">
        </div>
        
        <h1>Welcome to CCS Sit-in Monitoring</h1>
        <p>Track and manage your laboratory sessions easily and efficiently. Monitor your progress, reserve computer units, and stay updated with announcements.</p>

        <a href="login.php" class="btn btn-login">
            <i class="fas fa-sign-in-alt"></i> Student Login
        </a>

        <a href="register.php" class="btn btn-register">
            <i class="fas fa-user-plus"></i> Student Register
        </a>

        <div class="admin-link">
            <a href="admin_login.php">
                
            </a>
        </div>
    </div>
</div>

<!-- FOOTER -->
<div class="footer">
    <i class="fas fa-laptop-code"></i> © 2026 CCS Sit-in Monitoring System | University of Cebu
</div>

</body>
</html>