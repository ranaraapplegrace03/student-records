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

function ordinal($n) {
    if (in_array($n % 100, [11, 12, 13])) return $n . 'th';
    return $n . ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
}

$id = (int)($_GET['id'] ?? 0);
$found = rows($conn,
    "SELECT s.id, s.name, cy.year_level, c.id AS course_id, c.code, c.name AS course_name
     FROM sections s
     JOIN course_years cy ON cy.id = s.course_year_id
     JOIN courses c ON c.id = cy.course_id
     WHERE s.id = ?", 'i', [$id]);
if (!$found) {
    header("Location: courses.php");
    exit;
}
$sec = $found[0];
$yearNo = (int)$sec['year_level'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Students belong to this section when their course code, year level, and section match
$secParams = [$sec['code'], $yearNo, $sec['name']];
$sectionTotal = rows($conn, "SELECT COUNT(*) AS c FROM students WHERE course = ? AND year_level = ? AND section = ?", 'sis', $secParams)[0]['c'];

$search  = trim($_GET['search'] ?? '');
$status  = $_GET['status'] ?? '';
if (!in_array($status, ['Active', 'Inactive'], true)) $status = '';
$perPage = 10;

$conds  = ["course = ?", "year_level = ?", "section = ?"];
$types  = 'sis';
$params = $secParams;
if ($search !== '') {
    $conds[] = "(student_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
    $like = "%" . $search . "%";
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
if ($status !== '') {
    $conds[] = "status = ?";
    $types .= 's';
    $params[] = $status;
}
$where = 'WHERE ' . implode(' AND ', $conds);

$total  = rows($conn, "SELECT COUNT(*) AS c FROM students $where", $types, $params)[0]['c'];
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;

$students = rows($conn,
    "SELECT id, student_id, first_name, last_name, course, section, year_level, status, average
     FROM students $where ORDER BY last_name, first_name LIMIT $perPage OFFSET $offset",
    $types, $params);

$showStart = $total ? $offset + 1 : 0;
$showEnd   = min($offset + $perPage, $total);
$returnTo  = 'section.php?id=' . $id;

function page_url($p, $id, $search, $status) {
    return 'section.php?' . http_build_query(['id' => $id, 'search' => $search, 'status' => $status, 'page' => $p]);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($sec['name']) ?> Students - Student Records</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <script src="sidebar.js"></script>
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
            <a href="courses.php" class="active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 5h7a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H2z"/><path d="M22 5h-7a3 3 0 0 0-3 3v12a2 2 0 0 1 2-2h8z"/></svg>
                Courses
            </a>
            <a href="reports.php">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                Reports
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
            <nav class="crumbs">
                <a href="courses.php">Courses</a><span class="sep">&rsaquo;</span>
                <a href="courses.php?open=<?= (int)$sec['course_id'] ?>"><?= htmlspecialchars($sec['code']) ?></a><span class="sep">&rsaquo;</span>
                <a href="courses.php?open=<?= (int)$sec['course_id'] ?>"><?= ordinal($yearNo) ?> Year</a><span class="sep">&rsaquo;</span>
                <strong><?= htmlspecialchars($sec['name']) ?></strong>
            </nav>

            <div class="page-head">
                <div class="page-title" style="margin-bottom:0">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#1f5fd6" stroke-width="2" class="title-cap" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M22 20c0-2.6-1.6-4.4-4-5.1"/></svg>
                    <div>
                        <h1><?= htmlspecialchars($sec['name']) ?> Students</h1>
                        <p>List of students under <?= htmlspecialchars($sec['code']) ?>, <?= ordinal($yearNo) ?> Year, Section <?= htmlspecialchars($sec['name']) ?>.</p>
                    </div>
                </div>
                <a href="courses.php?open=<?= (int)$sec['course_id'] ?>" class="btn-outline">&larr; Back to Courses</a>
            </div>

            <section class="stats">
                <div class="stat blue">
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><path d="M2 5h7a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H2z"/><path d="M22 5h-7a3 3 0 0 0-3 3v12a2 2 0 0 1 2-2h8z"/></svg></div>
                    <div><span>Course</span><strong><?= htmlspecialchars($sec['code']) ?></strong></div>
                </div>
                <div class="stat green">
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><path d="M12 3l9 4.5-9 4.5-9-4.5z"/><path d="M3 12l9 4.5 9-4.5"/><path d="M3 16.5l9 4.5 9-4.5"/></svg></div>
                    <div><span>Year Level</span><strong><?= ordinal($yearNo) ?> Year</strong></div>
                </div>
                <div class="stat orange">
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/></svg></div>
                    <div><span>Section</span><strong><?= htmlspecialchars($sec['name']) ?></strong></div>
                </div>
                <div class="stat purple">
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/></svg></div>
                    <div><span>Total Students</span><strong><?= $sectionTotal ?></strong></div>
                </div>
            </section>

            <?php if ($flash): ?>
                <div class="toast toast-<?= $flash[0] ?>" data-toast><?= htmlspecialchars($flash[1]) ?></div>
            <?php endif; ?>

            <div class="toolbar">
                <form method="get" class="search-form" id="filterForm">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#7a8294" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <input type="text" name="search" placeholder="Search by Student ID or Name..." value="<?= htmlspecialchars($search) ?>">
                </form>
                <select form="filterForm" name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="" <?= $status === '' ? 'selected' : '' ?>>Status: All</option>
                    <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <button type="button" class="btn-primary" data-open="addStudentModal">+ Add Student</button>
            </div>

            <section class="card">
                <table>
                    <thead>
                        <tr>
                            <th>#</th><th>Student ID</th><th>Name</th><th>Course</th><th>Year Level</th><th>Section</th><th>Status</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$students): ?>
                        <tr><td colspan="8" class="empty">No students in this section yet. Click "+ Add Student" to add one.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($students as $i => $row): ?>
                        <tr>
                            <td><?= $offset + $i + 1 ?></td>
                            <td><?= htmlspecialchars($row['student_id']) ?></td>
                            <td><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></td>
                            <td><?= htmlspecialchars($row['course']) ?></td>
                            <td>Year <?= htmlspecialchars($row['year_level']) ?></td>
                            <td><?= htmlspecialchars($row['section']) ?></td>
                            <td><span class="badge <?= $row['status'] === 'Inactive' ? 'badge-inactive' : 'badge-active' ?>"><?= htmlspecialchars($row['status']) ?></span></td>
                            <td>
                                <div class="actions">
                                    <button type="button" class="btn-view" data-view="<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>">View</button>
                                    <button type="button" class="btn-edit" data-edit="<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>">Edit</button>
                                    <form method="post" action="students.php" class="inline" onsubmit="return confirm('Delete this student record?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="return" value="<?= htmlspecialchars($returnTo) ?>">
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
                            <a href="<?= page_url($page - 1, $id, $search, $status) ?>">&lsaquo;</a>
                        <?php endif; ?>
                        <?php for ($p = 1; $p <= $pages; $p++): ?>
                            <?php if ($p === $page): ?>
                                <span class="current"><?= $p ?></span>
                            <?php else: ?>
                                <a href="<?= page_url($p, $id, $search, $status) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $pages): ?>
                            <a href="<?= page_url($page + 1, $id, $search, $status) ?>">&rsaquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Add Student modal (course, year level, and section are fixed to this section) -->
    <div class="modal" id="addStudentModal" hidden>
        <div class="modal-box">
            <div class="modal-head"><h3>Add Student to <?= htmlspecialchars($sec['name']) ?></h3><button type="button" class="modal-close" data-close aria-label="Close">&times;</button></div>
            <form method="post" action="students.php" class="modal-form">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="course" value="<?= htmlspecialchars($sec['code']) ?>">
                <input type="hidden" name="year_level" value="<?= $yearNo ?>">
                <input type="hidden" name="section" value="<?= htmlspecialchars($sec['name']) ?>">
                <input type="hidden" name="return" value="<?= htmlspecialchars($returnTo) ?>">
                <label>Student ID <input type="text" name="student_id" required></label>
                <label>Status <select name="status"><option>Active</option><option>Inactive</option></select></label>
                <label>First Name <input type="text" name="first_name" required></label>
                <label>Last Name <input type="text" name="last_name" required></label>
                <div class="modal-actions">
                    <button type="button" class="btn-ghost" data-close>Cancel</button>
                    <button type="submit" class="btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Student modal -->
    <div class="modal" id="editStudentModal" hidden>
        <div class="modal-box">
            <div class="modal-head"><h3>Edit Student</h3><button type="button" class="modal-close" data-close aria-label="Close">&times;</button></div>
            <form method="post" action="students.php" class="modal-form">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="es-id">
                <input type="hidden" name="return" value="<?= htmlspecialchars($returnTo) ?>">
                <label>Student ID <input type="text" name="student_id" id="es-student_id" required></label>
                <label>Status <select name="status" id="es-status"><option>Active</option><option>Inactive</option></select></label>
                <label>First Name <input type="text" name="first_name" id="es-first_name" required></label>
                <label>Last Name <input type="text" name="last_name" id="es-last_name" required></label>
                <label>Course <select name="course" id="es-course" required></select></label>
                <label>Year Level <select name="year_level" id="es-year_level" required></select></label>
                <label>Section <select name="section" id="es-section" required></select></label>
                <div class="modal-actions">
                    <button type="button" class="btn-ghost" data-close>Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Student modal -->
    <div class="modal" id="viewStudentModal" hidden>
        <div class="modal-box">
            <div class="modal-head"><h3>Student Details</h3><button type="button" class="modal-close" data-close aria-label="Close">&times;</button></div>
            <dl class="details">
                <div><dt>Student ID</dt><dd id="vs-student_id"></dd></div>
                <div><dt>Name</dt><dd id="vs-name"></dd></div>
                <div><dt>Course</dt><dd id="vs-course"></dd></div>
                <div><dt>Year Level</dt><dd id="vs-year_level"></dd></div>
                <div><dt>Section</dt><dd id="vs-section"></dd></div>
                <div><dt>Status</dt><dd id="vs-status"></dd></div>
            </dl>
            <div class="modal-actions"><button type="button" class="btn-ghost" data-close>Close</button></div>
        </div>
    </div>

    <script>window.CATALOG = <?= json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="catalog.js"></script>
    <script>
        const $ = id => document.getElementById(id);
        const show = id => $(id).hidden = false;
        const hide = m => m.hidden = true;

        const EDIT = setupCascade('es-course', 'es-year_level', 'es-section');
        document.querySelectorAll('[data-open]').forEach(btn =>
            btn.addEventListener('click', () => show(btn.dataset.open)));

        document.querySelectorAll('[data-edit]').forEach(btn => btn.addEventListener('click', () => {
            const r = JSON.parse(btn.dataset.edit);
            $('es-id').value = r.id;
            $('es-student_id').value = r.student_id;
            $('es-first_name').value = r.first_name;
            $('es-last_name').value = r.last_name;
            $('es-status').value = r.status || 'Active';
            EDIT.preset(r.course || '', r.year_level, r.section || '');
        show('editStudentModal');
        }));

        document.querySelectorAll('[data-view]').forEach(btn => btn.addEventListener('click', () => {
            const r = JSON.parse(btn.dataset.view);
            $('vs-student_id').textContent = r.student_id;
            $('vs-name').textContent = r.last_name + ', ' + r.first_name;
            $('vs-course').textContent = r.course || '-';
            $('vs-year_level').textContent = 'Year ' + r.year_level;
            $('vs-section').textContent = r.section || '-';
            $('vs-status').textContent = r.status || 'Active';
            show('viewStudentModal');
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
