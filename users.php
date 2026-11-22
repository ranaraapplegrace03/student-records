<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notify.php';

function rows($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $data;
}

function set_flash($type, $msg) {
    $_SESSION['flash'] = [$type, $msg];
}

function read_user_form() {
    return [
        'username' => trim($_POST['username'] ?? ''),
        'first'    => trim($_POST['first_name'] ?? ''),
        'last'     => trim($_POST['last_name'] ?? ''),
        'email'    => trim($_POST['email'] ?? ''),
        'password' => $_POST['password'] ?? '',
    ];
}

function check_user_form($d, $conn, $selfId = 0) {
    if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $d['username'])) {
        return 'Username must be 3 to 50 letters, numbers, or underscores.';
    }
    if ($d['first'] === '' || $d['last'] === '') {
        return 'First name and last name are required.';
    }
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    $stmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1");
    $stmt->bind_param("ssi", $d['username'], $d['email'], $selfId);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $taken ? 'That username or email is already used by another account.' : '';
}

$me = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_user') {
            $d = read_user_form();
            $err = check_user_form($d, $conn);
            if ($err === '' && strlen($d['password']) < 6) $err = 'Password must be at least 6 characters.';
            if ($err !== '') {
                set_flash('error', $err);
            } else {
                $hash = password_hash($d['password'], PASSWORD_DEFAULT);
                $full = $d['first'] . ' ' . $d['last'];
                $stmt = $conn->prepare("INSERT INTO users (username, password_hash, first_name, last_name, full_name, email) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssss", $d['username'], $hash, $d['first'], $d['last'], $full, $d['email']);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'User added.');
            }

        } elseif ($action === 'update_user') {
            $id = (int)($_POST['id'] ?? 0);
            $d = read_user_form();
            $err = check_user_form($d, $conn, $id);
            if ($err === '' && $d['password'] !== '' && strlen($d['password']) < 6) {
                $err = 'New password must be at least 6 characters.';
            }
            if ($err !== '') {
                set_flash('error', $err);
            } else {
                $full = $d['first'] . ' ' . $d['last'];
                $stmt = $conn->prepare("UPDATE users SET username = ?, first_name = ?, last_name = ?, full_name = ?, email = ? WHERE id = ?");
                $stmt->bind_param("sssssi", $d['username'], $d['first'], $d['last'], $full, $d['email'], $id);
                $stmt->execute();
                $stmt->close();
                if ($d['password'] !== '') {
                    $hash = password_hash($d['password'], PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->bind_param("si", $hash, $id);
                    $stmt->execute();
                    $stmt->close();
                }
                set_flash('success', 'User updated.');
            }

        } elseif ($action === 'delete_user') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === $me) {
                set_flash('error', 'You cannot delete the account you are signed in with.');
            } else {
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $ok = $stmt->affected_rows > 0;
                $stmt->close();
                set_flash($ok ? 'success' : 'error', $ok ? 'User deleted.' : 'User not found.');
            }
        }
    } catch (mysqli_sql_exception $e) {
        set_flash('error', 'Could not save. Please try again.');
    }
    header("Location: users.php");
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$q = trim($_GET['search'] ?? '');
if ($q !== '') {
    $like = '%' . $q . '%';
    $users = rows($conn, "SELECT id, username, first_name, last_name, full_name, email FROM users WHERE username LIKE ? OR full_name LIKE ? OR email LIKE ? ORDER BY username", 'sss', [$like, $like, $like]);
} else {
    $users = rows($conn, "SELECT id, username, first_name, last_name, full_name, email FROM users ORDER BY username");
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Users - Student Records</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <script src="sidebar.js"></script>
    <script src="logout-confirm.js"></script>
    <script src="toast.js"></script>
</head>
<body class="app">

    <aside class="sidebar">
        <div class="side-brand">
            <svg viewBox="0 0 24 24" fill="currentColor" class="side-cap" aria-hidden="true">
                <path d="M12 3 1 9l11 6 9-4.9V17h2V9L12 3z"/><path d="M5 13v4c0 1.7 3.1 3 7 3s7-1.3 7-3v-4l-7 3.8L5 13z"/>
            </svg>
            <span>Student Records<br>System</span>
        </div>
        <nav class="side-nav">
            <a href="index.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/></svg>
                Dashboard
            </a>
            <a href="students.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M22 20c0-2.6-1.6-4.4-4-5.1"/></svg>
                Students
            </a>
            <a href="courses.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 5h7a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H2z"/><path d="M22 5h-7a3 3 0 0 0-3 3v12a2 2 0 0 1 2-2h8z"/></svg>
                Courses
            </a>
            <a href="reports.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                Reports
            </a>
            <a href="users.php" class="active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                Users
            </a>
            <a href="settings.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/></svg>
                Settings
            </a>
        </nav>
        <a href="logout.php" class="side-logout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>
            Logout
        </a>
    </aside>

    <div class="main">
        <header class="topbar">
            <?php render_notifications($conn); ?>
            <div class="user-menu">
                <span class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['username'], 0, 1))) ?></span>
                <strong><?= htmlspecialchars($_SESSION['username']) ?></strong>
                <a href="logout.php">Log out</a>
            </div>
        </header>

        <main class="content">
            <div class="page-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="#1f5fd6" stroke-width="2" class="title-cap" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M22 20c0-2.6-1.6-4.4-4-5.1"/></svg>
                <div>
                    <h1>Users</h1>
                    <p>Manage the accounts that can sign in to the system.</p>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="toast toast-<?= $flash[0] ?>" data-toast><?= htmlspecialchars($flash[1]) ?></div>
            <?php endif; ?>

            <div class="toolbar">
                <form method="get" class="search-form">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#7a8294" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <input type="text" name="search" placeholder="Search by username, name, or email" value="<?= htmlspecialchars($q) ?>">
                </form>
                <button type="button" class="btn-primary" data-open="addUserModal">+ Add User</button>
            </div>

            <section class="card">
                <table>
                    <thead><tr><th>Username</th><th>Full Name</th><th>Email</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$users): ?>
                        <tr><td colspan="4" class="empty">No users found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($u['username']) ?></strong><?= (int)$u['id'] === $me ? ' <span class="muted">(you)</span>' : '' ?></td>
                            <td><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></td>
                            <td><?= htmlspecialchars($u['email'] ?? '-') ?></td>
                            <td>
                                <div class="actions">
                                    <button type="button" class="btn-edit" data-edit="<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>">Edit</button>
                                    <?php if ((int)$u['id'] !== $me): ?>
                                    <form method="post" class="inline" onsubmit="return confirm('Delete this user account?');">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn-delete">Delete</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </main>
    </div>

    <!-- Add User modal -->
    <div class="modal" id="addUserModal" hidden>
        <div class="modal-box">
            <div class="modal-head"><h3>Add User</h3><button type="button" class="modal-close" data-close aria-label="Close">&times;</button></div>
            <form method="post" class="modal-form">
                <input type="hidden" name="action" value="add_user">
                <label class="full">Username <input type="text" name="username" required></label>
                <label>First Name <input type="text" name="first_name" required></label>
                <label>Last Name <input type="text" name="last_name" required></label>
                <label class="full">Email <input type="email" name="email" required></label>
                <label class="full">Password <input type="password" name="password" placeholder="At least 6 characters" required></label>
                <div class="modal-actions">
                    <button type="button" class="btn-ghost" data-close>Cancel</button>
                    <button type="submit" class="btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User modal -->
    <div class="modal" id="editUserModal" hidden>
        <div class="modal-box">
            <div class="modal-head"><h3>Edit User</h3><button type="button" class="modal-close" data-close aria-label="Close">&times;</button></div>
            <form method="post" class="modal-form">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="id" id="eu-id">
                <label class="full">Username <input type="text" name="username" id="eu-username" required></label>
                <label>First Name <input type="text" name="first_name" id="eu-first" required></label>
                <label>Last Name <input type="text" name="last_name" id="eu-last" required></label>
                <label class="full">Email <input type="email" name="email" id="eu-email" required></label>
                <label class="full">New Password <input type="password" name="password" placeholder="Leave blank to keep the current password"></label>
                <div class="modal-actions">
                    <button type="button" class="btn-ghost" data-close>Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const $ = id => document.getElementById(id);
        const show = id => $(id).hidden = false;
        const hide = m => m.hidden = true;

        document.querySelectorAll('[data-open]').forEach(btn =>
            btn.addEventListener('click', () => show(btn.dataset.open)));

        document.querySelectorAll('[data-edit]').forEach(btn => btn.addEventListener('click', () => {
            const r = JSON.parse(btn.dataset.edit);
            $('eu-id').value = r.id;
            $('eu-username').value = r.username;
            $('eu-first').value = r.first_name || '';
            $('eu-last').value = r.last_name || '';
            $('eu-email').value = r.email || '';
            show('editUserModal');
        }));

        document.querySelectorAll('.modal').forEach(m => m.addEventListener('click', e => {
            if (e.target === m || e.target.closest('[data-close]')) hide(m);
        }));
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') document.querySelectorAll('.modal').forEach(hide);
        });
    </script>
</body>
</html>
