<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/catalog.php';
$catalog = load_catalog($conn);

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

// Read and check the add/edit form fields
function read_student_form() {
    $data = [
        'student_id' => trim($_POST['student_id'] ?? ''),
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name'  => trim($_POST['last_name'] ?? ''),
        'course'     => trim($_POST['course'] ?? ''),
        'section'    => trim($_POST['section'] ?? ''),
        'year_level' => (int)($_POST['year_level'] ?? 1),
    ];
    $valid = $data['student_id'] !== '' && $data['first_name'] !== '' && $data['last_name'] !== '';
    return [$data, $valid];
}

// Handle add, update, and delete, then redirect so a refresh doesn't resubmit
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

    } elseif ($action === 'update') {
        [$d, $valid] = read_student_form();
        $id = (int)($_POST['id'] ?? 0);
        if (!$valid) {
            $_SESSION['flash'] = ['error', 'Student ID, first name, and last name are required.'];
        } else {
            $stmt = $conn->prepare("UPDATE students SET student_id = ?, first_name = ?, last_name = ?, course = ?, section = ?, year_level = ? WHERE id = ?");
            $stmt->bind_param("sssssii", $d['student_id'], $d['first_name'], $d['last_name'], $d['course'], $d['section'], $d['year_level'], $id);
            if ($stmt->execute()) {
                $_SESSION['flash'] = ['success', 'Student updated.'];
            } else {
                $_SESSION['flash'] = ['error', 'That Student ID already exists.'];
            }
            $stmt->close();
        }

    } else {
        [$d, $valid] = read_student_form();
        if (!$valid) {
            $_SESSION['flash'] = ['error', 'Student ID, first name, and last name are required.'];
        } else {
            $stmt = $conn->prepare("INSERT INTO students (student_id, first_name, last_name, course, section, year_level) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssi", $d['student_id'], $d['first_name'], $d['last_name'], $d['course'], $d['section'], $d['year_level']);
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
    $where  = "WHERE student_id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR course LIKE ? OR section LIKE ?";
    $like   = "%" . $search . "%";
    $types  = 'sssss';
    $params = [$like, $like, $like, $like, $like];
}

$total  = rows($conn, "SELECT COUNT(*) AS c FROM students $where", $types, $params)[0]['c'];
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;

$students = rows(
    $conn,
    "SELECT id, student_id, first_name, last_name, course, section, year_level FROM students $where ORDER BY last_name, first_name LIMIT $perPage OFFSET $offset",
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
            <a href="index.php" class="active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/></svg>
                Dashboard
            </a>
            <a href="students.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M22 20c0-2.6-1.6-4.4-4-5.1"/></svg>
                Students
            </a>
            <a href="courses.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 5h7a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H2z"/><path d="M22 5h-7a3 3 0 0 0-3 3v12a2 2 0 0 1 2-2h8z"/></svg>Courses</a>
            <a href="reports.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                Reports
            </a>
            <a href="users.php">
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
                <div class="toast toast-<?= $flash[0] ?>" data-toast><?= htmlspecialchars($flash[1]) ?></div>
            <?php endif; ?>

            <div class="toolbar">
                <form method="get" class="search-form">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#7a8294" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <input type="text" name="search" placeholder="Search by Student ID, name, course, or section"
                           value="<?= htmlspecialchars($search) ?>">
                </form>
                <button type="button" class="btn-primary" data-open="addModal">+ Add Student</button>
            </div>

            <section class="card">
                <h2>Student Records</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Student ID</th><th>Name</th><th>Course</th><th>Section</th><th>Year Level</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$students): ?>
                        <tr><td colspan="6" class="empty">No student records found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($students as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['student_id']) ?></td>
                            <td><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></td>
                            <td><?= htmlspecialchars($row['course']) ?></td>
                            <td><?= htmlspecialchars($row['section']) ?></td>
                            <td><?= htmlspecialchars($row['year_level']) ?></td>
                            <td>
                                <div class="actions">
                                    <button type="button" class="btn-view" data-view="<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>">View</button>
                                    <button type="button" class="btn-edit" data-edit="<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>">Edit</button>
                                    <form method="post" onsubmit="return confirm('Delete this student record?');" class="inline">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button type="submit" class="btn-delete">Delete</button>
                                    </form>
                                </div>
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

    <!-- Add Student modal -->
    <div class="modal" id="addModal" hidden>
        <div class="modal-box">
            <div class="modal-head">
                <h3>Add Student</h3>
                <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
            </div>
            <form method="post" class="modal-form">
                <input type="hidden" name="action" value="add">
                <label class="full">Student ID <input type="text" name="student_id" required></label>
                <label>First Name <input type="text" name="first_name" required></label>
                <label>Last Name <input type="text" name="last_name" required></label>
                <label class="full">Course <select name="course" id="add-course" required></select></label>
                <label>Year Level <select name="year_level" id="add-year_level" required></select></label>
                <label>Section <select name="section" id="add-section" required></select></label>
                <div class="modal-actions">
                    <button type="button" class="btn-ghost" data-close>Cancel</button>
                    <button type="submit" class="btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Student modal -->
    <div class="modal" id="editModal" hidden>
        <div class="modal-box">
            <div class="modal-head">
                <h3>Edit Student</h3>
                <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
            </div>
            <form method="post" class="modal-form">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit-id">
                <label>Student ID <input type="text" name="student_id" id="edit-student_id" required></label>
                <label>Year Level <select name="year_level" id="edit-year_level" required></select></label>
                <label>First Name <input type="text" name="first_name" id="edit-first_name" required></label>
                <label>Last Name <input type="text" name="last_name" id="edit-last_name" required></label>
                <label>Course <select name="course" id="edit-course" required></select></label>
                <label>Section <select name="section" id="edit-section" required></select></label>
                <div class="modal-actions">
                    <button type="button" class="btn-ghost" data-close>Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Student modal -->
    <div class="modal" id="viewModal" hidden>
        <div class="modal-box">
            <div class="modal-head">
                <h3>Student Details</h3>
                <button type="button" class="modal-close" data-close aria-label="Close">&times;</button>
            </div>
            <dl class="details">
                <div><dt>Student ID</dt><dd id="view-student_id"></dd></div>
                <div><dt>Name</dt><dd id="view-name"></dd></div>
                <div><dt>Course</dt><dd id="view-course"></dd></div>
                <div><dt>Section</dt><dd id="view-section"></dd></div>
                <div><dt>Year Level</dt><dd id="view-year_level"></dd></div>
            </dl>
            <div class="modal-actions">
                <button type="button" class="btn-ghost" data-close>Close</button>
            </div>
        </div>
    </div>

    <script>window.CATALOG = <?= json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="catalog.js"></script>
    <script>
        const show = id => document.getElementById(id).hidden = false;
        const hide = modal => modal.hidden = true;

        const ADD = setupCascade('add-course', 'add-year_level', 'add-section');
        const EDIT = setupCascade('edit-course', 'edit-year_level', 'edit-section');
        ADD.reset();
        document.querySelectorAll('[data-open]').forEach(btn =>
            btn.addEventListener('click', () => show(btn.dataset.open)));

        document.querySelectorAll('[data-edit]').forEach(btn =>
            btn.addEventListener('click', () => {
                const r = JSON.parse(btn.dataset.edit);
                document.getElementById('edit-id').value = r.id;
                document.getElementById('edit-student_id').value = r.student_id;
                document.getElementById('edit-first_name').value = r.first_name;
                document.getElementById('edit-last_name').value = r.last_name;
                EDIT.preset(r.course || '', r.year_level, r.section || '');
        show('editModal');
            }));

        document.querySelectorAll('[data-view]').forEach(btn =>
            btn.addEventListener('click', () => {
                const r = JSON.parse(btn.dataset.view);
                document.getElementById('view-student_id').textContent = r.student_id;
                document.getElementById('view-name').textContent = r.last_name + ', ' + r.first_name;
                document.getElementById('view-course').textContent = r.course || '-';
                document.getElementById('view-section').textContent = r.section || '-';
                document.getElementById('view-year_level').textContent = r.year_level;
                show('viewModal');
            }));

        // Close on the X or Cancel button, or by clicking the dark background
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', e => {
                if (e.target === modal || e.target.closest('[data-close]')) hide(modal);
            });
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') document.querySelectorAll('.modal').forEach(hide);
        });
    </script>
</body>
</html>
