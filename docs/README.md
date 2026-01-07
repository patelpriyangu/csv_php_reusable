# CSV Import/Export Engine

A production-ready PHP engine for handling CSV imports at scale. Built to process millions of rows without timeouts or memory exhaustion.

## Quick Start

```bash
# Database setup
mysql -u root -p < sql/full_schema.sql

# Start server
php -S localhost:8000 -t public

# Open browser
http://localhost:8000
```

## Architecture Overview

This isn't another CSV parser. It's designed to handle real-world data problems:

- **Chunked processing**: Never loads entire file into memory
- **Partial success**: Invalid rows don't kill the entire import
- **Validation pipeline**: Catches errors before database writes
- **Audit trail**: Full history of what was imported and what failed
- **Module agnostic**: Works for inventory, users, orders, anything

### Why Chunked Processing Matters

Memory limits are real. A 500MB CSV file will blow past PHP's default 128MB memory limit if you try to load it at once. We use byte-offset seeking to read files in chunks:

```php
// Bad: Loads entire file
$data = array_map('str_getcsv', file($filepath));

// Good: Reads 5000 rows at a time
$chunk = $csvService->readCsvChunk($filepath, $offset, 5000);
```

The frontend loops through chunks, showing progress. The server never sees more than 5000 rows at once.

## Core Components

### CsvService

The workhorse. Does three things well:

1. **Reads CSV files in chunks** using byte offsets
2. **Validates rows** against configurable rules
3. **Generates CSV output** for templates and exports

No database logic. No business logic. Just CSV operations.

### Import History

Tracks everything:
- What file was imported
- How many rows succeeded/failed
- Which specific rows had errors and why
- How long it took

This isn't optional logging. It's your debugging tool when someone asks "Why didn't my data import?"

### Validation System

String-based rules that get parsed once and applied to thousands of rows:

```php
$rules = [
    'sku' => 'required|unique_in_file',
    'quantity' => 'required|numeric',
    'price' => 'numeric'  // optional field
];
```

**Key insight**: The `unique_in_file` rule is a warning, not an error. Duplicates get imported, last one wins. This is intentional - users often update existing records via CSV.

## Project Structure

```
src/
├── Controllers/
│   └── InventoryController.php    # Request handlers, orchestration
├── Core/
│   └── Database.php                # PDO wrapper
├── Models/
│   ├── Inventory.php               # Business logic, bulk inserts
│   └── ImportHistory.php           # Audit log operations
└── Services/
    └── CsvService.php              # CSV operations only

public/
└── index.php                       # Router, autoloader

views/
├── import.php                      # Main import UI
└── history.php                     # Audit log UI

config/
├── database.php                    # DB credentials
└── test_*.csv                      # Test files for validation

sql/
└── full_schema.sql                 # Complete schema
```

## Database Schema

### inventory

Standard product table. The `sku` is unique, which enables upsert behavior:

```sql
INSERT INTO inventory (sku, product_name, quantity, price) 
VALUES (?, ?, ?, ?)
ON DUPLICATE KEY UPDATE 
    product_name = VALUES(product_name),
    quantity = VALUES(quantity),
    price = VALUES(price)
```

This means CSV imports can both create and update records.

### import_history

The audit trail. Stores:
- Session metadata (file, size, timestamps)
- Aggregate stats (total, valid, error counts)
- Detailed errors as JSON (row number → field → message)

The JSON column is intentional. Error structures vary by module, and JSON gives us flexibility without schema changes.

## How Import Works

### Phase 1: Upload

Client uploads file. Server:
1. Saves to `storage/uploads/`
2. Creates import history session
3. Returns file ID and session ID

No processing yet. Just stage the file.

### Phase 2: Preview

Client requests preview with file ID. Server:
1. Reads first 20 rows (fast)
2. Validates them
3. Returns valid rows, errors, and warnings

This catches obvious problems before processing 1M rows.

### Phase 3: Chunked Import

Client loops, passing current byte offset. Server:
1. Reads chunk from offset (5000 rows)
2. Validates chunk
3. Inserts valid rows
4. Updates import history
5. Returns next offset and progress

Loop continues until file is exhausted. Each request is independent - if one fails, client can retry that chunk.

### Phase 4: Completion

