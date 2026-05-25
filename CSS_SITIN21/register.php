<?php
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $id = trim($_POST['id_number']);
    $fname = trim($_POST['first_name']);
    $lname = trim($_POST['last_name']);
    $mname = trim($_POST['middle_name']);
    $course = $_POST['course'];
    $year = $_POST['year_level'];
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $pass = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    // VALIDATION
    if (empty($id) || empty($fname) || empty($lname) || empty($email) || empty($pass)) {
        $error = "Please fill in all required fields";
    } elseif ($pass !== $confirm) {
        $error = "Passwords do not match";
    } elseif (strlen($pass) < 6) {
        $error = "Password must be at least 6 characters";
    } else {

        // CHECK ID
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id_number = ?");
        $stmt->execute([$id]);

        if ($stmt->fetch()) {
            $error = "ID Number already registered!";
        } else {

            // CHECK EMAIL
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $error = "Email already registered!";
            } else {

                // INSERT USER
                $hash = password_hash($pass, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("INSERT INTO users (id_number, first_name, last_name, middle_name, course, year_level, email, address, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $stmt->execute([$id, $fname, $lname, $mname, $course, $year, $email, $address, $hash]);

                $_SESSION['success'] = "Registration successful! Please login.";
                header("Location: login.php");
                exit();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Register - CCS Sit-in Monitoring</title>
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

/* BLUR BACKGROUND */
body::before {
    content: "";
    position: fixed;
    width: 100%;
    height: 100%;
    background: url('unilogo.png') no-repeat center;
    background-size: cover;
    filter: blur(10px);
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
    box-shadow: 0 0 0 2px rgba(255,255,255,0.4), 0 0 0 8px #f39c12, 0 0 25px rgba(243,156,18,0.7);
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
    font-size: 1.2rem;
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

/* CONTAINER */
.container {
    display: flex;
    width: 1000px;
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
    width: 40%;
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
    box-shadow: 0 0 0 4px rgba(255,255,255,0.4), 0 0 0 12px #f39c12, 0 0 45px rgba(243,156,18,0.7);
}

.left-avatar img {
    width: 95px;
    height: 95px;
    object-fit: contain;
}

.left h2 {
    font-size: 1.6rem;
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
    width: 60%;
    background: white;
    padding: 35px 35px;
}

.right h2 {
    font-size: 1.6rem;
    color: #2c3e50;
    margin-bottom: 20px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.right h2 i {
    color: #3498db;
}

/* Form Grid */
.grid {
    display: flex;
    gap: 15px;
    margin-bottom: 12px;
}

.grid-full {
    margin-bottom: 12px;
}

/* Input Styles */
.input-box {
    position: relative;
    width: 100%;
}

input, select {
    width: 100%;
    padding: 12px 14px;
    border: 2px solid #e0e7ff;
    border-radius: 12px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.9rem;
    transition: all 0.3s;
    background: #f8faff;
}

input:focus, select:focus {
    border-color: #3498db;
    outline: none;
    background: white;
    box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
}

/* Eye Icon */
.eye {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #95a5a6;
    transition: 0.3s;
}

.eye:hover {
    color: #3498db;
}

/* Button */
button {
    width: 100%;
    padding: 14px;
    background: linear-gradient(145deg, #27ae60, #219a52);
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

button:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(39,174,96,0.3);
}

/* Error Message */
.error-message {
    background: #f8d7da;
    color: #721c24;
    padding: 12px;
    border-radius: 10px;
    margin-bottom: 20px;
    border: 1px solid #f5c6cb;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Login Link */
.login-link {
    text-align: center;
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #e0e7ff;
}

.login-link a {
    color: #3498db;
    text-decoration: none;
    font-weight: 500;
}

.login-link a:hover {
    text-decoration: underline;
}

/* Responsive */
@media (max-width: 950px) {
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
    
    .grid {
        flex-direction: column;
        gap: 0;
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
        padding: 25px;
    }
    
    .right h2 {
        font-size: 1.3rem;
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
        <a href="login.php"><i class="fas fa-sign-in-alt"></i> Login</a>
        <a href="register.php" class="active"><i class="fas fa-user-plus"></i> Register</a>
    </div>
</div>

<!-- REGISTER CARD -->
<div class="container">
    <div class="left">
        <div class="left-avatar">
            <img src="ccsmainlogo.png" alt="CCS Logo">
        </div>
        <h2>Join Our Community</h2>
        <p>Create your account to start monitoring your computer laboratory sittings.</p>
    </div>

    <div class="right">
        <h2>
            <i class="fas fa-user-plus"></i>
            REGISTER YOUR ACCOUNT
        </h2>

        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="grid">
                <input type="text" name="id_number" placeholder="ID Number" required>
                <select name="course" required>
                    <option value="">Select Course</option>
                    <option value="BSIT">BSIT</option>
                    <option value="BSCS">BSCS</option>
                    <option value="BSIS">BSIS</option>
                </select>
            </div>

            <div class="grid">
                <input type="text" name="last_name" placeholder="Last Name" required>
                <input type="text" name="first_name" placeholder="First Name" required>
            </div>

            <div class="grid-full">
                <input type="text" name="middle_name" placeholder="Middle Name (optional)">
            </div>

            <div class="grid">
                <select name="year_level" required>
                    <option value="">Select Year Level</option>
                    <option value="1">1st Year</option>
                    <option value="2">2nd Year</option>
                    <option value="3">3rd Year</option>
                    <option value="4">4th Year</option>
                </select>
                <input type="email" name="email" placeholder="Email Address" required>
            </div>

            <div class="grid-full">
                <input type="text" name="address" placeholder="Address" required>
            </div>

            <div class="grid">
                <div class="input-box">
                    <input type="password" id="password" name="password" placeholder="Password (min. 6 characters)" required>
                    <i class="fas fa-eye eye" onclick="togglePassword('password', this)"></i>
                </div>

                <div class="input-box">
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required>
                    <i class="fas fa-eye eye" onclick="togglePassword('confirm_password', this)"></i>
                </div>
            </div>

            <button type="submit">
                <i class="fas fa-user-plus"></i> Register
            </button>
        </form>

        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>
</div>

<!-- SCRIPT -->
<script>
function togglePassword(id, icon) {
    const input = document.getElementById(id);

    if (input.type === "password") {
        input.type = "text";
        icon.classList.replace("fa-eye", "fa-eye-slash");
    } else {
        input.type = "password";
        icon.classList.replace("fa-eye-slash", "fa-eye");
    }
}
</script>

</body>
</html>