<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Import Engine - High Volume</title>
    <style>
        :root {
            --primary: #2563eb;
            --danger: #dc2626;
            --success: #16a34a;
            --warning: #f59e0b;
            --bg: #f8fafc;
            --border: #e2e8f0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
            color: #1e293b;
        }

        .card {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        h1,
        h2 {
            margin-top: 0;
        }

        .btn {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            border: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-outline {
            border: 1px solid var(--border);
            background: white;
            color: #333;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .toolbar {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            font-size: 0.9rem;
        }

        th,
        td {
            text-align: left;
            padding: 0.75rem;
            border-bottom: 1px solid var(--border);
        }

        th {
            background: #f1f5f9;
            font-weight: 600;
        }

        .progress-bar {
            width: 100%;
            height: 20px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 1rem;
        }

        .progress-fill {
            height: 100%;
            background: var(--success);
            width: 0%;
            transition: width 0.3s ease;
        }

        .hidden {
            display: none;
        }
    </style>
</head>

<body>

    <div class="toolbar">
        <h1>Inventory Manager (Millions Ready)</h1>
        <div style="margin-left: auto; display: flex; gap: 0.5rem;">
            <a href="/history" class="btn btn-outline">📊 Import History</a>
            <a href="/download-template" class="btn btn-outline" download>⬇ Download Template</a>
            <a href="/export" class="btn btn-outline" download>⤓ Export Data</a>
        </div>
    </div>

    <div class="card">
        <h2>Import Large CSV</h2>
        <p>Supports files with millions of rows.</p>

        <form id="uploadForm" style="display: flex; gap: 1rem; align-items: center;">
            <input type="file" name="csv_file" accept=".csv" required>
            <button type="submit" id="uploadBtn" class="btn btn-primary">1. Upload & Analyze</button>
        </form>
    </div>

    <div id="previewSection" class="hidden">
        <div class="card">
            <h2>Preview (First 20 Rows)</h2>
            <div id="fileStats" style="margin-bottom: 1rem; color: #666;"></div>

            <div id="validRowsSection">
                <h3 style="color: var(--success);">✓ Valid Rows (<span id="validCount">0</span>)</h3>
                <table id="validTable">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Product Name</th>
                            <th>Quantity</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div id="warningRowsSection" class="hidden" style="margin-top: 2rem;">
                <h3 style="color: var(--warning);">⚠ Warnings (<span id="warningCount">0</span>)</h3>
                <div id="warningList" style="background: #fffbeb; padding: 1rem; border-radius: 4px; max-height: 200px; overflow-y: auto;">
                    <!-- Populated by JS -->
                </div>
            </div>

            <div id="errorRowsSection" class="hidden" style="margin-top: 2rem;">
                <h3 style="color: var(--danger);">✗ Error Rows (<span id="errorCount">0</span>)</h3>
                <div id="errorList" style="background: #fef2f2; padding: 1rem; border-radius: 4px; max-height: 300px; overflow-y: auto;">
                    <!-- Populated by JS -->
                </div>
            </div>

            <div style="margin-top: 2rem;">
                <button id="startImportBtn" class="btn btn-primary"
                    style="background: var(--success); font-size: 1.1rem;">
                    2. Start Full Import
                </button>
                <p id="importWarning" style="margin-top: 0.5rem; color: #666; font-size: 0.9rem;"></p>
            </div>
        </div>
    </div>

    <div id="progressSection" class="hidden">
        <div class="card">
            <h2>Importing...</h2>
            <p>Do not close this tab.</p>
            <div class="progress-bar">
                <div id="progressFill" class="progress-fill"></div>
            </div>
            <div id="progressText" style="margin-top: 0.5rem; font-weight: bold;">0%</div>
            <div id="log"
                style="margin-top: 1rem; font-family: monospace; font-size: 0.8rem; color: #666; max-height: 100px; overflow-y: auto;">
            </div>
        </div>
    </div>

    <script>
        let currentFileId = null;
        let currentSessionId = null;
        let currentOffset = 0;

        // 1. Upload
        document.getElementById('uploadForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('uploadBtn');
            btn.disabled = true;
            btn.textContent = 'Uploading...';

            const formData = new FormData(e.target);

            try {
                // Step A: Upload File
                const upRes = await fetch('/upload', { method: 'POST', body: formData });
                const upData = await upRes.json();

                if (!upData.success) throw new Error(upData.message);

                currentFileId = upData.file_id;
                currentSessionId = upData.session_id;

                // Step B: Get Preview
                const prevRes = await fetch('/preview', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ file_id: currentFileId })
                });
                const prevData = await prevRes.json();

                renderPreview(prevData);
            } catch (err) {
                alert('Error: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.textContent = '1. Upload & Analyze';
            }
        });

        function renderPreview(data) {
            document.getElementById('previewSection').classList.remove('hidden');
            
            let statsText = `File Size: ${data.file_size_mb} MB | Total Rows: ${data.total_preview_count} | Valid: ${data.valid_count || 0}`;
            if (data.warning_count > 0) {
                statsText += ` | Warnings: ${data.warning_count}`;
            }
            if (data.error_count > 0) {
                statsText += ` | Errors: ${data.error_count}`;
            }
            document.getElementById('fileStats').textContent = statsText;

            // Render valid rows
            const validTbody = document.getElementById('validTable').querySelector('tbody');
            validTbody.innerHTML = '';
            document.getElementById('validCount').textContent = data.valid_count || 0;
            
            if (data.preview_rows && data.preview_rows.length > 0) {
                data.preview_rows.forEach(row => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `<td>${row.sku || ''}</td><td>${row.product_name || ''}</td><td>${row.quantity || ''}</td><td>${row.price || ''}</td>`;
                    validTbody.appendChild(tr);
                });
            } else {
                validTbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: #999;">No valid rows in preview</td></tr>';
            }

            // Render warnings
            const warningSection = document.getElementById('warningRowsSection');
            const warningList = document.getElementById('warningList');
            const warningCount = document.getElementById('warningCount');
            
            if (data.warning_rows && Object.keys(data.warning_rows).length > 0) {
                warningSection.classList.remove('hidden');
                warningCount.textContent = Object.keys(data.warning_rows).length;
                
                let warningHtml = '';
                for (const [rowNum, warnings] of Object.entries(data.warning_rows)) {
                    warningHtml += `<div style="margin-bottom: 0.75rem; padding: 0.5rem; background: white; border-left: 3px solid var(--warning);">
                        <strong>Row ${rowNum}:</strong><br>`;
                    for (const [field, message] of Object.entries(warnings)) {
                        warningHtml += `<span style="margin-left: 1rem;">⚠ ${message}</span><br>`;
                    }
                    warningHtml += '</div>';
                }
                warningList.innerHTML = warningHtml;
            } else {
                warningSection.classList.add('hidden');
            }

            // Render errors
            const errorSection = document.getElementById('errorRowsSection');
            const errorList = document.getElementById('errorList');
            const errorCount = document.getElementById('errorCount');
            
            if (data.error_rows && Object.keys(data.error_rows).length > 0) {
                errorSection.classList.remove('hidden');
                errorCount.textContent = Object.keys(data.error_rows).length;
                
                let errorHtml = '';
                for (const [rowNum, errors] of Object.entries(data.error_rows)) {
                    errorHtml += `<div style="margin-bottom: 0.75rem; padding: 0.5rem; background: white; border-left: 3px solid var(--danger);">
                        <strong>Row ${rowNum}:</strong><br>`;
                    for (const [field, message] of Object.entries(errors)) {
                        errorHtml += `<span style="margin-left: 1rem;">• ${message}</span><br>`;
                    }
                    errorHtml += '</div>';
                }
                errorList.innerHTML = errorHtml;
            } else {
                errorSection.classList.add('hidden');
            }

            // Update import button message
            const warning = document.getElementById('importWarning');
            let messages = [];
            
            if (data.warning_count > 0) {
                messages.push(`⚠️ ${data.warning_count} duplicate SKUs detected - last occurrence will be kept`);
            }
            if (data.error_count > 0) {
                messages.push(`❌ ${data.error_count} rows have errors and will be skipped`);
            }
            
            if (messages.length > 0) {
                warning.textContent = messages.join('. ') + `. ${data.valid_count} rows will be imported.`;
                warning.style.color = data.error_count > 0 ? 'var(--danger)' : 'var(--warning)';
            } else {
                warning.textContent = `✓ All ${data.valid_count} rows are valid and will be imported.`;
                warning.style.color = 'var(--success)';
            }
        }

        // 2. Import Loop
        document.getElementById('startImportBtn').addEventListener('click', () => {
            document.getElementById('previewSection').classList.add('hidden');
            document.getElementById('progressSection').classList.remove('hidden');
            processChunk();
        });

        async function processChunk() {
            try {
                const res = await fetch('/import-chunk', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ 
                        file_id: currentFileId, 
                        offset: currentOffset,
                        session_id: currentSessionId 
                    })
                });
                const data = await res.json();

                if (!data.success) throw new Error(data.message);

                // Update UI
                const pct = data.progress + '%';
                document.getElementById('progressFill').style.width = pct;
                document.getElementById('progressText').textContent = pct + ` (${data.valid_count || data.processed_count} valid rows processed)`;

                // Log
                const log = document.getElementById('log');
                log.innerHTML += `<div>Chunk @ ${currentOffset}: ${data.valid_count || 0} valid, ${data.error_count} errors</div>`;
                log.scrollTop = log.scrollHeight;

                if (!data.is_complete) {
                    currentOffset = data.next_offset;
                    // Small delay to allow UI refresh and prevent browser confusing 100% CPU usage
                    setTimeout(processChunk, 50);
                } else {
                    alert('Import Complete! View details in Import History.');
                    window.location.href = '/history';
                }

            } catch (err) {
                alert('Import Loop Failed: ' + err.message);
                document.getElementById('startImportBtn').classList.remove('hidden');
            }
        }
    </script>
</body>

</html>