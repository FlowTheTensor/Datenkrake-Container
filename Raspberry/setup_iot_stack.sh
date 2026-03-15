#!/bin/bash
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "Please run this script with sudo." >&2
  exit 1
fi

TARGET_USER=${SUDO_USER:-root}
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_DIR="${SCRIPT_DIR}/compose"
NEED_RELOGIN=0

log() {
  echo "[setup] $1"
}

install_docker() {
  if ! command -v docker >/dev/null 2>&1; then
    log "Installing Docker engine via get.docker.com convenience script"
    curl -fsSL https://get.docker.com | sh
  else
    log "Docker already installed"
  fi

  if ! docker compose version >/dev/null 2>&1; then
    log "Installing docker compose plugin"
    apt-get update
    apt-get install -y docker-compose-plugin
  else
    log "Docker Compose plugin already installed"
  fi

  if [[ ${TARGET_USER} != "root" ]] && ! id -nG "${TARGET_USER}" | grep -qw docker; then
    log "Adding ${TARGET_USER} to docker group"
    usermod -aG docker "${TARGET_USER}"
    NEED_RELOGIN=1
  fi
}

prepare_directories() {
  log "Ensuring local volume directories exist"
  mkdir -p "${SCRIPT_DIR}/mosquitto/data"
  mkdir -p "${SCRIPT_DIR}/mosquitto/log"
  mkdir -p "${SCRIPT_DIR}/mosquitto/config"
  mkdir -p "${SCRIPT_DIR}/mariadb/data"
  mkdir -p "${SCRIPT_DIR}/mariadb/init"

  if [[ ! -f "${SCRIPT_DIR}/mosquitto/config/mosquitto.conf" ]] && [[ -f "${SCRIPT_DIR}/mosquitto/config/mosquitto.conf.example" ]]; then
    cp "${SCRIPT_DIR}/mosquitto/config/mosquitto.conf.example" "${SCRIPT_DIR}/mosquitto/config/mosquitto.conf"
  fi

  chown -R "${TARGET_USER}:${TARGET_USER}" "${SCRIPT_DIR}/mosquitto"
  chown -R "${TARGET_USER}:${TARGET_USER}" "${SCRIPT_DIR}/mariadb"
}

build_and_start() {
  if [[ ! -d "${COMPOSE_DIR}" ]] || [[ ! -f "${COMPOSE_DIR}/docker-compose.yml" ]]; then
    log "Compose directory or file missing; aborting"
    exit 1
  fi

  log "Building container images"
  (cd "${COMPOSE_DIR}" && docker compose build)

  log "Starting services in detached mode"
  (cd "${COMPOSE_DIR}" && docker compose up -d)
}

post_install_notes() {
  echo
  echo "MQTT broker, database and web server are starting via docker compose."
  echo "Next steps:"
  echo "  - Verify containers with: (cd ${COMPOSE_DIR} && docker compose ps)"
  echo "  - Access the web interface at: http://localhost:8080"
  if [[ ${NEED_RELOGIN} -eq 1 ]]; then
    echo "  - Log out and back in so ${TARGET_USER} can use docker without sudo."
  fi
}

setup_systemd_service() {
  log "Setting up systemd service for automatic container startup"
  local service_file="/etc/systemd/system/iot-stack.service"
  local user_home
  user_home=$(eval echo "~${TARGET_USER}")

  cat > "${service_file}" << EOF
[Unit]
Description=IoT Stack (MQTT, DB, Web)
Requires=docker.service
After=docker.service

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=${user_home}/Datenkrake-Container/compose
ExecStart=/usr/bin/docker compose up -d
ExecStop=/usr/bin/docker compose down
TimeoutStartSec=300

[Install]
WantedBy=multi-user.target
EOF

  systemctl daemon-reload
  systemctl enable iot-stack.service
  log "Systemd service enabled for automatic startup"
}

setup_accesspoint() {
  local ap_script="${SCRIPT_DIR}/accesspoint/setup_accesspoint.sh"
  if [[ -f "${ap_script}" ]]; then
    log "Setting up WLAN Access Point (Datenkrake)"
    log "HINWEIS: Nach dem AP-Setup ist keine Internetverbindung mehr möglich!"
    chmod +x "${ap_script}"
    # --auto Flag für nicht-interaktive Installation
    bash "${ap_script}" --auto
  else
    log "Access Point setup script not found, skipping"
  fi
}

# =============================================================================
# WICHTIG: Reihenfolge beachten!
# 1. Erst alles was Internet braucht (Docker, Images)
# 2. DANN erst Access Point (kappt Internet)
# =============================================================================

install_docker
prepare_directories
build_and_start
setup_systemd_service
# Access Point ZULETZT - danach kein Internet mehr!
setup_accesspoint
setup_wayvnc_autostart
post_install_notes

# wayvnc Autostart für Desktop-User einrichten
setup_wayvnc_autostart() {
  local user_home
  user_home=$(eval echo "~${TARGET_USER}")
  mkdir -p "${user_home}/.config/autostart"
  cat > "${user_home}/.config/autostart/wayvnc.desktop" << EOF
[Desktop Entry]
Type=Application
Name=wayvnc
Comment=VNC Server for Wayland
Exec=wayvnc 0.0.0.0 5900
Hidden=false
NoDisplay=false
X-GNOME-Autostart-enabled=true
EOF
  chown -R "${TARGET_USER}:${TARGET_USER}" "${user_home}/.config/autostart"
  # User-Lingering aktivieren (damit Autostart auch ohne Login funktioniert)
  loginctl enable-linger "${TARGET_USER}" 2>/dev/null || true
  log "wayvnc Autostart für ${TARGET_USER} eingerichtet."
}