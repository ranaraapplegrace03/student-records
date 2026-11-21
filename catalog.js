// Dependent dropdowns: Course -> Year Level -> Section
// Uses window.CATALOG, which the page sets from catalog.php
(function () {
    const same = (a, b) => String(a) === String(b);

    function fill(sel, items, selected, placeholder) {
        sel.innerHTML = '';
        sel.add(new Option(placeholder, ''));
        const values = [];
        items.forEach(i => {
            sel.add(new Option(i.label, String(i.value)));
            values.push(String(i.value));
        });
        const value = (selected === null || selected === undefined) ? '' : String(selected);
        // Keep a saved value that is no longer in the catalog so editing doesn't lose it
        if (value !== '' && !values.includes(value)) {
            sel.add(new Option(value + ' (not in catalog)', value));
        }
        sel.value = value;
    }

    window.setupCascade = function (courseId, yearId, sectionId) {
        const courseSel = document.getElementById(courseId);
        const yearSel = document.getElementById(yearId);
        const sectionSel = document.getElementById(sectionId);
        const catalog = () => window.CATALOG || [];

        const findCourse = code => catalog().find(c => same(c.code, code));
        const findYear = (course, year) => course ? course.years.find(y => same(y.year, year)) : null;

        function courseItems() {
            return catalog().map(c => ({ value: c.code, label: c.code + ' - ' + c.name }));
        }

        function refreshSections(selected) {
            const course = findCourse(courseSel.value);
            const year = findYear(course, yearSel.value);
            const secs = year ? year.sections : [];
            let placeholder = 'Select section';
            if (!courseSel.value) placeholder = 'Select course first';
            else if (!yearSel.value) placeholder = 'Select year level first';
            else if (!secs.length) placeholder = 'No sections for this year level';
            fill(sectionSel, secs.map(s => ({ value: s, label: s })), selected, placeholder);
        }

        function refreshYears(selectedYear, selectedSection) {
            const course = findCourse(courseSel.value);
            const years = course ? course.years.map(y => y.year) : [];
            let placeholder = 'Select year level';
            if (!courseSel.value) placeholder = 'Select course first';
            else if (!years.length) placeholder = 'No year levels for this course';
            fill(yearSel, years.map(y => ({ value: y, label: 'Year ' + y })), selectedYear, placeholder);
            refreshSections(selectedSection);
        }

        courseSel.addEventListener('change', () => refreshYears(null, null));
        yearSel.addEventListener('change', () => refreshSections(null));

        return {
            reset() {
                fill(courseSel, courseItems(), '', 'Select course');
                refreshYears(null, null);
            },
            preset(course, year, section) {
                fill(courseSel, courseItems(), course, 'Select course');
                refreshYears(year, section);
            }
        };
    };
})();
