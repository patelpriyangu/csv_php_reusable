<?php

namespace App\Services;

class CsvService
{

    /**
     * Reads a CSV file and returns an associative array of rows.
     */
    public function readCsv(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \Exception("File not found or not readable.");
        }

        $rows = [];
        if (($handle = fopen($filePath, "r")) !== false) {
            // Get Header
            $header = fgetcsv($handle, 1000, ",");
            // Remove BOM if present in first key
            if ($header && isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }

            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                // Skip empty lines vs header mismatch
                if (count($header) !== count($data)) {
                    continue;
                }
                $rows[] = array_combine($header, $data);
            }
            fclose($handle);
        }
        return $rows;
    }
    /**
     * Reads a chunk of the CSV starting from a byte offset.
     * Efficient for large files (O(1) memory).
     * 
     * @return array ['rows' => [], 'next_offset' => int, 'progress' => float]
     */
    public function readCsvChunk(string $filePath, int $startByte = 0, int $limit = 1000): array
    {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found.");
        }

        $handle = fopen($filePath, "r");
        fseek($handle, $startByte);

        $rows = [];
        $header = [];

        // Always read header to map keys
        // In a real optimized system, we'd cache the header, but reading line 1 is fast enough.
        $headerHandle = fopen($filePath, "r");
        $header = fgetcsv($headerHandle, 1000, ",");
        if ($header && isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        fclose($headerHandle);

        // If we are at start (0), skip the header line in the main handle
        if ($startByte === 0) {
            fgetcsv($handle); // discard header
        }

        $count = 0;
        while (($data = fgetcsv($handle, 1000, ",")) !== false) {
            if ($count >= $limit) {
                break;
            }

            if (count($header) === count($data)) {
                $rows[] = array_combine($header, $data);
            }
            $count++;
        }

        $nextOffset = ftell($handle);
        $eof = feof($handle);
        $totalSize = filesize($filePath);
        $progress = $totalSize > 0 ? round(($nextOffset / $totalSize) * 100, 2) : 100;

        fclose($handle);

        return [
            'rows' => $rows,
            'next_offset' => $nextOffset,
            'is_complete' => $eof || (empty($rows) && $count < $limit), // EOF or no more rows read
            'progress' => $progress
        ];
    }
    /**
     * Validates rows against a set of rules.
     * Rules format: ['field_name' => 'required|numeric|unique:table,col']
     * 
     * Returns: [
     *   'valid_rows' => [],
     *   'errors' => [ row_index => [ 'field' => 'error message' ] ]
     * ]
     */
    public function validate(array $rows, array $rules, ?callable $dbChecker = null): array
    {
        $validRows = [];
        $errors = [];

        // Pre-parse rules to avoid explode() overhead inside the loop
        $parsedRules = [];
        foreach ($rules as $field => $ruleString) {
            $parsedRules[$field] = explode('|', $ruleString);
        }

        foreach ($rows as $index => $row) {
            $rowErrors = [];
            foreach ($parsedRules as $field => $ruleList) {
                $value = $row[$field] ?? null;

                foreach ($ruleList as $rule) {
                    // Simple Validation Logic
                    if ($rule === 'required' && ($value === '' || $value === null)) {
                        $rowErrors[$field] = "$field is required.";
                        break;
                    }
                    if ($rule === 'numeric' && !is_numeric($value)) {
                        $rowErrors[$field] = "$field must be a number.";
                    }
                    if (str_starts_with($rule, 'min:')) {
                        $min = (int) substr($rule, 4);
                        if (strlen($value) < $min) {
                            $rowErrors[$field] = "$field must be at least $min chars.";
                        }
                    }
                    if (str_starts_with($rule, 'custom_unique') && $dbChecker) {
                        if (!$dbChecker($field, $value)) {
                            $rowErrors[$field] = "$field must be unique.";
                        }
                    }
                }
            }

            if (!empty($rowErrors)) {
                $errors[$index + 1] = $rowErrors;
            } else {
                $validRows[] = $row;
            }
        }

        return ['valid_rows' => $validRows, 'errors' => $errors];
    }

    /**
     * Generates a CSV download from an array of data.
     */
    public function downloadCsv(array $data, string $filename = 'export.csv')
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // Header
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
        }

        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    /**
     * Generates an empty CSV template based on expected headers.
     */
    public function downloadTemplate(array $headers, string $filename = 'template.csv')
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        fputcsv($output, $headers);
        fclose($output);
        exit;
    }
}
