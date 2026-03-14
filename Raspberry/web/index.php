<?php
// API-Endpoint für Netzwerk-Status
if (isset($_GET['api']) && $_GET['api'] === 'network_status') {
    header('Content-Type: application/json');
    
    // Status vom switch_mode.sh Skript holen
    $output = shell_exec('sudo /opt/datenkrake/switch_mode.sh status 2>/dev/null');
    if ($output) {
        echo $output;
    } else {
        // Fallback wenn Skript nicht verfügbar
        $mode_file = '/opt/datenkrake/current_mode';
        $mode = file_exists($mode_file) ? trim(file_get_contents($mode_file)) : 'unknown';
        echo json_encode([
            'mode' => $mode,
            'ip' => 'unknown',
            'ssid' => $mode === 'ap' ? 'Datenkrake' : 'unknown',
            'internet' => false,
            'services' => ['hostapd' => 'unknown', 'dnsmasq' => 'unknown', 'wpa_supplicant' => 'unknown']
        ]);
    }
    exit;
}

// API-Endpoint für Modus-Wechsel
if (isset($_GET['api']) && $_GET['api'] === 'network_switch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $mode = $input['mode'] ?? '';
    
    if (!in_array($mode, ['ap', 'client'])) {
        echo json_encode(['error' => 'Ungültiger Modus. Erlaubt: ap, client']);
        exit;
    }
    
    // Modus wechseln
    $output = shell_exec("sudo /opt/datenkrake/switch_mode.sh $mode 2>&1");
    
    // Kurz warten und dann Status holen
    sleep(2);
    $status = shell_exec('sudo /opt/datenkrake/switch_mode.sh status 2>/dev/null');
    
    echo json_encode([
        'success' => true,
        'requested_mode' => $mode,
        'output' => $output,
        'status' => json_decode($status, true)
    ]);
    exit;
}

// API-Endpoint für bekannte WLANs
if (isset($_GET['api']) && $_GET['api'] === 'known_networks') {
    header('Content-Type: application/json');
    $output = shell_exec('sudo /opt/datenkrake/switch_mode.sh list 2>/dev/null');
    echo $output ?: '[]';
    exit;
}

// API-Endpoint für WLAN hinzufügen
if (isset($_GET['api']) && $_GET['api'] === 'add_network' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $ssid = escapeshellarg($input['ssid'] ?? '');
    $password = escapeshellarg($input['password'] ?? '');
    
    if (empty($input['ssid'])) {
        echo json_encode(['error' => 'SSID erforderlich']);
        exit;
    }
    
    $output = shell_exec("sudo /opt/datenkrake/switch_mode.sh add $ssid $password 2>&1");
    echo json_encode(['success' => true, 'output' => $output]);
    exit;
}

// API-Endpoint für verbundene Geräte (DHCP-Leases)
if (isset($_GET['api']) && $_GET['api'] === 'devices') {
    header('Content-Type: application/json');
    
    $devices = [];
    
    // DHCP-Leases von dnsmasq lesen
    $leases_file = '/var/lib/misc/dnsmasq.leases';
    if (file_exists($leases_file)) {
        $lines = file($leases_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 4) {
                $expiry_ts = (int)$parts[0];
                $devices[] = [
                    'expiry' => $expiry_ts > 0 ? date('Y-m-d H:i:s', $expiry_ts) : 'Permanent',
                    'mac' => strtoupper($parts[1]),
                    'ip' => $parts[2],
                    'hostname' => isset($parts[3]) && $parts[3] !== '*' ? $parts[3] : '-',
                    'online' => true // Annahme: wenn in Leases, dann online
                ];
            }
        }
    }
    
    // Nach IP sortieren
    usort($devices, function($a, $b) {
        return ip2long($a['ip']) - ip2long($b['ip']);
    });
    
    echo json_encode([
        'devices' => $devices,
        'gateway' => [
            'ip' => '10.0.0.1',
            'hostname' => 'datenkrake',
            'ssid' => 'Datenkrake',
            'network' => '10.0.0.0/24'
        ]
    ]);
    exit;
}

