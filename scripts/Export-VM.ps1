<#
.SYNOPSIS
    Exports VM data from the VeeamOne API into Excel and/or PostgreSQL.

.DESCRIPTION
    Queries the VeeamOne REST API for all virtual machines and exports
    hostname, IP address, vCPU, vRAM, used storage, provisioned storage,
    and power state into an Excel file and/or a PostgreSQL database.

.PARAMETER VeeamOneServer
    Hostname or IP address of the VeeamOne server.

.PARAMETER Port
    Port of the VeeamOne REST API (default: 1239).

.PARAMETER Credential
    PSCredential object for authentication. If not provided, a prompt will appear.

.PARAMETER OutputPath
    Path for the output Excel file (default: .\VeeamOne_VMs_<date>.xlsx).

.PARAMETER PowerState
    Filters VMs by power state. Multiple values allowed.
    Valid values: On, Off, Suspended
    Default: all VMs are exported.

.PARAMETER Discover
    Queries the API and prints all available endpoints containing "vm" or
    "virtualMachine". Use this to troubleshoot 404 errors and find the
    correct API path for your VeeamOne version.

.PARAMETER MarkDuplicates
    Detects VMs with duplicate hostnames, adds a "Duplicate" column, and
    highlights those rows in yellow in the Excel output.
    Default: $true (enabled). Pass -MarkDuplicates $false to disable.

.PARAMETER CredentialFile
    Path to an encrypted credential file created by -SaveCredential.
    Default: <script directory>\veeamone.cred
    The file is encrypted with Windows DPAPI and can only be decrypted
    by the same user account on the same machine.

.PARAMETER SaveCredential
    Prompts for credentials interactively, encrypts them, and saves them
    to -CredentialFile. Run this once manually as the service account
    that will execute the Scheduled Task, then use -CredentialFile in
    the scheduled task command line.

.PARAMETER OutputTarget
    Output destination(s). Valid values: Excel, Postgres. Multiple values allowed.
    Default: Excel.

.PARAMETER PgHost
    Hostname or IP of the PostgreSQL server. Required when OutputTarget includes Postgres.

.PARAMETER PgPort
    Port of the PostgreSQL server (default: 5432).

.PARAMETER PgDatabase
    Name of the PostgreSQL database (default: InvoicingAssets).

.PARAMETER PgCredentialFile
    Path to an encrypted credential file for PostgreSQL, created by -SavePgCredential.
    Default: <script directory>\postgres.cred

.PARAMETER SavePgCredential
    Prompts for PostgreSQL credentials interactively, encrypts them, and saves
    them to -PgCredentialFile. Run this once manually, then use the credential
    file for unattended runs.

.PARAMETER LogFile
    Path to the log file. Defaults to the same directory and base name as
    OutputPath with a .log extension (e.g., VeeamOne_VMs_2026-03-03.log).
    Each run overwrites the log file.

.EXAMPLE
    # Step 1: save credentials once (run as the Scheduled Task service account)
    .\Export-VeeamOneVMs.ps1 -VeeamOneServer "veeamone.domain.local" -SaveCredential

.EXAMPLE
    # Step 2: run unattended (Scheduled Task command line)
    .\Export-VeeamOneVMs.ps1 -VeeamOneServer "veeamone.domain.local" -OutputPath "C:\Reports\VMs.xlsx"

.EXAMPLE
    # Save PostgreSQL credentials once
    .\Export-VeeamOneVMs.ps1 -VeeamOneServer "veeamone.domain.local" -SavePgCredential

.EXAMPLE
    # Export to both Excel and PostgreSQL
    .\Export-VeeamOneVMs.ps1 -VeeamOneServer "veeamone.domain.local" -OutputTarget Excel,Postgres -PgHost "db.domain.local"

.EXAMPLE
    .\Export-VeeamOneVMs.ps1 -VeeamOneServer "veeamone.domain.local" -PowerState Off

.EXAMPLE
    .\Export-VeeamOneVMs.ps1 -VeeamOneServer "veeamone.domain.local" -PowerState On, Suspended

.EXAMPLE
    .\Export-VeeamOneVMs.ps1 -VeeamOneServer "veeamone.domain.local" -Discover

.EXAMPLE
    $cred = Get-Credential
    .\Export-VeeamOneVMs.ps1 -VeeamOneServer "192.168.1.100" -Port 1239 -Credential $cred -OutputPath "C:\Reports\VMs.xlsx" -PowerState Off
#>