Final chunk returns `is_complete: true`. Server:
1. Marks session as completed
2. Calculates duration
3. Client redirects to history page

## Validation Deep Dive

### Rule Syntax

Rules are pipe-delimited strings:

```php
'field_name' => 'required|numeric|unique_in_file'
```

Parsed once at the start, applied to every row:

```php
$parsedRules[$field] = explode('|', $ruleString);
```

### Available Rules

**required**: Field cannot be empty or null
```php
if ($value === '' || $value === null) {
    $rowErrors[$field] = "$field is required.";
}
```

**numeric**: Field must be a valid number
```php
if ($value !== '' && $value !== null && !is_numeric($value)) {
    $rowErrors[$field] = "$field must be a number.";
}
```

Note: Empty values skip numeric validation. This allows optional numeric fields.

**unique_in_file**: Detects duplicates within CSV
```php
if (isset($seenValues[$field][$value])) {
    $rowWarnings[$field] = "Duplicate detected...";
}
```

This produces warnings, not errors. Duplicates still import.

### Errors vs Warnings

**Errors** = Row is skipped
**Warnings** = Row is imported with notification

This distinction matters. Duplicate SKUs are common in update scenarios. Blocking them would break bulk update workflows.

## Performance Characteristics

### Memory Usage

Constant O(1) per request. The 512MB limit in `public/index.php` is for safety, not because we need it. Typical memory usage per chunk request: ~20-50MB.

### Processing Speed

Rough benchmarks on modest hardware:

- 5000 rows/chunk: ~2-3 seconds (includes validation + DB writes)
- 100K rows: ~60-90 seconds total
- 1M rows: ~10-15 minutes

Bottleneck is database writes, not CSV parsing or validation.

### Database Batch Size

We chunk SQL inserts at 1000 rows to avoid `max_allowed_packet` limits:

```php
$batchSize = 1000;
$chunks = array_chunk($rows, $batchSize);

foreach ($chunks as $chunk) {
    // Build single INSERT with 1000 rows
    $sql = "INSERT INTO inventory (...) VALUES (?,?,...), (?,?,...)...";
}
```

This is significantly faster than 1000 individual INSERTs.

## Error Handling

### Upload Failures

If file upload fails, no session is created. Client receives immediate error.

### Validation Errors

Invalid rows are collected but don't halt processing. Valid rows proceed to import.

### Database Errors

If a batch insert fails, the entire chunk is rolled back:

```php
try {
    $pdo->beginTransaction();
    // ... multiple batch inserts ...
    $pdo->commit();
} catch (\Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
```

Client receives error and can retry that chunk.

### Session Tracking

Import history tracks session state:
- `in_progress`: Active import
- `completed`: Finished successfully
- `failed`: Fatal error occurred

Failed sessions record how far they got before dying.

## Adding New Modules

The engine is designed to be reused. Here's how to add a new module (e.g., "Users"):

### 1. Create Model

```php
// src/Models/User.php
class User {
    public function insertBatch(array $rows) {
        // Same pattern as Inventory::insertBatch
        // Use transactions, chunk inserts
    }
}
```

### 2. Create Controller

```php
// src/Controllers/UserController.php
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
}
```

### 3. Add Routes

```php
// public/index.php
case '/users':
    $userController = new UserController();
    $userController->index();
    break;
    
case '/users/upload':
    $userController = new UserController();
    $userController->upload();
    break;
    
// ... etc
```

### 4. Create View

Copy `views/import.php` and update:
- API endpoints (`/upload` → `/users/upload`)
- Template columns
- Module name in import history calls

That's it. CsvService and ImportHistory work unchanged.

## Configuration

### Memory and Timeouts

```php
// public/index.php
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);  // 5 minutes per request
```

Per-chunk timeout is set in controller:

```php
// src/Controllers/InventoryController.php
set_time_limit(120);  // 2 minutes per chunk
```

### Chunk Size

Defined in controller:

```php
$limit = 5000;  // rows per chunk
```

Larger = faster but more memory. Smaller = slower but safer. 5000 is a good balance.

### Batch Size

Defined in model:

```php
$batchSize = 1000;  // rows per INSERT statement
```

Don't exceed MySQL's `max_allowed_packet` (typically 16MB).

