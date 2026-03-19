# Accounting / Invoicing Platform

Plattform zur Erfassung und Abrechnung von IT-Ressourcen. Besteht aus PowerShell-Export-Skripten, einer PostgreSQL-Datenbank und einer PHP-Weboberfläche.

## Architektur

```
┌─────────────────┐     ┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│  PowerShell-     │────>│  PostgreSQL   │<────│  PHP-Web-    │────>│  Jira Assets │
│  Export-Skripte  │     │  (Docker)    │     │  oberfläche  │     │  (CMDB)      │
│  (Windows)       │     │  Port 5432   │     │  Apache      │     │  Cloud API   │
└─────────────────┘     └──────────────┘     └──────────────┘     └──────────────┘
                                               │
                                         nginx Reverse Proxy
                                         (SSL / Let's Encrypt)
```

| Komponente | Beschreibung |
|---|---|
| `scripts/Export-VM.ps1` | Exportiert VM-Daten aus VeeamOne nach Excel und/oder PostgreSQL |
| `web/` | PHP-Weboberfläche zur Anzeige, Filterung und Excel-Export der Daten |
| `nginx/` | Reverse Proxy mit SSL-Terminierung |
| `postgres/` | Datenbankschema (wird beim ersten Start automatisch angelegt) |
| `secrets/` | Docker Secrets (PG-Passwort, nicht im Git) |

## Sicherheitskonzept

| Geheimnis | Speicherort | Verschlüsselung |
|---|---|---|
| PostgreSQL-Passwort | `secrets/pg_password.txt` | Docker Secrets (Mount in `/run/secrets/`) |
| Vault-Passphrase | `.env` → `VAULT_KEY` | Wird nur zur Laufzeit gelesen |
| API-Tokens (Jira, etc.) | PostgreSQL `vault`-Tabelle | AES-256-GCM (verschlüsselt mit VAULT_KEY) |
| VeeamOne/PG-Credentials | Windows DPAPI `.cred`-Dateien | Benutzerkonto-gebunden |

**Kein Passwort oder Secret liegt im Plaintext auf dem Filesystem** (außer der VAULT_KEY in `.env`, die die Container-Runtime liest).

## Installation auf einem neuen Server

### Voraussetzungen

- **Docker** und **Docker Compose** (v2)
- **Git**
- **OpenSSL** (für selbstsigniertes Zertifikat)

### Docker installieren (Ubuntu 24.04)

```bash
# Alte Pakete entfernen
sudo apt remove docker docker-engine docker.io containerd runc

# Docker APT-Repository einrichten
sudo apt update
sudo apt install ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
  | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Docker installieren
sudo apt update
sudo apt install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Eigenen User zur docker-Gruppe hinzufuegen (kein sudo noetig)
sudo usermod -aG docker $USER
newgrp docker

# Testen
docker --version
docker compose version
```

### 1. Repository klonen

```bash
git clone <repo-url>
cd InvoicingTST
```

### 2. Secrets konfigurieren

```bash
# PostgreSQL-Passwort (Docker Secret)
mkdir -p secrets
echo "SICHERES_PASSWORT_HIER" > secrets/pg_password.txt
chmod 600 secrets/pg_password.txt

# .env-Datei anpassen
cp .env.example .env
nano .env
```

Die `.env`-Datei:

```
PG_USER=accounting
PG_DATABASE=InvoicingAssets
VAULT_KEY=HIER_STARKE_PASSPHRASE_SETZEN
```

| Variable | Beschreibung |
|---|---|
| `PG_USER` | PostgreSQL-Benutzername |
| `PG_DATABASE` | Datenbankname |
| `VAULT_KEY` | Passphrase für AES-256-GCM Verschlüsselung der Vault-Einträge |

**Wichtig:** `VAULT_KEY` sollte eine lange, zufällige Passphrase sein (z.B. `openssl rand -base64 32`). Wenn sie geändert wird, können bestehende Vault-Einträge nicht mehr entschlüsselt werden.

### 3. SSL-Zertifikat erstellen

**Option A: Selbstsigniertes Zertifikat (Testbetrieb)**

```bash
./nginx/generate-self-signed-cert.sh
```

**Option B: Let's Encrypt (Produktion)**

