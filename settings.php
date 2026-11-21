<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notify.php';

// Backups are kept outside the web folder so they can't be opened from a browser
const BACKUP_DIR = '/var/www/student-backups';

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

function build_backup($conn) {
    return [
        'app'          => 'Student Records System',
        'created'      => date('c'),
        'courses'      => rows($conn, "SELECT code, name, status FROM courses"),
        'course_years' => rows($conn, "SELECT c.code AS course_code, cy.year_level FROM course_years cy JOIN courses c ON c.id = cy.course_id"),
        'sections'     => rows($conn, "SELECT c.code AS course_code, cy.year_level, s.name FROM sections s JOIN course_years cy ON cy.id = s.course_year_id JOIN courses c ON c.id = cy.course_id"),
        'students'     => rows($conn, "SELECT student_id, first_name, last_name, course, section, year_level, status FROM students"),
        'settings'     => rows($conn, "SELECT setting_key, setting_value FROM settings"),
    ];
}

function backup_files() {
    if (!is_dir(BACKUP_DIR)) return [];
    $files = glob(BACKUP_DIR . '/StudentRecords_Backup_*.json') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files;
}

function valid_backup_name($name) {
    return (bool)preg_match('/^StudentRecords_Backup_[0-9_\-]+\.json$/', $name);
}

function human_size($bytes) {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    return number_format($bytes / 1024, 1) . ' KB';
}

$tabs = ['account' => 'Account', 'system' => 'System', 'backup' => 'Backup & Restore', 'about' => 'About'];
$uid = (int)$_SESSION['user_id'];

// Download a saved backup
if (isset($_GET['download'])) {
    $name = basename((string)$_GET['download']);
    $path = BACKUP_DIR . '/' . $name;
    if (valid_backup_name($name) && is_file($path)) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
    set_flash('error', 'That backup file was not found.');
    header("Location: settings.php?tab=backup");
    exit;
}

