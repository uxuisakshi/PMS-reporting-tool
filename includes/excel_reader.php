<?php
/**
 * Simple Excel Reader
 * Converts Excel files (.xlsx, .xls) to CSV-like array format
 * Uses PHP's built-in ZIP functions for .xlsx files
 */

function readExcelFile($filePath, $fileName = null) {
    // If fileName not provided, try to get extension from filePath
    if ($fileName === null) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    } else {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    }
    
    if ($ext === 'xlsx') {
        return readXlsxFile($filePath);
    } elseif ($ext === 'xls') {
        // For old .xls format, we'll try to use a simple parser
        // If not available, return error
        return readXlsFile($filePath);
    } elseif ($ext === 'csv') {
        return readCsvFile($filePath);
    }
    
    return ['error' => 'Unsupported file format: ' . $ext];
}

function readXlsxFile($filePath) {
    if (!class_exists('ZipArchive')) {
        return ['error' => 'ZipArchive extension not available'];
    }
    
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return ['error' => 'Unable to open Excel file'];
    }
    
    // Read shared strings
    $sharedStrings = [];
    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringsXml) {
        $xml = simplexml_load_string($sharedStringsXml);
        if ($xml) {
            foreach ($xml->si as $si) {
                $sharedStrings[] = (string)$si->t;
            }
        }
    }
    
    // Read first worksheet
    $worksheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$worksheetXml) {
        $zip->close();
        return ['error' => 'No worksheet found'];
    }
    
    $xml = simplexml_load_string($worksheetXml);
    if (!$xml) {
        $zip->close();
        return ['error' => 'Unable to parse worksheet'];
    }
    
    $rows = [];
    $currentRow = [];
    $lastRow = 0;
    
    foreach ($xml->sheetData->row as $row) {
        $rowNum = (int)$row['r'];
        $currentRow = [];
        $lastCol = 0;
        
        foreach ($row->c as $cell) {
            $cellRef = (string)$cell['r'];
            $colNum = getColumnNumber($cellRef);
            
            // Fill empty cells
            while ($lastCol < $colNum - 1) {
                $currentRow[] = '';
                $lastCol++;
            }
            
            $value = '';
            $type = (string)$cell['t'];
            
            if ($type === 's') {
                // Shared string
                $index = (int)$cell->v;
                $value = isset($sharedStrings[$index]) ? $sharedStrings[$index] : '';
            } else {
                $value = (string)$cell->v;
            }
            
            $currentRow[] = $value;
            $lastCol = $colNum;
        }
        
        $rows[] = $currentRow;
    }
    
    $zip->close();
    return ['success' => true, 'rows' => $rows];
}

function readXlsFile($filePath) {
    // For .xls files, we need a more complex parser
    // For now, return an error message suggesting to save as .xlsx or .csv
    return ['error' => 'Old Excel format (.xls) not supported. Please save as .xlsx or .csv'];
}

function readCsvFile($filePath) {
    $rows = [];
    if (($fp = fopen($filePath, 'r')) !== false) {
        while (($row = fgetcsv($fp)) !== false) {
            $rows[] = $row;
        }
        fclose($fp);
        return ['success' => true, 'rows' => $rows];
    }
    return ['error' => 'Unable to read CSV file'];
}

function getColumnNumber($cellRef) {
    // Extract column letter from cell reference (e.g., "A1" -> "A", "AB5" -> "AB")
    preg_match('/^([A-Z]+)/', $cellRef, $matches);
    if (!isset($matches[1])) return 0;
    
    $col = $matches[1];
    $num = 0;
    $len = strlen($col);
    
    for ($i = 0; $i < $len; $i++) {
        $num = $num * 26 + (ord($col[$i]) - ord('A') + 1);
    }
    
    return $num;
}