1. In `docker-compose.yml` den `certbot`-Service einkommentieren
2. `admin@example.com` und `example.com` durch eigene Domain/E-Mail ersetzen
3. In `nginx/nginx.conf` den `server_name _` durch die eigene Domain ersetzen
4. Zertifikate erstellen:

```bash
docker compose up -d nginx
docker compose run --rm certbot
docker compose restart nginx
```

Zertifikat-Renewal per Cronjob:

```bash
0 3 * * * cd /pfad/zu/InvoicingTST && docker compose run --rm certbot renew && docker compose restart nginx
```

### 4. Container starten

```bash
docker compose up -d --build
```

Beim ersten Start passiert automatisch:
- PostgreSQL-Datenbank und Schema werden angelegt
- Vault-Tabelle wird erstellt
- Web-App wird gebaut und gestartet
- nginx startet als Reverse Proxy

Die Weboberfläche ist dann erreichbar unter:
- **https://\<server-ip\>** (Port 443)
- HTTP auf Port 80 leitet automatisch auf HTTPS um

### 5. Prüfen ob alles läuft

```bash
docker compose ps
```

Alle drei Container sollten den Status `Up` haben:

| Container | Funktion |
|---|---|
| `accounting-postgres` | PostgreSQL-Datenbank |
| `accounting-web` | PHP-Weboberfläche (Apache, intern Port 80) |
| `accounting-nginx` | Reverse Proxy (Port 80/443) |

### 6. Jira Assets einrichten (optional)

Die CMDB-Anbindung wird über den Vault in der Weboberfläche konfiguriert (Admin > Vault). Folgende Secrets anlegen:

