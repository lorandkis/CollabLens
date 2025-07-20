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
        $val = html_entity_decode(trim($val));
        if ($val === '') return null;

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
        $stmt = $pdo->prepare("INSERT INTO `$table` ($columns) VALUES ($placeholders)");

        for ($i = 1; $i < count($rows); $i++) {
            $row = sanitizeRow($rows[$i]);

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