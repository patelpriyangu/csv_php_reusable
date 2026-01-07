<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import History - CSV Engine</title>
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
            max-width: 1400px;
            margin: 0 auto;
            color: #1e293b;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .card {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }

        h1 {
            margin: 0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .stat-label {
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 1.875rem;
            font-weight: 700;
            color: #0f172a;
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

        table {
            width: 100%;
            border-collapse: collapse;
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

        tr:hover {
            background: #f8fafc;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success {
            background: #dcfce7;
            color: #166534;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .btn-view {
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
            cursor: pointer;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
            width: 90%;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
        }

        .error-list {
            max-height: 400px;
            overflow-y: auto;
            background: #fef2f2;
            padding: 1rem;
            border-radius: 4px;
        }

        .error-item {
            margin-bottom: 0.75rem;
            padding: 0.5rem;
            background: white;
            border-left: 3px solid var(--danger);
            font-size: 0.875rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }
    </style>
</head>

<body>

    <div class="header">
        <h1>📊 Import History</h1>
        <a href="/" class="btn btn-outline">← Back to Import</a>
    </div>

    <div class="stats-grid" id="statsContainer">
        <div class="stat-card">
            <div class="stat-label">Total Imports</div>
            <div class="stat-value" id="statTotalImports">-</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Rows Processed</div>
            <div class="stat-value" id="statTotalRows">-</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Success Rate</div>
            <div class="stat-value" id="statSuccessRate">-</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Avg Duration</div>
            <div class="stat-value" id="statAvgDuration">-</div>
        </div>
    </div>

    <div class="card">
        <h2>Recent Imports</h2>
        <table id="historyTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Filename</th>
                    <th>Size (MB)</th>
                    <th>Total Rows</th>
                    <th>Valid</th>
                    <th>Errors</th>
                    <th>Status</th>
                    <th>Duration</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <!-- Populated by JS -->
            </tbody>
        </table>
        <div id="emptyState" class="empty-state" style="display: none;">
            <p>No import history found. Start by importing a CSV file.</p>
        </div>
    </div>

    <!-- Detail Modal -->
    <div id="detailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Import Details</h2>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div id="modalBody">
                <!-- Populated by JS -->
            </div>
        </div>
    </div>

    <script>
        // Load history on page load
        document.addEventListener('DOMContentLoaded', () => {
            loadHistory();
        });

        async function loadHistory() {
            try {
                const res = await fetch('/api/history?module=inventory');
                const data = await res.json();

                if (!data.success) throw new Error(data.message);

                // Update stats
                updateStats(data.stats);

                // Update table
                updateTable(data.records);

            } catch (err) {
                console.error('Failed to load history:', err);
                alert('Failed to load import history');
            }
        }

        function updateStats(stats) {
            document.getElementById('statTotalImports').textContent = stats.total_imports || 0;
            document.getElementById('statTotalRows').textContent = formatNumber(stats.total_rows_processed || 0);

            const successRate = stats.total_rows_processed > 0
                ? ((stats.total_valid_rows / stats.total_rows_processed) * 100).toFixed(1)
                : 0;
            document.getElementById('statSuccessRate').textContent = successRate + '%';

            const avgDuration = stats.avg_duration_seconds
                ? formatDuration(Math.round(stats.avg_duration_seconds))
                : '0s';
            document.getElementById('statAvgDuration').textContent = avgDuration;
        }

        function updateTable(records) {
            const tbody = document.querySelector('#historyTable tbody');
            const emptyState = document.getElementById('emptyState');

            if (!records || records.length === 0) {
                tbody.innerHTML = '';
                emptyState.style.display = 'block';
                return;
            }

            emptyState.style.display = 'none';

            tbody.innerHTML = records.map(record => `
                <tr>
                    <td>${record.id}</td>
                    <td>${formatDate(record.started_at)}</td>
                    <td>${record.filename}</td>
                    <td>${record.file_size_mb}</td>
                    <td>${formatNumber(record.total_rows)}</td>
                    <td style="color: var(--success);">${formatNumber(record.valid_rows)}</td>
                    <td style="color: var(--danger);">${formatNumber(record.error_rows)}</td>
                    <td>${getStatusBadge(record.status)}</td>
                    <td>${formatDuration(record.duration_seconds)}</td>
                    <td>
                        <button class="btn btn-primary btn-view" onclick="viewDetails(${record.id})">
                            View Details
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function getStatusBadge(status) {
            const badges = {
                'completed': '<span class="badge badge-success">Completed</span>',
                'failed': '<span class="badge badge-danger">Failed</span>',
                'in_progress': '<span class="badge badge-warning">In Progress</span>'
            };
            return badges[status] || status;
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleString();
        }

        function formatNumber(num) {
            return new Intl.NumberFormat().format(num);
        }

        function formatDuration(seconds) {
            if (!seconds || seconds === 0) return '0s';
            if (seconds < 60) return seconds + 's';
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${mins}m ${secs}s`;
        }

        async function viewDetails(sessionId) {
            try {
                const res = await fetch(`/api/history/detail?id=${sessionId}`);
                const data = await res.json();

                if (!data.success) throw new Error(data.message);

                const session = data.session;
                const errorDetails = session.error_details || {};
                const errorCount = Object.keys(errorDetails).length;

                let modalHtml = `
                    <div style="margin-bottom: 2rem;">
                        <h3>Session #${session.id}</h3>
                        <p><strong>Filename:</strong> ${session.filename}</p>
                        <p><strong>Started:</strong> ${formatDate(session.started_at)}</p>
                        <p><strong>Completed:</strong> ${session.completed_at ? formatDate(session.completed_at) : 'N/A'}</p>
                        <p><strong>Duration:</strong> ${formatDuration(session.duration_seconds)}</p>
                        <p><strong>Status:</strong> ${getStatusBadge(session.status)}</p>
                    </div>

                    <div style="margin-bottom: 2rem;">
                        <h3>Summary</h3>
                        <p><strong>Total Rows:</strong> ${formatNumber(session.total_rows)}</p>
                        <p style="color: var(--success);"><strong>Valid Rows:</strong> ${formatNumber(session.valid_rows)}</p>
                        <p style="color: var(--danger);"><strong>Error Rows:</strong> ${formatNumber(session.error_rows)}</p>
                    </div>
                `;

                if (errorCount > 0) {
                    modalHtml += `
                        <div>
                            <h3>Errors (${errorCount} rows)</h3>
                            <div class="error-list">
                    `;

                    for (const [rowIndex, errors] of Object.entries(errorDetails)) {
                        modalHtml += `
                            <div class="error-item">
                                <strong>Row ${rowIndex}:</strong><br>
                        `;
                        for (const [field, message] of Object.entries(errors)) {
                            modalHtml += `• ${field}: ${message}<br>`;
                        }
                        modalHtml += `</div>`;
                    }

                    modalHtml += `</div></div>`;
                } else {
                    modalHtml += `<p style="color: var(--success);">✓ No errors recorded</p>`;
                }

                document.getElementById('modalBody').innerHTML = modalHtml;
                document.getElementById('detailModal').classList.add('active');

            } catch (err) {
                console.error('Failed to load details:', err);
                alert('Failed to load session details');
            }
        }

        function closeModal() {
            document.getElementById('detailModal').classList.remove('active');
        }

        // Close modal on background click
        document.getElementById('detailModal').addEventListener('click', (e) => {
            if (e.target.id === 'detailModal') {
                closeModal();
            }
        });
    </script>

</body>

</html>

