#!/usr/bin/env bash
# ==============================================================================
# Jira Assets Setup (Service Account Scoped API-Token)
#
# Richtet die Jira-Anbindung ein:
#   1. Fragt API-Token und Workspace-ID ab
#   2. Speichert beides im Vault
#   3. Testet die Assets-Verbindung
#
# Voraussetzungen:
#   - Container laufen (docker compose up -d)
#   - Admin-Benutzer existiert
#   - Atlassian Service Account mit Scoped API-Token
#     (admin.atlassian.com > Servicekonten > Anmeldedaten > API-Token)
#     Benoetigte Scopes: read:cmdb-object:jira, read:cmdb-schema:jira,
#                        read:cmdb-type:jira, read:cmdb-attribute:jira
#
# Verwendung:
#   bash scripts/setup-jira.sh
# ==============================================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

info()  { echo -e "${CYAN}[INFO]${NC}  $*"; }
ok()    { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $*"; }
err()   { echo -e "${RED}[ERROR]${NC} $*" >&2; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_DIR"

COOKIE_FILE=$(mktemp)
BASE_URL="https://localhost"

cleanup() { rm -f "$COOKIE_FILE"; }
trap cleanup EXIT

# ==============================================================================
# Hilfsfunktionen
# ==============================================================================

login() {
    echo ""
    echo -e "${CYAN}=====================================${NC}"
    echo -e "${CYAN}  Jira Assets Setup${NC}"
    echo -e "${CYAN}=====================================${NC}"
    echo ""

    info "Anmeldung am Accounting-System..."
    local user pass

    read -rp "Admin-Benutzername [admin]: " user
    user="${user:-admin}"
    read -rsp "Passwort: " pass
    echo ""

    local response
    response=$(curl -sk -c "$COOKIE_FILE" -X POST "$BASE_URL/api/auth/login.php" \
        -H "Content-Type: application/json" \
        -d "{\"username\": \"$user\", \"password\": \"$pass\"}" 2>/dev/null)

    if echo "$response" | grep -q '"error"'; then
        err "Login fehlgeschlagen: $response"
        exit 1
    fi

    ok "Angemeldet als $user"
}

vault_set() {
    local key="$1" value="$2" description="${3:-}"

    local payload
    payload=$(python3 -c "
import json, sys
print(json.dumps({
    'key_name': sys.argv[1],
    'value': sys.argv[2],
    'description': sys.argv[3]
}))" "$key" "$value" "$description")

    local response
    response=$(curl -sk -b "$COOKIE_FILE" -X POST "$BASE_URL/api/vault.php" \
        -H "Content-Type: application/json" \
        -d "$payload" 2>/dev/null)

    if echo "$response" | grep -q '"error"'; then
        err "Vault-Eintrag '$key' fehlgeschlagen: $response"
        return 1
    fi
    ok "Vault: $key gespeichert"
}

# ==============================================================================
# 1. Login
# ==============================================================================
login

# ==============================================================================
# 2. Konfiguration abfragen
# ==============================================================================
echo ""
info "Jira Assets Konfiguration"
info ""
info "Voraussetzungen:"
info "  1. Service Account unter admin.atlassian.com > Servicekonten"
info "  2. API-Token erstellen mit Scopes:"
info "     read:cmdb-object:jira, read:cmdb-schema:jira,"
info "     read:cmdb-type:jira, read:cmdb-attribute:jira"
info ""
info "  3. Workspace-ID ermitteln:"
info "     Jira > Assets > F12 > Netzwerk > nach 'workspace' filtern"
echo ""

read -rsp "API-Token (Service Account Scoped Token): " API_TOKEN
echo ""
read -rp "Workspace-ID: " WORKSPACE_ID

if [ -z "$API_TOKEN" ] || [ -z "$WORKSPACE_ID" ]; then
    err "API-Token und Workspace-ID sind erforderlich."
    exit 1
fi

# ==============================================================================
# 3. Verbindung testen
# ==============================================================================
echo ""
info "Teste Assets-Verbindung..."

TEST_URL="https://api.atlassian.com/jsm/assets/workspace/${WORKSPACE_ID}/v1/objectschema/list"
TEST_RESPONSE=$(curl -s -w "\n%{http_code}" \
    -H "Authorization: Bearer ${API_TOKEN}" \
    -H "Accept: application/json" \
    "$TEST_URL")

HTTP_CODE=$(echo "$TEST_RESPONSE" | tail -1)
BODY=$(echo "$TEST_RESPONSE" | head -n -1)

if [ "$HTTP_CODE" = "200" ]; then
    SCHEMA_COUNT=$(echo "$BODY" | python3 -c "
import sys, json
d = json.load(sys.stdin)
schemas = d.get('objectschemas', d.get('values', []))
print(len(schemas))
for s in schemas:
    name = s.get('name', '?')
    count = s.get('objectCount', '?')
    print(f'  - {name} ({count} Objekte)')
" 2>/dev/null || echo "?")
    ok "Assets-Verbindung erfolgreich!"
    echo "$SCHEMA_COUNT"
else
    err "Verbindung fehlgeschlagen (HTTP $HTTP_CODE)"
    echo "$BODY" | python3 -m json.tool 2>/dev/null || echo "$BODY"
    echo ""
    err "Moegliche Ursachen:"
    err "  - API-Token falsch oder abgelaufen"
    err "  - Workspace-ID falsch"
    err "  - cmdb-Scopes fehlen auf dem Token"
    exit 1
fi

# ==============================================================================
# 4. Im Vault speichern
# ==============================================================================
echo ""
vault_set "jira_api_token" "$API_TOKEN" "Service Account Scoped API-Token (Bearer)"
vault_set "jira_workspace_id" "$WORKSPACE_ID" "Assets Workspace-ID"

# ==============================================================================
# Fertig
# ==============================================================================
echo ""
echo -e "${GREEN}=====================================${NC}"
echo -e "${GREEN}  Jira Assets Setup abgeschlossen!${NC}"
echo -e "${GREEN}=====================================${NC}"
echo ""
echo -e "  ${CYAN}Workspace:${NC}  $WORKSPACE_ID"
echo -e "  ${CYAN}Auth:${NC}       Scoped API-Token (Bearer)"
echo -e "  ${CYAN}API-Pfad:${NC}   api.atlassian.com/jsm/assets/workspace/..."
echo ""
echo -e "  Container neu bauen:"
echo -e "  ${YELLOW}docker compose up -d --build${NC}"
echo ""
