<?php

namespace App\Controllers;

use App\Services\CsvService;
use App\Models\Inventory;
use App\Models\ImportHistory;

class InventoryController
{
    private $csvService;
    private $inventoryModel;
    private $importHistory;

    public function __construct()
    {
        $this->csvService = new CsvService();
        $this->inventoryModel = new Inventory();
        $this->importHistory = new ImportHistory();
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
            // Create import history session
            $fileSizeMb = round(filesize($targetPath) / 1024 / 1024, 2);
            $sessionId = $this->importHistory->createSession('inventory', $file['name'], $fileSizeMb);
            
            echo json_encode([
                'success' => true, 
                'file_id' => $filename,
                'session_id' => $sessionId
            ]);
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
                'preview_rows' => $validation['valid_rows'],
                'error_rows' => $validation['errors'],
                'warning_rows' => $validation['warnings'] ?? [],
                'total_preview_count' => count($chunk['rows']),
                'valid_count' => count($validation['valid_rows']),
                'error_count' => count($validation['errors']),
                'warning_count' => count($validation['warnings'] ?? []),
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
        $sessionId = $input['session_id'] ?? null;
        $limit = 5000; // Increased to 5000 for better throughput on large datasets

        $filePath = __DIR__ . '/../../storage/uploads/' . basename($fileId);

        if (!file_exists($filePath)) {
            // Mark session as failed if provided
            if ($sessionId) {
                $this->importHistory->completeSession($sessionId, 'failed');
            }
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

            // Update import history
            if ($sessionId) {
                // Get current totals from session
                $session = $this->importHistory->getById($sessionId);
                $currentTotal = $session['total_rows'] ?? 0;
                $currentValid = $session['valid_rows'] ?? 0;
                $currentErrors = $session['error_rows'] ?? 0;
                
                // Add this chunk's counts
                $newTotal = $currentTotal + count($chunkData['rows']);
                $newValid = $currentValid + count($validation['valid_rows']);
                $newErrors = $currentErrors + count($validation['errors']);
                
                // Update progress with error details
                $this->importHistory->updateProgress(
                    $sessionId, 
                    $newTotal, 
                    $newValid, 
                    $newErrors,
                    $validation['errors'] // Store detailed errors
                );

                // Mark as completed if this is the last chunk
                if ($chunkData['is_complete']) {
                    $this->importHistory->completeSession($sessionId, 'completed');
                }
            }

            echo json_encode([
                'success' => true,
                'next_offset' => $chunkData['next_offset'],
                'is_complete' => $chunkData['is_complete'],
                'progress' => $chunkData['progress'],
                'processed_count' => count($chunkData['rows']),
                'valid_count' => count($validation['valid_rows']),
                'error_count' => count($validation['errors']),
                'warning_count' => count($validation['warnings'] ?? []),
                'session_id' => $sessionId
            ]);

        } catch (\Exception $e) {
            // Mark session as failed
            if ($sessionId) {
                $this->importHistory->completeSession($sessionId, 'failed');
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function history()
    {
        require __DIR__ . '/../../views/history.php';
    }

    public function getHistory()
    {
        header('Content-Type: application/json');
        
        $module = $_GET['module'] ?? 'inventory';
        $limit = (int) ($_GET['limit'] ?? 50);
        $offset = (int) ($_GET['offset'] ?? 0);
        
        $records = $this->importHistory->getAll($module, $limit, $offset);
        $stats = $this->importHistory->getStats($module);
        
        echo json_encode([
            'success' => true,
            'records' => $records,
            'stats' => $stats
        ]);
    }

    public function getHistoryDetail()
    {
        header('Content-Type: application/json');
        
        $sessionId = (int) ($_GET['id'] ?? 0);
        
        if (!$sessionId) {
            echo json_encode(['success' => false, 'message' => 'Session ID required']);
            return;
        }
        
        $session = $this->importHistory->getById($sessionId);
        
        if (!$session) {
            echo json_encode(['success' => false, 'message' => 'Session not found']);
            return;
        }
        
        echo json_encode([
            'success' => true,
            'session' => $session
        ]);
    }

    private function getValidationRules()
    {
        return [
            'sku' => 'required|unique_in_file',
            'product_name' => 'required',
            'quantity' => 'required|numeric',
            'price' => 'numeric'
        ];
    }
}
