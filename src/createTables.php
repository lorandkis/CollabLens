<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

// ---- Config via env, with sensible defaults ----
$DB_HOST = 'db';
$DB_PORT = '3306';
$DB_NAME = 'myapp';
$DB_USER = 'appuser';
$DB_PASS = 'apppass';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]
    );

    // ---- 1) Schema ----
    $ddls = [
        "CREATE TABLE IF NOT EXISTS organization (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            primary_location VARCHAR(100) NOT NULL,
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS professors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            department VARCHAR(100),
            email_verified_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_professors_org
            FOREIGN KEY (org_id) REFERENCES organization(id)
            ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id VARCHAR(50) UNIQUE NOT NULL,
            org_id INT NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email_verified_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_students_org
            FOREIGN KEY (org_id) REFERENCES organization(id)
            ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS classes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            professor_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            term ENUM('fall', 'winter', 'spring', 'intersession', 'summer'),
            description TEXT,
            discord_server_id VARCHAR(50) UNIQUE,
            sharepoint_site_id VARCHAR(255),
            sharepoint_folder_id VARCHAR(255),
            status ENUM('active', 'completed', 'archived') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_classes_org
            FOREIGN KEY (org_id) REFERENCES organization(id)
            ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_classes_prof
            FOREIGN KEY (professor_id) REFERENCES professors(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            discord_channels_made INT DEFAULT 0,
            sharepoint_site_id VARCHAR(100),
            sharepoint_folder_id VARCHAR(100),
            due_date DATETIME NOT NULL,
            status ENUM('active', 'completed', 'archived') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_assignments_class
            FOREIGN KEY (class_id) REFERENCES classes(id)
            ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS assignment_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            discord_channel_id VARCHAR(50) UNIQUE,
            sharepoint_folder_id VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_groups_assignment
            FOREIGN KEY (assignment_id) REFERENCES assignments(id)
            ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT uq_group_name_per_assignment UNIQUE (assignment_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS group_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            student_id INT NOT NULL,
            discord_user_id VARCHAR(50),
            discord_username VARCHAR(100),
            status ENUM('unregistered', 'registered', 'removed') DEFAULT 'unregistered',
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_group_members_group
            FOREIGN KEY (group_id) REFERENCES assignment_groups(id)
            ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_group_members_student
            FOREIGN KEY (student_id) REFERENCES students(id)
            ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT uq_group_members UNIQUE (group_id, student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS discord_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id VARCHAR(50) UNIQUE NOT NULL,
            channel_id VARCHAR(50) NOT NULL,
            author_id VARCHAR(50) NOT NULL,
            content TEXT,
            timestamp TIMESTAMP NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS sharepoint_activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_id VARCHAR(100) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            activity_type ENUM('created', 'modified', 'deleted', 'shared') NOT NULL,
            user_id VARCHAR(255),
            user_email VARCHAR(255),
            folder_id VARCHAR(100),
            timestamp TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    ];

    foreach ($ddls as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            echo "DDL error:\n$sql\n---\n" . $e->getMessage() . "\n";
            throw $e;
        }
    }
    echo "Schema ensured.\n";
    // Seeding skipped: createTables now only ensures schema creation and does not insert test data.

} catch (PDOException $e) {
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
    echo "PDO Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
    echo "General Error: " . $e->getMessage() . "\n";
}
