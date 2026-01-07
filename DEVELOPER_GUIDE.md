# CSV Import/Export Engine - Complete Developer Guide

Everything you need to know in one file.

## Table of Contents

1. [Quick Start](#quick-start)
2. [Project Overview](#project-overview)
3. [Architecture](#architecture)
4. [API Reference](#api-reference)
5. [Code Structure](#code-structure)
6. [Common Tasks](#common-tasks)
7. [Extending the System](#extending-the-system)
8. [Troubleshooting](#troubleshooting)
9. [Production Deployment](#production-deployment)

---

## Quick Start

```bash
# 1. Database setup
mysql -u root -p < sql/full_schema.sql

# 2. Configure
# Edit config/database.php with your credentials

# 3. Start server
php -S localhost:8000 -t public

# 4. Test
# Open http://localhost:8000
# Upload config/products.csv
```

### Project Structure

```
├── config/              # Database config, test CSV files
├── public/
│   └── index.php       # Router, autoloader, entry point
├── sql/
│   └── full_schema.sql # Database schema
├── src/
│   ├── Controllers/    # HTTP request handlers
│   ├── Core/          # Database connection
│   ├── Models/        # Business logic, DB operations
│   └── Services/      # Reusable services (CSV)
├── storage/
│   └── uploads/       # Temporary uploaded files
└── views/             # HTML templates
```

### Requirements

- PHP 8.0+ (8.2+ recommended)
- MySQL 5.7+ or MariaDB 10.2+
- PDO + pdo_mysql extensions
- mbstring extension

---

## Project Overview

### What It Does

Imports and exports CSV files at scale. Key features:

- **Chunked processing**: Handles millions of rows without memory issues
- **Partial success**: Invalid rows don't kill the import
- **Validation**: Catches errors before database writes
- **Audit trail**: Complete history with detailed error reports
- **Duplicate detection**: Warns about duplicate keys
- **Module-agnostic**: Reusable for any data type

### Design Philosophy

1. **Memory-efficient**: Never loads entire file into memory
2. **Fast**: Batch inserts, byte-offset seeking
3. **Debuggable**: Detailed error logging
4. **Simple**: No frameworks, minimal dependencies
5. **Reusable**: Works for inventory, users, orders, anything

### Performance

Benchmarks (local MySQL, modest hardware):
- 10K rows: ~6 seconds
- 100K rows: ~60 seconds
- 1M rows: ~10 minutes
- 10M rows: ~100 minutes

Linear scaling. Bottleneck is database writes (~2K rows/sec).

---

## Architecture

### How Import Works

```
1. Upload (POST /upload)
   ├─ Save file to storage/uploads/
   ├─ Create import_history session
   └─ Return file_id + session_id

2. Preview (POST /preview)
   ├─ Read first 20 rows
   ├─ Validate them
   └─ Return valid/error/warning counts

3. Import Loop (POST /import-chunk)
   ├─ Read 5000 rows from byte offset
   ├─ Validate rows
   ├─ Insert valid rows (transaction)
   ├─ Update import_history
   └─ Return next offset + progress
   
   Repeat until is_complete = true

4. Complete
   └─ Mark session as completed
```

### Why Chunked Processing?

**Problem**: 100MB CSV = 1M rows won't fit in 128MB PHP memory limit.

**Solution**: Read file in 5K-row chunks using byte offsets.

```php
// Bad: Loads entire file
$data = array_map('str_getcsv', file($filepath));

// Good: Reads chunk at a time
fseek($handle, $byteOffset);  // Jump to position
// Read 5000 rows
```

**Benefits**:
- Constant memory (25MB per request regardless of file size)
- No timeouts (each chunk < 2 minutes)
- Progress tracking
- Recoverable (retry failed chunks)

### Why Byte Offsets?

**Row-based**: O(n) - have to parse all previous rows to get to row N  
**Byte-based**: O(1) - jump directly to byte position

For 1M rows in 200 chunks, byte offsets save parsing 99.5M rows.

### Core Components

**CsvService** (`src/Services/CsvService.php`)
- CSV operations only
- No business logic, no database
- Reusable across modules

**Controllers** (`src/Controllers/`)
- Handle HTTP requests
- Orchestrate operations
- Module-specific validation rules

**Models** (`src/Models/`)
- Database operations
- Business logic
- Batch inserts with transactions

**ImportHistory** (`src/Models/ImportHistory.php`)
- Audit log
- Tracks every import
- Stores detailed errors as JSON

### Database Schema

**inventory**
```sql
CREATE TABLE inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(50) NOT NULL UNIQUE,    -- Enables upsert
    product_name VARCHAR(255) NOT NULL,
    quantity INT DEFAULT 0,
    price DECIMAL(10, 2),               -- DECIMAL not FLOAT
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**import_history**
```sql
CREATE TABLE import_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(50) NOT NULL,        -- 'inventory', 'users', etc.
    filename VARCHAR(255),
    file_size_mb DECIMAL(10, 2),
    total_rows INT,
    valid_rows INT,
    error_rows INT,
    status ENUM('in_progress', 'completed', 'failed'),
    error_details JSON,                 -- Row-level errors
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    duration_seconds INT,
    INDEX idx_module (module),
    INDEX idx_status (status)
);
```

### Validation System

String-based rules:
```php
'sku' => 'required|unique_in_file'
'quantity' => 'required|numeric'
'price' => 'numeric'  // optional
```

**Available Rules**:
- `required` - Field cannot be empty
- `numeric` - Must be a number (allows empty if not required)
- `unique_in_file` - Detects duplicates (warning, not error)
- `min:X` - String length ≥ X characters

**Errors vs Warnings**:
- **Errors** = Row is skipped
- **Warnings** = Row is imported with notification

Duplicate SKUs are warnings because users often update existing records via CSV.

---

## API Reference

### Endpoints Summary

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/` | Main import UI |
| GET | `/download-template` | Download CSV template |
| POST | `/upload` | Upload CSV file |
| POST | `/preview` | Preview first 20 rows |
| POST | `/import-chunk` | Import one chunk |
| GET | `/export` | Export all data as CSV |
| GET | `/history` | Import history UI |
| GET | `/api/history` | History data (JSON) |
| GET | `/api/history/detail?id=X` | Session detail |

### Upload File

```http
POST /upload
Content-Type: multipart/form-data

Body: csv_file (file)
```

**Response**:
```json
{
  "success": true,
  "file_id": "import_63a8f2c4d1e3f.csv",
  "session_id": 42
}
```

### Preview File

```http
POST /preview
Content-Type: application/json

{
  "file_id": "import_63a8f2c4d1e3f.csv"
}
```

**Response**:
```json
{
  "success": true,
  "preview_rows": [...],
  "error_rows": {
    "3": {"sku": "sku is required."}
  },
  "warning_rows": {
    "5": {"sku": "Duplicate detected"}
  },
  "total_preview_count": 20,
  "valid_count": 18,
  "error_count": 1,
  "warning_count": 1,
  "file_size_mb": 2.5
}
```

### Import Chunk

```http
POST /import-chunk
Content-Type: application/json

{
  "file_id": "import_63a8f2c4d1e3f.csv",
  "session_id": 42,
  "offset": 0
}
```

**Response**:
```json
{
  "success": true,
  "next_offset": 245678,
  "is_complete": false,
  "progress": 25.5,
  "processed_count": 5000,
  "valid_count": 4987,
  "error_count": 13,
  "session_id": 42
}
```

**Loop pattern**:
```javascript
let offset = 0;
while (true) {
    const res = await fetch('/import-chunk', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({file_id, session_id, offset})
    });
    const data = await res.json();
    
    if (data.is_complete) break;
    offset = data.next_offset;
}
```

### Get Import History

```http
GET /api/history?module=inventory&limit=50&offset=0
```

**Response**:
```json
{
  "success": true,
  "records": [
    {
      "id": 42,
      "filename": "products.csv",
      "total_rows": 10000,
      "valid_rows": 9987,
      "error_rows": 13,
      "status": "completed",
      "duration_seconds": 135
    }
  ],
  "stats": {
    "total_imports": 15,
    "total_rows_processed": 150000,
    "avg_duration_seconds": 120
  }
}
```

---

## Code Structure

### Request Flow

```
public/index.php (router)
    ↓
src/Controllers/InventoryController.php
    ↓
src/Services/CsvService.php (CSV operations)
    ↓
src/Models/Inventory.php (DB operations)
    ↓
Database
```

### Key Files

**public/index.php**
- Router
- Autoloader
- Configuration (memory, timeouts)

**src/Services/CsvService.php**
- `readCsvChunk()` - Read chunk from byte offset
- `validate()` - Validate rows against rules
- `downloadCsv()` - Generate CSV output
- `downloadTemplate()` - Generate CSV template

**src/Controllers/InventoryController.php**
- `upload()` - Handle file upload
- `preview()` - Show first 20 rows
- `importChunk()` - Process one chunk
- `export()` - Export all data
- `getValidationRules()` - Define validation rules

**src/Models/Inventory.php**
- `insertBatch()` - Bulk insert with transaction
- `getAll()` - Get all records

**src/Models/ImportHistory.php**
- `createSession()` - Start import tracking
- `updateProgress()` - Update stats
- `completeSession()` - Mark as done
- `getAll()` - Get history list
- `getById()` - Get session details

### Configuration

**Chunk size** (rows per request):
```php
// src/Controllers/InventoryController.php
$limit = 5000;
```

**Batch size** (rows per INSERT):
```php
// src/Models/Inventory.php
$batchSize = 1000;
```

**Memory limit**:
```php
// public/index.php
ini_set('memory_limit', '512M');
```

**Timeout**:
```php
// public/index.php
ini_set('max_execution_time', 300);

// src/Controllers/InventoryController.php
set_time_limit(120);
```

---

## Common Tasks

### Add Custom Validation Rule

Edit `src/Services/CsvService.php` in `validate()` method:

```php
if ($rule === 'email') {
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        $rowErrors[$field] = "$field must be a valid email.";
    }
}
```

### Change Chunk Size

```php
// src/Controllers/InventoryController.php
public function importChunk() {
    $limit = 10000;  // Change from 5000 to 10000
    // ... rest of method
}
```

Larger = faster but more memory. Smaller = slower but safer.

### View Import Errors

```sql
-- Get recent failed imports
SELECT id, filename, started_at, error_rows
FROM import_history
WHERE status = 'failed'
ORDER BY started_at DESC;

-- View error details
SELECT id, filename, error_details
FROM import_history
WHERE error_rows > 0
ORDER BY started_at DESC
LIMIT 1;
```

### Clean Old Uploads

```bash
# Delete files older than 7 days
find storage/uploads -name "import_*.csv" -mtime +7 -delete
```

### Reset Stuck Import

```sql
UPDATE import_history 
SET status = 'failed' 
WHERE status = 'in_progress' 
  AND started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

### Test Validation

Create test file:
```php
<?php
require_once 'src/Services/CsvService.php';
use App\Services\CsvService;

$csv = new CsvService();
$rows = [
    ['sku' => '', 'product_name' => 'Test', 'quantity' => 'ABC', 'price' => '29.99'],
    ['sku' => 'SKU001', 'product_name' => 'Valid', 'quantity' => '100', 'price' => '39.99']
];

$rules = [
    'sku' => 'required',
    'product_name' => 'required',
    'quantity' => 'required|numeric',
    'price' => 'numeric'
];

$result = $csv->validate($rows, $rules);
print_r($result);
```

---

## Extending the System

### Add New Module (e.g., Users)

**1. Create Model** (`src/Models/User.php`):
```php
<?php
namespace App\Models;

use App\Core\Database;

class User {
    private $pdo;

    public function __construct() {
        $db = new Database();
        $this->pdo = $db->getConnection();
    }

    public function insertBatch(array $rows) {
        if (empty($rows)) return true;

        $batchSize = 1000;
        $chunks = array_chunk($rows, $batchSize);

        try {
            $this->pdo->beginTransaction();

            foreach ($chunks as $chunk) {
                $values = [];
                $params = [];
                
                foreach ($chunk as $i => $row) {
                    $values[] = "(:email$i, :username$i, :age$i)";
                    $params[":email$i"] = $row['email'];
                    $params[":username$i"] = $row['username'];
                    $params[":age$i"] = $row['age'];
                }

                $sql = "INSERT INTO users (email, username, age) VALUES " 
                     . implode(',', $values)
                     . " ON DUPLICATE KEY UPDATE username = VALUES(username)";

                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
            }

            $this->pdo->commit();
            return true;
        } catch (\Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getAll() {
        $stmt = $this->pdo->query("SELECT email, username, age FROM users");
        return $stmt->fetchAll();
    }
}
```

**2. Create Controller** (`src/Controllers/UserController.php`):
```php
<?php
namespace App\Controllers;

use App\Services\CsvService;
use App\Models\User;
use App\Models\ImportHistory;

class UserController {
    private $csvService;
    private $userModel;
    private $importHistory;

    public function __construct() {
        $this->csvService = new CsvService();
        $this->userModel = new User();
        $this->importHistory = new ImportHistory();
    }

    private function getValidationRules() {
        return [
            'email' => 'required|unique_in_file',
            'username' => 'required',
            'age' => 'numeric'
        ];
    }

    // Copy upload(), preview(), importChunk() from InventoryController
    // Change 'inventory' to 'users' in import history calls
    // Change downloadTemplate() columns to match user fields
}
```

**3. Add Routes** (`public/index.php`):
```php
use App\Controllers\UserController;

// Add in switch statement
case '/users':
    $userController = new UserController();
    $userController->index();
    break;

case '/users/upload':
    $userController = new UserController();
    if ($method === 'POST') $userController->upload();
    break;

case '/users/preview':
    $userController = new UserController();
    if ($method === 'POST') $userController->preview();
    break;

case '/users/import-chunk':
    $userController = new UserController();
    if ($method === 'POST') $userController->importChunk();
    break;

case '/users/export':
    $userController = new UserController();
    $userController->export();
    break;
```

**4. Create View** (copy `views/import.php` to `views/users.php`):
- Update API endpoints (`/upload` → `/users/upload`)
- Update table columns
- Update template download columns

That's it. CsvService and ImportHistory work unchanged.

### Add Authentication

```php
// public/index.php (before routing)
session_start();

if (!isset($_SESSION['user_id']) && $path !== '/login') {
    header('Location: /login');
    exit;
}

// Track user in import_history
ALTER TABLE import_history ADD COLUMN user_id INT;

// In controller
$sessionId = $this->importHistory->createSession(
    'inventory', 
    $filename, 
    $fileSizeMb,
    $_SESSION['user_id']  // Add user tracking
);
```

### Add Webhooks

```php
// src/Controllers/InventoryController.php
public function importChunk() {
    // ... existing code ...

    if ($chunkData['is_complete']) {
        $this->importHistory->completeSession($sessionId, 'completed');
        
        // Notify external system
        $this->sendWebhook($sessionId);
    }
}

private function sendWebhook($sessionId) {
    $session = $this->importHistory->getById($sessionId);
    
    $payload = [
        'event' => 'import.completed',
        'session_id' => $sessionId,
        'total_rows' => $session['total_rows'],
        'valid_rows' => $session['valid_rows'],
        'error_rows' => $session['error_rows']
    ];

    $ch = curl_init('https://your-webhook-url.com/import-complete');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}
```

---

## Troubleshooting

### Import Stuck at 0%

**Check**:
1. Browser console for JavaScript errors
2. Network tab for failed requests
3. Server logs for PHP errors

**Fix**:
- Hard refresh (Ctrl+Shift+R)
- Check PHP-FPM is running: `systemctl status php8.2-fpm`

### "Table 'import_history' doesn't exist"

**Fix**:
```bash
mysql -u root -p < sql/full_schema.sql
```

### "Allowed memory size exhausted"

**Check**: File might be malformed (one giant row)
```bash
wc -l file.csv  # Should have reasonable line count
```

**Fix**:
- Reduce chunk size: `$limit = 2000;`
- Increase memory: `ini_set('memory_limit', '1G');`

### Import Taking Forever

**Check** database indexes:
```sql
SHOW INDEX FROM inventory;
```

**Fix**:
```sql
CREATE INDEX idx_sku ON inventory(sku);
```

### All Rows Showing as Errors

**Check** CSV format:
```bash
head -n 1 file.csv  # Verify headers
```

**Common issues**:
- Wrong column names (case-sensitive)
- BOM in file: `sed -i '1s/^\xEF\xBB\xBF//' file.csv`
- Wrong delimiter (semicolon vs comma)

### Upload Fails Silently

**Check** permissions:
```bash
ls -la storage/uploads
chmod 775 storage/uploads
chown www-data:www-data storage/uploads
```

**Check** PHP limits:
```php
echo ini_get('upload_max_filesize');  // Should be >= file size
echo ini_get('post_max_size');        // Should be >= file size
```

### Duplicate SKUs Not Showing

**Check** validation rules:
```php
// Should have unique_in_file
'sku' => 'required|unique_in_file'
```

### Debug Mode

```php
// public/index.php (development only!)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../storage/logs/php-errors.log');
```

---

## Production Deployment

### Pre-Deployment Checklist

- [ ] Database schema applied
- [ ] File upload directory writable (`storage/uploads/`)
- [ ] PHP memory limit configured (512MB+)
- [ ] Database credentials secured (use env vars)
- [ ] Error logging enabled
- [ ] Backups configured

### Server Configuration

**PHP** (php.ini or runtime):
```ini
memory_limit = 512M
max_execution_time = 300
upload_max_filesize = 100M
post_max_size = 100M
```

**Nginx**:
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/csv-import/public;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    client_max_body_size 100M;
}
```

**Apache**:
```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/csv-import/public
    
    <Directory /var/www/csv-import/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Security

**Use environment variables**:
```bash
export DB_HOST=localhost
export DB_NAME=csv_import_export_engine
export DB_USER=csv_app
export DB_PASS=secure_password
```

```php
// config/database.php
return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'dbname' => getenv('DB_NAME'),
    'user' => getenv('DB_USER'),
    'password' => getenv('DB_PASS'),
    'charset' => 'utf8mb4'
];
```

**File permissions**:
```bash
chmod -R 755 .
chmod -R 775 storage/uploads
chown -R www-data:www-data storage/uploads
```

**Add CSRF protection**:
```php
// Generate token
session_start();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Validate
if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die('Invalid CSRF token');
}
```

### Monitoring

**Check failed imports**:
```sql
SELECT COUNT(*) FROM import_history WHERE status = 'failed';
```

**Check stuck imports**:
```sql
SELECT * FROM import_history 
WHERE status = 'in_progress' 
  AND started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

**Check performance**:
```sql
SELECT AVG(duration_seconds) as avg_time,
       AVG(total_rows / duration_seconds) as avg_rows_per_sec
FROM import_history 
WHERE status = 'completed' AND duration_seconds > 0;
```

### Backup

**Database**:
```bash
mysqldump -u csv_app -p csv_import_export_engine | gzip > backup_$(date +%Y%m%d).sql.gz
```

**Cron** (daily at 2am):
```cron
0 2 * * * mysqldump -u csv_app -p'password' csv_import_export_engine | gzip > /backups/db_$(date +\%Y\%m\%d).sql.gz
```

### Maintenance

**Clean old files**:
```bash
find storage/uploads -name "import_*.csv" -mtime +7 -delete
```

**Clean old history**:
```sql
DELETE FROM import_history 
WHERE started_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

**Optimize tables**:
```sql
OPTIMIZE TABLE inventory;
OPTIMIZE TABLE import_history;
```

---

## Quick Reference

### Common Commands

```bash
# Start server
php -S localhost:8000 -t public

# Apply schema
mysql -u root -p < sql/full_schema.sql

# Check syntax
php -l src/Services/CsvService.php

# Clean uploads
find storage/uploads -name "import_*.csv" -delete

# Restart PHP-FPM
sudo systemctl restart php8.2-fpm
```

### Common Queries

```sql
-- Recent imports
SELECT * FROM import_history ORDER BY started_at DESC LIMIT 10;

-- Failed imports
SELECT * FROM import_history WHERE status = 'failed';

-- Stuck imports
SELECT * FROM import_history 
WHERE status = 'in_progress' 
  AND started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);

-- Performance stats
SELECT AVG(duration_seconds), AVG(total_rows / duration_seconds) 
FROM import_history WHERE status = 'completed' AND duration_seconds > 0;

-- Error details
SELECT error_details FROM import_history WHERE id = 42;
```

### File Locations

| File | Purpose |
|------|---------|
| `public/index.php` | Router, entry point |
| `config/database.php` | DB credentials |
| `src/Services/CsvService.php` | CSV operations |
| `src/Controllers/InventoryController.php` | Request handlers |
| `src/Models/Inventory.php` | DB operations |
| `src/Models/ImportHistory.php` | Audit log |
| `views/import.php` | Main UI |
| `sql/full_schema.sql` | Database schema |

---

## Getting Help

1. Check import history detail view (shows exact errors)
2. Check server logs (PHP, web server, MySQL)
3. Enable debug mode (see Troubleshooting section)
4. Test with provided `config/products.csv`
5. Read the code (~1500 lines, well-commented)

Most issues are:
- Configuration (memory, permissions, timeouts)
- CSV format (headers, encoding, delimiters)
- Database (missing tables, wrong credentials)

The code is the truth. When docs disagree with code, code is correct.

---

## Summary

**This system**:
- Processes millions of rows without memory issues
- Validates data before import
- Tracks detailed import history
- Handles partial success gracefully
- Works for any module (inventory, users, orders, etc.)

**Key concepts**:
- Chunked processing (5K rows at a time)
- Byte offsets (O(1) seeking)
- Batch inserts (1K rows per query)
- Transactions (all-or-nothing per chunk)
- JSON error storage (flexible, module-agnostic)

**To extend**:
1. Copy existing module as template
2. Change validation rules
3. Change database table
4. Add routes
5. Done

Everything else stays the same.

**Performance**: ~2-3 seconds per 5K-row chunk. Linear scaling to millions of rows.

**Production-ready**: Add authentication, CSRF protection, rate limiting. Core engine is secure.

---

That's everything. Read code when in doubt.