$defaults = [
    'school_name'       => 'Student Records System',
    'academic_term'     => 'SY 2026 - 2027',
    'grading_scale'     => '1.00 - 5.00',
    'student_id_format' => 'YYYY-NNNN',
    'notifications'     => '1',
];
$cfg = $defaults;
foreach (rows($conn, "SELECT setting_key, setting_value FROM settings") as $r) {
    $cfg[$r['setting_key']] = $r['setting_value'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tab = 'account';

    try {
        if ($action === 'account_profile') {
            $tab = 'account';
            $full = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                set_flash('error', 'Please enter a valid email address.');
            } else {
                $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
                $stmt->bind_param("ssi", $full, $email, $uid);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Account information saved.');
            }

        } elseif ($action === 'account_password') {
            $tab = 'account';
            $current = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            $row = rows($conn, "SELECT password_hash FROM users WHERE id = ?", 'i', [$uid])[0] ?? null;
            if (!$row || !password_verify($current, $row['password_hash'])) {
                set_flash('error', 'The current password is incorrect.');
            } elseif (strlen($new) < 6) {
                set_flash('error', 'The new password must be at least 6 characters.');
            } elseif ($new !== $confirm) {
                set_flash('error', 'The new passwords do not match.');
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->bind_param("si", $hash, $uid);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Password changed.');
            }

        } elseif ($action === 'system') {
            $tab = 'system';
            $values = [
                'school_name'       => trim($_POST['school_name'] ?? ''),
                'academic_term'     => trim($_POST['academic_term'] ?? ''),
                'grading_scale'     => trim($_POST['grading_scale'] ?? ''),
                'student_id_format' => trim($_POST['student_id_format'] ?? ''),
                'notifications'     => isset($_POST['notifications']) ? '1' : '0',
            ];
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ($values as $k => $v) {
                $stmt->bind_param("ss", $k, $v);
                $stmt->execute();
            }
            $stmt->close();
            set_flash('success', 'System settings saved.');

        } elseif ($action === 'create_backup') {
            $tab = 'backup';
            if (!is_dir(BACKUP_DIR) && !@mkdir(BACKUP_DIR, 0750, true)) {
                set_flash('error', 'The backup folder is missing. Ask your administrator to create ' . BACKUP_DIR . '.');
            } else {
                $name = 'StudentRecords_Backup_' . date('Y-m-d_His') . '.json';
                $json = json_encode(build_backup($conn), JSON_PRETTY_PRINT);
                if (file_put_contents(BACKUP_DIR . '/' . $name, $json) === false) {
                    set_flash('error', 'Could not save the backup. Check the folder permissions.');
                } else {
                    set_flash('success', 'Backup created: ' . $name);
                }
            }

        } elseif ($action === 'delete_backup') {
            $tab = 'backup';
            $name = basename((string)($_POST['file'] ?? ''));
            $path = BACKUP_DIR . '/' . $name;
            if (valid_backup_name($name) && is_file($path) && unlink($path)) {
                set_flash('success', 'Backup deleted.');
            } else {
                set_flash('error', 'Could not delete that backup.');
            }

        } elseif ($action === 'restore') {
            $tab = 'backup';
            $file = $_FILES['backup_file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                set_flash('error', 'Please choose a backup file to restore.');
            } else {
                $data = json_decode(file_get_contents($file['tmp_name']), true);
                if (!is_array($data) || ($data['app'] ?? '') !== 'Student Records System') {
                    set_flash('error', 'That file is not a valid Student Records backup.');
                } else {
                    $counts = ['courses' => 0, 'year levels' => 0, 'sections' => 0, 'students' => 0];
                    $conn->begin_transaction();
                    try {
                        $s = $conn->prepare("INSERT IGNORE INTO courses (code, name, status) VALUES (?, ?, ?)");
                        foreach ($data['courses'] ?? [] as $c) {
                            $s->bind_param("sss", $c['code'], $c['name'], $c['status']);
                            $s->execute();
                            $counts['courses'] += $s->affected_rows;
                        }
                        $s = $conn->prepare("INSERT IGNORE INTO course_years (course_id, year_level) SELECT id, ? FROM courses WHERE code = ?");
                        foreach ($data['course_years'] ?? [] as $y) {
                            $s->bind_param("is", $y['year_level'], $y['course_code']);
                            $s->execute();
                            $counts['year levels'] += $s->affected_rows;
                        }
                        $s = $conn->prepare("INSERT IGNORE INTO sections (course_year_id, name) SELECT cy.id, ? FROM course_years cy JOIN courses c ON c.id = cy.course_id WHERE c.code = ? AND cy.year_level = ?");
                        foreach ($data['sections'] ?? [] as $sec) {
                            $s->bind_param("ssi", $sec['name'], $sec['course_code'], $sec['year_level']);
                            $s->execute();
                            $counts['sections'] += $s->affected_rows;
                        }
                        $s = $conn->prepare("INSERT IGNORE INTO students (student_id, first_name, last_name, course, section, year_level, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        foreach ($data['students'] ?? [] as $st) {
                            $s->bind_param("sssssis", $st['student_id'], $st['first_name'], $st['last_name'], $st['course'], $st['section'], $st['year_level'], $st['status']);
                            $s->execute();
                            $counts['students'] += $s->affected_rows;
                        }
                        $s = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                        foreach ($data['settings'] ?? [] as $set) {
                            $s->bind_param("ss", $set['setting_key'], $set['setting_value']);
                            $s->execute();
                        }
                        $conn->commit();
                        set_flash('success', 'Restore complete. Added: ' . $counts['courses'] . ' courses, ' . $counts['year levels'] . ' year levels, ' . $counts['sections'] . ' sections, ' . $counts['students'] . ' students.');
                    } catch (Throwable $e) {
                        $conn->rollback();
                        set_flash('error', 'Restore failed and no changes were saved.');
                    }
                }
            }
        }
    } catch (mysqli_sql_exception $e) {
        set_flash('error', 'Could not save those changes. Please try again.');
    }

    header("Location: settings.php?tab=" . $tab);
    exit;
}