| Schlüssel | Beschreibung |
|---|---|
| `jira_base_url` | z.B. `https://yoursite.atlassian.net` |
| `jira_user_email` | Atlassian-Account E-Mail |
| `jira_api_token` | API-Token (https://id.atlassian.com/manage-profile/security/api-tokens) |
| `jira_workspace_id` | Assets Workspace-ID (GET `/rest/servicedeskapi/assets/workspace`) |

## Netzwerk-Freigaben

### Eingehend (zum Docker-Server)

| Port | Protokoll | Zweck | Zugriff |
|---|---|---|---|
| **443** | HTTPS | Weboberfläche | Browser |
| **80** | HTTP | Redirect auf HTTPS | Browser |
| **5432** | TCP | PostgreSQL | Nur PowerShell-Server |

### Ausgehend (vom Docker-Server, nur für Setup/Build)

| Ziel | Port | Zweck |
|---|---|---|
| `github.com` | 443 | Repository klonen |
| `registry-1.docker.io` / `production.cloudflare.docker.com` | 443 | Docker Images |
| `auth.docker.io` | 443 | Docker Hub Auth |
| `registry.npmjs.org` | 443 | npm-Pakete (Docker Build) |

### Ausgehend (vom Docker-Server, Laufzeit)

| Ziel | Port | Zweck |
|---|---|---|
| `api.atlassian.com` | 443 | Jira Assets API (falls konfiguriert) |

### Firewall-Freigaben für PowerShell-Module (Windows-Server)

| URL | Port | Zweck |
|---|---|---|
| `www.powershellgallery.com` | 443 | Modul-Metadaten (ImportExcel, SimplySql) |
| `psg-prod-eastus.azureedge.net` | 443 | Modul-Download (NuGet-Pakete) |
| `onegetcdn.azureedge.net` | 443 | NuGet Package Provider |
| `nuget.org` / `api.nuget.org` | 443 | NuGet Registry |

## Nützliche Befehle

```bash
# Container stoppen / starten
docker compose stop
docker compose start

# Neu bauen nach Code-Änderungen
docker compose up -d --build

# psql-Shell im Container öffnen
docker exec -it accounting-postgres psql -U accounting -d InvoicingAssets

# Vault-Schema nachträglich einspielen (bei bestehender DB)
docker exec -i accounting-postgres psql -U accounting -d InvoicingAssets < postgres/install-vault-schema.sql

# Datenbank-Backup
docker exec accounting-postgres pg_dump -U accounting InvoicingAssets > backup.sql

# Daten komplett zurücksetzen (Container und Volumes löschen!)
docker compose down -v
```

## Export-Skripte (PowerShell)

Die Export-Skripte laufen auf einem Windows-Server und schreiben Daten in die PostgreSQL-Datenbank.

### Voraussetzungen

- **PowerShell 7+** (`pwsh.exe`, nicht `powershell.exe`)
- **VeeamOne** mit aktivierter REST API (Standard-Port: 1239)

PowerShell-Module werden bei Bedarf automatisch installiert:

| Modul | Zweck |
|---|---|
| `ImportExcel` | Excel-Export |
| `SimplySql` | PostgreSQL-Anbindung |

### Credentials speichern (einmalig)

Credentials werden mit Windows DPAPI verschlüsselt und können nur vom selben Benutzer auf derselben Maschine entschlüsselt werden.

```powershell
# VeeamOne-Credentials
pwsh.exe -File .\scripts\Export-VM.ps1 -VeeamOneServer "veeamone.domain.local" -SaveCredential

# PostgreSQL-Credentials
pwsh.exe -File .\scripts\Export-VM.ps1 -VeeamOneServer "veeamone.domain.local" -SavePgCredential
```

### Export-Beispiele

```powershell
# Nur Excel
pwsh.exe -File .\scripts\Export-VM.ps1 -VeeamOneServer "veeamone.domain.local"

# Nur PostgreSQL
pwsh.exe -File .\scripts\Export-VM.ps1 -VeeamOneServer "veeamone.domain.local" -OutputTarget Postgres -PgHost "docker-host.domain.local"

# Beides
pwsh.exe -File .\scripts\Export-VM.ps1 -VeeamOneServer "veeamone.domain.local" -OutputTarget Excel,Postgres -PgHost "docker-host.domain.local"

# Nach Power State filtern
pwsh.exe -File .\scripts\Export-VM.ps1 -VeeamOneServer "veeamone.domain.local" -PowerState Off
```

### Scheduled Task (unbeaufsichtigter Betrieb)

1. Credentials einmalig als Service-Account speichern
2. Scheduled Task einrichten:

```
pwsh.exe -File "C:\Scripts\Export-VM.ps1" -VeeamOneServer "veeamone.domain.local" -OutputTarget Excel,Postgres -PgHost "docker-host.domain.local"
```

**Hinweis:** Pro Monat kann nur einmal exportiert werden. Ein erneuter Export im selben Monat wird abgebrochen.

## Projektstruktur

```
InvoicingTST/
├── .env                         # Umgebungsvariablen (VAULT_KEY, PG_USER)
├── .gitignore
├── docker-compose.yml
├── nginx/
│   ├── nginx.conf               # Reverse Proxy Konfiguration
│   ├── certs/                   # SSL-Zertifikate (nicht im Git)
│   └── generate-self-signed-cert.sh
├── postgres/
│   ├── Dockerfile
│   ├── install-postgres-schema.sql
│   ├── install-auth-schema.sql
│   ├── install-customer-schema.sql
│   ├── install-pricing-schema.sql
│   └── install-vault-schema.sql
├── secrets/
│   └── pg_password.txt          # Docker Secret (nicht im Git)
├── scripts/
│   └── Export-VM.ps1            # VeeamOne VM-Export
└── web/
    ├── Dockerfile
    ├── composer.json
    ├── src/
    │   ├── db.php               # DB-Verbindung (liest Docker Secret)
    │   ├── vault.php            # AES-256-GCM Vault
    │   ├── jira_assets.php      # Jira Assets API Client
    │   ├── auth.php             # Authentifizierung
    │   ├── middleware.php        # Auth-Middleware
    │   ├── pricing.php          # Preisberechnung
    │   ├── customers.php        # Kundenverwaltung
    │   └── ...
    └── public/
        ├── index.html           # Dashboard
        ├── vms.html             # VM-Übersicht
        ├── style.css            # Dark Mode UI
        ├── app.js               # Sidebar, Auth, Theme
        ├── settings.html        # Benutzereinstellungen
        ├── stammdaten/
        │   ├── customers.html   # Kundenkürzel
        │   └── pricing.html     # Preisklassen
        ├── admin/
        │   ├── users.html       # Benutzerverwaltung
        │   └── vault.html       # Vault (verschlüsselte Secrets)
        └── api/
            ├── vms.php
            ├── stats.php
            ├── vault.php
            ├── jira-assets.php
            └── ...
```
