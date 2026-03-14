#!/bin/bash
# =============================================================================
# Datenkrake Netzwerk-Modus Umschalter
# Wechselt zwischen Access Point und WLAN-Client Modus
# =============================================================================
set -euo pipefail

MODE_FILE="/opt/datenkrake/current_mode"
WLAN_INTERFACE="wlan0"

# WLAN-Konfiguration für Client-Modus (Internet)
# Diese Werte müssen angepasst werden!
CLIENT_SSID="${DATENKRAKE_CLIENT_SSID:-}"
CLIENT_PASSWORD="${DATENKRAKE_CLIENT_PASSWORD:-}"
WPA_CONF="/etc/wpa_supplicant/wpa_supplicant.conf"

log() {
    echo "[Mode-Switch] $1"
    logger -t datenkrake-mode "$1"
}

get_current_mode() {
    if [[ -f "$MODE_FILE" ]]; then
        cat "$MODE_FILE"
    else
        echo "unknown"
    fi
}

# Access Point Modus aktivieren
enable_ap_mode() {
    log "Aktiviere Access Point Modus..."
    
    # Client-Modus Dienste stoppen
    systemctl stop wpa_supplicant 2>/dev/null || true
    
    # DHCP für wlan0 deaktivieren (statische IP)
    ip addr flush dev ${WLAN_INTERFACE} 2>/dev/null || true
    ip addr add 10.0.0.1/24 dev ${WLAN_INTERFACE} 2>/dev/null || true
    ip link set ${WLAN_INTERFACE} up
    
    # AP-Dienste starten
    systemctl start dnsmasq 2>/dev/null || true
    systemctl start hostapd 2>/dev/null || true
    
    echo "ap" > "$MODE_FILE"
    log "Access Point Modus aktiv - SSID: Datenkrake, IP: 10.0.0.1"
}

# Client/Internet Modus aktivieren
enable_client_mode() {
    log "Aktiviere Client/Internet Modus..."
    
    # AP-Dienste stoppen
    systemctl stop hostapd 2>/dev/null || true
    systemctl stop dnsmasq 2>/dev/null || true
    
    # IP-Konfiguration zurücksetzen
    ip addr flush dev ${WLAN_INTERFACE} 2>/dev/null || true
    
    # wpa_supplicant Konfiguration prüfen
    if [[ ! -f "$WPA_CONF" ]] || ! grep -q "ssid=" "$WPA_CONF"; then
        log "FEHLER: Keine WLAN-Konfiguration in $WPA_CONF"
        log "Bitte erst WLAN konfigurieren: sudo raspi-config"
        echo "error" > "$MODE_FILE"
        exit 1
    fi
    
    # Client-Modus aktivieren
    systemctl start wpa_supplicant 2>/dev/null || true
    
    # DHCP für wlan0 aktivieren
    systemctl restart dhcpcd 2>/dev/null || true
    
    # Warten auf Verbindung
    log "Warte auf WLAN-Verbindung..."
    for i in {1..30}; do
        if ip addr show ${WLAN_INTERFACE} | grep -q "inet "; then
            IP=$(ip addr show ${WLAN_INTERFACE} | grep "inet " | awk '{print $2}' | cut -d'/' -f1)
            log "Verbunden! IP: $IP"
            echo "client" > "$MODE_FILE"
            exit 0
        fi
        sleep 1
    done
    
    log "WARNUNG: Keine IP erhalten, möglicherweise nicht verbunden"
    echo "client" > "$MODE_FILE"
}

# Status anzeigen
show_status() {
    local mode=$(get_current_mode)
    echo "{"
    echo "  \"mode\": \"$mode\","
    
    # IP-Adresse
    local ip=$(ip addr show ${WLAN_INTERFACE} 2>/dev/null | grep "inet " | awk '{print $2}' | cut -d'/' -f1 | head -1)
    echo "  \"ip\": \"${ip:-keine}\","
    
    # SSID (wenn verbunden)
    local ssid=$(iwgetid -r 2>/dev/null || echo "")
    echo "  \"ssid\": \"${ssid:-nicht verbunden}\","
    
    # Internet-Verbindung testen
    if ping -c 1 -W 2 8.8.8.8 >/dev/null 2>&1; then
        echo "  \"internet\": true,"
    else
        echo "  \"internet\": false,"
    fi
    
    # Dienste-Status
    local hostapd_status="inactive"
    local dnsmasq_status="inactive"
    local wpa_status="inactive"
    systemctl is-active hostapd >/dev/null 2>&1 && hostapd_status="active"
    systemctl is-active dnsmasq >/dev/null 2>&1 && dnsmasq_status="active"
    systemctl is-active wpa_supplicant >/dev/null 2>&1 && wpa_status="active"
    
    echo "  \"services\": {"
    echo "    \"hostapd\": \"$hostapd_status\","
    echo "    \"dnsmasq\": \"$dnsmasq_status\","
    echo "    \"wpa_supplicant\": \"$wpa_status\""
    echo "  }"
    echo "}"
}

# Bekannte WLANs auflisten
list_known_networks() {
    echo "["
    if [[ -f "$WPA_CONF" ]]; then
        grep -oP 'ssid="\K[^"]+' "$WPA_CONF" 2>/dev/null | while read ssid; do
            echo "  \"$ssid\","
        done | sed '$ s/,$//'
    fi
    echo "]"
}

# WLAN hinzufügen
add_network() {
    local ssid="$1"
    local password="$2"
    
    if [[ -z "$ssid" ]]; then
        log "FEHLER: SSID erforderlich"
        exit 1
    fi
    
    log "Füge WLAN hinzu: $ssid"
    
    # wpa_passphrase nutzen für sicheren Hash
    if [[ -n "$password" ]]; then
        wpa_passphrase "$ssid" "$password" >> "$WPA_CONF"
    else
        # Offenes Netzwerk
        cat >> "$WPA_CONF" << EOF

network={
    ssid="$ssid"
    key_mgmt=NONE
}
EOF
    fi
    
    log "WLAN '$ssid' hinzugefügt"
}

# Hauptprogramm
case "${1:-status}" in
    ap|access-point)
        enable_ap_mode
        ;;
    client|internet)
        enable_client_mode
        ;;
    status)
        show_status
        ;;
    list)
        list_known_networks
        ;;
    add)
        add_network "${2:-}" "${3:-}"
        ;;
    *)
        echo "Verwendung: $0 {ap|client|status|list|add <ssid> [password]}"
        echo ""
        echo "  ap      - Access Point Modus (SSID: Datenkrake)"
        echo "  client  - Client/Internet Modus (verbindet mit bekanntem WLAN)"
        echo "  status  - Zeigt aktuellen Status (JSON)"
        echo "  list    - Zeigt bekannte WLANs"
        echo "  add     - Fügt neues WLAN hinzu"
        exit 1
        ;;
esac
