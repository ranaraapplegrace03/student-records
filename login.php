<?php
session_start();
require_once __DIR__ . '/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Please enter your username and password.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header("Location: index.php");
            exit;
        }
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log In - Student Records</title>
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
        <h2>Student Records System</h2>
        <p class="sub">Sign in to your account</p>

        <?php if (isset($_GET['registered'])): ?>
            <div class="success">Account created. You can log in now.</div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="off">
            <div class="field">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>
                </svg>
                <input type="text" name="username" placeholder="Username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
            </div>
            <div class="field">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>
                </svg>
                <input type="password" name="password" id="password" placeholder="Password" required>
                <button type="button" class="toggle" id="togglePw" aria-label="Show password">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                </button>
            </div>
            <button type="submit" class="login-btn">Log In</button>
        </form>

        <div class="divider">or</div>
        <a class="secondary-btn" href="register.php">Create Account</a>
    </div>

    <script>
        const pw = document.getElementById('password');
        const btn = document.getElementById('togglePw');
        btn.addEventListener('click', () => {
            const show = pw.type === 'password';
            pw.type = show ? 'text' : 'password';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    </script>
</body>
</html>