[CmdletBinding()]
param (
    [Parameter(Mandatory = $true)]
    [string]$VeeamOneServer,

    [Parameter(Mandatory = $false)]
    [int]$Port = 1239,

    [Parameter(Mandatory = $false)]
    [System.Management.Automation.PSCredential]$Credential,

    [Parameter(Mandatory = $false)]
    [string]$OutputPath = ".\VeeamOne_VMs_$(Get-Date -Format 'yyyy-MM-dd_HHmm').xlsx",

    [Parameter(Mandatory = $false)]
    [ValidateSet("On", "Off", "Suspended")]
    [string[]]$PowerState,

    [Parameter(Mandatory = $false)]
    [switch]$Discover,

    [Parameter(Mandatory = $false)]
    [bool]$MarkDuplicates = $true,

    [Parameter(Mandatory = $false)]
    [string]$CredentialFile = "$PSScriptRoot\veeamone.cred",

    [Parameter(Mandatory = $false)]
    [switch]$SaveCredential,

    [Parameter(Mandatory = $false)]
    [ValidateSet("Excel", "Postgres")]
    [string[]]$OutputTarget = @("Excel"),

    [Parameter(Mandatory = $false)]
    [string]$PgHost,

    [Parameter(Mandatory = $false)]
    [int]$PgPort = 5432,

    [Parameter(Mandatory = $false)]
    [string]$PgDatabase = "InvoicingAssets",

    [Parameter(Mandatory = $false)]
    [string]$PgCredentialFile = "$PSScriptRoot\postgres.cred",

    [Parameter(Mandatory = $false)]
    [switch]$SavePgCredential,

    [Parameter(Mandatory = $false)]
    [string]$LogFile
)

# Resolve default log file path: same directory and base name as OutputPath, .log extension
if (-not $LogFile) {
    $logDir  = Split-Path $OutputPath -Parent
    if (-not $logDir) { $logDir = "." }
    $logBase = [System.IO.Path]::GetFileNameWithoutExtension($OutputPath)
    $LogFile = Join-Path $logDir "$logBase.log"
}

# Ensure log directory exists and start fresh log for this run
$logParent = Split-Path $LogFile -Parent
if ($logParent -and -not (Test-Path $logParent)) {
    New-Item -ItemType Directory -Path $logParent -Force | Out-Null
}
Set-Content -Path $LogFile -Value "" -Force -ErrorAction SilentlyContinue

#region Helper Functions

function Write-Log {
    param (
        [Parameter(Mandatory = $true)]
        [string]$Message,

        [Parameter(Mandatory = $false)]
        [ValidateSet("INFO", "WARN", "ERROR")]
        [string]$Level = "INFO",

        [Parameter(Mandatory = $false)]
        [string]$ForegroundColor
    )

    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $logEntry  = "[$timestamp] [$Level] $Message"
    Add-Content -Path $script:LogFile -Value $logEntry -ErrorAction SilentlyContinue

    switch ($Level) {
        "ERROR" { Write-Error $Message }
        "WARN"  { Write-Warning $Message }
        default {
            if ($ForegroundColor) {
                Write-Host $Message -ForegroundColor $ForegroundColor
            } else {
                Write-Host $Message
            }
        }
    }
}