// API-Endpoint für JSON-Daten
if (isset($_GET['api']) && $_GET['api'] === 'data') {
    header('Content-Type: application/json');
    
    $servername = "db";
    $username = "sensor";
    $password = "changeMeSensor";
    $dbname = "telemetry";
    
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        echo json_encode(['error' => $conn->connect_error]);
        exit;
    }
    
    // Filter nach Label (optional)
    $labelFilter = isset($_GET['label']) ? $_GET['label'] : null;
    
    // Letzte 100 Werte abfragen
    if ($labelFilter && in_array($labelFilter, ['gut', 'schlecht'])) {
        $sql = "SELECT * FROM audio_spectrum WHERE label = ? ORDER BY ts DESC LIMIT 100";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $labelFilter);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $sql = "SELECT * FROM audio_spectrum ORDER BY ts DESC LIMIT 100";
        $result = $conn->query($sql);
    }
    
    $data = [];
    while($row = $result->fetch_assoc()) {
        // Spectrum JSON dekodieren
        $row['spectrum'] = json_decode($row['spectrum'], true);
        $data[] = $row;
    }
    $conn->close();
    
    // Umkehren für chronologische Reihenfolge
    $data = array_reverse($data);
    
    echo json_encode($data);
    exit;
}

// Datenbank leeren
if (isset($_GET['api']) && $_GET['api'] === 'clear_database' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $conn = new mysqli("db", "sensor", "changeMeSensor", "telemetry");
    if ($conn->connect_error) {
        echo json_encode(['error' => $conn->connect_error]);
        exit;
    }
    $result = $conn->query("SELECT COUNT(*) as cnt FROM audio_spectrum");
    $count = (int)$result->fetch_assoc()['cnt'];
    $conn->query("TRUNCATE TABLE audio_spectrum");
    $conn->close();
    echo json_encode(['success' => true, 'deleted' => $count]);
    exit;
}

