# CSV Import/Export Engine

A reusable, high-performance CSV import/export engine designed for handling large-scale data imports with validation, error reporting, and audit trails. Built with plain PHP and PDO for maximum compatibility and easy integration.

## Framework Compatibility

### Current Implementation
This engine is **framework-agnostic** and uses:
- **Plain PHP 8.0+** (no framework dependencies)
- **PDO** for database operations
- **Custom autoloader** (no Composer required)
- **Simple routing** (can be replaced with any framework's routing)

### Laravel Integration
While this codebase is standalone, it can be easily integrated into Laravel by:

1. **Extracting the core service** (`CsvService`) - works as-is
2. **Adapting models** to use Laravel's Eloquent/DB facade
3. **Converting controllers** to Laravel controllers
4. **Using Laravel routing** instead of the custom router

See [Laravel Integration Guide](#laravel-integration-guide) below for detailed examples.

## Requirements

- PHP 8.0+ (8.2+ recommended)
- MySQL 5.7+ or MariaDB 10.2+
- PDO + pdo_mysql extensions
- mbstring extension

**No Composer dependencies** - this is a lightweight, dependency-free solution.

## Quick Start

### 1. Database Setup

```bash
mysql -u root -p < sql/full_schema.sql
```

### 2. Configuration

Edit `config/database.php` with your database credentials:

```php
return [
    'host' => '127.0.0.1',
    'dbname' => 'csv_import_export_engine',
    'user' => 'your_username',
    'password' => 'your_password',
    'charset' => 'utf8mb4'
];
```

### 3. Run Standalone

```bash
php -S localhost:8000 -t public
```

Visit `http://localhost:8000` to access the import interface.

## How HotelHub Should Consume This Component

### Option 1: Direct Integration (Recommended)

Copy the core components into your Laravel application:

```
hotelhub/
├── app/
│   ├── Services/
│   │   └── CsvService.php          # Copy from src/Services/CsvService.php
│   └── Models/
│       └── ImportHistory.php      # Adapt to Laravel Eloquent
├── app/Http/Controllers/
│   └── CsvImportController.php    # Create Laravel controller
└── routes/
    └── web.php                     # Add routes
```

### Option 2: Package Integration

1. Extract `CsvService` as a standalone service
2. Create Laravel service provider
3. Register routes and controllers
4. Use Laravel's database layer instead of PDO

## Laravel Integration Guide

### Step 1: Copy CsvService

The `CsvService` class is framework-agnostic and works directly in Laravel:

```php
// app/Services/CsvService.php
namespace App\Services;

class CsvService
{
    // Copy entire class from src/Services/CsvService.php
    // No changes needed - it's pure PHP with no dependencies
}
```

### Step 2: Create Laravel Controller

```php
// app/Http/Controllers/CsvImportController.php
namespace App\Http\Controllers;

use App\Services\CsvService;
use App\Models\Inventory; // Your Laravel model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CsvImportController extends Controller
{
    private $csvService;

    public function __construct(CsvService $csvService)
    {
        $this->csvService = $csvService;
    }

    /**
     * Download CSV template
     */
    public function downloadTemplate()
    {
        $headers = ['sku', 'product_name', 'quantity', 'price'];
        return $this->csvService->downloadTemplate($headers);
    }

    /**
     * Upload CSV file
     */
    public function upload(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:10240'
        ]);

        $file = $request->file('csv_file');
        $filename = uniqid('import_') . '.csv';
        $filePath = $file->storeAs('imports', $filename);

        // Create import history session
        $sessionId = DB::table('import_history')->insertGetId([
            'module' => 'inventory',
            'filename' => $file->getClientOriginalName(),
            'file_size_mb' => round($file->getSize() / 1024 / 1024, 2),
            'status' => 'in_progress',
            'started_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'file_id' => $filename,
            'session_id' => $sessionId
        ]);
    }

    /**
     * Preview first 20 rows
     */
    public function preview(Request $request)
    {
        $filePath = storage_path('app/imports/' . $request->file_id);
        
        $chunk = $this->csvService->readCsvChunk($filePath, 0, 20);
        $rules = $this->getValidationRules();
        $validation = $this->csvService->validate($chunk['rows'], $rules);

        return response()->json([
            'success' => true,
            'preview_rows' => $validation['valid_rows'],
            'error_rows' => $validation['errors'],
            'warning_rows' => $validation['warnings'] ?? [],
            'total_preview_count' => count($chunk['rows']),
            'valid_count' => count($validation['valid_rows']),
            'error_count' => count($validation['errors'])
        ]);
    }

    /**
     * Import chunk of data
     */
    public function importChunk(Request $request)
    {
        $filePath = storage_path('app/imports/' . $request->file_id);
        $offset = $request->offset ?? 0;
        $sessionId = $request->session_id;
        $limit = 5000;

        $chunkData = $this->csvService->readCsvChunk($filePath, $offset, $limit);
        $rules = $this->getValidationRules();
        $validation = $this->csvService->validate($chunkData['rows'], $rules);

        // Insert valid rows using Laravel's batch insert
        if (!empty($validation['valid_rows'])) {
            DB::table('inventory')->insert($validation['valid_rows']);
        }

        // Update import history
        if ($sessionId) {
            $session = DB::table('import_history')->where('id', $sessionId)->first();
            
            DB::table('import_history')
                ->where('id', $sessionId)
                ->update([
                    'total_rows' => ($session->total_rows ?? 0) + count($chunkData['rows']),
                    'valid_rows' => ($session->valid_rows ?? 0) + count($validation['valid_rows']),
                    'error_rows' => ($session->error_rows ?? 0) + count($validation['errors']),
                    'error_details' => json_encode($validation['errors'])
                ]);

            if ($chunkData['is_complete']) {
                DB::table('import_history')
                    ->where('id', $sessionId)
                    ->update([
                        'status' => 'completed',
                        'completed_at' => now()
                    ]);
            }
        }

        return response()->json([
            'success' => true,
            'next_offset' => $chunkData['next_offset'],
            'is_complete' => $chunkData['is_complete'],
            'progress' => $chunkData['progress'],
            'processed_count' => count($chunkData['rows']),
            'valid_count' => count($validation['valid_rows']),
            'error_count' => count($validation['errors'])
        ]);
    }

    /**
     * Export data as CSV
     */
    public function export()
    {
        $data = DB::table('inventory')
            ->select('sku', 'product_name', 'quantity', 'price')
            ->get()
            ->toArray();

        return $this->csvService->downloadCsv(
            array_map(fn($item) => (array) $item, $data),
            'inventory_export_' . date('Y-m-d') . '.csv'
        );
    }

    /**
     * Get validation rules for inventory module
     */
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
```

### Step 3: Add Laravel Routes

```php
// routes/web.php
use App\Http\Controllers\CsvImportController;

Route::prefix('csv-import')->group(function () {
    Route::get('/template', [CsvImportController::class, 'downloadTemplate']);
    Route::post('/upload', [CsvImportController::class, 'upload']);
    Route::post('/preview', [CsvImportController::class, 'preview']);
    Route::post('/import-chunk', [CsvImportController::class, 'importChunk']);
    Route::get('/export', [CsvImportController::class, 'export']);
});
```

### Step 4: Create Database Migration

```php
// database/migrations/xxxx_create_import_history_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('import_history', function (Blueprint $table) {
            $table->id();
            $table->string('module', 50);
            $table->string('filename', 255);
            $table->decimal('file_size_mb', 10, 2)->default(0);
            $table->integer('total_rows')->default(0);
            $table->integer('valid_rows')->default(0);
            $table->integer('error_rows')->default(0);
            $table->enum('status', ['in_progress', 'completed', 'failed'])->default('in_progress');
            $table->json('error_details')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->default(0);
            
            $table->index('module');
            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('import_history');
    }
};
```

## Example Usage in Laravel

### Basic Import Flow

```php
use App\Services\CsvService;
use Illuminate\Support\Facades\DB;

// 1. Read CSV chunk
$csvService = new CsvService();
$chunk = $csvService->readCsvChunk($filePath, 0, 5000);

// 2. Validate rows
$rules = [
    'sku' => 'required|unique_in_file',
    'product_name' => 'required',
    'quantity' => 'required|numeric',
    'price' => 'numeric'
];
$validation = $csvService->validate($chunk['rows'], $rules);

// 3. Insert valid rows
if (!empty($validation['valid_rows'])) {
    DB::table('inventory')->insert($validation['valid_rows']);
}

// 4. Handle errors
foreach ($validation['errors'] as $rowIndex => $errors) {
    // Log or store errors
    logger()->error("Row $rowIndex errors", $errors);
}
```

### Service Call Example

```php
// In your Laravel service class
namespace App\Services;

use App\Services\CsvService;
use App\Models\Inventory;

class InventoryImportService
{
    private $csvService;

    public function __construct(CsvService $csvService)
    {
        $this->csvService = $csvService;
    }

    public function importFromFile(string $filePath, string $module = 'inventory')
    {
        $offset = 0;
        $sessionId = $this->createImportSession($module, $filePath);

        while (true) {
            $chunk = $this->csvService->readCsvChunk($filePath, $offset, 5000);
            $validation = $this->csvService->validate(
                $chunk['rows'],
                $this->getValidationRules()
            );

            // Insert valid rows
            if (!empty($validation['valid_rows'])) {
                Inventory::insert($validation['valid_rows']);
            }

            $this->updateImportProgress($sessionId, $chunk, $validation);

            if ($chunk['is_complete']) {
                break;
            }

            $offset = $chunk['next_offset'];
        }

        return $sessionId;
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
```

## Key Features

- ✅ **Chunked Processing**: Handles millions of rows without memory issues
- ✅ **Validation**: Row-level validation with detailed error reporting
- ✅ **Partial Success**: Invalid rows don't stop the import
- ✅ **Audit Trail**: Complete import history with error details
- ✅ **Duplicate Detection**: Warns about duplicate keys
- ✅ **Module-Agnostic**: Reusable for any data type (inventory, users, orders, etc.)
- ✅ **Framework-Agnostic**: Works standalone or integrates with Laravel/any framework

## Architecture

### Core Components

- **`CsvService`**: Pure PHP service for CSV operations (framework-agnostic)
- **`Database`**: PDO-based database connection (replaceable with Laravel DB)
- **`Models`**: Data access layer (adaptable to Eloquent)
- **`Controllers`**: Request handlers (convert to Laravel controllers)

### Processing Flow

```
1. Upload → Save file, create import session
2. Preview → Validate first 20 rows
3. Import Loop → Process chunks of 5000 rows
   - Read chunk from byte offset
   - Validate rows
   - Insert valid rows
   - Update progress
4. Complete → Mark session as completed
```

## Performance

- **10K rows**: ~6 seconds
- **100K rows**: ~60 seconds  
- **1M rows**: ~10 minutes
- **10M rows**: ~100 minutes

Linear scaling with constant memory usage (~25MB per request).

## Documentation

See `docs/documentation.md` for complete developer documentation including:
- API reference
- Architecture details
- Troubleshooting guide
- Extension examples

## License

[Specify your license here]

## Support

For integration questions or issues, please refer to:
1. `docs/documentation.md` - Complete technical documentation
2. Example Laravel integration code above
3. Source code comments in `src/Services/CsvService.php`
