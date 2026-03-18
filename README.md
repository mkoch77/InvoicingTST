# Accounting / Invoicing Platform

Plattform zur Erfassung und Abrechnung von IT-Ressourcen. Besteht aus PowerShell-Export-Skripten, einer PostgreSQL-Datenbank und einer Weboberfläche.

## Architektur

```
┌─────────────────┐     ┌──────────────┐     ┌──────────────┐
│  PowerShell-     │────>│  PostgreSQL   │<────│  Webober-    │
│  Export-Skripte  │     │  (Docker)    │     │  fläche      │
│  (Windows)       │     │  Port 5432   │     │  Port 443    │
└─────────────────┘     └──────────────┘     └──────────────┘
                                               │
                                         nginx Reverse Proxy
                                         (SSL / Let's Encrypt)
```

| Komponente | Beschreibung |
|---|---|
| `scripts/Export-VM.ps1` | Exportiert VM-Daten aus VeeamOne nach Excel und/oder PostgreSQL |
| `web/` | Node.js-Weboberfläche (Express) zur Anzeige und Excel-Export der Daten |
| `nginx/` | Reverse Proxy mit SSL-Terminierung |
| `install-postgres-schema.sql` | Datenbankschema (wird beim ersten Start automatisch angelegt) |

## Installation auf einem neuen Server

### Voraussetzungen

- **Docker** und **Docker Compose** (v2)
- **Git**
- **OpenSSL** (für selbstsigniertes Zertifikat, bereits auf den meisten Linux-Systemen vorhanden)

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

### 2. Passwort konfigurieren

```bash
cp .env.example .env
nano .env
```

Die `.env`-Datei enthält die Zugangsdaten für die PostgreSQL-Datenbank:

```
PG_USER=accounting
PG_PASSWORD=HIER_SICHERES_PASSWORT_SETZEN
PG_DATABASE=InvoicingAssets
```

**Wichtig:** Ein sicheres Passwort setzen. Die `.env`-Datei wird nicht ins Git eingecheckt.

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
| `accounting-web` | Weboberfläche (intern Port 3000) |
| `accounting-nginx` | Reverse Proxy (Port 80/443) |

Logs anzeigen:

```bash
docker compose logs -f
```

## Nützliche Befehle

```bash
# Container stoppen / starten
docker compose stop
docker compose start

# Neu bauen nach Code-Änderungen
docker compose up -d --build

# psql-Shell im Container öffnen
docker exec -it accounting-postgres psql -U accounting -d InvoicingAssets

# Daten komplett zurücksetzen (Container und Volumes löschen!)
docker compose down -v
```

## Export-Skripte (PowerShell)

Die Export-Skripte laufen auf einem Windows-Server und schreiben Daten in die PostgreSQL-Datenbank.

### Voraussetzungen

- **PowerShell 7+**
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
.\scripts\Export-VM.ps1 -VeeamOneServer "veeamone.domain.local" -SaveCredential

# PostgreSQL-Credentials
.\scripts\Export-VM.ps1 -VeeamOneServer "veeamone.domain.local" -SavePgCredential
```

### Export nach Excel

```powershell
.\scripts\Export-VM.ps1 -VeeamOneServer "veeamone.domain.local"
```

### Export nach PostgreSQL

```powershell
.\scripts\Export-VM.ps1 -VeeamOneServer "veeamone.domain.local" -OutputTarget Postgres -PgHost "docker-host.domain.local"
```

### Export nach Excel und PostgreSQL

```powershell
.\scripts\Export-VM.ps1 -VeeamOneServer "veeamone.domain.local" -OutputTarget Excel,Postgres -PgHost "docker-host.domain.local"
```

### Nach Power State filtern

```powershell
.\scripts\Export-VM.ps1 -VeeamOneServer "veeamone.domain.local" -PowerState Off
.\scripts\Export-VM.ps1 -VeeamOneServer "veeamone.domain.local" -PowerState On,Suspended
```

### Scheduled Task (unbeaufsichtigter Betrieb)

1. Credentials einmalig als Service-Account speichern (siehe oben)
2. Scheduled Task einrichten:

```
pwsh.exe -File "C:\Scripts\Export-VM.ps1" -VeeamOneServer "veeamone.domain.local" -OutputTarget Excel,Postgres -PgHost "docker-host.domain.local"
```

### Parameter

| Parameter | Pflicht | Standard | Beschreibung |
|---|---|---|---|
| `-VeeamOneServer` | Ja | - | Hostname/IP des VeeamOne-Servers |
| `-Port` | Nein | `1239` | REST API Port |
| `-Credential` | Nein | - | PSCredential-Objekt (sonst Datei oder Prompt) |
| `-OutputPath` | Nein | `.\VeeamOne_VMs_<datum>.xlsx` | Pfad zur Excel-Datei |
| `-OutputTarget` | Nein | `Excel` | Ziel: `Excel`, `Postgres` oder beides |
| `-PowerState` | Nein | alle | Filter: `On`, `Off`, `Suspended` |
| `-MarkDuplicates` | Nein | `$true` | Doppelte Hostnamen markieren (Excel) |
| `-Discover` | Nein | - | API-Endpunkte anzeigen |
| `-CredentialFile` | Nein | `.\veeamone.cred` | Pfad zur verschlüsselten Credential-Datei |
| `-SaveCredential` | Nein | - | VeeamOne-Credentials speichern |
| `-PgHost` | Nein* | - | PostgreSQL-Host (*Pflicht bei Postgres-Export) |
| `-PgPort` | Nein | `5432` | PostgreSQL-Port |
| `-PgDatabase` | Nein | `InvoicingAssets` | PostgreSQL-Datenbank |
| `-PgCredentialFile` | Nein | `.\postgres.cred` | Pfad zur PG-Credential-Datei |
| `-SavePgCredential` | Nein | - | PostgreSQL-Credentials speichern |
| `-LogFile` | Nein | `<OutputPath>.log` | Pfad zur Log-Datei |

## Datenbankschema

```
operating_system          power_state
 id  SERIAL PK             id  SERIAL PK
 name TEXT UNIQUE           name TEXT UNIQUE
       |                         |
       +-------- vm -------------+
                 id  SERIAL PK
                 hostname  TEXT
                 dns_name  TEXT
                 operating_system_id  FK
                 vcpu  INTEGER
                 vram_mb  INTEGER
                 used_storage_gb  DOUBLE
                 provisioned_storage_gb  DOUBLE
                 power_state_id  FK
                 exported_at  TIMESTAMPTZ
                       |
               vm_ip_address
                 id  SERIAL PK
                 vm_id  FK (CASCADE)
                 ip_address  INET
```

## Projektstruktur

```
InvoicingTST/
├── .env.example              # Vorlage für Zugangsdaten
├── .env                      # Zugangsdaten (nicht im Git)
├── .gitignore
├── docker-compose.yml
├── install-postgres-schema.sql
├── nginx/
│   ├── nginx.conf            # Reverse Proxy Konfiguration
│   ├── certs/                # SSL-Zertifikate (nicht im Git)
│   └── generate-self-signed-cert.sh
├── scripts/
│   └── Export-VM.ps1         # VeeamOne VM-Export
└── web/
    ├── Dockerfile
    ├── package.json
    ├── server.js
    └── public/
        ├── index.html        # Dashboard
        ├── vms.html          # VM-Übersicht
        ├── vms.js
        └── style.css
```