$tab = $_GET['tab'] ?? 'account';
if (!isset($tabs[$tab])) $tab = 'account';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$me = rows($conn, "SELECT username, full_name, email FROM users WHERE id = ?", 'i', [$uid])[0];
$fullName = $me['full_name'] ?: $me['username'];
$backups = $tab === 'backup' ? backup_files() : [];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings - Student Records</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
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
            <a href="settings.php" class="active">
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
                <svg viewBox="0 0 24 24" fill="none" stroke="#1f5fd6" stroke-width="2" class="title-cap" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/></svg>
                <div>
                    <h1>Settings</h1>
                    <p>Manage system preferences, account information, and application settings.</p>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="flash flash-<?= $flash[0] ?>"><?= htmlspecialchars($flash[1]) ?></div>
            <?php endif; ?>

            <div class="settings-layout">
                <aside class="sub-nav card">
                    <?php
                    $icons = [
                        'account' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
                        'system'  => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
                        'backup'  => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
                        'about'   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
                    ];
                    foreach ($tabs as $key => $label): ?>
                        <a href="settings.php?tab=<?= $key ?>" class="<?= $tab === $key ? 'active' : '' ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?= $icons[$key] ?></svg>
                            <?= $label ?>
                        </a>
                    <?php endforeach; ?>
                </aside>

                <section class="card settings-panel">
                <?php if ($tab === 'account'): ?>
                    <div class="panel-head">
                        <h2>Account Settings</h2>
                        <p>Update your account information and password.</p>
                    </div>
                    <div class="account-top">
                        <div class="profile-pic">
                            <span><?= htmlspecialchars(strtoupper(substr($fullName, 0, 1))) ?></span>
                        </div>
                        <form method="post" class="form-grid">
                            <input type="hidden" name="action" value="account_profile">
                            <label>Username <input type="text" value="<?= htmlspecialchars($me['username']) ?>" readonly class="readonly"></label>
                            <label>Full Name <input type="text" name="full_name" value="<?= htmlspecialchars($me['full_name'] ?? '') ?>"></label>
                            <label class="full">Email <input type="email" name="email" value="<?= htmlspecialchars($me['email'] ?? '') ?>"></label>
                            <div class="panel-actions full">
                                <button type="submit" class="btn-primary">Save Changes</button>
                            </div>
                        </form>
                    </div>

                    <div class="panel-divider"></div>

                    <div class="panel-head">
                        <h2>Change Password</h2>
                        <p>Update your password to keep your account secure.</p>
                    </div>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="action" value="account_password">
                        <label class="full">Current Password <input type="password" name="current_password" required></label>
                        <label>New Password <input type="password" name="new_password" required></label>
                        <label>Confirm Password <input type="password" name="confirm_password" required></label>
                        <div class="panel-actions full">
                            <button type="submit" class="btn-primary">Update Password</button>
                        </div>
                    </form>

                <?php elseif ($tab === 'system'): ?>
                    <div class="panel-head">
                        <h2>System Settings</h2>
                        <p>Configure general system preferences and application behavior.</p>
                    </div>
                    <div class="setting-row">
                        <div><strong>System Theme</strong><span>Choose the appearance. This applies in this browser.</span></div>
                        <div class="theme-buttons">
                            <button type="button" class="theme-btn" data-theme-choice="light">Light</button>
                            <button type="button" class="theme-btn" data-theme-choice="dark">Dark</button>
                            <button type="button" class="theme-btn" data-theme-choice="system">System</button>
                        </div>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="system">
                        <div class="setting-row">
                            <div><strong>School Information</strong><span>The school name shown across the system.</span></div>
                            <input type="text" name="school_name" value="<?= htmlspecialchars($cfg['school_name']) ?>">
                        </div>
                        <div class="setting-row">
                            <div><strong>Academic Term</strong><span>The current academic term, for example SY 2026 - 2027.</span></div>
                            <input type="text" name="academic_term" value="<?= htmlspecialchars($cfg['academic_term']) ?>">
                        </div>
                        <div class="setting-row">
                            <div><strong>Grading Scale</strong><span>Saved for future grade reports. Not used yet.</span></div>
                            <input type="text" name="grading_scale" value="<?= htmlspecialchars($cfg['grading_scale']) ?>">
                        </div>
                        <div class="setting-row">
                            <div><strong>Student ID Format</strong><span>The format used for student IDs.</span></div>
                            <input type="text" name="student_id_format" value="<?= htmlspecialchars($cfg['student_id_format']) ?>">
                        </div>
                        <div class="setting-row">
                            <div><strong>Notifications</strong><span>Show the bell in the top bar with alerts for the system.</span></div>
                            <label class="switch">
                                <input type="checkbox" name="notifications" value="1" <?= $cfg['notifications'] === '1' ? 'checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="panel-actions">
                            <a href="settings.php?tab=system" class="btn-ghost">Reset</a>
                            <button type="submit" class="btn-primary">Save Changes</button>
                        </div>
                    </form>

                <?php elseif ($tab === 'backup'): ?>
                    <div class="panel-head">
                        <h2>Backup &amp; Restore</h2>
                        <p>Create a backup of your data or restore the system from a backup file.</p>
                    </div>
                    <div class="backup-grid">
                        <form method="post" class="backup-card blue">
                            <input type="hidden" name="action" value="create_backup">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#1f5fd6" stroke-width="1.8" class="big-icon"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>
                            <h3>Backup Data</h3>
                            <p>Save a backup copy of the student records, courses, and system settings.</p>
                            <button type="submit" class="btn-primary wide">Create Backup</button>
                        </form>
                        <form method="post" enctype="multipart/form-data" class="backup-card green" onsubmit="return confirm('Restore this backup? Records in the file that are missing will be added. Existing records are kept.');">
                            <input type="hidden" name="action" value="restore">
                            <svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="1.8" class="big-icon"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>
                            <h3>Restore Data</h3>
                            <p>Restore the system using a previously created backup file.</p>
                            <input type="file" name="backup_file" accept=".json,application/json" required class="file-input">
                            <button type="submit" class="btn-restore wide">Restore</button>
                        </form>
                    </div>

                    <div class="panel-divider"></div>
                    <div class="panel-head">
                        <h2>Backup History</h2>
                        <p>List of recent backup files.</p>
                    </div>
                    <table class="history">
                        <thead><tr><th>File Name</th><th>Date Created</th><th>File Size</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php if (!$backups): ?>
                            <tr><td colspan="4" class="empty">No backups yet. Click "Create Backup" to make one.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($backups as $file):
                            $name = basename($file); ?>
                            <tr>
                                <td><?= htmlspecialchars($name) ?></td>
                                <td><?= date('M d, Y g:i A', filemtime($file)) ?></td>
                                <td><?= human_size(filesize($file)) ?></td>
                                <td>
                                    <div class="actions">
                                        <a href="settings.php?download=<?= urlencode($name) ?>" class="btn-view">Download</a>
                                        <form method="post" class="inline" onsubmit="return confirm('Delete this backup file?');">
                                            <input type="hidden" name="action" value="delete_backup">
                                            <input type="hidden" name="file" value="<?= htmlspecialchars($name) ?>">
                                            <button type="submit" class="btn-delete">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php else: ?>
                    <div class="panel-head">
                        <h2>About</h2>
                        <p>Information about the Student Records System.</p>
                    </div>
                    <div class="about-hero">
                        <svg viewBox="0 0 24 24" fill="currentColor" class="about-cap" aria-hidden="true">
                            <path d="M12 3 1 9l11 6 9-4.9V17h2V9L12 3z"/><path d="M5 13v4c0 1.7 3.1 3 7 3s7-1.3 7-3v-4l-7 3.8L5 13z"/>
                        </svg>
                        <div>
                            <h3><?= htmlspecialchars($cfg['school_name']) ?></h3>
                            <span class="version">Version 1.0.0</span>
                            <p>A mini system for managing student information, courses, and academic records.</p>
                        </div>
                    </div>
                    <dl class="about-list">
                        <div><dt>System Name</dt><dd>Student Records System</dd></div>
                        <div><dt>Version</dt><dd>1.0.0</dd></div>
                        <div><dt>Release Date</dt><dd>Nov 21, 2026</dd></div>
                        <div><dt>Developed By</dt><dd>BSIT 4C &ndash; Group Project</dd></div>
                        <div><dt>Description</dt><dd>A simple and user-friendly system for managing student records, courses, and reports.</dd></div>
                    </dl>
                    <div class="about-footer">&copy; 2026 Student Records System. All rights reserved.</div>
                <?php endif; ?>
                </section>
            </div>
        </main>
    </div>

    <script>
        const current = window.getThemeChoice();
        const mark = () => document.querySelectorAll('[data-theme-choice]').forEach(b =>
            b.classList.toggle('active', b.dataset.themeChoice === window.getThemeChoice()));
        document.querySelectorAll('[data-theme-choice]').forEach(b =>
            b.addEventListener('click', () => { window.setThemeChoice(b.dataset.themeChoice); mark(); }));
        mark();
    </script>
</body>
</html>