function Get-VeeamOneToken {
    param (
        [string]$BaseUrl,
        [System.Management.Automation.PSCredential]$Credential
    )

    $body = @{
        grant_type = "password"
        username   = $Credential.UserName
        password   = $Credential.GetNetworkCredential().Password
    }

    try {
        $response = Invoke-RestMethod `
            -Uri "$BaseUrl/api/token" `
            -Method POST `
            -Body $body `
            -ContentType "application/x-www-form-urlencoded" `
            -SkipCertificateCheck
        return $response.access_token
    }
    catch {
        Write-Log "Authentication failed: $_" -Level ERROR
        Write-Log "Exception: $($_.Exception.GetType().FullName): $($_.Exception.Message)" -Level ERROR
        Write-Log "Stack trace: $($_.ScriptStackTrace)" -Level ERROR
        throw
    }
}

function Invoke-VeeamOneApi {
    param (
        [string]$Uri,
        [string]$Token
    )

    $headers = @{
        Authorization = "Bearer $Token"
        Accept        = "application/json"
    }

    try {
        return Invoke-RestMethod `
            -Uri $Uri `
            -Method GET `
            -Headers $headers `
            -SkipCertificateCheck
    }
    catch {
        Write-Log "API call failed ($Uri): $($_.Exception.Message)" -Level WARN
        return $null
    }
}

function Probe-Path {
    param (
        [string]$Uri,
        [string]$Token
    )
    $headers = @{ Authorization = "Bearer $Token"; Accept = "application/json" }
    try {
        $response = Invoke-RestMethod -Uri $Uri -Headers $headers -SkipCertificateCheck -ErrorAction Stop
        return $response
    }
    catch {
        return $null
    }
}

function Get-ApiEndpoints {
    # Tries to retrieve the OpenAPI/Swagger spec and extract all paths.
    param (
        [string]$BaseUrl,
        [string]$Token
    )

    $swaggerCandidates = @(
        "$BaseUrl/api/swagger/v1/swagger.json",
        "$BaseUrl/api/swagger.json",
        "$BaseUrl/swagger/v1/swagger.json",
        "$BaseUrl/swagger.json"
    )

    foreach ($swaggerUrl in $swaggerCandidates) {
        try {
            $spec = Invoke-RestMethod -Uri $swaggerUrl -Headers @{ Authorization = "Bearer $Token" } -SkipCertificateCheck -ErrorAction Stop
            if ($spec.paths) {
                return $spec.paths.PSObject.Properties.Name
            }
        }
        catch { <# try next candidate #> }
    }

    return $null
}

function Find-VmEndpoint {
    # Probes known endpoint patterns and returns the first one that responds with HTTP 200.
    param (
        [string]$BaseUrl,
        [string]$Token
    )

    # VeeamOne 13 (v2.3): platform-specific paths
    # VeeamOne 12 and older: generic infrastructure path
    $candidates = @(
        "/api/v2.3/vSphere/vms",
        "/api/v2.3/hyperV/vms",
        "/api/v2.2/infrastructure/virtualMachines",
        "/api/v2.1/infrastructure/virtualMachines",
        "/api/v2/infrastructure/virtualMachines",
        "/api/v1/infrastructure/virtualMachines"
    )

    $headers = @{ Authorization = "Bearer $Token"; Accept = "application/json" }

    foreach ($path in $candidates) {
        $uri = "$BaseUrl$path" + "?Offset=0&Limit=1"
        try {
            $null = Invoke-RestMethod -Uri $uri -Headers $headers -SkipCertificateCheck -ErrorAction Stop
            Write-Log "  [OK] $path" -Level INFO -ForegroundColor Green
            return $path
        }
        catch {
            $status = $_.Exception.Response.StatusCode.value__
            Write-Log "  [$status] $path" -Level INFO -ForegroundColor DarkGray
        }
    }

    return $null
}

function ConvertFrom-JwtToken {
    # Decodes a JWT token payload (Base64Url) without validating the signature.
    param ([string]$Token)
    try {
        $parts   = $Token.Split('.')
        $payload = $parts[1]
        # Base64Url -> Base64
        $payload = $payload.Replace('-', '+').Replace('_', '/')
        switch ($payload.Length % 4) {
            2 { $payload += '==' }
            3 { $payload += '=' }
        }
        $json = [System.Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($payload))
        return $json | ConvertFrom-Json
    }
    catch {
        return $null
    }
}

function Invoke-ApiExplore {
    param (
        [string]$BaseUrl,
        [string]$Token,
        [string]$Hostname
    )

    $headers = @{ Authorization = "Bearer $Token"; Accept = "application/json" }

    # 1. Decode JWT to reveal audience / issuer / custom claims
    Write-Log "--- JWT Token Claims ---" -Level INFO -ForegroundColor Cyan
    $claims = ConvertFrom-JwtToken -Token $Token
    if ($claims) {
        $claims.PSObject.Properties | ForEach-Object {
            Write-Log "  $($_.Name): $($_.Value)" -Level INFO -ForegroundColor White
        }
    } else {
        Write-Log "  Could not decode token." -Level WARN -ForegroundColor DarkGray
    }

    # 2. Probe different ports on the same host
    Write-Log "--- Port Scan (common VeeamOne / REST ports) ---" -Level INFO -ForegroundColor Cyan
    $ports = @(1239, 443, 1340, 1240, 8080, 8443, 9443)
    $testPath = "/api/v2.3/vSphere/vms?Offset=0&Limit=1"

    foreach ($port in $ports) {
        $uri = "https://${Hostname}:${port}${testPath}"
        try {
            $null = Invoke-RestMethod -Uri $uri -Headers $headers -SkipCertificateCheck -ErrorAction Stop
            Write-Log "  [200] Port $port -> VM endpoint found!" -Level INFO -ForegroundColor Green
        }
        catch {
            $status = $_.Exception.Response.StatusCode.value__
            if ($status) {
                $color = if ($status -eq 401) { "Yellow" } elseif ($status -eq 404) { "DarkGray" } else { "White" }
                Write-Log "  [$status] Port $port" -Level INFO -ForegroundColor $color
            } else {
                Write-Log "  [ERR] Port $port - $($_.Exception.Message -replace '\r?\n',' ')" -Level WARN -ForegroundColor DarkGray
            }
        }
    }

    # 3. Broad path scan on the original port, show response headers on 404
    Write-Log "--- Path Scan with response headers ---" -Level INFO -ForegroundColor Cyan
    $probePaths = @("/api", "/api/v2.3", "/api/v2.3/vSphere/vms", "/api/v2.3/hyperV/vms",
                    "/api/v2.3/infrastructure", "/api/v2.2", "/api/v2.2/infrastructure")

    foreach ($path in $probePaths) {
        $uri = "$BaseUrl$path"
        try {
            $resp = Invoke-WebRequest -Uri $uri -Headers $headers -SkipCertificateCheck -ErrorAction Stop
            Write-Log "  [$($resp.StatusCode)] $path" -Level INFO -ForegroundColor Green
            Write-Log "    Content-Type: $($resp.Headers['Content-Type'])" -Level INFO -ForegroundColor Gray
            Write-Log "    Body: $($resp.Content.Substring(0, [Math]::Min(200,$resp.Content.Length)) -replace '\s+',' ')" -Level INFO -ForegroundColor Gray
        }
        catch {
            $webResp = $_.Exception.Response
            if ($webResp) {
                $status = [int]$webResp.StatusCode
                $color  = if ($status -eq 401) { "Yellow" } elseif ($status -lt 500) { "DarkGray" } else { "Red" }
                Write-Log "  [$status] $path" -Level INFO -ForegroundColor $color
                # Show response headers that might give clues (e.g. x-api-version, www-authenticate)
                $interesting = @('WWW-Authenticate','x-api-version','x-veeam-version','Server','Location')
                foreach ($h in $interesting) {
                    $val = $webResp.Headers[$h]
                    if ($val) { Write-Log "    $h`: $val" -Level INFO -ForegroundColor Gray }
                }
            } else {
                Write-Log "  [ERR] $path - $($_.Exception.Message -replace '\r?\n',' ')" -Level WARN -ForegroundColor DarkGray
            }
        }
    }
}

