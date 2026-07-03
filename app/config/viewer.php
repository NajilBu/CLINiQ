<?php
// Database Viewer - PHP Version
// This file connects to your MySQL database and displays all tables and their structures

// Database configuration - Update these values to match your setup
$host = 'localhost';
$db = 'cliniq';
$user = 'root';
$pass = ''; // Update with your MySQL password

// Create connection
$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed: ' . $conn->connect_error]));
}

// Get all tables
$tables_result = $conn->query("SHOW TABLES");
$tables = [];

if ($tables_result && $tables_result->num_rows > 0) {
    while ($row = $tables_result->fetch_array()) {
        $table_name = $row[0];

        // Get table info
        $info_result = $conn->query("SHOW TABLE STATUS WHERE Name = '$table_name'");
        $info = $info_result->fetch_assoc();

        // Get table structure
        $columns_result = $conn->query("DESCRIBE `$table_name`");
        $columns = [];

        while ($col = $columns_result->fetch_assoc()) {
            $columns[] = $col;
        }

        $tables[] = [
            'name' => $table_name,
            'engine' => $info['Engine'] ?? 'Unknown',
            'rows' => $info['Rows'] ?? 0,
            'size' => formatBytes(($info['Data_length'] ?? 0) + ($info['Index_length'] ?? 0)),
            'columns' => $columns
        ];
    }
}

