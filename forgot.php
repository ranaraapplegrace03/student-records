<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

function mask_email($email) {
    [$local, $domain] = explode('@', $email, 2);
    return substr($local, 0, 1) . str_repeat('*', max(3, strlen($local) - 1)) . '@' . $domain;
}

$error = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if ($identifier === '') {
        $error = 'Please enter your username or email.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->bind_param("ss", $identifier, $identifier);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $error = 'No account matches that username or email.';
        } elseif (empty($user['email'])) {
            $error = 'This account has no email on file. Ask the administrator to reset your password.';
        } else {
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = password_hash($code, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET reset_code_hash = ?, reset_expires = DATE_ADD(NOW(), INTERVAL 10 MINUTE), reset_attempts = 0 WHERE id = ?");
            $stmt->bind_param("si", $hash, $user['id']);
            $stmt->execute();
            $stmt->close();

            try {
                send_mail(
                    $user['email'],
                    'Your password reset code',
                    "Hello " . $user['username'] . ",\n\nYour password reset code is: " . $code . "\n\n"
                    . "The code expires in 10 minutes. If you did not ask to reset your password, you can ignore this email.\n\n"
                    . "Student Records System"
                );
                $_SESSION['reset_user'] = (int)$user['id'];
                $_SESSION['reset_email'] = mask_email($user['email']);
                header("Location: reset.php");
                exit;
            } catch (Throwable $e) {
                error_log('Password reset email failed: ' . $e->getMessage());
                $error = 'We could not send the code. Check the email settings and try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password - Student Records</title>
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
        <h2>Forgot Password</h2>
        <p class="sub">Enter your username or email. We will send a 6-digit code to the email on your account.</p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="forgot.php" autocomplete="off">
            <div class="field">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>
                </svg>
                <input type="text" name="identifier" placeholder="Username or email"
                       value="<?= htmlspecialchars($identifier) ?>" required autofocus>
            </div>
            <button type="submit" class="login-btn">Send Code</button>
        </form>

        <p class="back-link"><a href="login.php">Back to log in</a></p>
    </div>
</body>
</html>
