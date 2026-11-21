<?php
session_start();
require_once __DIR__ . '/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
        $error = "Username must be 3 to 50 letters, numbers, or underscores.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($exists) {
            $error = "That username is already taken.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
            $stmt->bind_param("ss", $username, $hash);
            if ($stmt->execute()) {
                $stmt->close();
                header("Location: login.php?registered=1");
                exit;
            }
            $error = "Could not create the account. Please try again.";
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
            <path d="M12 3 1 9l11 6 9-4.9V17h2V9L12 3z"/>
            <path d="M5 13v4c0 1.7 3.1 3 7 3s7-1.3 7-3v-4l-7 3.8L5 13z"/>
        </svg>
        <h2>Create Account</h2>
        <p class="sub">Sign up to manage student records</p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="register.php" autocomplete="off">
            <div class="field">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>
                </svg>
                <input type="text" name="username" placeholder="Username (letters, numbers, _)"
                       value="<?= htmlspecialchars($username) ?>" required autofocus>
            </div>
            <div class="field">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                </svg>
                <input type="password" name="password" placeholder="Password (at least 6 characters)" required>
            </div>
            <div class="field">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                </svg>
                <input type="password" name="confirm" placeholder="Confirm Password" required>
            </div>
            <button type="submit" class="login-btn">Create Account</button>
        </form>

        <p class="back-link">Already have an account? <a href="login.php">Log in</a></p>
    </div>
</body>
</html>