function Get-AllPages {
    param (
        [string]$BaseUri,
        [string]$Token,
        [int]$PageSize = 500
    )

    $allItems = [System.Collections.Generic.List[object]]::new()
    $offset   = 0

    do {
        $uri      = "${BaseUri}?Offset=$offset&Limit=$PageSize"
        $response = Invoke-VeeamOneApi -Uri $uri -Token $Token

        if ($null -eq $response) { break }

        # VeeamOne v2.3 wraps results under "items"; older versions used "data" or a bare array
        $items = if ($response.PSObject.Properties.Name -contains 'items') {
            $response.items
        } elseif ($response.PSObject.Properties.Name -contains 'data') {
            $response.data
        } else {
            $response
        }

        if ($null -eq $items -or $items.Count -eq 0) { break }

        $allItems.AddRange([object[]]$items)

        # v2.3 uses "totalCount"; older versions used "total"
        $total = if ($response.PSObject.Properties.Name -contains 'totalCount') {
            $response.totalCount
        } elseif ($response.PSObject.Properties.Name -contains 'total') {
            $response.total
        } else {
            $items.Count
        }
        $offset += $PageSize

    } while ($offset -lt $total)

    return $allItems
}

function Export-ToExcel {
    param (
        [System.Collections.Generic.List[object]]$Data,
        [string]$FilePath
    )

    # Check for and install the ImportExcel module if missing
    if (-not (Get-Module -ListAvailable -Name ImportExcel)) {
        Write-Log "Installing ImportExcel module..." -Level WARN -ForegroundColor Yellow
        Install-Module -Name ImportExcel -Scope CurrentUser -Force -ErrorAction Stop
    }
    Import-Module ImportExcel -ErrorAction Stop

    $sortedData = $Data | Sort-Object -Property "Hostname"

    # Save original IP strings before export (ImportExcel may convert them to numbers)
    $originalIps = @($sortedData | ForEach-Object { $_."IP Address" })

    $excelParams = @{
        Path          = $FilePath
        AutoSize      = $true
        TableName     = "VMs"
        TableStyle    = "Medium2"
        FreezeTopRow  = $true
        BoldTopRow    = $true
        WorksheetName = "Virtual Machines"
    }

    $pkg = $sortedData | Export-Excel @excelParams -PassThru
    $ws  = $pkg.Workbook.Worksheets["Virtual Machines"]

    if ($ws.Dimension -and $ws.Dimension.End.Row -gt 1) {
        $lastRow = $ws.Dimension.End.Row
        $lastCol = $ws.Dimension.End.Column

        # Find column indices
        $ipColIdx   = $null
        $dupeColIdx = $null
        for ($c = 1; $c -le $lastCol; $c++) {
            $header = $ws.Cells[1, $c].Value
            if ($header -eq "IP Address") { $ipColIdx = $c }
            if ($header -eq "Duplicate")  { $dupeColIdx = $c }
        }

        # Fix IP Address column: force text format and re-write original strings
        # to undo any automatic number conversion by ImportExcel/EPPlus
        if ($ipColIdx) {
            for ($r = 2; $r -le $lastRow; $r++) {
                $ws.Cells[$r, $ipColIdx].Style.Numberformat.Format = "@"
                $ws.Cells[$r, $ipColIdx].Value = [string]$originalIps[$r - 2]
            }
        }

        # Highlight entire row yellow when Duplicate = "Yes"
        if ($dupeColIdx) {
            $dupeColLetter = [char](64 + $dupeColIdx)
            $lastColLetter = [char](64 + $lastCol)
            $dataRange     = "A2:${lastColLetter}${lastRow}"

            Add-ConditionalFormatting -WorkSheet $ws -Range $dataRange `
                -RuleType Expression `
                -ConditionValue "`$$dupeColLetter`2=""Yes""" `
                -BackgroundColor ([System.Drawing.Color]::FromArgb(255, 255, 255, 0))
        }
    }

    Close-ExcelPackage $pkg

    Write-Log "Excel file saved: $FilePath" -Level INFO -ForegroundColor Green
}

function Export-ToPostgres {
    param (
        [System.Collections.Generic.List[object]]$Data,
        [string]$ConnectionString
    )

    # Check for and install the SimplySql module if missing
    if (-not (Get-Module -ListAvailable -Name SimplySql)) {
        Write-Log "Installing SimplySql module..." -Level WARN -ForegroundColor Yellow
        Install-Module -Name SimplySql -Scope CurrentUser -Force -ErrorAction Stop
    }
    Import-Module SimplySql -ErrorAction Stop

    # Upsert operating system and return its id
    $upsertOsSql = @"
INSERT INTO operating_system (name) VALUES (@name)
ON CONFLICT (name) DO UPDATE SET name = EXCLUDED.name
RETURNING id
"@

    # Look up power_state id by name
    $lookupPowerStateSql = "SELECT id FROM power_state WHERE name = @name"

    # Upsert VM row: update on duplicate hostname+month
    $insertVmSql = @"
INSERT INTO vm (hostname, dns_name, operating_system_id, vcpu, vram_mb,
                used_storage_gb, provisioned_storage_gb, power_state_id)
VALUES (@hostname, @dns_name, @operating_system_id, @vcpu, @vram_mb,
        @used_storage_gb, @provisioned_storage_gb, @power_state_id)
ON CONFLICT (hostname, export_month) DO UPDATE SET
    dns_name               = EXCLUDED.dns_name,
    operating_system_id    = EXCLUDED.operating_system_id,
    vcpu                   = EXCLUDED.vcpu,
    vram_mb                = EXCLUDED.vram_mb,
    used_storage_gb        = EXCLUDED.used_storage_gb,
    provisioned_storage_gb = EXCLUDED.provisioned_storage_gb,
    power_state_id         = EXCLUDED.power_state_id,
    exported_at            = NOW()
RETURNING id
"@

    # Upsert individual IP address (avoid duplicates)
    $insertIpSql = @"
INSERT INTO vm_ip_address (vm_id, ip_address)
VALUES (@vm_id, @ip_address::inet)
ON CONFLICT DO NOTHING
"@

    try {
        Open-PostGreConnection -ConnectionString $ConnectionString
        Write-Log "Connected to PostgreSQL." -Level INFO -ForegroundColor Green

        # Check if data for the current month already exists (info only, upsert handles re-runs)
        $currentMonth = (Get-Date).ToString('yyyy-MM')
        $checkMonthSql = "SELECT COUNT(*) FROM vm WHERE export_month = @month"
        $existingCount = [int](Invoke-SqlScalar -Query $checkMonthSql -Parameters @{ month = [string]$currentMonth })
        if ($existingCount -gt 0) {
            Write-Log "Monat ${currentMonth}: ${existingCount} VMs vorhanden — Daten werden per Upsert aktualisiert." -Level INFO -ForegroundColor Yellow
        }

        # Cache for already-resolved lookup values
        $osCache         = @{}
        $powerStateCache = @{}

        $inserted = 0
        $failed   = 0
        foreach ($row in $Data) {
            try {
                # Resolve operating_system_id
                $osName = $row."Operating System"
                $osId   = $null
                if ($osName) {
                    if ($osCache.ContainsKey($osName)) {
                        $osId = $osCache[$osName]
                    } else {
                        $osId = [int](Invoke-SqlScalar -Query $upsertOsSql -Parameters @{ name = [string]$osName })
                        $osCache[$osName] = $osId
                    }
                }

                # Resolve power_state_id
                $psName = $row."Power State"
                $psId   = $null
                if ($psName) {
                    if ($powerStateCache.ContainsKey($psName)) {
                        $psId = $powerStateCache[$psName]
                    } else {
                        $psId = [int](Invoke-SqlScalar -Query $lookupPowerStateSql -Parameters @{ name = [string]$psName })
                        $powerStateCache[$psName] = $psId
                    }
                }

                # Insert VM — Npgsql requires explicit .NET types, $null must be [DBNull]::Value
                $vmParams = @{
                    hostname               = [string]($row."Hostname" ?? "")
                    dns_name               = if ($row."DNS Name") { [string]$row."DNS Name" } else { [DBNull]::Value }
                    operating_system_id    = if ($null -ne $osId) { [int]$osId } else { [DBNull]::Value }
                    vcpu                   = [int]($row."vCPU" ?? 0)
                    vram_mb                = [int]($row."vRAM (MB)" ?? 0)
                    used_storage_gb        = [double]($row."Used Storage (GB)" ?? 0)
                    provisioned_storage_gb = [double]($row."Provisioned Storage (GB)" ?? 0)
                    power_state_id         = if ($null -ne $psId) { [int]$psId } else { [DBNull]::Value }
                }
                $vmId = [int](Invoke-SqlScalar -Query $insertVmSql -Parameters $vmParams)

                # Replace IP addresses: delete old ones first (handles changed IPs on upsert)
                Invoke-SqlUpdate -Query "DELETE FROM vm_ip_address WHERE vm_id = @vm_id" -Parameters @{ vm_id = [int]$vmId } | Out-Null

                $ipString = $row."IP Address"
                if ($ipString) {
                    foreach ($ip in ($ipString -split ',\s*')) {
                        if ($ip) {
                            Invoke-SqlUpdate -Query $insertIpSql -Parameters @{ vm_id = [int]$vmId; ip_address = [string]$ip } | Out-Null
                        }
                    }
                }

                $inserted++
            }
            catch {
                $failed++
                if ($failed -le 3) {
                    Write-Log "Failed to insert VM '$($row."Hostname")': $($_.Exception.Message)" -Level WARN
                } elseif ($failed -eq 4) {
                    Write-Log "Further insert errors will be suppressed..." -Level WARN
                }
            }
        }

        if ($failed -gt 0) {
            Write-Log "PostgreSQL: $inserted VMs inserted, $failed failed." -Level WARN
        } else {
            Write-Log "PostgreSQL: $inserted VMs inserted (normalized)." -Level INFO -ForegroundColor Green
        }
    }
    finally {
        Close-SqlConnection -ErrorAction SilentlyContinue
    }
}

#endregion

#region Main

Write-Log "=== Export-VeeamOneVMs.ps1 started ===" -Level INFO -ForegroundColor Cyan
Write-Log "Parameters: VeeamOneServer=$VeeamOneServer, Port=$Port, OutputPath=$OutputPath, PowerState=$($PowerState -join ','), Discover=$Discover, MarkDuplicates=$MarkDuplicates, LogFile=$LogFile" -Level INFO

# -SaveCredential: encrypt and persist credentials for unattended / Scheduled Task use.
# Run this once interactively as the service account that will run the Scheduled Task.
if ($SaveCredential) {
    $credToSave = Get-Credential -Message "Enter VeeamOne credentials to save"
    if (-not $credToSave) {
        Write-Log "No credentials provided. Aborting." -Level ERROR
        exit 1
    }
    $credDir = Split-Path $CredentialFile -Parent
    if ($credDir -and -not (Test-Path $credDir)) {
        New-Item -ItemType Directory -Path $credDir -Force | Out-Null
    }
    $credToSave | Export-Clixml -Path $CredentialFile -Force
    Write-Log "Credentials saved to: $CredentialFile" -Level INFO -ForegroundColor Green
    Write-Log "This file is encrypted with Windows DPAPI and can only be decrypted by user '$env:USERNAME' on this machine." -Level INFO -ForegroundColor Yellow
    Write-Log "=== Script ended (SaveCredential) ===" -Level INFO
    exit 0
}

# -SavePgCredential: encrypt and persist PostgreSQL credentials for unattended use.
if ($SavePgCredential) {
    $pgCredToSave = Get-Credential -Message "Enter PostgreSQL credentials (username/password)"
    if (-not $pgCredToSave) {
        Write-Log "No PostgreSQL credentials provided. Aborting." -Level ERROR
        exit 1
    }
    $pgCredDir = Split-Path $PgCredentialFile -Parent
    if ($pgCredDir -and -not (Test-Path $pgCredDir)) {
        New-Item -ItemType Directory -Path $pgCredDir -Force | Out-Null
    }
    $pgCredToSave | Export-Clixml -Path $PgCredentialFile -Force
    Write-Log "PostgreSQL credentials saved to: $PgCredentialFile" -Level INFO -ForegroundColor Green
    Write-Log "This file is encrypted with Windows DPAPI and can only be decrypted by user '$env:USERNAME' on this machine." -Level INFO -ForegroundColor Yellow
    Write-Log "=== Script ended (SavePgCredential) ===" -Level INFO
    exit 0
}

# Resolve PostgreSQL credentials and build connection string if Postgres output is requested
$pgConnString = $null
if ("Postgres" -in $OutputTarget) {
    if (-not $PgHost) {
        Write-Log "OutputTarget includes 'Postgres' but no -PgHost was provided." -Level ERROR
        exit 1
    }

    $pgCred = $null
    if (Test-Path $PgCredentialFile) {
        try {
            $pgCred = Import-Clixml -Path $PgCredentialFile
            Write-Log "PostgreSQL credentials loaded from: $PgCredentialFile" -Level INFO -ForegroundColor DarkGray
        }
        catch {
            Write-Log "Failed to load PostgreSQL credentials from '$PgCredentialFile': $_" -Level ERROR
            Write-Log "Exception: $($_.Exception.GetType().FullName): $($_.Exception.Message)" -Level ERROR
            exit 1
        }
    } else {
        $pgCred = Get-Credential -Message "Enter PostgreSQL credentials"
        if (-not $pgCred) {
            Write-Log "No PostgreSQL credentials provided. Exiting." -Level ERROR
            exit 1
        }
    }

    $pgUser = $pgCred.UserName
    $pgPass = $pgCred.GetNetworkCredential().Password
    $pgConnString = "Host=$PgHost;Port=$PgPort;Database=$PgDatabase;Username=$pgUser;Password=$pgPass"
    Write-Log "PostgreSQL target: ${PgHost}:${PgPort}/${PgDatabase} (user: ${pgUser})" -Level INFO -ForegroundColor Cyan
}

# Resolve credentials: explicit param > credential file > interactive prompt
if (-not $Credential) {
    if (Test-Path $CredentialFile) {
        try {
            $Credential = Import-Clixml -Path $CredentialFile
            Write-Log "Credentials loaded from: $CredentialFile" -Level INFO -ForegroundColor DarkGray
        }
        catch {
            Write-Log "Failed to load credentials from '$CredentialFile': $_" -Level ERROR
            Write-Log "Exception: $($_.Exception.GetType().FullName): $($_.Exception.Message)" -Level ERROR
            Write-Log "Stack trace: $($_.ScriptStackTrace)" -Level ERROR
            exit 1
        }
    } else {
        $Credential = Get-Credential -Message "Enter VeeamOne credentials"
        if (-not $Credential) {
            Write-Log "No credentials provided. Exiting." -Level ERROR
            exit 1
        }
    }
}

$baseUrl = "https://${VeeamOneServer}:${Port}"

Write-Log "Connecting to VeeamOne: $baseUrl" -Level INFO -ForegroundColor Cyan

# Retrieve access token
try {
    $token = Get-VeeamOneToken -BaseUrl $baseUrl -Credential $Credential
    Write-Log "Authentication successful." -Level INFO -ForegroundColor Green
}
catch {
    Write-Log "Failed to authenticate to VeeamOne at $baseUrl" -Level ERROR
    Write-Log "=== Script ended with error ===" -Level ERROR
    exit 1
}

# -Discover mode: print available VM-related endpoints and exit
if ($Discover) {
    # 1. Try Swagger spec for a full path list
    Write-Log "Probing Swagger spec..." -Level INFO -ForegroundColor Cyan
    $allPaths = Get-ApiEndpoints -BaseUrl $baseUrl -Token $token
    if ($allPaths) {
        Write-Log "Swagger spec found. VM-related paths:" -Level INFO -ForegroundColor Green
        $allPaths | Where-Object { $_ -imatch 'vm|virtualMachine' } |
            ForEach-Object { Write-Log "  $_" -Level INFO -ForegroundColor White }
    } else {
        Write-Log "Swagger spec not accessible." -Level WARN -ForegroundColor DarkGray
    }

    # 2. Explore API root paths to reveal actual structure
    Invoke-ApiExplore -BaseUrl $baseUrl -Token $token -Hostname $VeeamOneServer

    # 3. Probe known VM endpoint candidates
    Write-Log "--- Known VM Endpoint Candidates ---" -Level INFO -ForegroundColor Cyan
    $null = Find-VmEndpoint -BaseUrl $baseUrl -Token $token

    Write-Log "Tip: Copy a path marked [OK] above and pass it via -VmEndpointPath (if added) or edit the script." -Level INFO -ForegroundColor Yellow
    Write-Log "=== Script ended (Discover mode) ===" -Level INFO
    exit 0
}

try {

# Auto-detect the correct VM endpoint for this VeeamOne version
Write-Log "Detecting VM endpoint..." -Level INFO -ForegroundColor Cyan
$vmEndpointPath = Find-VmEndpoint -BaseUrl $baseUrl -Token $token

if (-not $vmEndpointPath) {
    Write-Log "Could not find a working VM endpoint. Run with -Discover to inspect available paths." -Level ERROR
    Write-Log "=== Script ended with error ===" -Level ERROR
    exit 1
}

Write-Log "Using endpoint: $vmEndpointPath" -Level INFO -ForegroundColor Green

# Fetch VMs
Write-Log "Loading VM data..." -Level INFO -ForegroundColor Cyan
$vms = Get-AllPages -BaseUri "$baseUrl$vmEndpointPath" -Token $token

if ($vms.Count -eq 0) {
    Write-Log "No VMs found." -Level WARN
    Write-Log "=== Script ended (no VMs) ===" -Level INFO
    exit 0
}

Write-Log "$($vms.Count) VMs found. Processing data..." -Level INFO -ForegroundColor Cyan

# Show active filter
if ($PowerState) {
    Write-Log "Filter active - Power State: $($PowerState -join ', ')" -Level INFO -ForegroundColor Yellow
}

# Build result set
# Field mapping for VeeamOne 13 REST API v2.3:
#   name                    -> VM display name
#   guestDnsName            -> DNS hostname (empty if VMware Tools not running)
#   guestIpAddresses[]      -> list of guest IP addresses
#   guestOs                 -> operating system description string
#   cpuCount                -> number of vCPUs
#   memorySizeMb            -> RAM in MB
#   powerState              -> "PoweredOn" | "PoweredOff" | "Suspended"
#   totalDiskCapacityBytes  -> sum of all virtual disk sizes (provisioned)
#   datastoreUsage[]        -> per-datastore usage (commitedBytes = used space)
$result = foreach ($vm in $vms) {

    # IP addresses: extract IPv4 only, join as comma-separated string.
    # guestIpAddresses can be an array of strings OR an array of objects
    # (e.g. { address: "..." } or { ipAddress: "..." }) depending on VeeamOne version.
    $ipAddresses = ""
    if ($vm.guestIpAddresses) {
        $rawIps = @($vm.guestIpAddresses) | ForEach-Object {
            if ($_ -is [string]) {
                $_
            } else {
                # Object: try common property names used across API versions
                $_.address ?? $_.ipAddress ?? $_.ip ?? ([string]$_)
            }
        }
        $ipAddresses = (
            $rawIps | Where-Object { $_ -and $_ -match '^\d{1,3}(\.\d{1,3}){3}' }
        ) -join ", "
    }

    # RAM: API already returns MB, output directly
    $ramMB = [int]($vm.memorySizeMb ?? $vm.memorySizeMB ?? 0)

    # Provisioned storage: totalDiskCapacityBytes = sum of all virtual disk sizes
    $provisionedStorageGB = [math]::Round(
        [double]($vm.totalDiskCapacityBytes ?? 0) / 1GB, 2
    )

    # Used storage: sum of committed bytes across all datastores
    $usedStorageGB = if ($vm.datastoreUsage) {
        [math]::Round(
            ($vm.datastoreUsage | Measure-Object -Property commitedBytes -Sum).Sum / 1GB, 2
        )
    } else { 0 }

    # Power state: v2.3 uses title-case ("PoweredOn", "PoweredOff", "Suspended")
    $normalizedPowerState = switch ($vm.powerState) {
        "PoweredOn"  { "On" }
        "PoweredOff" { "Off" }
        "Suspended"  { "Suspended" }
        default      { $vm.powerState ?? "Unknown" }
    }

    $entry = [PSCustomObject]@{
        "Hostname"                  = $vm.name ?? ""
        "DNS Name"                  = $vm.guestDnsName ?? ""
        "IP Address"                = $ipAddresses
        "Operating System"          = $vm.guestOs ?? ""
        "vCPU"                      = $vm.cpuCount ?? 0
        "vRAM (MB)"                 = $ramMB
        "Used Storage (GB)"         = $usedStorageGB
        "Provisioned Storage (GB)"  = $provisionedStorageGB
        "Power State"               = $normalizedPowerState
    }

    # Apply filter - pass everything through if no filter is set
    if (-not $PowerState -or $normalizedPowerState -in $PowerState) {
        $entry
    }
}

# Export results
if (-not $result) {
    Write-Log "No VMs remaining after filtering - no output will be created." -Level WARN
    Write-Log "=== Script ended (no VMs after filter) ===" -Level INFO
    exit 0
}

# Detect and mark duplicate hostnames
if ($MarkDuplicates) {
    $dupeNames = ($result | Group-Object -Property Hostname | Where-Object Count -gt 1).Name

    $result = $result | ForEach-Object {
        $dupeValue = if ($_.Hostname -in $dupeNames) { "Yes" } else { "" }
        $_ | Add-Member -MemberType NoteProperty -Name "Duplicate" -Value $dupeValue -PassThru
    }

    if ($dupeNames.Count -gt 0) {
        $dupeVmCount = @($result | Where-Object Duplicate -eq "Yes").Count
        Write-Log "Found $dupeVmCount VMs with duplicate hostnames ($($dupeNames.Count) duplicate name(s)): $($dupeNames -join ', ')" -Level WARN
    } else {
        Write-Log "No duplicate hostnames found." -Level INFO -ForegroundColor Green
    }
}

$resultList = [System.Collections.Generic.List[object]]$result

if ("Excel" -in $OutputTarget) {
    Export-ToExcel -Data $resultList -FilePath $OutputPath
}

if ("Postgres" -in $OutputTarget) {
    Export-ToPostgres -Data $resultList -ConnectionString $pgConnString
}

Write-Log "Done! $($result.Count) VMs exported to: $($OutputTarget -join ', ')" -Level INFO -ForegroundColor Green
Write-Log "=== Export-VeeamOneVMs.ps1 finished ===" -Level INFO

}
catch {
    Write-Log "Unhandled error: $($_.Exception.Message)" -Level ERROR
    Write-Log "Exception type: $($_.Exception.GetType().FullName)" -Level ERROR
    Write-Log "Stack trace: $($_.ScriptStackTrace)" -Level ERROR
    Write-Log "=== Script ended with unhandled error ===" -Level ERROR
    exit 1
}

#endregion
