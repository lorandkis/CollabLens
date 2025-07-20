<?php

$dsn = 'mysql:host=db;dbname=myapp;charset=utf8';
$user = 'appuser';
$pass = 'apppass';


function readXlsx($filePath) {
    $zip = new ZipArchive;
    if ($zip->open($filePath) === true) {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        $strings = [];
        if ($xml !== false) {
            $dom = new DOMDocument();
            $dom->loadXML($xml);
            foreach ($dom->getElementsByTagName('si') as $si) {
                $strings[] = $si->textContent;
            }
        }

        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $data = [];
        if ($xml !== false) {
            $dom = new DOMDocument();
            $dom->loadXML($xml);
            foreach ($dom->getElementsByTagName('row') as $row) {
                $rowData = [];
                foreach ($row->getElementsByTagName('c') as $c) {
                    $v = $c->getElementsByTagName('v')->item(0);
                    if ($v) {
                        $val = $v->nodeValue;
                        $type = $c->getAttribute('t');
                        $rowData[] = ($type === 's') ? $strings[$val] : $val;
                    } else {
                        $rowData[] = null;
                    }
                }
                $data[] = $rowData;
            }
        }
        $zip->close();
        return $data;
    }
    return [];
}

function excelSerialToDateTime($serial) {
    $baseDate = new DateTime('1899-12-30');
    $interval = new DateInterval('PT' . round($serial * 86400) . 'S');
    $baseDate->add($interval);
    return $baseDate->format('Y-m-d H:i:s');
}

function sanitizeRow($row) {
    return array_map(function ($val) {
        if ($val === null || $val === '') return null;

        $val = html_entity_decode(trim((string)$val)); // Safe cast to string

        if (is_numeric($val) && $val > 10000 && $val < 60000) {
            return excelSerialToDateTime((float)$val);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $val) || preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}/', $val)) {
            return date("Y-m-d H:i:s", strtotime($val));
        }

        return $val;
    }, $row);
}


function isValidForeignKey($pdo, $table, $column, $value) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?");
    $stmt->execute([$value]);
    return $stmt->fetchColumn() > 0;
}

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS organization (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            primary_location VARCHAR(100) NOT NULL
        );

        CREATE TABLE IF NOT EXISTS professors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            department VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (org_id) REFERENCES organization(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            professor_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            discord_server_id VARCHAR(50) UNIQUE,
            sharepoint_site_id VARCHAR(100),
            sharepoint_folder_id VARCHAR(100),
            due_date DATETIME NOT NULL,
            status ENUM('active', 'completed', 'archived') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (professor_id) REFERENCES professors(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS `groups` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            discord_channel_id VARCHAR(50) UNIQUE,
            sharepoint_folder_id VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS group_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            student_id INT NOT NULL,
            discord_user_id VARCHAR(50) UNIQUE,
            discord_username VARCHAR(100),
            status ENUM('unregistered', 'registered', 'removed') DEFAULT 'unregistered',
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            UNIQUE KEY unique_group_student (group_id, student_id)
        );

        CREATE TABLE IF NOT EXISTS discord_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id VARCHAR(50) UNIQUE NOT NULL,
            channel_id VARCHAR(50) NOT NULL,
            author_id VARCHAR(50) NOT NULL,
            content TEXT,
            timestamp TIMESTAMP NOT NULL,
            group_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS sharepoint_activities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_id VARCHAR(100) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            activity_type ENUM('created', 'modified', 'deleted', 'shared') NOT NULL,
            user_id VARCHAR(100) NOT NULL,
            user_email VARCHAR(255),
            folder_id VARCHAR(100),
            group_id INT,
            timestamp TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE SET NULL
        );
    ");

    $tables = [
        'organization',
        'professors',
        'students',
        'assignments',
        'groups',
        'group_members',
        'discord_messages',
        'sharepoint_activities'
    ];

    foreach ($tables as $table) {
        $filePath = __DIR__ . "/test_data/{$table}.xlsx";
        if (!file_exists($filePath)) {
            echo "⚠️  File not found: $filePath\n";
            continue;
        }

        $rows = readXlsx($filePath);
        if (count($rows) < 2) continue;

        $headers = array_map('strtolower', $rows[0]);
        $placeholders = implode(', ', array_fill(0, count($headers), '?'));
        $columns = implode(', ', array_map(fn($col) => "`$col`", $headers));
        $stmt = $pdo->prepare("INSERT IGNORE INTO `$table` ($columns) VALUES ($placeholders)");

        for ($i = 1; $i < count($rows); $i++) {
            $row = array_pad($rows[$i], count($headers), null);
            $row = sanitizeRow($row);


            // FK checks for discord_messages and sharepoint_activities
            if (in_array($table, ['discord_messages', 'sharepoint_activities'])) {
                $groupIdIdx = array_search('group_id', $headers);
                if ($groupIdIdx !== false && $row[$groupIdIdx] !== null) {
                    if (!isValidForeignKey($pdo, 'groups', 'id', $row[$groupIdIdx])) {
                        echo "⚠️  Skipped $table row " . ($i + 1) . ": group_id " . $row[$groupIdIdx] . " does not exist\n";
                        continue;
                    }
                }
            }

            try {
                $stmt->execute($row);
            } catch (PDOException $e) {
                echo "❌ Insert error on $table row " . ($i + 1) . ": " . $e->getMessage() . "\n";
            }
        }
        echo "✅ Inserted into $table\n";
    }

    echo "✅ All data processed.\n";

} catch (PDOException $e) {
    echo "❌ PDO Error: " . $e->getMessage();
} catch (Exception $e) {
    echo "❌ General Error: " . $e->getMessage();
}
?>
