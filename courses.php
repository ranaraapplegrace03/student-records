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

function ordinal($n) {
    if (in_array($n % 100, [11, 12, 13])) return $n . 'th';
    return $n . ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
}
function year_label($n) {
    return ordinal($n) . ' Year';
}
function summarize_years($years, $sectionCount) {
    $n = count($years);
    if ($n === 0) return 'No year levels yet';
    $secText = $sectionCount . ($sectionCount === 1 ? ' section' : ' sections');
    $levels = array_map(fn($y) => (int)$y['year'], array_values($years));
    sort($levels);
    if ($n === 1) return ordinal($levels[0]) . ' Year (' . $secText . ')';
    $contiguous = ($levels[$n - 1] - $levels[0]) === $n - 1;
    $range = $contiguous
        ? ordinal($levels[0]) . ' - ' . ordinal($levels[$n - 1]) . ' Year'
        : implode(', ', array_map('ordinal', $levels)) . ' Year';
    return $range . ' (' . $secText . ')';
}
function set_flash($type, $msg) {
    $_SESSION['flash'] = [$type, $msg];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $courseId = (int)($_POST['course_id'] ?? 0);
    $openId   = (int)($_POST['open'] ?? $courseId);

    try {
        if ($action === 'add_course' || $action === 'update_course') {
            $id     = (int)($_POST['id'] ?? 0);
            $code   = strtoupper(trim($_POST['code'] ?? ''));
            $name   = trim($_POST['name'] ?? '');
            $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
            $openId = 0;
            if ($code === '' || $name === '') {
                set_flash('error', 'Course code and course name are required.');
            } elseif ($action === 'add_course') {
                $stmt = $conn->prepare("INSERT INTO courses (code, name, status) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $code, $name, $status);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Course added.');
            } else {
                $stmt = $conn->prepare("UPDATE courses SET code = ?, name = ?, status = ? WHERE id = ?");
                $stmt->bind_param("sssi", $code, $name, $status, $id);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Course updated.');
            }

        } elseif ($action === 'delete_course') {
            $id = (int)($_POST['id'] ?? 0);
            $openId = 0;
            $stmt = $conn->prepare("DELETE FROM courses WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $stmt->close();
            set_flash($ok ? 'success' : 'error', $ok ? 'Course deleted. Student records were kept.' : 'Course not found.');

        } elseif ($action === 'add_year') {
            $year = (int)($_POST['year_level'] ?? 0);
            if ($courseId && $year >= 1 && $year <= 4) {
                $stmt = $conn->prepare("INSERT INTO course_years (course_id, year_level) VALUES (?, ?)");
                $stmt->bind_param("ii", $courseId, $year);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Year level added.');
            } else {
                set_flash('error', 'Please choose a year level from 1st to 4th Year.');
            }

        } elseif ($action === 'delete_year') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM course_years WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'Year level deleted. Student records were kept.');

        } elseif ($action === 'add_section') {
            $cy   = (int)($_POST['course_year_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                set_flash('error', 'Section name is required.');
            } else {
                $stmt = $conn->prepare("INSERT INTO sections (course_year_id, name) VALUES (?, ?)");
                $stmt->bind_param("is", $cy, $name);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Section added.');
            }

        } elseif ($action === 'delete_section') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM sections WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'Section removed. Student records were kept.');
        }
    } catch (mysqli_sql_exception $e) {
        set_flash('error', 'Could not save. That course code, year level, or section may already exist.');
    }

    header("Location: courses.php" . ($openId ? "?open=" . $openId : ""));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Search, status filter, and pagination
