<?php

namespace App\Controllers;

use App\Services\CsvService;
use App\Models\Inventory;

class InventoryController
{
    private $csvService;
    private $inventoryModel;

    public function __construct()
    {
        $this->csvService = new CsvService();
        $this->inventoryModel = new Inventory();
    }

    public function index()
    {
        require __DIR__ . '/../../views/import.php';
    }

    public function downloadTemplate()
    {
        $this->csvService->downloadTemplate(['sku', 'product_name', 'quantity', 'price']);
    }

    public function export()
    {
        // Warning: For millions of rows, export also needs chunking (fputcsv in a loop to output stream).
        // But for now, we leave as memory-based per previous scope, as user asked about IMPORT.
        $data = $this->inventoryModel->getAll();
        $this->csvService->downloadCsv($data, 'inventory_export_' . date('Y-m-d') . '.csv');
    }

    public function upload()
    {
        header('Content-Type: application/json');

        if (!isset($_FILES['csv_file'])) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded']);
            return;
        }

        // Move file (do not read it yet)
        $file = $_FILES['csv_file'];
        $uploadDir = __DIR__ . '/../../storage/uploads/';
        if (!is_dir($uploadDir))
            mkdir($uploadDir, 0777, true);

        $filename = uniqid('import_') . '.csv';
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            echo json_encode(['success' => true, 'file_id' => $filename]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file. Check permissions.']);
        }
    }

    // Preview ONLY the first 20 rows
    public function preview()
    {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $fileId = $input['file_id'] ?? '';
        $filePath = __DIR__ . '/../../storage/uploads/' . basename($fileId);

        if (!file_exists($filePath)) {
            echo json_encode(['success' => false, 'message' => 'File not found.']);
            return;
        }

        try {
            // Read first 20 lines only
            $chunk = $this->csvService->readCsvChunk($filePath, 0, 20);

            // Validate ONLY these 20 lines for display purposes
            $rules = $this->getValidationRules();
            $validation = $this->csvService->validate($chunk['rows'], $rules);

            echo json_encode([
                'success' => true,
                'preview_rows' => $validation['valid_rows'], // Just showing valid ones for preview
                'total_preview_count' => count($chunk['rows']),
                'file_size_mb' => round(filesize($filePath) / 1024 / 1024, 2)
            ]);

        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function importChunk()
    {
        header('Content-Type: application/json');

        // Increase time limit for this chunk
        set_time_limit(120);

        $input = json_decode(file_get_contents('php://input'), true);
        $fileId = $input['file_id'] ?? '';
        $offset = $input['offset'] ?? 0;
        $limit = 5000; // Increased to 5000 for better throughput on large datasets

        $filePath = __DIR__ . '/../../storage/uploads/' . basename($fileId);

        if (!file_exists($filePath)) {
            echo json_encode(['success' => false, 'message' => 'File expired or missing.']);
            return;
        }

        try {
            // Read Chunk
            $chunkData = $this->csvService->readCsvChunk($filePath, $offset, $limit);

            // Validate
            $rules = $this->getValidationRules();
            $validation = $this->csvService->validate($chunkData['rows'], $rules);

            // Insert Valid Only (Skip errors in bulk mode for now, or log them)
            if (!empty($validation['valid_rows'])) {
                $this->inventoryModel->insertBatch($validation['valid_rows']);
            }

            echo json_encode([
                'success' => true,
                'next_offset' => $chunkData['next_offset'],
                'is_complete' => $chunkData['is_complete'],
                'progress' => $chunkData['progress'],
                'processed_count' => count($chunkData['rows']),
                'error_count' => count($validation['errors'])
            ]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function getValidationRules()
    {
        return [
            'sku' => 'required',
            'product_name' => 'required',
            'quantity' => 'required|numeric',
            'price' => 'numeric'
        ];
    }
}
