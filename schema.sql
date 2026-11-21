CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) NOT NULL UNIQUE,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    course VARCHAR(50),
    section VARCHAR(20),
    year_level INT DEFAULT 1
);

-- These add columns to a students table created before this change
ALTER TABLE students ADD COLUMN IF NOT EXISTS section VARCHAR(20) AFTER course;
ALTER TABLE students ADD COLUMN IF NOT EXISTS status VARCHAR(10) NOT NULL DEFAULT 'Active' AFTER section;
ALTER TABLE students ADD COLUMN IF NOT EXISTS average DECIMAL(5,2) NULL AFTER status;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL
);

-- Account details for the Settings page
ALTER TABLE users ADD COLUMN IF NOT EXISTS full_name VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(100) NULL;

-- Courses, year levels, and sections
CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'Active'
);

CREATE TABLE IF NOT EXISTS course_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    year_level INT NOT NULL,
    UNIQUE KEY uniq_course_year (course_id, year_level),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_year_id INT NOT NULL,
    name VARCHAR(30) NOT NULL,
    UNIQUE KEY uniq_section (course_year_id, name),
    FOREIGN KEY (course_year_id) REFERENCES course_years(id) ON DELETE CASCADE
);

-- System settings (key and value pairs)
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255) NULL
);