## Testing

Test files in `config/` cover all validation scenarios:

- `test_all_valid.csv`: Happy path
- `test_missing_required_fields.csv`: Required validation
- `test_invalid_numeric_values.csv`: Type validation
- `test_mixed_valid_invalid.csv`: Partial success
- `test_duplicate_skus.csv`: Duplicate detection
- `test_edge_cases.csv`: Unicode, special chars, boundaries
- `test_all_invalid.csv`: Complete failure case
- `test_empty_file.csv`: Empty file handling

Run through each one. Verify preview shows correct errors/warnings. Verify only valid rows land in database.

## Common Issues

### "Table 'import_history' doesn't exist"

You skipped the migration. Run:
```bash
mysql -u root -p < sql/full_schema.sql
```

### "Allowed memory size exhausted"

Either:
1. CSV is malformed (e.g., no newlines for 100K rows)
2. You're doing something in application code that loads too much

Check server logs for where it died.

### Import stuck at X%

Check browser console for JavaScript errors. Check server logs for PHP errors. The chunk that failed will show in both places.

### Duplicate SKUs aren't showing as errors

By design. They're warnings. Duplicates get imported, last occurrence wins. If you want to block duplicates, change the rule from warning-based to error-based in CsvService.

### Import says "completed" but rows are missing

Check import history detail view. It will show which rows had errors and why. Those rows were skipped intentionally.

## Production Considerations

### File Storage

Currently files are stored in `storage/uploads/`. In production:

1. Clean up old files periodically (they're not deleted after import)
2. Consider moving to S3/object storage for large deployments
3. Generate unique filenames (we use `uniqid('import_')`)

### Database Indexes

The import_history table has indexes on:
- `module` (for filtering by type)
- `status` (for finding active imports)
- `started_at` (for recent imports query)

Inventory table needs index on `sku` (it's unique, so it has one).

### Concurrency

Multiple imports can run simultaneously. Each has its own:
- File in storage
- Import history session
- Database transaction per chunk

No shared state, no locks needed.

### Monitoring

Import history is your monitoring tool:
- Check for failed imports: `WHERE status = 'failed'`
- Average duration trending up? Performance issue
- High error rates? Data quality issue

### Security

- File uploads: We accept .csv only (not enforced server-side yet - add MIME check)
- SQL injection: All queries use prepared statements
- XSS: Frontend escapes output (using textContent, not innerHTML)
- Path traversal: We basename() the file ID before reading

## Development Notes

### Why No Framework?

This is intentionally vanilla PHP. Adding it to Laravel/Symfony is straightforward - the core logic doesn't change. The hard parts (chunking, validation, history) are framework-agnostic.

### Why JSON for Error Storage?

Different modules have different fields and validation rules. A rigid error table would need per-module schemas. JSON gives flexibility without schema migrations when adding modules.

### Why Not Queue/Background Jobs?

We use chunked processing instead. Same benefits (no timeouts, progress tracking) without the infrastructure overhead (Redis, workers, etc.). For most use cases, this is simpler and good enough.

If you need true background processing (user submits and walks away), wrap the chunk loop in a job instead of JavaScript.

### Why Byte Offsets vs Row Numbers?

Byte offsets are faster. We don't have to parse rows we've already seen. `fseek()` jumps directly to the byte position and starts reading from there.

The tradeoff: progress is approximate (based on bytes, not rows). In practice, rows are similar sizes so progress is accurate enough.

## Future Enhancements

Things that would be nice but aren't critical:

1. **Downloadable error report**: Export failed rows as CSV with error messages
2. **Column mapping UI**: User maps CSV columns to system fields (currently auto-maps by header)
3. **Preview row limit**: Make the 20-row preview configurable
4. **Import cancellation**: Stop an in-progress import
5. **Scheduled imports**: Cron-based imports from SFTP/S3
6. **Excel support**: Parse .xlsx files (requires PHPSpreadsheet, adds complexity)

## Support

This is documentation, not a FAQ. If you have questions:

1. Read the code (it's ~1500 lines total, well commented)
2. Check import history detail view (shows exactly what happened)
3. Look at test files (they cover all edge cases)

The code is the truth. Comments and docs can lie, the code doesn't.

