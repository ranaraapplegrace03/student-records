<?php
// Builds the list of active courses, year levels, and sections for the student forms
function load_catalog($conn) {
    $courses = $conn->query("SELECT id, code, name FROM courses WHERE status = 'Active' ORDER BY code")->fetch_all(MYSQLI_ASSOC);
    $years = $conn->query(
        "SELECT cy.course_id, cy.year_level, s.name AS section_name
         FROM course_years cy LEFT JOIN sections s ON s.course_year_id = cy.id
         ORDER BY cy.year_level, s.name"
    )->fetch_all(MYSQLI_ASSOC);

    $catalog = [];
    $index = [];
    foreach ($courses as $c) {
        $index[$c['id']] = count($catalog);
        $catalog[] = ['code' => $c['code'], 'name' => $c['name'], 'years' => []];
    }
    foreach ($years as $r) {
        if (!isset($index[$r['course_id']])) continue;
        $ci = $index[$r['course_id']];
        $y = (int)$r['year_level'];
        $pos = null;
        foreach ($catalog[$ci]['years'] as $k => $yy) {
            if ($yy['year'] === $y) { $pos = $k; break; }
        }
        if ($pos === null) {
            $catalog[$ci]['years'][] = ['year' => $y, 'sections' => []];
            $pos = array_key_last($catalog[$ci]['years']);
        }
        if ($r['section_name'] !== null) {
            $catalog[$ci]['years'][$pos]['sections'][] = $r['section_name'];
        }
    }
    return $catalog;
}
