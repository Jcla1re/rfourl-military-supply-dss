<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'Procurement Report') ?></title>
    <style>
        :root {
            --bg-dark: #10170f;
            --bg-sidebar: #21372a;
            --bg-header: #2d4737;
            --panel: #ece3d7;
            --line: #cfc7bc;
            --text: #1a1b1a;
            --muted: #5e5e5e;
            --green: #5d8a62;
            --green-strong: #3d6e4a;
            --red: #d95b5b;
            --amber: #d9a752;
            --gray: #d8d2cc;
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; font-family: Arial, sans-serif; background: var(--bg-dark); }
        body { display:flex; }

        .sidebar {
            width: 300px; background: linear-gradient(180deg, #2b3b2c, #213429); color: white;
            display:flex; flex-direction:column; padding: 18px 18px 10px;
        }

        .brand { display:flex; align-items:center; gap:12px; padding: 8px 8px 18px; border-bottom:1px solid rgba(255,255,255,0.12); margin-bottom: 18px; }
        .brand-mark { width: 42px; height: 42px; border-radius: 10px; background: #d7d7d7; position: relative; }
        .brand-mark::before { content:"R"; position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#1a1a1a; font-weight:700; font-size:20px; }
        .brand-text { font-size: 18px; font-weight: 700; line-height:1.05; }
        .brand-text small { display:block; font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: rgba(255,255,255,0.75); }

        .nav-section { font-size:12px; letter-spacing:.18em; text-transform:uppercase; color: rgba(255,255,255,0.55); margin:20px 8px 8px; }
        .nav-item { display:flex; align-items:center; gap:12px; text-decoration:none; color:white; padding:14px 12px; border-radius:10px; margin:4px 0; }
        .nav-item.active { background: rgba(255,255,255,0.1); }
        .nav-icon { width:20px; text-align:center; }

        .sidebar-footer { margin-top:auto; border-top: 1px solid rgba(255,255,255,0.12); padding-top:14px; display:flex; align-items:center; justify-content:space-between; }
        .user-box { display:flex; align-items:center; gap:12px; }
        .avatar { width:38px; height:38px; border-radius:50%; background: linear-gradient(135deg, #e3d8ca, #9bb0a2); }
        .user-name { font-size:18px; font-weight:600; }
        .user-role { font-size:12px; color: rgba(255,255,255,0.65); }
        .logout-btn { border:none; background: rgba(255,255,255,0.08); color:white; border-radius:8px; height:36px; width:36px; cursor:pointer; }

        .main { flex:1; background: #dfe4df; display:flex; flex-direction:column; }
        .topbar {
            background: var(--bg-header); min-height: 90px; display:flex; align-items:center;
            justify-content:space-between; padding: 0 24px; color:white; border-bottom:1px solid rgba(255,255,255,0.08);
        }
        .topbar h1 { margin:0; font-size:42px; font-weight:700; letter-spacing:-.04em; }
        .header-tools { display:flex; align-items:center; gap:12px; }
        .status-badge { font-size:12px; color: rgba(255,255,255,0.75); background: rgba(255,255,255,0.08); padding: 10px 18px; border-radius:12px; }
        .report-btn { background: rgba(255,255,255,0.12); color:white; border:none; border-radius:11px; padding: 12px 18px; font-size:16px; font-weight:700; }

        .page {
            padding: 22px 18px 18px;
            overflow:auto;
            height: calc(100vh - 90px);
        }

        .report-panel {
            background: rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 18px 18px 12px;
            box-shadow: 0 8px 18px rgba(0,0,0,0.06);
            animation: slideIn .35s ease;
        }

        @keyframes slideIn {
            from { transform: translateX(20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .report-card {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 14px;
            padding: 16px 18px 18px;
        }

        .metrics {
            display:grid; grid-template-columns: repeat(4, 1fr); gap: 14px;
            margin-top: 10px;
        }

        .metric-box {
            background: rgba(255,255,255,0.14); border: 1px solid rgba(0,0,0,0.08);
            border-radius: 12px; padding: 12px 14px; min-height: 110px; display:flex; flex-direction:column; align-items:center; justify-content:center;
        }

        .metric-box strong { font-size: 32px; }
        .metric-box span { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }

        table {
            width:100%; border-collapse: collapse; margin-top: 18px;
            background: rgba(255,255,255,0.08);
            border:1px solid rgba(0,0,0,0.08);
        }
        th, td {
            border-bottom: 1px solid rgba(0,0,0,0.08);
            padding: 12px 10px; text-align:left; font-size: 15px;
        }
        th { background: rgba(0,0,0,0.04); text-transform: uppercase; font-size: 12px; letter-spacing: .08em; color: #2d2d2d; }
        .pill {
            display:inline-block; padding: 6px 12px; border-radius: 999px; font-weight:700; font-size: 12px;
        }
        .pill.green { background: rgba(95,157,95,0.12); color: #2f6431; }
        .pill.amber { background: rgba(217,169,84,0.14); color: #8d6316; }
        .pill.red { background: rgba(217,91,91,0.12); color: #9d2f2f; }
        .action-btn { background: #dfe2dc; border:1px solid rgba(0,0,0,0.18); border-radius:8px; padding:8px 12px; cursor:pointer; font-weight:700; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark"></div>
            <div class="brand-text">
                RfourL
                <small>Military Supply</small>
            </div>
        </div>

        <div class="nav-section">Main</div>
        <a href="/admin/dashboard" class="nav-item"><span class="nav-icon">▣</span> Dashboard</a>
        <a href="/admin/inventory" class="nav-item"><span class="nav-icon">▤</span> Inventory</a>
        <a href="/admin/reorder-alerts" class="nav-item active"><span class="nav-icon">◫</span> Reorder Alerts</a>
        <a href="/admin/trend-analysis" class="nav-item"><span class="nav-icon">⌕</span> Trend Analysis</a>

        <div class="nav-section">Sales</div>
        <a href="/admin/pos-sales" class="nav-item"><span class="nav-icon">◫</span> POS &amp; Sales</a>

        <div class="nav-section">Procurement</div>
        <a href="/admin/orders" class="nav-item"><span class="nav-icon">◍</span> Orders</a>
        <a href="/admin/suppliers" class="nav-item"><span class="nav-icon">▣</span> Suppliers</a>

        <div class="nav-section">System</div>
        <a href="/admin/settings" class="nav-item"><span class="nav-icon">⚙</span> Settings</a>
        <a href="/admin/notifications" class="nav-item"><span class="nav-icon">🔔</span> Notifications</a>

        <div class="sidebar-footer">
            <div class="user-box">
                <div class="avatar"></div>
                <div>
                    <div class="user-name">Richelle Garcia</div>
                    <div class="user-role">Admin</div>
                </div>
            </div>
            <button class="logout-btn">⇠</button>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <h1>Reorder Alerts</h1>
            <div class="header-tools">
                <div class="status-badge">Last updated: 5 min ago</div>
                <button class="report-btn" type="button" onclick="window.location.href='/admin/reorder-alerts'">Back</button>
            </div>
        </header>

        <div class="page">
            <div class="report-panel">
                <div class="report-card">
                    <div class="metrics">
                        <div class="metric-box">
                            <strong>0</strong>
                            <span>Critical</span>
                        </div>
                        <div class="metric-box">
                            <strong>0</strong>
                            <span>Low Stock</span>
                        </div>
                        <div class="metric-box">
                            <strong>0</strong>
                            <span>Received</span>
                        </div>
                        <div class="metric-box">
                            <strong>0</strong>
                            <span>Open Orders</span>
                        </div>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>ABC</th>
                            <th>Stock</th>
                            <th>ROP</th>
                            <th>Status</th>
                            <th>Safety Stock</th>
                            <th>EOQ</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="8" style="text-align:center; color:#666; padding: 24px;">No procurement data available.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>