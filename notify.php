<?php
// Shows the notifications bell in the top bar when notifications are turned on in Settings
function render_notifications($conn) {
    try {
        $row = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'notifications'")->fetch_assoc();
        $enabled = $row ? $row['setting_value'] === '1' : true;
        if (!$enabled) return;

        $alerts = [];
        $n = (int)$conn->query("SELECT COUNT(*) AS c FROM students WHERE status = 'Inactive'")->fetch_assoc()['c'];
        if ($n) $alerts[] = $n . ' inactive student' . ($n === 1 ? '' : 's');

        $n = (int)$conn->query("SELECT COUNT(*) AS c FROM courses c WHERE NOT EXISTS (SELECT 1 FROM course_years cy WHERE cy.course_id = c.id)")->fetch_assoc()['c'];
        if ($n) $alerts[] = $n . ' course' . ($n === 1 ? ' has' : 's have') . ' no year levels yet';

        $n = (int)$conn->query("SELECT COUNT(*) AS c FROM course_years cy WHERE NOT EXISTS (SELECT 1 FROM sections s WHERE s.course_year_id = cy.id)")->fetch_assoc()['c'];
        if ($n) $alerts[] = $n . ' year level' . ($n === 1 ? ' has' : 's have') . ' no sections yet';

        $backupDir = '/var/www/student-backups';
        $backups = is_dir($backupDir) ? glob($backupDir . '/*.json') : [];
        if (!$backups) $alerts[] = 'No backup has been created yet';

        echo '<details class="notify">';
        echo '<summary class="bell-btn" aria-label="Notifications">';
        echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/></svg>';
        if ($alerts) echo '<span class="bell-dot"></span>';
        echo '</summary>';
        echo '<div class="notify-menu"><strong>Notifications</strong>';
        if (!$alerts) {
            echo '<p class="muted">You have no notifications.</p>';
        } else {
            echo '<ul>';
            foreach ($alerts as $a) {
                echo '<li>' . htmlspecialchars($a) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div></details>';
    } catch (Throwable $e) {
        // Keep the page working even if the notifications check fails
    }
}
