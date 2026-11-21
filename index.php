<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = trim($_POST['student_id'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $course     = trim($_POST['course'] ?? '');
    $year_level = (int)($_POST['year_level'] ?? 1);

    if ($student_id !== '' && $first_name !== '' && $last_name !== '') {
        $stmt = $conn->prepare("INSERT INTO students (student_id, first_name, last_name, course, year_level) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $student_id, $first_name, $last_name, $course, $year_level);
        if ($stmt->execute()) {
            $message = "Student added.";
        } else {
            $message = "Error: the Student ID may already exist.";
        }
        $stmt->close();
    } else {
        $message = "Student ID, first name, and last name are required.";
    }
}

$search = trim($_GET['search'] ?? '');
if ($search !== '') {
    $like = "%" . $search . "%";
    $stmt = $conn->prepare("SELECT id, student_id, first_name, last_name, course, year_level FROM students WHERE student_id LIKE ? OR last_name LIKE ? OR course LIKE ? ORDER BY last_name");
    $stmt->bind_param("sss", $like, $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT id, student_id, first_name, last_name, course, year_level FROM students ORDER BY last_name");
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
<body class="dashboard">
    <header class="topbar">
        <span class="topbar-title">Student Records System</span>
        <span class="topbar-user">
            Signed in as <strong><?= htmlspecialchars($_SESSION['username']) ?></strong>
            <a href="logout.php">Log out</a>
        </span>
    </header>

    <main class="content">
        <?php if ($message !== ''): ?>
            <p class="notice"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>

        <section class="panel">
            <h3>Add Student</h3>
            <form method="post" class="inline-form">
                <input type="text" name="student_id" placeholder="Student ID" required>
                <input type="text" name="first_name" placeholder="First Name" required>
                <input type="text" name="last_name" placeholder="Last Name" required>
                <input type="text" name="course" placeholder="Course">
                <input type="number" name="year_level" value="1" min="1" max="6" title="Year Level">
                <button type="submit" class="btn">Add Student</button>
            </form>
        </section>

        <section class="panel">
            <form method="get" class="inline-form">
                <input type="text" name="search" placeholder="Search by Student ID, last name, or course"
                       value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn">Search</button>
                <a href="index.php" class="clear">Clear</a>
            </form>

            <table>
                <tr><th>ID</th><th>Student ID</th><th>First Name</th><th>Last Name</th><th>Course</th><th>Year Level</th></tr>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['id']) ?></td>
                    <td><?= htmlspecialchars($row['student_id']) ?></td>
                    <td><?= htmlspecialchars($row['first_name']) ?></td>
                    <td><?= htmlspecialchars($row['last_name']) ?></td>
                    <td><?= htmlspecialchars($row['course']) ?></td>
                    <td><?= htmlspecialchars($row['year_level']) ?></td>
                </tr>
                <?php endwhile; ?>
            </table>
        </section>
    </main>
</body>
</html>
