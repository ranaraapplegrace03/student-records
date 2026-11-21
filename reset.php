<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if (empty($_SESSION['reset_user'])) {
    header("Location: forgot.php");
    exit;
}
require_once __DIR__ . '/db.php';

$uid = (int)$_SESSION['reset_user'];
$masked = $_SESSION['reset_email'] ?? 'your email';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code    = trim($_POST['code'] ?? '');
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $conn->prepare("SELECT reset_code_hash, reset_expires, reset_attempts FROM users WHERE id = ?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['reset_code_hash'])) {
        $error = 'There is no active code. Request a new one.';
    } elseif ((int)$row['reset_attempts'] >= 5) {
        $error = 'Too many wrong attempts. Request a new code.';
    } elseif (strtotime($row['reset_expires']) < time()) {
        $error = 'This code has expired. Request a new one.';
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $error = 'Enter the 6-digit code from your email.';
    } elseif (!password_verify($code, $row['reset_code_hash'])) {
        $stmt = $conn->prepare("UPDATE users SET reset_attempts = reset_attempts + 1 WHERE id = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
        $error = 'The code is incorrect.';
    } elseif (strlen($new) < 6) {
        $error = 'The new password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $error = 'The passwords do not match.';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password_hash = ?, reset_code_hash = NULL, reset_expires = NULL, reset_attempts = 0 WHERE id = ?");
        $stmt->bind_param("si", $hash, $uid);
        $stmt->execute();
        $stmt->close();
        unset($_SESSION['reset_user'], $_SESSION['reset_email']);
        header("Location: login.php?reset=1");
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password - Student Records</title>
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
        <h2>Reset Password</h2>
        <p class="sub">Enter the 6-digit code sent to <strong><?= htmlspecialchars($masked) ?></strong>.</p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="reset.php" autocomplete="off">
            <div class="field">
                <input type="text" name="code" placeholder="6-digit code" inputmode="numeric" maxlength="6" required autofocus>
            </div>
            <div class="field">
                <input type="password" name="new_password" placeholder="New password (at least 6 characters)" required>
            </div>
            <div class="field">
                <input type="password" name="confirm_password" placeholder="Confirm new password" required>
            </div>
            <button type="submit" class="login-btn">Change Password</button>
        </form>

        <p class="back-link">Did not get a code? <a href="forgot.php">Request a new one</a></p>
        <p class="back-link"><a href="login.php">Back to log in</a></p>
    </div>
</body>
</html>