// Statistik-Endpoint
if (isset($_GET['api']) && $_GET['api'] === 'stats') {
    header('Content-Type: application/json');
    
    $servername = "db";
    $username = "sensor";
    $password = "changeMeSensor";
    $dbname = "telemetry";
    
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        echo json_encode(['error' => $conn->connect_error]);
        exit;
    }
    
    $stats = [];
    
    // Anzahl pro Label
    $result = $conn->query("SELECT label, COUNT(*) as count FROM audio_spectrum GROUP BY label");
    while($row = $result->fetch_assoc()) {
        $stats[$row['label']] = (int)$row['count'];
    }
    
    // Gesamtanzahl
    $result = $conn->query("SELECT COUNT(*) as total FROM audio_spectrum");
    $stats['total'] = (int)$result->fetch_assoc()['total'];
    
    $conn->close();
    echo json_encode($stats);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Datenkrake Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        h1 { display: flex; align-items: center; gap: 10px; color: #8b1a1a; }
        .live-indicator { width: 12px; height: 12px; background: #28a745; border-radius: 50%; animation: pulse 1s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        
        /* Tab-System */
        .tabs { display: flex; gap: 5px; margin-bottom: 20px; border-bottom: 2px solid #ddd; padding-bottom: 0; }
        .tab-btn { padding: 12px 30px; font-size: 16px; border: none; border-radius: 8px 8px 0 0; cursor: pointer; background: #e9ecef; color: #666; transition: all 0.2s; }
        .tab-btn:hover { background: #ddd; }
        .tab-btn.active { background: #8b1a1a; color: #fff; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .status { padding: 10px; margin-bottom: 15px; border-radius: 5px; background: #d4edda; color: #155724; }
        .status.error { background: #f8d7da; color: #721c24; }
        
        .stats-container { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); min-width: 150px; text-align: center; }
        .stat-card.gut { border-left: 4px solid #28a745; }
        .stat-card.schlecht { border-left: 4px solid #dc3545; }
        .stat-card.total { border-left: 4px solid #007bff; }
        .stat-value { font-size: 32px; font-weight: bold; color: #333; }
        .stat-label { color: #666; margin-top: 5px; }
        
        .filter-container { margin-bottom: 15px; }
        .filter-btn { padding: 8px 20px; margin-right: 10px; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; }
        .filter-btn.active { color: white; }
        .filter-btn.all { background: #e9ecef; }
        .filter-btn.all.active { background: #007bff; }
        .filter-btn.gut { background: #d4edda; color: #155724; }
        .filter-btn.gut.active { background: #28a745; color: white; }
        .filter-btn.schlecht { background: #f8d7da; color: #721c24; }
        .filter-btn.schlecht.active { background: #dc3545; color: white; }
        
        .charts-container { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .chart-box { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .chart-box h2 { margin-top: 0; color: #8b1a1a; font-size: 16px; }
        .chart-container { height: 250px; }
        
        .table-section { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .table-section h2 { margin-top: 0; color: #8b1a1a; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 13px; }
        th { background-color: #f8f8f8; position: sticky; top: 0; }
        .table-wrapper { max-height: 300px; overflow-y: auto; }
        .label-gut { background: #d4edda; color: #155724; padding: 2px 8px; border-radius: 3px; }
        .label-schlecht { background: #f8d7da; color: #721c24; padding: 2px 8px; border-radius: 3px; }
        .btn-danger { padding: 8px 20px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; margin-left: 15px; }
        .btn-danger:hover { background: #c82333; }
        
        @media (max-width: 900px) {
            .charts-container { grid-template-columns: 1fr; }
        }
        
        /* Geräte-Tab Styles */
        .network-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .network-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid #8b1a1a; }
        .network-card h3 { margin: 0 0 10px 0; color: #8b1a1a; font-size: 14px; }
        .network-card .value { font-size: 24px; font-weight: bold; color: #333; }
        .network-card .sub { color: #666; font-size: 13px; margin-top: 5px; }
        
        .devices-table { background: white; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .devices-table h2 { margin-top: 0; color: #8b1a1a; display: flex; align-items: center; gap: 10px; }
        .devices-table table { border-collapse: collapse; width: 100%; }
        .devices-table th, .devices-table td { border: 1px solid #ddd; padding: 10px 12px; text-align: left; }
        .devices-table th { background-color: #f8f8f8; font-weight: 600; }
        .devices-table tr:hover { background: #f9f9f9; }
        
        .status-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 8px; }
        .status-dot.online { background: #28a745; }
        .status-dot.offline { background: #dc3545; }
        
        .mac-address { font-family: monospace; font-size: 12px; color: #666; }
        .ip-address { font-family: monospace; font-weight: bold; }
        .hostname { color: #007bff; }
        
        .refresh-btn { padding: 8px 16px; background: #8b1a1a; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; }
        .refresh-btn:hover { background: #6d1515; }
        
        .no-devices { text-align: center; padding: 40px; color: #666; }
        
        /* Modus-Schalter Styles */
        .mode-switcher { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .mode-switcher h2 { margin-top: 0; color: #8b1a1a; }
        .mode-buttons { display: flex; gap: 15px; margin: 15px 0; }
        .mode-btn { padding: 15px 30px; font-size: 16px; border: 2px solid #ddd; border-radius: 8px; cursor: pointer; background: #f8f8f8; transition: all 0.2s; display: flex; flex-direction: column; align-items: center; min-width: 180px; }
        .mode-btn:hover { border-color: #8b1a1a; }
        .mode-btn.active { background: #8b1a1a; color: white; border-color: #8b1a1a; }
        .mode-btn .icon { font-size: 32px; margin-bottom: 8px; }
        .mode-btn .label { font-weight: bold; }
        .mode-btn .desc { font-size: 12px; opacity: 0.8; margin-top: 4px; }
        .mode-status { padding: 15px; border-radius: 8px; margin-top: 15px; }
        .mode-status.ap { background: #d4edda; border-left: 4px solid #28a745; }
        .mode-status.client { background: #cce5ff; border-left: 4px solid #007bff; }
        .mode-status.switching { background: #fff3cd; border-left: 4px solid #ffc107; }
        .mode-status.error { background: #f8d7da; border-left: 4px solid #dc3545; }
        
        .wlan-config { background: #f8f8f8; padding: 15px; border-radius: 8px; margin-top: 15px; display: none; }
        .wlan-config.show { display: block; }
        .wlan-config input { padding: 10px; border: 1px solid #ddd; border-radius: 5px; margin-right: 10px; }
        .wlan-config button { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>🐙 Datenkrake Dashboard <span class="live-indicator" title="Live-Aktualisierung aktiv"></span></h1>
    
    <div class="tabs">
        <button class="tab-btn active" onclick="showTab('spektrum')">📊 Audio-Spektrum</button>
        <button class="tab-btn" onclick="showTab('geraete')">📡 Verbundene Geräte</button>
    </div>
    
    <!-- Tab 1: Audio-Spektrum (bestehend) -->
    <div id="tab-spektrum" class="tab-content active">
        <div class="status" id="status">Live-Aktualisierung alle 2 Sekunden | Letzte Aktualisierung: <span id="lastUpdate">-</span></div>
    
    <div class="stats-container">
        <div class="stat-card total">
            <div class="stat-value" id="statTotal">0</div>
            <div class="stat-label">Gesamt</div>
        </div>
        <div class="stat-card gut">
            <div class="stat-value" id="statGut">0</div>
            <div class="stat-label">Gut</div>
        </div>
        <div class="stat-card schlecht">
            <div class="stat-value" id="statSchlecht">0</div>
            <div class="stat-label">Schlecht</div>
        </div>
    </div>
    
    <div class="filter-container">
        <button class="filter-btn all active" onclick="setFilter(null)">Alle</button>
        <button class="filter-btn gut" onclick="setFilter('gut')">Nur Gut</button>
        <button class="filter-btn schlecht" onclick="setFilter('schlecht')">Nur Schlecht</button>
        <button class="btn-danger" onclick="clearDatabase()">🗑️ Datenbank leeren</button>
    </div>
    
    <div class="charts-container">
        <div class="chart-box">
            <h2>Peak-Frequenz über Zeit</h2>
            <div class="chart-container"><canvas id="freqChart"></canvas></div>
        </div>
        <div class="chart-box">
            <h2>Aktuelles Spektrum</h2>
            <div class="chart-container"><canvas id="spectrumChart"></canvas></div>
        </div>
    </div>
    
    <div class="table-section">
        <h2>Letzte Messungen</h2>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>ID</th><th>Zeitstempel</th><th>Label</th><th>Peak Freq (Hz)</th><th>Peak dB</th><th>Sample Rate</th></tr>
                </thead>
                <tbody id="dataTable"></tbody>
            </table>
        </div>
    </div>
    </div> <!-- Ende Tab Spektrum -->
    
    <!-- Tab 2: Verbundene Geräte -->
    <div id="tab-geraete" class="tab-content">
        
        <!-- Modus-Umschalter -->
        <div class="mode-switcher">
            <h2>🔄 Netzwerk-Modus</h2>
            <p>Wechsle zwischen Access Point (eigenes WLAN) und Client-Modus (Internet für Pi-Connect & Updates)</p>
            
            <div class="mode-buttons">
                <button class="mode-btn" id="modeAP" onclick="switchMode('ap')">
                    <span class="icon">📡</span>
                    <span class="label">Access Point</span>
                    <span class="desc">SSID: Datenkrake</span>
                    <span class="desc">Kein Internet</span>
                </button>
                <button class="mode-btn" id="modeClient" onclick="switchMode('client')">
                    <span class="icon">🌐</span>
                    <span class="label">Client/Internet</span>
                    <span class="desc">Pi-Connect & Git Pull</span>
                    <span class="desc">Braucht WLAN-Zugang</span>
                </button>
            </div>
            
            <div class="mode-status ap" id="modeStatus">
                <strong>Status:</strong> <span id="modeStatusText">Lade...</span>
            </div>
            
            <div class="wlan-config" id="wlanConfig">
                <h4>📶 WLAN für Internet-Modus konfigurieren</h4>
                <p style="font-size: 13px; color: #666;">Damit der Client-Modus funktioniert, muss ein WLAN konfiguriert sein:</p>
                <input type="text" id="wlanSSID" placeholder="WLAN-Name (SSID)">
                <input type="password" id="wlanPassword" placeholder="Passwort">
                <button onclick="addWlan()">WLAN hinzufügen</button>
                <p style="font-size: 12px; color: #999; margin-top: 10px;">
                    Alternativ: <code>sudo raspi-config</code> → System Options → Wireless LAN
                </p>
            </div>
        </div>
        
        <div class="network-info">
            <div class="network-card">
                <h3>🌐 Netzwerk</h3>
                <div class="value" id="networkSSID">Datenkrake</div>
                <div class="sub">WLAN SSID</div>
            </div>
            <div class="network-card">
                <h3>🖥️ Gateway (Raspberry Pi)</h3>
                <div class="value" id="gatewayIP">10.0.0.1</div>
                <div class="sub">datenkrake.krake.local</div>
            </div>
            <div class="network-card">
                <h3>📱 Verbundene Geräte</h3>
                <div class="value" id="deviceCount">0</div>
                <div class="sub">aktive DHCP-Leases</div>
            </div>
            <div class="network-card">
                <h3>🔒 IP-Bereich</h3>
                <div class="value">10.0.0.x</div>
                <div class="sub">DHCP: .10 - .254</div>
            </div>
        </div>
        
        <div class="devices-table">
            <h2>
                Verbundene Geräte
                <button class="refresh-btn" onclick="loadDevices()">🔄 Aktualisieren</button>
            </h2>
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>IP-Adresse</th>
                        <th>Hostname</th>
                        <th>MAC-Adresse</th>
                        <th>Lease gültig bis</th>
                    </tr>
                </thead>
                <tbody id="devicesTable">
                    <tr><td colspan="5" class="no-devices">Lade Geräte...</td></tr>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
            <strong>💡 Tipp:</strong> Um einem Gerät eine feste IP zuzuweisen, trage die MAC-Adresse in 
            <code>/etc/dnsmasq.d/static-hosts.conf</code> ein und starte dnsmasq neu.
        </div>
    </div>

    <script>
        let freqChart, spectrumChart;
        let currentFilter = null;
        let currentTab = 'spektrum';
        
        function showTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            if (tab === 'spektrum') {
                document.querySelector('.tab-btn:nth-child(1)').classList.add('active');
            } else {
                document.querySelector('.tab-btn:nth-child(2)').classList.add('active');
                loadDevices();
            }
            document.getElementById('tab-' + tab).classList.add('active');
        }
        
        async function loadDevices() {
            try {
                const response = await fetch('?api=devices');
                const data = await response.json();
                
                document.getElementById('deviceCount').textContent = data.devices.length;
                
                const tableBody = document.getElementById('devicesTable');
                if (data.devices.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="5" class="no-devices">Keine Geräte verbunden. Sobald sich ein Gerät mit dem WLAN "Datenkrake" verbindet, erscheint es hier.</td></tr>';
                    return;
                }
                
                tableBody.innerHTML = data.devices.map(device => `
                    <tr>
                        <td><span class="status-dot ${device.online ? 'online' : 'offline'}"></span>${device.online ? 'Online' : 'Offline'}</td>
                        <td class="ip-address">${device.ip}</td>
                        <td class="hostname">${device.hostname || '-'}</td>
                        <td class="mac-address">${device.mac}</td>
                        <td>${device.expiry}</td>
                    </tr>
                `).join('');
                
            } catch (error) {
                document.getElementById('devicesTable').innerHTML = 
                    '<tr><td colspan="5" class="no-devices" style="color: #dc3545;">Fehler beim Laden: ' + error.message + '</td></tr>';
            }
        }

        // Netzwerk-Modus Funktionen
        async function loadNetworkStatus() {
            try {
                const response = await fetch('?api=network_status');
                const status = await response.json();
                
                const statusEl = document.getElementById('modeStatus');
                const statusTextEl = document.getElementById('modeStatusText');
                const modeAPBtn = document.getElementById('modeAP');
                const modeClientBtn = document.getElementById('modeClient');
                const wlanConfig = document.getElementById('wlanConfig');
                
                // Buttons aktualisieren
                modeAPBtn.classList.remove('active');
                modeClientBtn.classList.remove('active');
                
                if (status.mode === 'ap') {
                    modeAPBtn.classList.add('active');
                    statusEl.className = 'mode-status ap';
                    statusTextEl.innerHTML = `<strong>Access Point aktiv</strong><br>
                        SSID: Datenkrake | IP: ${status.ip || '10.0.0.1'}<br>
                        <small>Geräte können sich mit dem WLAN "Datenkrake" verbinden</small>`;
                    wlanConfig.classList.remove('show');
                    document.getElementById('networkSSID').textContent = 'Datenkrake';
                    document.getElementById('gatewayIP').textContent = status.ip || '10.0.0.1';
                } else if (status.mode === 'client') {
                    modeClientBtn.classList.add('active');
                    statusEl.className = 'mode-status client';
                    const internetIcon = status.internet ? '✅' : '❌';
                    statusTextEl.innerHTML = `<strong>Client-Modus aktiv</strong><br>
                        Verbunden mit: ${status.ssid || 'unbekannt'} | IP: ${status.ip || 'wird bezogen...'}<br>
                        Internet: ${internetIcon} ${status.internet ? 'Verbunden' : 'Keine Verbindung'}<br>
                        <small>Pi-Connect und Git Pull möglich</small>`;
                    wlanConfig.classList.add('show');
                    document.getElementById('networkSSID').textContent = status.ssid || '-';
                    document.getElementById('gatewayIP').textContent = status.ip || '-';
                } else {
                    statusEl.className = 'mode-status error';
                    statusTextEl.innerHTML = `<strong>Status unbekannt</strong><br>
                        Modus-Skript nicht verfügbar. Bitte setup_accesspoint.sh ausführen.`;
                    wlanConfig.classList.add('show');
                }
            } catch (error) {
                document.getElementById('modeStatus').className = 'mode-status error';
                document.getElementById('modeStatusText').innerHTML = 'Fehler: ' + error.message;
            }
        }
        
        async function switchMode(mode) {
            const statusEl = document.getElementById('modeStatus');
            const statusTextEl = document.getElementById('modeStatusText');
            
            statusEl.className = 'mode-status switching';
            statusTextEl.innerHTML = `<strong>⏳ Wechsle zu ${mode === 'ap' ? 'Access Point' : 'Client'}-Modus...</strong><br>
                <small>Dies kann einige Sekunden dauern. Bei Modus-Wechsel zu Client wird die Verbindung unterbrochen!</small>`;
            
            if (mode === 'client') {
                if (!confirm('Achtung: Beim Wechsel zum Client-Modus wird das Datenkrake-WLAN deaktiviert und diese Seite ist nicht mehr erreichbar!\n\nFortfahren?')) {
                    loadNetworkStatus();
                    return;
                }
            }
            
            try {
                const response = await fetch('?api=network_switch', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mode: mode })
                });
                const result = await response.json();
                
                if (result.error) {
                    statusEl.className = 'mode-status error';
                    statusTextEl.innerHTML = 'Fehler: ' + result.error;
                } else {
                    setTimeout(loadNetworkStatus, 3000);
                }
            } catch (error) {
                // Bei Modus-Wechsel zu Client ist Verbindungsfehler normal
                if (mode === 'client') {
                    statusEl.className = 'mode-status client';
                    statusTextEl.innerHTML = `<strong>Client-Modus aktiviert</strong><br>
                        <small>Verbindung zum Access Point unterbrochen. Der Pi verbindet sich jetzt mit dem konfigurierten WLAN.</small>`;
                } else {
                    statusEl.className = 'mode-status error';
                    statusTextEl.innerHTML = 'Verbindungsfehler: ' + error.message;
                }
            }
        }
        
        async function addWlan() {
            const ssid = document.getElementById('wlanSSID').value.trim();
            const password = document.getElementById('wlanPassword').value;
            
            if (!ssid) {
                alert('Bitte WLAN-Name eingeben');
                return;
            }
            
            try {
                const response = await fetch('?api=add_network', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ssid, password })
                });
                const result = await response.json();
                
                if (result.error) {
                    alert('Fehler: ' + result.error);
                } else {
                    alert('WLAN "' + ssid + '" hinzugefügt!\n\nDu kannst jetzt in den Client-Modus wechseln.');
                    document.getElementById('wlanSSID').value = '';
                    document.getElementById('wlanPassword').value = '';
                }
            } catch (error) {
                alert('Fehler: ' + error.message);
            }
        }

        function initCharts() {
            const ctxFreq = document.getElementById('freqChart').getContext('2d');
            freqChart = new Chart(ctxFreq, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Peak Frequenz (Hz)',
                        data: [],
                        borderColor: 'rgba(139, 26, 26, 1)',
                        backgroundColor: 'rgba(139, 26, 26, 0.2)',
                        fill: true,
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 300 },
                    scales: {
                        x: { display: true, title: { display: true, text: 'Zeit' } },
                        y: { display: true, title: { display: true, text: 'Frequenz (Hz)' }, beginAtZero: true }
                    }
                }
            });

            const ctxSpectrum = document.getElementById('spectrumChart').getContext('2d');
            spectrumChart = new Chart(ctxSpectrum, {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Amplitude (dB)',
                        data: [],
                        backgroundColor: 'rgba(54, 162, 235, 0.6)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 300 },
                    scales: {
                        x: { display: true, title: { display: true, text: 'Frequenz (Hz)' } },
                        y: { display: true, title: { display: true, text: 'dB' } }
                    }
                }
            });
        }

        async function clearDatabase() {
            if (!confirm('Wirklich ALLE Daten aus der Datenbank löschen? Dies kann nicht rückgängig gemacht werden!')) return;
            try {
                const response = await fetch('?api=clear_database', { method: 'POST' });
                const data = await response.json();
                if (data.error) {
                    alert('Fehler: ' + data.error);
                } else {
                    alert(data.deleted + ' Einträge gelöscht.');
                    loadStats();
                    loadData();
                }
            } catch (e) {
                alert('Fehler: ' + e.message);
            }
        }

        function setFilter(label) {
            currentFilter = label;
            document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
            if (label === null) {
                document.querySelector('.filter-btn.all').classList.add('active');
            } else {
                document.querySelector(`.filter-btn.${label}`).classList.add('active');
            }
            loadData();
        }

        async function loadStats() {
            try {
                const response = await fetch('?api=stats');
                const stats = await response.json();
                document.getElementById('statTotal').textContent = stats.total || 0;
                document.getElementById('statGut').textContent = stats.gut || 0;
                document.getElementById('statSchlecht').textContent = stats.schlecht || 0;
            } catch (error) {
                console.error('Stats error:', error);
            }
        }

        async function loadData() {
            try {
                let url = '?api=data';
                if (currentFilter) {
                    url += '&label=' + currentFilter;
                }
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.error) {
                    document.getElementById('status').className = 'status error';
                    document.getElementById('status').innerHTML = 'Fehler: ' + data.error;
                    return;
                }

                // Tabelle aktualisieren (neueste oben)
                const tableBody = document.getElementById('dataTable');
                tableBody.innerHTML = data.slice().reverse().map(row => `
                    <tr>
                        <td>${row.id}</td>
                        <td>${row.ts}</td>
                        <td><span class="label-${row.label}">${row.label}</span></td>
                        <td>${parseFloat(row.peak_freq).toFixed(1)}</td>
                        <td>${parseFloat(row.peak_db).toFixed(1)}</td>
                        <td>${row.sample_rate}</td>
                    </tr>
                `).join('');

                // Peak-Frequenz Chart aktualisieren
                const timestamps = data.map(r => r.ts.split(' ')[1]);
                const peakFreqs = data.map(r => parseFloat(r.peak_freq) || 0);

                freqChart.data.labels = timestamps;
                freqChart.data.datasets[0].data = peakFreqs;
                freqChart.update('none');

                // Spektrum des neuesten Eintrags anzeigen
                if (data.length > 0) {
                    const latest = data[data.length - 1];
                    const spectrum = latest.spectrum || [];
                    const sampleRate = latest.sample_rate || 16000;
                    const maxFreq = sampleRate / 2;
                    const freqLabels = spectrum.map((_, i) => Math.round(i * maxFreq / spectrum.length));
                    
                    spectrumChart.data.labels = freqLabels;
                    spectrumChart.data.datasets[0].data = spectrum;
                    spectrumChart.update('none');
                }

                document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString('de-DE');
                document.getElementById('status').className = 'status';
                
            } catch (error) {
                document.getElementById('status').className = 'status error';
                document.getElementById('status').innerHTML = 'Verbindungsfehler: ' + error.message;
            }
        }

        initCharts();
        loadStats();
        loadData();
        loadDevices();  // Geräte initial laden
        loadNetworkStatus();  // Netzwerk-Status laden
        
        setInterval(() => {
            loadStats();
            loadData();
            if (currentTab === 'geraete') {
                loadDevices();  // Geräte nur aktualisieren wenn Tab aktiv
                loadNetworkStatus();  // Netzwerk-Status aktualisieren
            }
        }, 2000);
    </script>
</body>
</html>