$search  = trim($_GET['search'] ?? '');
$status  = $_GET['status'] ?? '';
if (!in_array($status, ['Active', 'Inactive'], true)) $status = '';
$perPage = 10;
$conds   = [];
$types   = '';
$params  = [];
if ($search !== '') {
    $conds[] = "(code LIKE ? OR name LIKE ?)";
    $like = "%" . $search . "%";
    $types .= 'ss';
    array_push($params, $like, $like);
}
if ($status !== '') {
    $conds[] = "status = ?";
    $types .= 's';
    $params[] = $status;
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$total  = rows($conn, "SELECT COUNT(*) AS c FROM courses $where", $types, $params)[0]['c'];
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;

$courses = rows($conn, "SELECT id, code, name, status FROM courses $where ORDER BY code LIMIT $perPage OFFSET $offset", $types, $params);

// Year levels and sections for the listed courses
$yearsBy = [];
if ($courses) {
    $ids = implode(',', array_map('intval', array_column($courses, 'id')));
    $data = rows($conn,
        "SELECT cy.id AS cy_id, cy.course_id, cy.year_level, s.id AS sec_id, s.name AS sec_name
         FROM course_years cy LEFT JOIN sections s ON s.course_year_id = cy.id
         WHERE cy.course_id IN ($ids) ORDER BY cy.year_level, s.name");
    foreach ($data as $r) {
        $cid = $r['course_id'];
        $cy  = $r['cy_id'];
        if (!isset($yearsBy[$cid][$cy])) {
            $yearsBy[$cid][$cy] = ['year' => $r['year_level'], 'sections' => []];
        }
        if ($r['sec_id'] !== null) {
            $yearsBy[$cid][$cy]['sections'][] = ['id' => $r['sec_id'], 'name' => $r['sec_name']];
        }
    }
}

$showStart = $total ? $offset + 1 : 0;
$showEnd   = min($offset + $perPage, $total);

function page_url($p, $search, $status) {
    return 'courses.php?' . http_build_query(['search' => $search, 'status' => $status, 'page' => $p]);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Courses - Student Records</title>
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
            <a href="courses.php" class="active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 5h7a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H2z"/><path d="M22 5h-7a3 3 0 0 0-3 3v12a2 2 0 0 1 2-2h8z"/></svg>
                Courses
            </a>
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
                <svg viewBox="0 0 24 24" fill="none" stroke="#1f5fd6" stroke-width="2" class="title-cap" aria-hidden="true"><path d="M2 5h7a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H2z"/><path d="M22 5h-7a3 3 0 0 0-3 3v12a2 2 0 0 1 2-2h8z"/></svg>
                <div>
                    <h1>Courses</h1>
                    <p>Manage courses, year levels, and sections.</p>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="toast toast-<?= $flash[0] ?>" data-toast><?= htmlspecialchars($flash[1]) ?></div>
            <?php endif; ?>

            <div class="toolbar">
                <form method="get" class="search-form" id="filterForm">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#7a8294" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <input type="text" name="search" placeholder="Search by Course Code or Course Name..."
                           value="<?= htmlspecialchars($search) ?>">
                </form>
                <select form="filterForm" name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="" <?= $status === '' ? 'selected' : '' ?>>Status: All</option>
                    <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= $status === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <button type="button" class="btn-primary" data-open="addCourseModal">+ Add Course</button>
            </div>

            <section class="card">
                <table>
                    <thead>
                        <tr>
                            <th style="width:44px"></th><th>Course Code</th><th>Course Name</th><th>Year Levels &amp; Sections</th><th>Status</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$courses): ?>
                        <tr><td colspan="6" class="empty">No courses found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($courses as $c):
                        $years = $yearsBy[$c['id']] ?? [];
                        $sectionCount = 0;
                        foreach ($years as $y) { $sectionCount += count($y['sections']); }
                        $summary = summarize_years($years, $sectionCount);
                        $editJson = htmlspecialchars(json_encode($c), ENT_QUOTES);
                        $viewJson = htmlspecialchars(json_encode([
                            'code' => $c['code'], 'name' => $c['name'], 'status' => $c['status'],
                            'years' => count($years), 'sections' => $sectionCount
                        ]), ENT_QUOTES);
                    ?>
                        <tr class="course-row">
                            <td><button type="button" class="chev" data-toggle="<?= $c['id'] ?>" aria-label="Show year levels">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M9 6l6 6-6 6"/></svg>
                            </button></td>
                            <td><strong><?= htmlspecialchars($c['code']) ?></strong></td>
                            <td><?= htmlspecialchars($c['name']) ?></td>
                            <td><?= $summary ?></td>
                            <td><span class="badge <?= $c['status'] === 'Inactive' ? 'badge-inactive' : 'badge-active' ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                            <td>
                                <div class="actions">
                                    <button type="button" class="btn-view" data-view="<?= $viewJson ?>">View</button>
                                    <button type="button" class="btn-edit" data-edit="<?= $editJson ?>">Edit</button>
                                    <form method="post" class="inline" onsubmit="return confirm('Delete this course? Its year levels and sections will be removed. Student records are kept.');">
                                        <input type="hidden" name="action" value="delete_course">
                                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                        <button type="submit" class="btn-delete">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <tr class="expand" id="exp-<?= $c['id'] ?>" hidden>
                            <td colspan="6">
                                <div class="expand-head">
                                    <span>Year Levels and Sections</span>
                                    <button type="button" class="btn-primary small" data-open="addYearModal"
                                            data-course="<?= $c['id'] ?>" data-course-code="<?= htmlspecialchars($c['code']) ?>">+ Add Year Level</button>
                                </div>
                                <?php if (!$years): ?>
                                    <p class="muted">No year levels yet. Click "+ Add Year Level" to start.</p>
                                <?php endif; ?>
                                <?php foreach ($years as $cyId => $y): ?>
                                    <div class="year-row">
                                        <div class="year-label"><?= year_label($y['year']) ?></div>
                                        <div class="chips">
                                            <?php if (!$y['sections']): ?>
                                                <span class="muted">No sections yet</span>
                                            <?php endif; ?>
                                            <?php foreach ($y['sections'] as $s): ?>
                                                <span class="chip">
                                                    <a href="section.php?id=<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></a>
                                                    <form method="post" class="inline" onsubmit="return confirm('Remove this section? Student records are kept.');">
                                                        <input type="hidden" name="action" value="delete_section">
                                                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                                        <input type="hidden" name="open" value="<?= (int)$c['id'] ?>">
                                                        <button class="chip-x" type="submit" aria-label="Remove section">&times;</button>
                                                    </form>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="btn-add-small" data-open="addSectionModal"
                                                data-cy="<?= (int)$cyId ?>" data-course="<?= (int)$c['id'] ?>"
                                                data-label="<?= htmlspecialchars($c['code'] . ' - ' . year_label($y['year'])) ?>">+ Add Section</button>
                                        <form method="post" class="inline" onsubmit="return confirm('Delete this year level and its sections? Student records are kept.');">
                                            <input type="hidden" name="action" value="delete_year">
                                            <input type="hidden" name="id" value="<?= (int)$cyId ?>">
                                            <input type="hidden" name="open" value="<?= (int)$c['id'] ?>">
                                            <button type="submit" class="btn-delete-small">Delete Year</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="pager">
                    <span>Showing <?= $showStart ?> to <?= $showEnd ?> of <?= $total ?> courses</span>
                    <div class="pages">
                        <?php if ($page > 1): ?>
                            <a href="<?= page_url($page - 1, $search, $status) ?>">&lsaquo;</a>
                        <?php endif; ?>
                        <?php for ($p = 1; $p <= $pages; $p++): ?>
                            <?php if ($p === $page): ?>
                                <span class="current"><?= $p ?></span>
                            <?php else: ?>
                                <a href="<?= page_url($p, $search, $status) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $pages): ?>
                            <a href="<?= page_url($page + 1, $search, $status) ?>">&rsaquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- Add Course modal -->
    <div class="modal" id="addCourseModal" hidden>
        <div class="modal-box">
            <div class="modal-head"><h3>Add Course</h3><button type="button" class="modal-close" data-close aria-label="Close">&times;</button></div>
            <form method="post" class="modal-form">
                <input type="hidden" name="action" value="add_course">
                <label>Course Code <input type="text" name="code" placeholder="e.g. BSIT" required></label>
                <label>Status
                    <select name="status"><option>Active</option><option>Inactive</option></select>
                </label>
                <label class="full">Course Name <input type="text" name="name" placeholder="e.g. Bachelor of Science in Information Technology" required></label>
                <div class="modal-actions">
                    <button type="button" class="btn-ghost" data-close>Cancel</button>
                    <button type="submit" class="btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Course modal -->
    <div class="modal" id="editCourseModal" hidden>
        <div class="modal-box">
            <div class="modal-head"><h3>Edit Course</h3><button type="button" class="modal-close" data-close aria-label="Close">&times;</button></div>
            <form method="post" class="modal-form">
                <input type="hidden" name="action" value="update_course">
                <input type="hidden" name="id" id="ec-id">
                <label>Course Code <input type="text" name="code" id="ec-code" required></label>
                <label>Status
                    <select name="status" id="ec-status"><option>Active</option><option>Inactive</option></select>
                </label>
                <label class="full">Course Name <input type="text" name="name" id="ec-name" required></label>
                <div class="modal-actions">
                    <button type="button" class="btn-ghost" data-close>Cancel</button>
                    <button type="submit" class="btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Course modal -->
    <div class="modal" id="viewCourseModal" hidden>
        <div class="modal-box">
            <div class="modal-head"><h3>Course Details</h3><button type="button" class="modal-close" data-close aria-label="Close">&times;</button></div>
            <dl class="details">
                <div><dt>Course Code</dt><dd id="vc-code"></dd></div>
                <div><dt>Status</dt><dd id="vc-status"></dd></div>
                <div style="grid-column: 1 / -1"><dt>Course Name</dt><dd id="vc-name"></dd></div>
                <div><dt>Year Levels</dt><dd id="vc-years"></dd></div>
                <div><dt>Sections</dt><dd id="vc-sections"></dd></div>
            </dl>
            <div class="modal-actions"><button type="button" class="btn-ghost" data-close>Close</button></div>
        </div>
    </div>

    <!-- Add Year Level modal -->
    <div class="modal" id="addYearModal" hidden>
        <div class="modal-box">
            <div class="modal-head"><h3>Add Year Level to <span id="year-title"></span></h3><button type="button" class="modal-close" data-close aria-label="Close">&times;</button></div>
            <form method="post" class="modal-form">
                <input type="hidden" name="action" value="add_year">
                <input type="hidden" name="course_id" id="year-course_id">
                <input type="hidden" name="open" id="year-open">
                <label class="full">Year Level
                    <select name="year_level">
                        <?php for ($y = 1; $y <= 4; $y++): ?><option value="<?= $y ?>"><?= year_label($y) ?></option><?php endfor; ?>
                    </select>
                </label>
                <div class="modal-actions">
                    <button type="button" class="btn-ghost" data-close>Cancel</button>
                    <button type="submit" class="btn-primary">Add Year Level</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Section modal -->
    <div class="modal" id="addSectionModal" hidden>
        <div class="modal-box">
            <div class="modal-head"><h3>Add Section to <span id="section-title"></span></h3><button type="button" class="modal-close" data-close aria-label="Close">&times;</button></div>
            <form method="post" class="modal-form">
                <input type="hidden" name="action" value="add_section">
                <input type="hidden" name="course_year_id" id="section-cy">
                <input type="hidden" name="open" id="section-open">
                <label class="full">Section Name <input type="text" name="name" placeholder="e.g. BSIT-1A" required></label>
                <div class="modal-actions">
                    <button type="button" class="btn-ghost" data-close>Cancel</button>
                    <button type="submit" class="btn-primary">Add Section</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const $ = id => document.getElementById(id);
        const show = id => $(id).hidden = false;
        const hide = m => m.hidden = true;

        // Expand and collapse a course's year levels
        document.querySelectorAll('[data-toggle]').forEach(btn => btn.addEventListener('click', () => {
            const row = $('exp-' + btn.dataset.toggle);
            row.hidden = !row.hidden;
            btn.classList.toggle('open', !row.hidden);
        }));

        // Re-open a course after saving, using ?open=ID
        const openId = new URLSearchParams(location.search).get('open');
        if (openId && $('exp-' + openId)) {
            $('exp-' + openId).hidden = false;
            document.querySelector('[data-toggle="' + openId + '"]')?.classList.add('open');
        }

        document.querySelectorAll('[data-open]').forEach(btn => btn.addEventListener('click', () => {
            if (btn.dataset.open === 'addYearModal') {
                $('year-course_id').value = btn.dataset.course;
                $('year-open').value = btn.dataset.course;
                $('year-title').textContent = btn.dataset.courseCode;
            }
            if (btn.dataset.open === 'addSectionModal') {
                $('section-cy').value = btn.dataset.cy;
                $('section-open').value = btn.dataset.course;
                $('section-title').textContent = btn.dataset.label;
            }
            show(btn.dataset.open);
        }));

        document.querySelectorAll('[data-edit]').forEach(btn => btn.addEventListener('click', () => {
            const r = JSON.parse(btn.dataset.edit);
            $('ec-id').value = r.id;
            $('ec-code').value = r.code;
            $('ec-name').value = r.name;
            $('ec-status').value = r.status;
            show('editCourseModal');
        }));

        document.querySelectorAll('[data-view]').forEach(btn => btn.addEventListener('click', () => {
            const r = JSON.parse(btn.dataset.view);
            $('vc-code').textContent = r.code;
            $('vc-name').textContent = r.name;
            $('vc-status').textContent = r.status;
            $('vc-years').textContent = r.years;
            $('vc-sections').textContent = r.sections;
            show('viewCourseModal');
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
