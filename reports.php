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

// Student filters. $skip lists filters to leave out (used by the charts)
function student_filters($course, $year, $section, $skip = []) {
    $c = []; $t = ''; $p = [];
    if ($course !== '' && !in_array('course', $skip)) { $c[] = 'course = ?'; $t .= 's'; $p[] = $course; }
    if ($year && !in_array('year', $skip))             { $c[] = 'year_level = ?'; $t .= 'i'; $p[] = $year; }
    if ($section !== '' && !in_array('section', $skip)) { $c[] = 'section = ?'; $t .= 's'; $p[] = $section; }
    return [$c ? 'WHERE ' . implode(' AND ', $c) : '', $t, $p];
}

$tabs = [
    'student'    => 'Student Report',
    'enrollment' => 'Enrollment Report',
    'course'     => 'Course Report',
    'section'    => 'Section Report',
    'summary'    => 'Summary Report',
];
$tab        = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'student';
$courseF    = trim($_GET['course'] ?? '');
$yearF      = (int)($_GET['year_level'] ?? 0);
if ($yearF < 1 || $yearF > 6) $yearF = 0;
$sectionF   = trim($_GET['section'] ?? '');

// CSV export (opens in Excel)
if (($_GET['export'] ?? '') === 'csv') {
    [$w, $t, $p] = student_filters($courseF, $yearF, $sectionF);
    $list = rows($conn, "SELECT student_id, last_name, first_name, course, year_level, section, status FROM students $w ORDER BY course, year_level, section, last_name", $t, $p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student-report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID', 'Last Name', 'First Name', 'Course', 'Year Level', 'Section', 'Status']);
    foreach ($list as $r) {
        fputcsv($out, [$r['student_id'], $r['last_name'], $r['first_name'], $r['course'], 'Year ' . $r['year_level'], $r['section'], $r['status']]);
    }
    fclose($out);
    exit;
}

// Totals
$totalCourses  = rows($conn, "SELECT COUNT(*) AS c FROM courses")[0]['c'];
$totalYears    = rows($conn, "SELECT COUNT(*) AS c FROM course_years")[0]['c'];
$totalSections = rows($conn, "SELECT COUNT(*) AS c FROM sections")[0]['c'];
[$w, $t, $p] = student_filters($courseF, $yearF, $sectionF);
$filteredTotal = rows($conn, "SELECT COUNT(*) AS c FROM students $w", $t, $p)[0]['c'];

// Students per course (donut) and enrollment per year level (bars)
[$wc, $tc, $pc] = student_filters($courseF, $yearF, $sectionF, ['course']);
$byCourse = rows($conn, "SELECT course, COUNT(*) AS c FROM students $wc GROUP BY course ORDER BY c DESC", $tc, $pc);
[$wy, $ty, $py] = student_filters($courseF, $yearF, $sectionF, ['year']);
$byYearRaw = rows($conn, "SELECT year_level, COUNT(*) AS c FROM students $wy GROUP BY year_level", $ty, $py);
$byYear = array_fill(1, 6, 0);
foreach ($byYearRaw as $r) { $byYear[(int)$r['year_level']] = (int)$r['c']; }

$palette = ['#2563eb', '#7c3aed', '#16a34a', '#f59e0b', '#ef4444', '#06b6d4', '#84cc16', '#ec4899'];
$donutTotal = array_sum(array_column($byCourse, 'c'));
$stops = []; $acc = 0.0;
foreach ($byCourse as $i => $r) {
    $pct = $donutTotal ? $r['c'] / $donutTotal * 100 : 0;
    $color = $palette[$i % count($palette)];
    $stops[] = sprintf('%s %.2f%% %.2f%%', $color, $acc, $acc + $pct);
    $acc += $pct;
}
$donutStyle = $stops ? 'conic-gradient(' . implode(', ', $stops) . ')' : 'conic-gradient(#e5e7eb 0 100%)';
$yearMax = max(1, max($byYear));

// Tab data
$pct = fn($n, $d) => $d ? number_format($n / $d * 100, 1) . '%' : '0%';

$pageRows = []; $pages = 1; $page = 1; $perPage = 10; $offset = 0;
if ($tab === 'student') {
    $perPage = 10;
    $pages = max(1, (int)ceil($filteredTotal / $perPage));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    $offset = ($page - 1) * $perPage;
    $pageRows = rows($conn, "SELECT student_id, last_name, first_name, course, year_level, section, status FROM students $w ORDER BY course, year_level, section, last_name LIMIT $perPage OFFSET $offset", $t, $p);
}

$courseRows = [];
if ($tab === 'course') {
    $cw = $courseF !== '' ? 'WHERE c.code = ?' : '';
    $courseRows = rows($conn,
        "SELECT c.code, c.name, c.status,
            (SELECT COUNT(*) FROM course_years cy WHERE cy.course_id = c.id) AS years,
            (SELECT COUNT(*) FROM sections s JOIN course_years cy ON cy.id = s.course_year_id WHERE cy.course_id = c.id) AS sections,
            (SELECT COUNT(*) FROM students st WHERE st.course = c.code) AS students
         FROM courses c $cw ORDER BY c.code", $courseF !== '' ? 's' : '', $courseF !== '' ? [$courseF] : []);
}

$sectionRows = [];
if ($tab === 'section') {
    $sc = []; $st = ''; $sp = [];
    if ($courseF !== '')  { $sc[] = 'c.code = ?'; $st .= 's'; $sp[] = $courseF; }
    if ($yearF)           { $sc[] = 'cy.year_level = ?'; $st .= 'i'; $sp[] = $yearF; }
    if ($sectionF !== '') { $sc[] = 's.name = ?'; $st .= 's'; $sp[] = $sectionF; }
    $swhere = $sc ? 'WHERE ' . implode(' AND ', $sc) : '';
    $sectionRows = rows($conn,
        "SELECT c.code, cy.year_level, s.name,
            (SELECT COUNT(*) FROM students st WHERE st.course = c.code AND st.year_level = cy.year_level AND st.section = s.name) AS students
         FROM sections s
         JOIN course_years cy ON cy.id = s.course_year_id
         JOIN courses c ON c.id = cy.course_id
         $swhere ORDER BY c.code, cy.year_level, s.name", $st, $sp);
}

$statusCounts = ['Active' => 0, 'Inactive' => 0];
if ($tab === 'summary') {
    foreach (rows($conn, "SELECT status, COUNT(*) AS c FROM students $w GROUP BY status", $t, $p) as $r) {
        $statusCounts[$r['status']] = (int)$r['c'];
    }
}

$sectionOptions = rows($conn, "SELECT DISTINCT name FROM sections ORDER BY name");
$courseOptions  = rows($conn, "SELECT code, name FROM courses ORDER BY code");

// Keep the filters in the links
$keep = ['course' => $courseF, 'year_level' => $yearF ?: '', 'section' => $sectionF];
function tab_url($key, $keep) {
    return 'reports.php?' . http_build_query(array_merge(['tab' => $key], $keep));
}
function page_url($p, $keep) {
    return 'reports.php?' . http_build_query(array_merge(['tab' => 'student', 'page' => $p], $keep));
}
$exportUrl = 'reports.php?' . http_build_query(array_merge(['export' => 'csv'], $keep));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports - Student Records</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <script src="sidebar.js"></script>
    <script src="logout-confirm.js"></script>
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
            <a href="reports.php" class="active">
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
                <svg viewBox="0 0 24 24" fill="none" stroke="#1f5fd6" stroke-width="2.2" class="title-cap" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                <div>
                    <h1>Reports</h1>
                    <p>Generate and view student records reports by course, year level, and section.</p>
                </div>
            </div>

            <nav class="tabs">
                <?php foreach ($tabs as $key => $label): ?>
                    <a href="<?= tab_url($key, $keep) ?>" class="tab <?= $tab === $key ? 'active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </nav>

            <form method="get" class="report-filters">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                <label>Course
                    <select name="course">
                        <option value="">All Courses</option>
                        <?php foreach ($courseOptions as $c): ?>
                            <option value="<?= htmlspecialchars($c['code']) ?>" <?= $courseF === $c['code'] ? 'selected' : '' ?>><?= htmlspecialchars($c['code'] . ' - ' . $c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Year Level
                    <select name="year_level">
                        <option value="">All Year Levels</option>
                        <?php for ($y = 1; $y <= 6; $y++): ?>
                            <option value="<?= $y ?>" <?= $yearF === $y ? 'selected' : '' ?>><?= ordinal($y) ?> Year</option>
                        <?php endfor; ?>
                    </select>
                </label>
                <label>Section
                    <select name="section">
                        <option value="">All Sections</option>
                        <?php foreach ($sectionOptions as $s): ?>
                            <option value="<?= htmlspecialchars($s['name']) ?>" <?= $sectionF === $s['name'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn-primary generate">Generate Report</button>
            </form>

            <section class="stats">
                <div class="stat blue">
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M22 20c0-2.6-1.6-4.4-4-5.1"/></svg></div>
                    <div><span>Total Students</span><strong><?= $filteredTotal ?></strong></div>
                </div>
                <div class="stat green">
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="#16a34a"><path d="M12 3 1 9l11 6 9-4.9V17h2V9L12 3z"/><path d="M5 13v4c0 1.7 3.1 3 7 3s7-1.3 7-3v-4l-7 3.8L5 13z"/></svg></div>
                    <div><span>Total Courses</span><strong><?= $totalCourses ?></strong></div>
                </div>
                <div class="stat purple">
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M12 3l9 4.5-9 4.5-9-4.5z"/><path d="M3 12l9 4.5 9-4.5"/><path d="M3 16.5l9 4.5 9-4.5"/></svg></div>
                    <div><span>Total Year Levels</span><strong><?= $totalYears ?></strong></div>
                </div>
                <div class="stat orange">
                    <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#ea580c" stroke-width="2"><circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/></svg></div>
                    <div><span>Total Sections</span><strong><?= $totalSections ?></strong></div>
                </div>
            </section>

            <section class="charts">
                <div class="card chart-card">
                    <h3>Students per Course</h3>
                    <div class="donut-wrap">
                        <div class="donut" style="background: <?= $donutStyle ?>">
                            <div class="donut-center"><strong><?= $donutTotal ?></strong><span>Students</span></div>
                        </div>
                        <ul class="legend">
                            <?php if (!$byCourse): ?><li class="muted">No students yet</li><?php endif; ?>
                            <?php foreach ($byCourse as $i => $r): ?>
                                <li><span class="swatch" style="background: <?= $palette[$i % count($palette)] ?>"></span>
                                    <?= htmlspecialchars($r['course']) ?> &ndash; <?= $r['c'] ?> (<?= $pct($r['c'], $donutTotal) ?>)</li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <div class="card chart-card">
                    <h3>Enrollment per Year Level</h3>
                    <div class="bars">
                        <?php foreach ($byYear as $y => $count): ?>
                            <div class="bar-col">
                                <span class="bar-value"><?= $count ?></span>
                                <div class="bar" style="height: <?= round($count / $yearMax * 100) ?>%"></div>
                                <span class="bar-label"><?= ordinal($y) ?> Year</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="card report-card">
                <div class="report-head">
                    <h2><?= $tabs[$tab] ?></h2>
                    <div class="report-actions">
                        <a href="<?= $exportUrl ?>" class="btn-excel">Export to Excel</a>
                        <button type="button" class="btn-pdf" onclick="window.print()">Export to PDF</button>
                        <button type="button" class="btn-print" onclick="window.print()">Print</button>
                    </div>
                </div>

                <?php if ($tab === 'student'): ?>
                    <table>
                        <thead><tr><th>#</th><th>Student ID</th><th>Name</th><th>Course</th><th>Year Level</th><th>Section</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if (!$pageRows): ?><tr><td colspan="7" class="empty">No students match these filters.</td></tr><?php endif; ?>
                        <?php foreach ($pageRows as $i => $r): ?>
                            <tr>
                                <td><?= $offset + $i + 1 ?></td>
                                <td><?= htmlspecialchars($r['student_id']) ?></td>
                                <td><?= htmlspecialchars($r['last_name'] . ', ' . $r['first_name']) ?></td>
                                <td><?= htmlspecialchars($r['course']) ?></td>
                                <td>Year <?= htmlspecialchars($r['year_level']) ?></td>
                                <td><?= htmlspecialchars($r['section']) ?></td>
                                <td><span class="badge <?= $r['status'] === 'Inactive' ? 'badge-inactive' : 'badge-active' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="pager">
                        <span>Showing <?= $filteredTotal ? $offset + 1 : 0 ?> to <?= min($offset + $perPage, $filteredTotal) ?> of <?= $filteredTotal ?> students</span>
                        <div class="pages">
                            <?php if ($page > 1): ?><a href="<?= page_url($page - 1, $keep) ?>">&lsaquo;</a><?php endif; ?>
                            <?php for ($p2 = 1; $p2 <= $pages; $p2++): ?>
                                <?php if ($p2 === $page): ?><span class="current"><?= $p2 ?></span>
                                <?php else: ?><a href="<?= page_url($p2, $keep) ?>"><?= $p2 ?></a><?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($page < $pages): ?><a href="<?= page_url($page + 1, $keep) ?>">&rsaquo;</a><?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($tab === 'enrollment'): ?>
                    <table>
                        <thead><tr><th>Year Level</th><th>Students</th><th>Share of Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($byYear as $y => $count): ?>
                            <tr>
                                <td><?= ordinal($y) ?> Year</td>
                                <td><?= $count ?></td>
                                <td><?= $pct($count, $filteredTotal) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php elseif ($tab === 'course'): ?>
                    <table>
                        <thead><tr><th>Course Code</th><th>Course Name</th><th>Year Levels</th><th>Sections</th><th>Students</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if (!$courseRows): ?><tr><td colspan="6" class="empty">No courses found.</td></tr><?php endif; ?>
                        <?php foreach ($courseRows as $r): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['code']) ?></strong></td>
                                <td><?= htmlspecialchars($r['name']) ?></td>
                                <td><?= $r['years'] ?></td>
                                <td><?= $r['sections'] ?></td>
                                <td><?= $r['students'] ?></td>
                                <td><span class="badge <?= $r['status'] === 'Inactive' ? 'badge-inactive' : 'badge-active' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php elseif ($tab === 'section'): ?>
                    <table>
                        <thead><tr><th>Course</th><th>Year Level</th><th>Section</th><th>Students</th></tr></thead>
                        <tbody>
                        <?php if (!$sectionRows): ?><tr><td colspan="4" class="empty">No sections match these filters.</td></tr><?php endif; ?>
                        <?php foreach ($sectionRows as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['code']) ?></td>
                                <td><?= ordinal((int)$r['year_level']) ?> Year</td>
                                <td><?= htmlspecialchars($r['name']) ?></td>
                                <td><?= $r['students'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                <?php else: ?>
                    <table>
                        <thead><tr><th>Summary</th><th>Count</th><th>Share of Total</th></tr></thead>
                        <tbody>
                            <tr><td>Total Students</td><td><?= $filteredTotal ?></td><td>100%</td></tr>
                            <tr><td>Active Students</td><td><?= $statusCounts['Active'] ?></td><td><?= $pct($statusCounts['Active'], $filteredTotal) ?></td></tr>
                            <tr><td>Inactive Students</td><td><?= $statusCounts['Inactive'] ?></td><td><?= $pct($statusCounts['Inactive'], $filteredTotal) ?></td></tr>
                            <tr><td>Courses</td><td><?= $totalCourses ?></td><td>-</td></tr>
                            <tr><td>Year Levels</td><td><?= $totalYears ?></td><td>-</td></tr>
                            <tr><td>Sections</td><td><?= $totalSections ?></td><td>-</td></tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
