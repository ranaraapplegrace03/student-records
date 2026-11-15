<?php
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
    <title>Student Records</title>
</head>
<body>
    <h2>Student Records</h2>

    <?php if ($message !== ''): ?>
        <p><strong><?= htmlspecialchars($message) ?></strong></p>
    <?php endif; ?>

    <form method="post">
        Student ID: <input type="text" name="student_id" required>
        First Name: <input type="text" name="first_name" required>
        Last Name: <input type="text" name="last_name" required>
        Course: <input type="text" name="course">
        Year Level: <input type="number" name="year_level" value="1" min="1" max="6">
        <button type="submit">Add Student</button>
    </form>

    <br>
    <form method="get">
        Search (Student ID, last name, or course): <input type="text" name="search" value="<?= htmlspecialchars($search) ?>">
        <button type="submit">Search</button>
        <a href="index.php">Clear</a>
    </form>

    <br>
    <table border="1" cellpadding="4">
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
</body>
</html>