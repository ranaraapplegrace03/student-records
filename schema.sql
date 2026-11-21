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
