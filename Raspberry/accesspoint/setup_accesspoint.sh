#!/bin/bash
# =============================================================================
# Datenkrake Access Point Setup
# Erstellt ein lokales WLAN-Netzwerk (10.0.0.0/24) ohne Internet
# =============================================================================
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Bitte mit sudo ausführen: sudo ./setup_accesspoint.sh" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WLAN_INTERFACE="wlan0"
AP_IP="10.0.0.1"
AP_NETMASK="255.255.255.0"
DHCP_RANGE_START="10.0.0.10"
DHCP_RANGE_END="10.0.0.254"
AP_SSID="Datenkrake"
AP_PASSWORD="datenkrake2024"  # Mindestens 8 Zeichen
AP_CHANNEL="7"

log() {
  echo "[AP-Setup] $1"
}

# System aktualisieren und Pakete installieren
install_packages() {
  log "Installiere benötigte Pakete..."
  apt-get update
  apt-get install -y hostapd dnsmasq iptables realvnc-vnc-server
  
  # Dienste stoppen für Konfiguration
  systemctl stop hostapd 2>/dev/null || true
  systemctl stop dnsmasq 2>/dev/null || true
}

# VNC Server einrichten
configure_vnc() {
  log "Konfiguriere VNC Server..."
  
  # VNC Server aktivieren
  systemctl enable vncserver-x11-serviced 2>/dev/null || true
  systemctl start vncserver-x11-serviced 2>/dev/null || true
  
  # Falls RealVNC nicht verfügbar, TigerVNC als Fallback
  if ! systemctl is-active --quiet vncserver-x11-serviced; then
    log "RealVNC nicht verfügbar, installiere TigerVNC..."
    apt-get install -y tigervnc-standalone-server tigervnc-common
    
    # VNC Passwort setzen (interaktiv oder Standard)
    mkdir -p /home/${SUDO_USER:-pi}/.vnc
    echo "datenkrake" | vncpasswd -f > /home/${SUDO_USER:-pi}/.vnc/passwd 2>/dev/null || true
    chmod 600 /home/${SUDO_USER:-pi}/.vnc/passwd 2>/dev/null || true
    chown -R ${SUDO_USER:-pi}:${SUDO_USER:-pi} /home/${SUDO_USER:-pi}/.vnc 2>/dev/null || true
  fi
  
  log "VNC Server eingerichtet"
}

# Netzwerk-Interface konfigurieren
configure_interface() {
  log "Konfiguriere WLAN-Interface ${WLAN_INTERFACE}..."
  
  # Deaktiviere wpa_supplicant für wlan0 (kein Client-Modus)
  systemctl stop wpa_supplicant 2>/dev/null || true
  
  # Statische IP für wlan0 in dhcpcd.conf
  if ! grep -q "interface ${WLAN_INTERFACE}" /etc/dhcpcd.conf; then
    cat >> /etc/dhcpcd.conf << EOF

# Datenkrake Access Point Konfiguration
interface ${WLAN_INTERFACE}
    static ip_address=${AP_IP}/24
    nohook wpa_supplicant
EOF
  fi
  
  # Interface sofort konfigurieren
  ip addr flush dev ${WLAN_INTERFACE} 2>/dev/null || true
  ip addr add ${AP_IP}/24 dev ${WLAN_INTERFACE} 2>/dev/null || true
  ip link set ${WLAN_INTERFACE} up
}

# hostapd konfigurieren (WLAN Access Point)
configure_hostapd() {
  log "Konfiguriere hostapd..."
  
  # Backup der Original-Konfiguration
  [[ -f /etc/hostapd/hostapd.conf ]] && mv /etc/hostapd/hostapd.conf /etc/hostapd/hostapd.conf.bak
  
  cp "${SCRIPT_DIR}/hostapd.conf" /etc/hostapd/hostapd.conf
  
  # hostapd aktivieren
  sed -i 's/#DAEMON_CONF=""/DAEMON_CONF="\/etc\/hostapd\/hostapd.conf"/' /etc/default/hostapd 2>/dev/null || true
  echo 'DAEMON_CONF="/etc/hostapd/hostapd.conf"' > /etc/default/hostapd
  
  systemctl unmask hostapd
  systemctl enable hostapd
}

# dnsmasq konfigurieren (DHCP + DNS)
configure_dnsmasq() {
  log "Konfiguriere dnsmasq..."
  
  # Backup der Original-Konfiguration
  [[ -f /etc/dnsmasq.conf ]] && mv /etc/dnsmasq.conf /etc/dnsmasq.conf.bak
  
  cp "${SCRIPT_DIR}/dnsmasq.conf" /etc/dnsmasq.conf
  
  # Statische Hosts-Datei für lokale DNS-Namen
  cp "${SCRIPT_DIR}/static-hosts.conf" /etc/dnsmasq.d/static-hosts.conf 2>/dev/null || true
  
  systemctl enable dnsmasq
}

# IP-Forwarding deaktivieren (kein Internet-Routing)
disable_forwarding() {
  log "Deaktiviere IP-Forwarding (kein Internet)..."
  
  # Permanent deaktivieren
  sed -i 's/net.ipv4.ip_forward=1/net.ipv4.ip_forward=0/' /etc/sysctl.conf 2>/dev/null || true
  
  # Sofort deaktivieren
  sysctl -w net.ipv4.ip_forward=0
}

# Systemd-Service für Access Point
create_service() {
  log "Erstelle Systemd-Service..."
  
  cat > /etc/systemd/system/datenkrake-ap.service << EOF
[Unit]
Description=Datenkrake Access Point
After=network.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/sbin/ip addr add ${AP_IP}/24 dev ${WLAN_INTERFACE} || true
ExecStart=/sbin/ip link set ${WLAN_INTERFACE} up

[Install]
WantedBy=multi-user.target
EOF
  
  systemctl daemon-reload
  systemctl enable datenkrake-ap.service
}

