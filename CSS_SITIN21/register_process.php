<?php
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $id = $_POST['id_number'];
    $fname = $_POST['first_name'];
    $lname = $_POST['last_name'];
    $email = $_POST['email'];
    $pass = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if ($pass != $confirm) {
        $error = "Password not match";
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("INSERT INTO users (id_number, first_name, last_name, email, password) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$id, $fname, $lname, $email, $hash]);

        header("Location: login.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Register</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">

<style>
body {
    font-family: 'Poppins';
    margin: 0;
    background: url('unilogo.png') no-repeat center;
    background-size: cover;
    height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
}

.container {
    display: flex;
    width: 900px;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(0,0,0,0.3);
}

.left {
    width: 50%;
    background: #1e2a38;
    color: white;
    text-align: center;
    padding: 50px;
}

.left img {
    width: 130px;
}

.right {
    width: 50%;
    background: white;
    padding: 30px;
}

.grid {
    display: flex;
    gap: 10px;
}

input {
    width: 100%;
    padding: 10px;
    margin-bottom: 10px;
}
</style>
</head>

<body>

<div class="container">

<div class="left">
    <img src="ccsmainlogo.png">
    <h2>Join Our Community</h2>
</div>

<div class="right">

<h2>Register</h2>

<?php if ($error): ?>
<p style="color:red;"><?php echo $error; ?></p>
<?php endif; ?>

<form method="POST">

<div class="grid">
<input type="text" name="id_number" placeholder="ID Number">
<input type="email" name="email" placeholder="Email">
</div>

<div class="grid">
<input type="text" name="last_name" placeholder="Last Name">
<input type="text" name="first_name" placeholder="First Name">
</div>

<input type="password" name="password" placeholder="Password">
<input type="password" name="confirm_password" placeholder="Confirm Password">

<button>Register</button>

</form>

<p>Already have account? <a href="login.php">Login</a></p>

</div>

</div>

</body>
</html>