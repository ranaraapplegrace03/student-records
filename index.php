<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/db.php';

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

// Handle add and delete, then redirect so a refresh doesn't resubmit the form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        $_SESSION['flash'] = $ok ? ['success', 'Student deleted.'] : ['error', 'Student not found.'];
    } else {
        $student_id = trim($_POST['student_id'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $course     = trim($_POST['course'] ?? '');
        $year_level = (int)($_POST['year_level'] ?? 1);

        if ($student_id === '' || $first_name === '' || $last_name === '') {
            $_SESSION['flash'] = ['error', 'Student ID, first name, and last name are required.'];
        } else {
            $stmt = $conn->prepare("INSERT INTO students (student_id, first_name, last_name, course, year_level) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssi", $student_id, $first_name, $last_name, $course, $year_level);
            if ($stmt->execute()) {
                $_SESSION['flash'] = ['success', 'Student added.'];
            } else {
                $_SESSION['flash'] = ['error', 'That Student ID already exists.'];
            }
            $stmt->close();
        }
    }
    header("Location: index.php");
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Dashboard numbers
$totalAll  = rows($conn, "SELECT COUNT(*) AS c FROM students")[0]['c'];
$courses   = rows($conn, "SELECT COUNT(DISTINCT course) AS c FROM students WHERE course <> ''")[0]['c'];
$firstYear = rows($conn, "SELECT COUNT(*) AS c FROM students WHERE year_level = 1")[0]['c'];
$upper     = rows($conn, "SELECT COUNT(*) AS c FROM students WHERE year_level >= 3")[0]['c'];

// Search and pagination
$search  = trim($_GET['search'] ?? '');
$perPage = 10;
$where   = '';
$types   = '';
$params  = [];
if ($search !== '') {
    $where  = "WHERE student_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR course LIKE ?";
    $like   = "%" . $search . "%";
    $types  = 'ssss';
    $params = [$like, $like, $like, $like];
}

$total = rows($conn, "SELECT COUNT(*) AS c FROM students $where", $types, $params)[0]['c'];
$pages = max(1, (int)ceil($total / $perPage));
$page  = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;

$students = rows(
    $conn,
    "SELECT id, student_id, first_name, last_name, course, year_level FROM students $where ORDER BY last_name, first_name LIMIT $perPage OFFSET $offset",
    $types,
    $params
);

$showStart = $total ? $offset + 1 : 0;
$showEnd   = min($offset + $perPage, $total);

function page_url($p, $search) {
    return 'index.php?' . http_build_query(['search' => $search, 'page' => $p]);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Records</title>
    <link rel="stylesheet" href="style.css">
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
            <a href="index.php" class="active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/></svg>
                Dashboard
            </a>
            <a href="index.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M22 20c0-2.6-1.6-4.4-4-5.1"/></svg>
                Students
            </a>
            <span class="disabled">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                Reports
            </span>
            <span class="disabled">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/></svg>
                Settings
            </span>
        </nav>
        <a href="logout.php" class="side-logout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>
            Logout
        </a>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="user-menu">
                <span class="avatar"><?= htmlspecialchars(strtoupper(substr($_SESSION['username'], 0, 1))) ?></span>
                <strong><?= htmlspecialchars($_SESSION['username']) ?></strong>
                <a href="logout.php">Log out</a>
            </div>
        </header>

        <main class="content">
            <div class="page-title">
                <svg viewBox="0 0 24 24" fill="currentColor" class="title-cap" aria-hidden="true">
                    <path d="M12 3 1 9l11 6 9-4.9V17h2V9L12 3z"/><path d="M5 13v4c0 1.7 3.1 3 7 3s7-1.3 7-3v-4l-7 3.8L5 13z"/>
                </svg>
                <div>
                    <h1>Student Records System</h1>
                    <p>Manage student information and academic records.</p>
                </div>
            </div>

            <section class="stats">
                <div class="stat blue">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M22 20c0-2.6-1.6-4.4-4-5.1"/></svg>
                    </div>
                    <div><span>Total Students</span><strong><?= $totalAll ?></strong></div>
                </div>
                <div class="stat green">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="#16a34a"><path d="M12 3 1 9l11 6 9-4.9V17h2V9L12 3z"/><path d="M5 13v4c0 1.7 3.1 3 7 3s7-1.3 7-3v-4l-7 3.8L5 13z"/></svg>
                    </div>
                    <div><span>Courses</span><strong><?= $courses ?></strong></div>
                </div>
                <div class="stat orange">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><circle cx="10" cy="8" r="3.5"/><path d="M3 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M19 8v6M16 11h6"/></svg>
                    </div>
                    <div><span>First Year</span><strong><?= $firstYear ?></strong></div>
                </div>
                <div class="stat purple">
                    <div class="stat-icon">
                        <svg viewBox="0 0 24 24" fill="#7c3aed"><rect x="3" y="12" width="4" height="9" rx="1"/><rect x="10" y="7" width="4" height="14" rx="1"/><rect x="17" y="3" width="4" height="18" rx="1"/></svg>
                    </div>
                    <div><span>Year 3 and Up</span><strong><?= $upper ?></strong></div>
                </div>
            </section>

            <?php if ($flash): ?>
                <div class="flash flash-<?= $flash[0] ?>"><?= htmlspecialchars($flash[1]) ?></div>
            <?php endif; ?>

            <div class="toolbar">
                <form method="get" class="search-form">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#7a8294" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <input type="text" name="search" placeholder="Search by Student ID, name, or course"
                           value="<?= htmlspecialchars($search) ?>">
                </form>
                <details class="add-details">
                    <summary class="btn-primary">+ Add Student</summary>
                    <form method="post" class="add-form">
                        <input type="hidden" name="action" value="add">
                        <input type="text" name="student_id" placeholder="Student ID" required>
                        <input type="text" name="first_name" placeholder="First Name" required>
                        <input type="text" name="last_name" placeholder="Last Name" required>
                        <input type="text" name="course" placeholder="Course">
                        <input type="number" name="year_level" value="1" min="1" max="6" title="Year Level">
                        <button type="submit" class="btn-primary">Save</button>
                    </form>
                </details>
            </div>

            <section class="card">
                <h2>Student Records</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Student ID</th><th>Name</th><th>Course</th><th>Year Level</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$students): ?>
                        <tr><td colspan="5" class="empty">No student records found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($students as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['student_id']) ?></td>
                            <td><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></td>
                            <td><?= htmlspecialchars($row['course']) ?></td>
                            <td><?= htmlspecialchars($row['year_level']) ?></td>
                            <td>
                                <form method="post" onsubmit="return confirm('Delete this student record?');" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="pager">
                    <span>Showing <?= $showStart ?> to <?= $showEnd ?> of <?= $total ?> students</span>
                    <div class="pages">
                        <?php if ($page > 1): ?>
                            <a href="<?= page_url($page - 1, $search) ?>">&lsaquo;</a>
                        <?php endif; ?>
                        <?php for ($p = 1; $p <= $pages; $p++): ?>
                            <?php if ($p === $page): ?>
                                <span class="current"><?= $p ?></span>
                            <?php else: ?>
                                <a href="<?= page_url($p, $search) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $pages): ?>
                            <a href="<?= page_url($page + 1, $search) ?>">&rsaquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
