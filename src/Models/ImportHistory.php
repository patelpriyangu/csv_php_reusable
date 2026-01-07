<?php

namespace App\Models;

use App\Core\Database;
use PDO;

class ImportHistory
{
    private $pdo;

    public function __construct()
    {
        $db = new Database();
        $this->pdo = $db->getConnection();
    }

    /**
     * Create a new import session
     * 
     * @param string $module Module name (e.g., 'inventory')
     * @param string $filename Original filename
     * @param float $fileSizeMb File size in MB
     * @return int Import session ID
     */
    public function createSession(string $module, string $filename, float $fileSizeMb): int
    {
        $sql = "INSERT INTO import_history (module, filename, file_size_mb, status, started_at) 
                VALUES (?, ?, ?, 'in_progress', NOW())";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$module, $filename, $fileSizeMb]);
        
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update import session progress
     * 
     * @param int $sessionId Import session ID
     * @param int $totalRows Total rows processed so far
     * @param int $validRows Valid rows count
     * @param int $errorRows Error rows count
     * @param array $errorDetails Detailed errors (optional, will be merged)
     */
    public function updateProgress(int $sessionId, int $totalRows, int $validRows, int $errorRows, array $errorDetails = [])
    {
        // Get existing error details and merge
        $existingErrors = $this->getErrorDetails($sessionId);
        $mergedErrors = array_merge($existingErrors, $errorDetails);
        
        $sql = "UPDATE import_history 
                SET total_rows = ?, 
                    valid_rows = ?, 
                    error_rows = ?,
                    error_details = ?
                WHERE id = ?";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $totalRows,
            $validRows,
            $errorRows,
            json_encode($mergedErrors),
            $sessionId
        ]);
    }

    /**
     * Mark import session as completed
     * 
     * @param int $sessionId Import session ID
     * @param string $status 'completed' or 'failed'
     */
    public function completeSession(int $sessionId, string $status = 'completed')
    {
        $sql = "UPDATE import_history 
                SET status = ?, 
                    completed_at = NOW(),
                    duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW())
                WHERE id = ?";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$status, $sessionId]);
    }

    /**
     * Get all import history records
     * 
     * @param string|null $module Filter by module
     * @param int $limit Number of records to retrieve
     * @param int $offset Offset for pagination
     * @return array
     */
    public function getAll(?string $module = null, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT id, module, filename, file_size_mb, total_rows, valid_rows, error_rows, 
                       status, started_at, completed_at, duration_seconds
                FROM import_history";
        
        if ($module) {
            $sql .= " WHERE module = ?";
        }
        
        $sql .= " ORDER BY started_at DESC LIMIT ? OFFSET ?";
        
        $stmt = $this->pdo->prepare($sql);
        
        if ($module) {
            $stmt->bindValue(1, $module, PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single import session by ID
     * 
     * @param int $sessionId
     * @return array|null
     */
    public function getById(int $sessionId): ?array
    {
        $sql = "SELECT * FROM import_history WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$sessionId]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['error_details']) {
            $result['error_details'] = json_decode($result['error_details'], true);
        }
        
        return $result ?: null;
    }

    /**
     * Get error details for a session
     * 
     * @param int $sessionId
     * @return array
     */
    private function getErrorDetails(int $sessionId): array
    {
        $sql = "SELECT error_details FROM import_history WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$sessionId]);
        
        $result = $stmt->fetchColumn();
        
        if ($result) {
            $decoded = json_decode($result, true);
            return is_array($decoded) ? $decoded : [];
        }
        
        return [];
    }

    /**
     * Get statistics summary
     * 
     * @param string|null $module
     * @return array
     */
    public function getStats(?string $module = null): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_imports,
                    SUM(total_rows) as total_rows_processed,
                    SUM(valid_rows) as total_valid_rows,
                    SUM(error_rows) as total_error_rows,
                    AVG(duration_seconds) as avg_duration_seconds
                FROM import_history
                WHERE status = 'completed'";
        
        if ($module) {
            $sql .= " AND module = ?";
        }
        
        $stmt = $this->pdo->prepare($sql);
        
        if ($module) {
            $stmt->execute([$module]);
        } else {
            $stmt->execute();
        }
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete old import history records
     * 
     * @param int $daysOld Delete records older than X days
     * @return int Number of deleted records
     */
    public function cleanOldRecords(int $daysOld = 90): int
    {
        $sql = "DELETE FROM import_history 
                WHERE started_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$daysOld]);
        
        return $stmt->rowCount();
    }
}

