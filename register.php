<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once __DIR__ . '/db.php';

$error = '';
$v = ['username' => '', 'first_name' => '', 'last_name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v['username']   = trim($_POST['username'] ?? '');
    $v['first_name'] = trim($_POST['first_name'] ?? '');
    $v['last_name']  = trim($_POST['last_name'] ?? '');
    $v['email']      = trim($_POST['email'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirm         = $_POST['confirm'] ?? '';

    if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $v['username'])) {
        $error = 'Username must be 3 to 50 letters, numbers, or underscores.';
    } elseif ($v['first_name'] === '' || $v['last_name'] === '') {
        $error = 'First name and last name are required.';
    } elseif (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $v['username'], $v['email']);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($exists) {
            $error = 'That username or email is already in use.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $full = $v['first_name'] . ' ' . $v['last_name'];
            $stmt = $conn->prepare("INSERT INTO users (username, password_hash, first_name, last_name, full_name, email) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $v['username'], $hash, $v['first_name'], $v['last_name'], $full, $v['email']);
            if ($stmt->execute()) {
                $stmt->close();
                header("Location: login.php?registered=1");
                exit;
            }
            $error = 'Could not create the account. Please try again.';
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Account - Student Records</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
    <div class="brand">
        <h1>Student Records System</h1>
        <p>A Mini Student Records Management System</p>
        <div class="bar"></div>
    </div>

    <div class="login-card">
        <svg class="cap" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 3 1 9l11 6 9-4.9V17h2V9L12 3z"/><path d="M5 13v4c0 1.7 3.1 3 7 3s7-1.3 7-3v-4l-7 3.8L5 13z"/>
        </svg>
        <h2>Create Account</h2>
        <p class="sub">Sign up to manage student records</p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="register.php" autocomplete="off">
            <div class="field">
                <input type="text" name="username" placeholder="Username (letters, numbers, _)" value="<?= htmlspecialchars($v['username']) ?>" required autofocus>
            </div>
            <div class="field">
                <input type="text" name="first_name" placeholder="First name" value="<?= htmlspecialchars($v['first_name']) ?>" required>
            </div>
            <div class="field">
                <input type="text" name="last_name" placeholder="Last name" value="<?= htmlspecialchars($v['last_name']) ?>" required>
            </div>
            <div class="field">
                <input type="email" name="email" placeholder="Email (for password reset)" value="<?= htmlspecialchars($v['email']) ?>" required>
            </div>
            <div class="field">
                <input type="password" name="password" placeholder="Password (at least 6 characters)" required>
            </div>
            <div class="field">
                <input type="password" name="confirm" placeholder="Confirm password" required>
            </div>
            <button type="submit" class="login-btn">Create Account</button>
        </form>

        <p class="back-link">Already have an account? <a href="login.php">Log in</a></p>
    </div>
</body>
</html>
