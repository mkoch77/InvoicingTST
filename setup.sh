#!/usr/bin/env bash
# ==============================================================================
# InvoicingTST – Server-Setup-Skript
#
# Dieses Skript richtet die gesamte Anwendung auf einem neuen Ubuntu-Server ein:
#   - Installiert Docker + Docker Compose (falls nicht vorhanden)
#   - Erstellt Verzeichnisstruktur und setzt Berechtigungen
#   - Generiert Secrets (Postgres-Passwort, Vault-Key)
#   - Erstellt .env aus .env.example
#   - Baut und startet alle Container
#   - Legt den initialen Admin-Benutzer an
#
# Verwendung:
#   sudo bash setup.sh
#   sudo bash setup.sh --reset        # Alles zuruecksetzen (loescht DB!)
#   sudo bash setup.sh --update       # Git pull + Rebuild ohne Datenverlust
# ==============================================================================

set -euo pipefail

# --- Farben fuer Ausgabe ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

info()  { echo -e "${CYAN}[INFO]${NC}  $*"; }
ok()    { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $*"; }
err()   { echo -e "${RED}[ERROR]${NC} $*" >&2; }

# --- Root-Check ---
if [ "$(id -u)" -ne 0 ]; then
    err "Dieses Skript muss als root ausgefuehrt werden (sudo bash setup.sh)"
    exit 1
fi

# --- Arbeitsverzeichnis = Skript-Verzeichnis ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"
info "Arbeitsverzeichnis: $SCRIPT_DIR"

# --- Parameter ---
MODE="install"
if [ "${1:-}" = "--reset" ]; then
    MODE="reset"
elif [ "${1:-}" = "--update" ]; then
    MODE="update"
fi

# ==============================================================================
# 1. Docker installieren (falls nicht vorhanden)
# ==============================================================================
install_docker() {
    if command -v docker &>/dev/null; then
        ok "Docker ist bereits installiert: $(docker --version)"
        return
    fi

    info "Installiere Docker..."
    apt-get update -qq
    apt-get install -y -qq ca-certificates curl gnupg lsb-release >/dev/null

    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | \
        gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg

    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" > \
        /etc/apt/sources.list.d/docker.list

    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io \
        docker-buildx-plugin docker-compose-plugin >/dev/null

    systemctl enable --now docker
    ok "Docker installiert: $(docker --version)"
}

# ==============================================================================
# 2. Docker Compose pruefen
# ==============================================================================
check_compose() {
    if docker compose version &>/dev/null; then
        ok "Docker Compose: $(docker compose version --short)"
    else
        err "Docker Compose Plugin nicht gefunden."
        err "Bitte installieren: apt-get install docker-compose-plugin"
        exit 1
    fi
}

# ==============================================================================
# 3. Secrets generieren
# ==============================================================================
setup_secrets() {
    local SECRETS_DIR="$SCRIPT_DIR/secrets"
    mkdir -p "$SECRETS_DIR"

    if [ ! -f "$SECRETS_DIR/pg_password.txt" ]; then
        openssl rand -base64 32 > "$SECRETS_DIR/pg_password.txt"
        info "Postgres-Passwort generiert."
    else
        ok "Postgres-Passwort existiert bereits."
    fi

    if [ ! -f "$SECRETS_DIR/vault_key.txt" ]; then
        openssl rand -base64 32 > "$SECRETS_DIR/vault_key.txt"
        info "Vault-Key generiert."
    else
        ok "Vault-Key existiert bereits."
    fi

    # Berechtigungen: nur root kann lesen
    chmod 600 "$SECRETS_DIR"/*.txt
    chown root:root "$SECRETS_DIR"/*.txt
    ok "Secrets gesichert (600, root:root)"
}

# ==============================================================================
# 4. .env erstellen
# ==============================================================================
setup_env() {
    if [ ! -f "$SCRIPT_DIR/.env" ]; then
        if [ -f "$SCRIPT_DIR/.env.example" ]; then
            cp "$SCRIPT_DIR/.env.example" "$SCRIPT_DIR/.env"
            info ".env aus .env.example erstellt."
        else
            cat > "$SCRIPT_DIR/.env" <<'ENVEOF'
PG_USER=accounting
PG_DATABASE=InvoicingAssets
ENVEOF
            info ".env mit Standardwerten erstellt."
        fi
    else
        ok ".env existiert bereits."
    fi
}

# ==============================================================================
# 5. Verzeichnisse und Berechtigungen
# ==============================================================================
setup_directories() {
    # nginx/certs Verzeichnis fuer Self-Signed-Zertifikate
    mkdir -p "$SCRIPT_DIR/nginx/certs"

    # Alle Projektdateien lesbar fuer Docker-Build
    find "$SCRIPT_DIR" -type f -not -path '*/secrets/*' -not -path '*/.git/*' \
        -exec chmod a+r {} \;
    find "$SCRIPT_DIR" -type d -not -path '*/.git/*' \
        -exec chmod a+rx {} \;

    # Shell-Skripte ausfuehrbar
    chmod +x "$SCRIPT_DIR/nginx/docker-entrypoint.sh" 2>/dev/null || true
    chmod +x "$SCRIPT_DIR/nginx/generate-self-signed-cert.sh" 2>/dev/null || true

    ok "Verzeichnisse und Berechtigungen gesetzt."
}

# ==============================================================================
# 6. Container bauen und starten
# ==============================================================================
start_containers() {
    info "Baue und starte Container..."
    docker compose up -d --build

    # Warte bis Postgres bereit ist
    info "Warte auf PostgreSQL..."
    local retries=30
    while [ $retries -gt 0 ]; do
        if docker exec accounting-postgres pg_isready -U accounting &>/dev/null; then
            ok "PostgreSQL ist bereit."
            return
        fi
        retries=$((retries - 1))
        sleep 1
    done
    err "PostgreSQL nicht bereit nach 30 Sekunden."
    docker logs accounting-postgres --tail 20
    exit 1
}

# ==============================================================================
# 7. Secrets fuer www-data bereitstellen und DB-Passwort setzen
# ==============================================================================
setup_runtime_secrets() {
    info "Kopiere Secrets fuer Web-Container..."

    # Docker Secrets sind root:root 600 auf einem read-only tmpfs.
    # Apache (www-data) kann sie nicht lesen. Wir kopieren sie in
    # ein beschreibbares Verzeichnis mit www-data-Berechtigungen.
    docker exec accounting-web bash -c "
        mkdir -p /var/www/secrets
        cp /run/secrets/pg_password /var/www/secrets/pg_password
        cp /run/secrets/vault_key /var/www/secrets/vault_key
        chown -R www-data:www-data /var/www/secrets
        chmod 700 /var/www/secrets
        chmod 600 /var/www/secrets/*
    "
    ok "Secrets fuer www-data bereitgestellt."

    # Postgres-Passwort setzen (POSTGRES_PASSWORD_FILE wird nur bei
    # der allerersten Initialisierung gelesen, danach nicht mehr)
    info "Setze PostgreSQL-Passwort..."
    local pg_pass
    pg_pass=$(cat "$SCRIPT_DIR/secrets/pg_password.txt")
    docker exec accounting-postgres psql -U accounting -c \
        "ALTER USER accounting PASSWORD '${pg_pass}';" >/dev/null 2>&1 || true
    ok "PostgreSQL-Passwort gesetzt."
}

# ==============================================================================
# 8. Admin-Benutzer anlegen
# ==============================================================================
setup_admin() {
    # Pruefen ob bereits ein Admin existiert
    local admin_exists
    admin_exists=$(docker exec accounting-postgres psql -U accounting -d InvoicingAssets \
        -tAc "SELECT COUNT(*) FROM app_user WHERE role = 'admin'" 2>/dev/null || echo "0")

    if [ "$admin_exists" -gt 0 ] 2>/dev/null; then
        ok "Admin-Benutzer existiert bereits."
        return
    fi

    echo ""
    echo -e "${CYAN}=====================================${NC}"
    echo -e "${CYAN}  Admin-Benutzer anlegen${NC}"
    echo -e "${CYAN}=====================================${NC}"

    local admin_user admin_pass admin_pass2

    read -rp "Admin-Benutzername [admin]: " admin_user
    admin_user="${admin_user:-admin}"

    while true; do
        read -rsp "Admin-Passwort: " admin_pass
        echo
        read -rsp "Passwort wiederholen: " admin_pass2
        echo
        if [ "$admin_pass" = "$admin_pass2" ] && [ -n "$admin_pass" ]; then
            break
        fi
        warn "Passwoerter stimmen nicht ueberein oder sind leer. Nochmal."
    done

    # Passwort-Hash via PHP im Web-Container erzeugen
    local hash
    hash=$(docker exec accounting-web php -r "echo password_hash('$admin_pass', PASSWORD_BCRYPT);")

    docker exec accounting-postgres psql -U accounting -d InvoicingAssets -c \
        "INSERT INTO app_user (username, password_hash, display_name, role)
         VALUES ('$admin_user', '$hash', 'Administrator', 'admin')
         ON CONFLICT (username) DO NOTHING;"

    ok "Admin-Benutzer '$admin_user' angelegt."
}

# ==============================================================================
# 8. Jira Assets einrichten (optional)
# ==============================================================================
setup_jira() {
    echo ""
    read -rp "Jira Assets einrichten? (j/n) [n]: " do_jira
    if [ "${do_jira:-n}" != "j" ]; then
        info "Jira-Setup uebersprungen. Spaeter ausfuehren mit: bash scripts/setup-jira.sh"
        return
    fi

    if [ -f "$SCRIPT_DIR/scripts/setup-jira.sh" ]; then
        bash "$SCRIPT_DIR/scripts/setup-jira.sh"
    else
        err "scripts/setup-jira.sh nicht gefunden."
    fi
}

# ==============================================================================
# 9. Microsoft Entra ID einrichten (optional)
# ==============================================================================
setup_entra() {
    echo ""
    read -rp "Microsoft Entra ID (Lizenz-Sync) einrichten? (j/n) [n]: " do_entra
    if [ "${do_entra:-n}" != "j" ]; then
        info "Entra-Setup uebersprungen. Spaeter im Vault konfigurieren."
        return
    fi

    echo ""
    info "Microsoft Entra ID Konfiguration"
    info "Benoetigt: App Registration mit Application Permissions:"
    info "  User.Read.All, Directory.Read.All"
    echo ""

    read -rp "Tenant ID: " ENTRA_TENANT
    read -rp "Client ID: " ENTRA_CLIENT
    read -rsp "Client Secret: " ENTRA_SECRET
    echo ""

    if [ -z "$ENTRA_TENANT" ] || [ -z "$ENTRA_CLIENT" ] || [ -z "$ENTRA_SECRET" ]; then
        warn "Unvollstaendige Eingabe. Entra-Setup uebersprungen."
        return
    fi

    # Login am Accounting-System
    local cookie
    cookie=$(mktemp)
    curl -sk -c "$cookie" -X POST "https://localhost/api/auth/login.php" \
        -H "Content-Type: application/json" \
        -d '{"username":"admin","password":"admin"}' >/dev/null 2>&1

    # Vault-Eintraege speichern
    for entry in "entra_tenant_id:$ENTRA_TENANT:Microsoft Entra ID Tenant ID" \
                 "entra_client_id:$ENTRA_CLIENT:Microsoft Entra ID App Client ID" \
                 "entra_client_secret:$ENTRA_SECRET:Microsoft Entra ID App Client Secret"; do
        IFS=':' read -r key val desc <<< "$entry"
        local payload
        payload=$(python3 -c "
import json, sys
print(json.dumps({'key_name': sys.argv[1], 'value': sys.argv[2], 'description': sys.argv[3]}))" \
            "$key" "$val" "$desc")
        curl -sk -b "$cookie" -X POST "https://localhost/api/vault.php" \
            -H "Content-Type: application/json" -d "$payload" >/dev/null 2>&1
    done

    rm -f "$cookie"
    ok "Entra ID Credentials im Vault gespeichert."

    # Verbindung testen
    info "Teste Entra-Verbindung..."
    local test_cookie
    test_cookie=$(mktemp)
    curl -sk -c "$test_cookie" -X POST "https://localhost/api/auth/login.php" \
        -H "Content-Type: application/json" \
        -d '{"username":"admin","password":"admin"}' >/dev/null 2>&1

    local sync_result
    sync_result=$(curl -sk -b "$test_cookie" -X POST "https://localhost/api/licenses.php" \
        -H "Content-Type: application/json" \
        -d '{"action":"sync"}' 2>/dev/null)
    rm -f "$test_cookie"

    if echo "$sync_result" | grep -q '"message"'; then
        local msg
        msg=$(echo "$sync_result" | python3 -c "import sys,json; print(json.load(sys.stdin).get('message',''))" 2>/dev/null)
        ok "Entra Sync erfolgreich: $msg"
    else
        warn "Entra Sync fehlgeschlagen. Bitte Credentials pruefen."
        echo "$sync_result" | python3 -m json.tool 2>/dev/null || echo "$sync_result"
    fi
}

# ==============================================================================
# 10. Status-Uebersicht
# ==============================================================================
show_status() {
    echo ""
    echo -e "${GREEN}=====================================${NC}"
    echo -e "${GREEN}  Setup abgeschlossen!${NC}"
    echo -e "${GREEN}=====================================${NC}"
    echo ""

    local pg_pass
    pg_pass=$(cat "$SCRIPT_DIR/secrets/pg_password.txt")
    local server_ip
    server_ip=$(hostname -I | awk '{print $1}')

    echo -e "  ${CYAN}Web-Oberflaeche:${NC}  https://${server_ip}"
    echo -e "  ${CYAN}                  ${NC}  (Self-Signed-Zertifikat — Browserwarnung ist normal)"
    echo ""
    echo -e "  ${CYAN}PostgreSQL:${NC}"
    echo -e "    Host:           ${server_ip}:5432"
    echo -e "    Datenbank:      InvoicingAssets"
    echo -e "    Benutzer:       accounting"
    echo -e "    Passwort:       ${pg_pass}"
    echo ""
    echo -e "  ${CYAN}Naechste Schritte:${NC}"
    echo -e "    1. Im Browser https://${server_ip} oeffnen und anmelden"
    echo -e "    2. Auf dem Windows-Rechner das Export-Skript konfigurieren:"
    echo -e "       ${YELLOW}Export-VM.ps1 -SavePgCredential${NC}"
    echo -e "       (Benutzer: accounting, Passwort: siehe oben)"
    echo -e "    3. Export ausfuehren:"
    echo -e "       ${YELLOW}Export-VM.ps1 -VeeamOneServer <server> -OutputTarget Postgres -PgHost ${server_ip}${NC}"
    echo ""
    echo -e "  ${CYAN}Jira Assets (optional):${NC}"
    echo -e "    ${YELLOW}bash scripts/setup-jira.sh${NC}"
    echo -e "    (OAuth App unter https://developer.atlassian.com/console/myapps/)"
    echo -e "    Callback-URL: http://localhost:19876/callback"
    echo ""
    echo -e "  ${CYAN}Verwaltung:${NC}"
    echo -e "    Neustart:       docker compose restart"
    echo -e "    Logs:           docker compose logs -f"
    echo -e "    Update:         sudo bash setup.sh --update"
    echo -e "    Zuruecksetzen:  sudo bash setup.sh --reset  ${RED}(loescht alle Daten!)${NC}"
    echo ""
}

# ==============================================================================
# Hauptprogramm
# ==============================================================================
echo ""
echo -e "${CYAN}=====================================${NC}"
echo -e "${CYAN}  InvoicingTST Setup${NC}"
echo -e "${CYAN}=====================================${NC}"
echo ""

case "$MODE" in
    reset)
        warn "ACHTUNG: Alle Daten werden geloescht!"
        read -rp "Wirklich zuruecksetzen? (ja/nein): " confirm
        if [ "$confirm" != "ja" ]; then
            info "Abgebrochen."
            exit 0
        fi
        info "Stoppe und loesche Container + Volumes..."
        docker compose down -v 2>/dev/null || true
        rm -rf "$SCRIPT_DIR/secrets"
        rm -rf "$SCRIPT_DIR/nginx/certs"
        ok "Alles zurueckgesetzt."
        info "Fuehre Setup neu aus..."
        setup_secrets
        setup_env
        setup_directories
        start_containers
        setup_runtime_secrets
        setup_admin
        setup_jira
        setup_entra
        show_status
        ;;

    update)
        info "Update: Git Pull + Rebuild..."
        git pull || warn "Git pull fehlgeschlagen — fahre mit lokalem Stand fort."
        setup_directories
        docker compose up -d --build
        # Warte auf Postgres
        info "Warte auf PostgreSQL..."
        retries=30
        while [ $retries -gt 0 ]; do
            if docker exec accounting-postgres pg_isready -U accounting &>/dev/null; then
                break
            fi
            retries=$((retries - 1))
            sleep 1
        done
        setup_runtime_secrets
        ok "Update abgeschlossen."
        docker compose ps
        ;;

    install)
        install_docker
        check_compose
        setup_secrets
        setup_env
        setup_directories
        start_containers
        setup_runtime_secrets
        setup_admin
        setup_jira
        show_status
        ;;
esac
