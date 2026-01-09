# Laravel Integration Guide

This document provides step-by-step instructions for integrating the CSV Import/Export Engine into a Laravel application.

## Overview

The CSV engine is designed to be framework-agnostic. The core `CsvService` works directly in Laravel without modifications. You'll need to:

1. Copy `CsvService` to your Laravel app
2. Create Laravel controllers
3. Adapt models to use Eloquent
4. Set up routes
5. Create database migrations

## Step-by-Step Integration

### 1. Copy CsvService

Copy `src/Services/CsvService.php` to `app/Services/CsvService.php`:

```bash
cp src/Services/CsvService.php app/Services/CsvService.php
```

Update the namespace:

```php
namespace App\Services;
```

No other changes needed - the service is pure PHP with no dependencies.

### 2. Create Database Migration

```bash
php artisan make:migration create_import_history_table
```

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
            $table->string('module', 50)->index();
            $table->string('filename', 255);
            $table->decimal('file_size_mb', 10, 2)->default(0);
            $table->integer('total_rows')->default(0);
            $table->integer('valid_rows')->default(0);
            $table->integer('error_rows')->default(0);
            $table->enum('status', ['in_progress', 'completed', 'failed'])->default('in_progress')->index();
            $table->json('error_details')->nullable();
            $table->timestamp('started_at')->useCurrent()->index();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->default(0);
        });
    }

    public function down()
    {
        Schema::dropIfExists('import_history');
    }
};
```

Run migration:

```bash
php artisan migrate
```

### 3. Create Eloquent Model (Optional)

```php
// app/Models/ImportHistory.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportHistory extends Model
{
    protected $table = 'import_history';
    
    protected $casts = [
        'error_details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $fillable = [
        'module',
        'filename',
        'file_size_mb',
        'total_rows',
        'valid_rows',
        'error_rows',
        'status',
        'error_details',
        'started_at',
        'completed_at',
        'duration_seconds',
    ];
}
```

### 4. Create Controller

```bash
php artisan make:controller CsvImportController
```

See `README.md` for complete controller implementation.

### 5. Add Routes

```php
// routes/web.php
use App\Http\Controllers\CsvImportController;

Route::middleware(['auth'])->group(function () {
    Route::prefix('admin/csv-import')->name('csv-import.')->group(function () {
        Route::get('/template', [CsvImportController::class, 'downloadTemplate'])
            ->name('template');
        Route::post('/upload', [CsvImportController::class, 'upload'])
            ->name('upload');
        Route::post('/preview', [CsvImportController::class, 'preview'])
            ->name('preview');
        Route::post('/import-chunk', [CsvImportController::class, 'importChunk'])
            ->name('import-chunk');
        Route::get('/export', [CsvImportController::class, 'export'])
            ->name('export');
        Route::get('/history', [CsvImportController::class, 'history'])
            ->name('history');
    });
});
```

### 6. Create Service Class (Recommended)

For better organization, create a service class:

```php
// app/Services/InventoryImportService.php
namespace App\Services;

use App\Services\CsvService;
use App\Models\ImportHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InventoryImportService
{
    private $csvService;

    public function __construct(CsvService $csvService)
    {
        $this->csvService = $csvService;
    }

    /**
     * Process entire CSV import
     */
    public function processImport(string $filePath, string $originalFilename): int
    {
        $fileSizeMb = round(filesize($filePath) / 1024 / 1024, 2);
        
        // Create import session
        $session = ImportHistory::create([
            'module' => 'inventory',
            'filename' => $originalFilename,
            'file_size_mb' => $fileSizeMb,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $offset = 0;
        $rules = $this->getValidationRules();

        while (true) {
            // Read chunk
            $chunk = $this->csvService->readCsvChunk($filePath, $offset, 5000);
            
            // Validate
            $validation = $this->csvService->validate($chunk['rows'], $rules);
            
            // Insert valid rows
            if (!empty($validation['valid_rows'])) {
                DB::table('inventory')->insert($validation['valid_rows']);
            }
            
            // Update progress
            $session->increment('total_rows', count($chunk['rows']));
            $session->increment('valid_rows', count($validation['valid_rows']));
            $session->increment('error_rows', count($validation['errors']));
            
            // Merge error details
            $existingErrors = $session->error_details ?? [];
            $session->error_details = array_merge($existingErrors, $validation['errors']);
            $session->save();
            
            // Check if complete
            if ($chunk['is_complete']) {
                $session->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'duration_seconds' => now()->diffInSeconds($session->started_at),
                ]);
                break;
            }
            
            $offset = $chunk['next_offset'];
        }

        return $session->id;
    }

    private function getValidationRules(): array
    {
        return [
            'sku' => 'required|unique_in_file',
            'product_name' => 'required',
            'quantity' => 'required|numeric',
            'price' => 'numeric',
        ];
    }
}
```

### 7. Usage in Controller

```php
// app/Http/Controllers/CsvImportController.php
use App\Services\InventoryImportService;
use Illuminate\Http\Request;

