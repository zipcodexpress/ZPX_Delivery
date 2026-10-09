param(
    [ValidateSet('Start', 'Status')][string]$Action = 'Start',
    [string]$Php = "$env:LOCALAPPDATA\ZPX\PHP83\php.exe",
    [ValidateRange(1024, 65535)][int]$Port = 8000
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$configPath = Join-Path $projectRoot '.env.dev'
if (-not (Test-Path -LiteralPath $Php)) { throw 'PHP 8.3+ with pdo_pgsql, sodium and mbstring is required. Pass -Php with its path.' }
if (-not (Test-Path -LiteralPath $configPath)) { throw 'Create private .env.dev using the existing shared development credentials.' }
$savedEnvironment = @{}
try {
    foreach ($line in Get-Content -LiteralPath $configPath) {
        if ($line -match '^\s*([A-Z][A-Z0-9_]*)=(.*)$') {
            $name = $matches[1]
            $value = $matches[2].Trim()
            if ($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) {
                $value = $value.Substring(1, $value.Length - 2)
            }
            $savedEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
            [Environment]::SetEnvironmentVariable($name, $value, 'Process')
        }
    }
    foreach ($name in @('APP_ENV', 'AUTH_ALLOWED_ORIGINS')) {
        if (-not $savedEnvironment.ContainsKey($name)) { $savedEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
    }
    $env:APP_ENV = 'development'
    $env:AUTH_ALLOWED_ORIGINS = "http://localhost:5173,http://localhost:5174,http://127.0.0.1:5173,http://127.0.0.1:5174,http://localhost:$Port,http://127.0.0.1:$Port"
    foreach ($name in @('DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD')) {
        if (-not [Environment]::GetEnvironmentVariable($name, 'Process')) { throw "Missing $name in private .env.dev." }
    }
    if ($env:DB_USER -eq 'postgres') { throw 'Use the existing zpx_runtime account for the API.' }
    Push-Location (Join-Path $projectRoot 'apps/api')
    try {
        & $Php bin/migrate.php status
        if ($LASTEXITCODE -ne 0) { throw 'Shared database schema verification failed. No migration was attempted.' }
        if ($Action -eq 'Status') { return }
        if (-not $env:AUTH_ENCRYPTION_KEY -or -not $env:AUTH_LOOKUP_KEY -or -not $env:ZPX_ORGANIZATION_ID) {
            Write-Warning 'Shared identity configuration is incomplete. Health works; existing-account login requires the Mac auth keys and organization ID.'
        }
        Write-Host "Starting development API at http://127.0.0.1:$Port against $env:DB_HOST/$env:DB_NAME. Ctrl+C stops it."
        & $Php -S "127.0.0.1:$Port" -t public public/index.php
        if ($LASTEXITCODE -ne 0) { throw 'PHP development server failed.' }
    } finally { Pop-Location }
} finally {
    foreach ($name in $savedEnvironment.Keys) { [Environment]::SetEnvironmentVariable($name, $savedEnvironment[$name], 'Process') }
}