function formatBytes($bytes, $precision = 2)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');

    for ($i = 0; $bytes > 1024 && $i < 4; $i++) {
        $bytes /= 1024;
    }

    return round($bytes, $precision) . ' ' . $units[$i];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PLP AMS Database Viewer - Live</title>
    <style>
        :root {
            --primary-color: #0B3203;
            --secondary-color: #205017;
            --accent-color: #357CB6;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --card-bg: #ffffff;
            --border-color: #e9ecef;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .status-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .status-info {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .status-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .status-label {
            font-size: 0.8rem;
            color: #666;
            text-transform: uppercase;
            font-weight: 600;
        }

        .status-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .refresh-btn {
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: background 0.3s;
        }

        .refresh-btn:hover {
            background: #2563eb;
        }

        .tables-grid {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .table-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .table-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border-color);
        }

        .table-name {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-icon {
            width: 24px;
            height: 24px;
            background: var(--secondary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
        }

        .table-meta {
            font-size: 0.9rem;
            color: #666;
            display: flex;
            gap: 15px;
        }

        .table-meta span {
            background: #f0f0f0;
            padding: 2px 8px;
            border-radius: 4px;
        }

        .table-content {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        th {
            background: #f8f9fa;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        td {
            padding: 8px 10px;
            border-top: 1px solid var(--border-color);
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        .column-type {
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            color: #666;
            background: #f0f0f0;
            padding: 2px 6px;
            border-radius: 3px;
        }

        .column-key {
            font-size: 0.7rem;
            color: var(--accent-color);
            font-weight: 600;
            text-transform: uppercase;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
            background: var(--card-bg);
            border: 2px dashed var(--border-color);
            border-radius: 8px;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: var(--primary-color);
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            color: #666;
            font-size: 0.9rem;
        }

        .connection-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-connected {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            header h1 {
                font-size: 1.8rem;
            }

            .status-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .tables-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Collapsible structure styles */
        .structure-header {
            transition: background-color 0.2s;
        }

        .structure-header:hover {
            background-color: #e9ecef;
        }

        .structure-toggle {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--accent-color);
            transition: transform 0.3s;
        }
    </style>
</head>

<body>
    <div class="container">
        <header>
            <h1>
                <span>🗄️</span>
                PLP AMS Database Viewer - Live
            </h1>
            <p>Real-time view of all database tables and their structures</p>
        </header>

        <div class="status-card">
            <div class="status-info">
                <div class="status-item">
                    <span class="status-label">Database</span>
                    <span class="status-value"><?php echo $db; ?></span>
                </div>
                <div class="status-item">
                    <span class="status-label">Connection</span>
                    <span class="status-value">
                        <span class="connection-status status-connected">✅ Connected</span>
                    </span>
                </div>
                <div class="status-item">
                    <span class="status-label">Last Updated</span>
                    <span class="status-value" id="last-updated"><?php echo date('Y-m-d H:i:s'); ?></span>
                </div>
                <div class="status-item">
                    <span class="status-label">Total Tables</span>
                    <span class="status-value" id="total-tables"><?php echo count($tables); ?></span>
                </div>
            </div>
            <button class="refresh-btn" onclick="location.reload()">🔄 Refresh</button>
        </div>

        <div id="tables-container" class="tables-grid">
            <?php if (empty($tables)): ?>
                <div class="empty-state">
                    <h3>
                        <tool_call> No Tables Found
                    </h3>
                    <p>Your database appears to be empty. Please ensure your database is properly set up.</p>
                </div>
            <?php else: ?>
                <?php foreach ($tables as $table): ?>
                    <div class="table-card">
                        <div class="table-header">
                            <div class="table-name">
                                <div class="table-icon">📋</div>
                                <?php echo htmlspecialchars($table['name']); ?>
                            </div>
                            <div class="table-meta">
                                <span>Engine: <?php echo htmlspecialchars($table['engine']); ?></span>
                                <span>Rows: <?php echo number_format($table['rows']); ?></span>
                                <span>Size: <?php echo htmlspecialchars($table['size']); ?></span>
                            </div>
                        </div>

                        <!-- Table Structure -->
                        <div class="table-structure-section" style="margin-bottom: 20px;">
                            <div class="structure-header"
                                style="display: flex; justify-content: space-between; align-items: center; cursor: pointer; padding: 10px; background: #f8f9fa; border: 1px solid var(--border-color); border-radius: 4px; margin-bottom: 10px;"
                                onclick="toggleStructure('<?php echo $table['name']; ?>')">
                                <h4 style="margin: 0; color: var(--primary-color); font-size: 1rem;">Table Structure</h4>
                                <span class="structure-toggle" id="toggle-<?php echo $table['name']; ?>">▼</span>
                            </div>
                            <div class="table-content" id="structure-<?php echo $table['name']; ?>" style="display: none;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Field</th>
                                            <th>Type</th>
                                            <th>Null</th>
                                            <th>Key</th>
                                            <th>Default</th>
                                            <th>Extra</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($table['columns'] as $col): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($col['Field']); ?></strong></td>
                                                <td><span class="column-type"><?php echo htmlspecialchars($col['Type']); ?></span>
                                                </td>
                                                <td><?php echo $col['Null'] === 'NO' ? '<span style="color: var(--danger-color); font-weight: 600;">NOT NULL</span>' : 'NULL'; ?>
                                                </td>
                                                <td><?php echo $col['Key'] ? '<span class="column-key">' . htmlspecialchars($col['Key']) . '</span>' : '-'; ?>
                                                </td>
                                                <td><?php echo $col['Default'] !== null ? htmlspecialchars($col['Default']) : '-'; ?>
                                                </td>
                                                <td><?php echo $col['Extra'] ? htmlspecialchars($col['Extra']) : '-'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Table Data -->
                        <?php
                        // Get sample data from the table
                        $data_result = $conn->query("SELECT * FROM `{$table['name']}` LIMIT 10");
                        $data_rows = [];
                        if ($data_result) {
                            while ($row = $data_result->fetch_assoc()) {
                                $data_rows[] = $row;
                            }
                            $data_result->free();
                        }
                        ?>

                        <?php if (!empty($data_rows)): ?>
                            <div class="table-content">
                                <h4 style="margin-bottom: 10px; color: var(--primary-color); font-size: 1rem;">Sample Data (First 10
                                    Rows)</h4>
                                <table>
                                    <thead>
                                        <tr>
                                            <?php foreach ($table['columns'] as $col): ?>
                                                <th><?php echo htmlspecialchars($col['Field']); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($data_rows as $row): ?>
                                            <tr>
                                                <?php foreach ($table['columns'] as $col): ?>
                                                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                                                        title="<?php echo htmlspecialchars($row[$col['Field']] ?? ''); ?>">
                                                        <?php
                                                        $value = $row[$col['Field']] ?? '';
                                                        if ($value === null || $value === '') {
                                                            echo '<span style="color: #999; font-style: italic;">NULL</span>';
                                                        } elseif (strlen($value) > 50) {
                                                            echo htmlspecialchars(substr($value, 0, 50)) . '...';
                                                        } else {
                                                            echo htmlspecialchars($value);
                                                        }
                                                        ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php if ($table['rows'] > 10): ?>
                                    <div style="margin-top: 10px; font-size: 0.8rem; color: #666; text-align: right;">
                                        Showing 10 of <?php echo number_format($table['rows']); ?> total rows
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state" style="margin: 0; border-style: solid;">
                                <h3>📭 No Data</h3>
                                <p>This table is empty. Add some records to see data here.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="footer">
            <p>💡 Tip: This page shows the current structure of your PLP AMS database. Use it to verify table creation
                and monitor database changes.</p>
            <p style="margin-top: 10px; font-size: 0.8rem; color: #888;">
                Note: This is a live connection to your database. Always be careful when viewing sensitive data.
            </p>
        </div>

        <script>
            function toggleStructure(tableName) {
                const structureDiv = document.getElementById('structure-' + tableName);
                const toggleSpan = document.getElementById('toggle-' + tableName);

                if (structureDiv.style.display === 'none') {
                    structureDiv.style.display = 'block';
                    toggleSpan.textContent = '▼';
                    toggleSpan.style.transform = 'rotate(0deg)';
                } else {
                    structureDiv.style.display = 'none';
                    toggleSpan.textContent = '▶';
                    toggleSpan.style.transform = 'rotate(-90deg)';
                }
            }
        </script>
    </div>
</body>

</html>