public function upload(Request $request, InventoryImportService $importService)
{
    $request->validate([
        'csv_file' => 'required|file|mimes:csv,txt|max:10240'
    ]);

    $file = $request->file('csv_file');
    $path = $file->store('imports');
    $fullPath = Storage::path($path);

    // Process import
    $sessionId = $importService->processImport($fullPath, $file->getClientOriginalName());

    return response()->json([
        'success' => true,
        'session_id' => $sessionId
    ]);
}
```

## Key Differences from Standalone Version

1. **Database**: Use Laravel's DB facade or Eloquent instead of PDO
2. **File Storage**: Use Laravel's Storage facade instead of direct file operations
3. **Routing**: Use Laravel routes instead of custom router
4. **Validation**: Can use Laravel's validation alongside CSV validation
5. **Authentication**: Use Laravel's auth middleware
6. **Configuration**: Use Laravel's config system instead of direct PHP arrays

## Testing

Create a test:

```php
// tests/Feature/CsvImportTest.php
use Tests\TestCase;
use App\Services\CsvService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CsvImportTest extends TestCase
{
    public function test_csv_import_processes_file()
    {
        Storage::fake('imports');
        
        $file = UploadedFile::fake()->create('test.csv', 100);
        // ... test implementation
    }
}
```

## Queue Integration (Optional)

For very large files, you can use Laravel queues:

```php
// app/Jobs/ProcessCsvChunk.php
namespace App\Jobs;

use App\Services\CsvService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class ProcessCsvChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        private string $filePath,
        private int $offset,
        private int $sessionId
    ) {}

    public function handle(CsvService $csvService)
    {
        $chunk = $csvService->readCsvChunk($this->filePath, $this->offset, 5000);
        $validation = $csvService->validate($chunk['rows'], $this->getRules());
        
        if (!empty($validation['valid_rows'])) {
            DB::table('inventory')->insert($validation['valid_rows']);
        }
        
        // Dispatch next chunk if not complete
        if (!$chunk['is_complete']) {
            ProcessCsvChunk::dispatch(
                $this->filePath,
                $chunk['next_offset'],
                $this->sessionId
            );
        }
    }
}
```

## Best Practices

1. **Use Laravel's Storage**: Don't use direct file paths
2. **Add Authentication**: Protect import endpoints
3. **Add Rate Limiting**: Prevent abuse
4. **Use Queues**: For files > 10MB
5. **Add Logging**: Use Laravel's Log facade
6. **Add Notifications**: Notify users when import completes
7. **Add Validation**: Use Laravel's form validation for file uploads
8. **Add CSRF Protection**: Laravel handles this automatically

## Troubleshooting

### File Not Found
- Use `Storage::path()` to get full path
- Check `config/filesystems.php` for disk configuration

### Memory Issues
- Use queues for large files
- Increase `memory_limit` in `php.ini`

### Database Errors
- Check migrations are run
- Verify database connection in `.env`

## Next Steps

1. Add authentication middleware
2. Create admin UI using Laravel Blade or Vue/React
3. Add email notifications on completion
4. Add export functionality
5. Add import history UI
6. Add error reporting/alerting
