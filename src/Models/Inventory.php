<?php

namespace App\Models;

use App\Core\Database;
use PDO;

class Inventory
{
    private $pdo;

    public function __construct()
    {
        $db = new Database();
        $this->pdo = $db->getConnection();
    }

    public function getAll()
    {
        $stmt = $this->pdo->query("SELECT sku, product_name, quantity, price FROM inventory");
        return $stmt->fetchAll();
    }

    public function checkExists(string $field, $value): bool
    {
        // Allowed fields purely for safety
        if (!in_array($field, ['sku'])) {
            return false;
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM inventory WHERE $field = ?");
        $stmt->execute([$value]);
        return $stmt->fetchColumn() > 0;
    }

    public function insertBatch(array $rows)
    {
        if (empty($rows))
            return true;

        $pdo = $this->pdo;

        // Chunk the SQL insertion itself to avoid MySQL "Packet too large" errors (max_allowed_packet)
        // Even if we process 5000 rows in PHP, we might split SQL into batches of 1000
        $batchSize = 1000;
        $chunks = array_chunk($rows, $batchSize);

        try {
            $pdo->beginTransaction();

            foreach ($chunks as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as $index => $row) {
                    $skuParam = ":sku$index";
                    $nameParam = ":name$index";
                    $qtyParam = ":qty$index";
                    $priceParam = ":price$index";

                    $values[] = "($skuParam, $nameParam, $qtyParam, $priceParam)";

                    $params[$skuParam] = $row['sku'];
                    $params[$nameParam] = $row['product_name'] ?? $row['name'];
                    $params[$qtyParam] = $row['quantity'];
                    $params[$priceParam] = $row['price'];
                }

                $sql = "INSERT INTO inventory (sku, product_name, quantity, price) VALUES " . implode(',', $values) . "
                        ON DUPLICATE KEY UPDATE product_name = VALUES(product_name), quantity = VALUES(quantity), price = VALUES(price)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }

            $pdo->commit();
            return true;
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