# PHP-Helper-Skript für Webinterface (liest DHCP-Leases)
create_php_helper() {
  log "Erstelle PHP-Helper-Skripte..."
  
  # Verzeichnis für Helper-Skripte
  mkdir -p /var/www/html/api
  
  # Skript zum Auslesen der DHCP-Leases
  cat > /var/www/html/api/devices.php << 'PHPEOF'
<?php
header('Content-Type: application/json');

$devices = [];

// DHCP-Leases von dnsmasq lesen
$leases_file = '/var/lib/misc/dnsmasq.leases';
if (file_exists($leases_file)) {
    $lines = file($leases_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', $line);
        if (count($parts) >= 5) {
            $devices[] = [
                'expiry' => date('Y-m-d H:i:s', (int)$parts[0]),
                'mac' => strtoupper($parts[1]),
                'ip' => $parts[2],
                'hostname' => $parts[3] !== '*' ? $parts[3] : '',
                'client_id' => $parts[4] ?? ''
            ];
        }
    }
}

// Aktive ARP-Einträge hinzufügen (für Geräte ohne DHCP-Lease)
$arp_output = shell_exec('arp -a 2>/dev/null') ?? '';
preg_match_all('/\((\d+\.\d+\.\d+\.\d+)\) at ([0-9a-f:]+)/i', $arp_output, $matches, PREG_SET_ORDER);

foreach ($matches as $match) {
    $ip = $match[1];
    $mac = strtoupper($match[2]);
    
    // Prüfen ob IP bereits in Devices-Liste
    $found = false;
    foreach ($devices as $d) {
        if ($d['ip'] === $ip) {
            $found = true;
            break;
        }
    }
    
    if (!$found && strpos($ip, '10.0.0.') === 0) {
        $devices[] = [
            'expiry' => 'ARP-Cache',
            'mac' => $mac,
            'ip' => $ip,
            'hostname' => '',
            'client_id' => ''
        ];
    }
}

// Ping-Status prüfen
foreach ($devices as &$device) {
    $ping = shell_exec("ping -c 1 -W 1 " . escapeshellarg($device['ip']) . " 2>/dev/null");
    $device['online'] = (strpos($ping, '1 received') !== false || strpos($ping, '1 packets received') !== false);
}

// Nach IP sortieren
usort($devices, function($a, $b) {
    return ip2long($a['ip']) - ip2long($b['ip']);
});

echo json_encode($devices);
PHPEOF

  chmod 644 /var/www/html/api/devices.php
  log "PHP-Helper erstellt: /var/www/html/api/devices.php"
}

# Modus-Wechsel-Skript installieren
install_mode_switcher() {
  log "Installiere Modus-Wechsel-Skript..."
  
  mkdir -p /opt/datenkrake
  cp "${SCRIPT_DIR}/switch_mode.sh" /opt/datenkrake/switch_mode.sh
  chmod +x /opt/datenkrake/switch_mode.sh
  
  # Status-Datei erstellen
  echo "ap" > /opt/datenkrake/current_mode
  chmod 666 /opt/datenkrake/current_mode
  
  # sudoers Eintrag für www-data (PHP kann Skript ausführen)
  echo "www-data ALL=(ALL) NOPASSWD: /opt/datenkrake/switch_mode.sh" > /etc/sudoers.d/datenkrake
  chmod 440 /etc/sudoers.d/datenkrake
  
  log "Modus-Wechsel installiert: /opt/datenkrake/switch_mode.sh"
}

# Dienste starten
start_services() {
  log "Starte Dienste..."
  
  systemctl restart dhcpcd
  sleep 2
  systemctl restart dnsmasq
  sleep 1
  systemctl restart hostapd
  
  log "Access Point gestartet!"
}

# Hauptprogramm
main() {
  echo "============================================"
  echo "  Datenkrake Access Point Setup"
  echo "============================================"
  echo "  SSID:     ${AP_SSID}"
  echo "  Passwort: ${AP_PASSWORD}"
  echo "  Netzwerk: 10.0.0.0/24"
  echo "  Pi IP:    ${AP_IP}"
  echo "============================================"
  echo ""
  
  read -p "Fortfahren? (j/n) " -n 1 -r
  echo ""
  if [[ ! $REPLY =~ ^[JjYy]$ ]]; then
    echo "Abgebrochen."
    exit 0
  fi
  
  install_packages
  configure_vnc
  configure_interface
  configure_hostapd
  configure_dnsmasq
  disable_forwarding
  create_service
  create_php_helper
  install_mode_switcher
  start_services
  
  echo ""
  echo "============================================"
  echo "  Setup abgeschlossen!"
  echo "============================================"
  echo ""
  echo "  WLAN-Name: ${AP_SSID}"
  echo "  Passwort:  ${AP_PASSWORD}"
  echo "  Pi-IP:     ${AP_IP}"
  echo ""
  echo "  Webinterface: http://${AP_IP}"
  echo "  VNC:          ${AP_IP}:5900"
  echo ""
  echo "  Modus wechseln:"
  echo "    sudo /opt/datenkrake/switch_mode.sh client  # Internet/Pi-Connect"
  echo "    sudo /opt/datenkrake/switch_mode.sh ap      # Access Point"
  echo ""
  echo "  Status prüfen:"
  echo "    systemctl status hostapd"
  echo "    systemctl status dnsmasq"
  echo "    systemctl status vncserver-x11-serviced"
  echo ""
  echo "  Verbundene Geräte:"
  echo "    cat /var/lib/misc/dnsmasq.leases"
  echo ""
}

main "$@"
