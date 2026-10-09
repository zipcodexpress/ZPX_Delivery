param([string]$Php = "$env:LOCALAPPDATA\ZPX\PHP83\php.exe",[string]$TerminalHttpTest)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$postgresBin = 'C:\Program Files\PostgreSQL\17\bin'
$testRoot = Join-Path $projectRoot ('.local/postgres-tests-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path $testRoot | Out-Null
$listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
$listener.Start(); $testPort = $listener.LocalEndpoint.Port; $listener.Stop()
$testPassword = [Guid]::NewGuid().ToString('N') + [Guid]::NewGuid().ToString('N')
$passwordFile = Join-Path $testRoot 'admin-password'
[System.IO.File]::WriteAllText($passwordFile,$testPassword)
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
& icacls $testRoot /inheritance:r /grant:r "${identity}:(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Could not protect local test directory.' }
$testNames = @('DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASSWORD','TEST_MIGRATION_PASSWORD','APP_ENV','AUTH_ENCRYPTION_KEY','AUTH_LOOKUP_KEY','ZPX_ORGANIZATION_ID','PGPASSWORD','ZPX_TERMINAL_HTTP_TEST','ZPX_TERMINAL_HTTP_FIXTURE','ZPX_TERMINAL_HTTP_ENDPOINT')
$previous = @{}; foreach ($name in $testNames) { $previous[$name] = [Environment]::GetEnvironmentVariable($name,'Process') }
$started = $false
$fixture = Join-Path $projectRoot 'apps/api/fixtures/pilot.json'
$createdFixture = -not (Test-Path -LiteralPath $fixture)
function Invoke-TestProgram([string]$Executable,[string[]]$Arguments) {
    & $Executable @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$Executable exited with $LASTEXITCODE" }
}
try {
    Invoke-TestProgram (Join-Path $postgresBin 'initdb.exe') @('-D',(Join-Path $testRoot 'data'),'-U','postgres','-A','scram-sha-256','--pwfile',$passwordFile,'--encoding=UTF8','--locale=C')
    Remove-Item -LiteralPath $passwordFile
    $serverArgs=@('-D',('"'+(Join-Path $testRoot 'data')+'"'),'-l',('"'+(Join-Path $testRoot 'postgres.log')+'"'),'-o',('"-h 127.0.0.1 -p '+$testPort+'"'),'-w','start')
    $serverStart=Start-Process -FilePath (Join-Path $postgresBin 'pg_ctl.exe') -ArgumentList $serverArgs -PassThru -WindowStyle Hidden -RedirectStandardOutput (Join-Path $testRoot 'startup.out') -RedirectStandardError (Join-Path $testRoot 'startup.err')
    if (-not $serverStart.WaitForExit(20000) -or $serverStart.ExitCode -ne 0) { throw 'Disposable PostgreSQL startup failed.' }
    $started = $true
    $env:PGPASSWORD = $testPassword
    $env:DB_PASSWORD = $testPassword
    $env:TEST_MIGRATION_PASSWORD = $testPassword
    $roles = @'
\getenv test_password DB_PASSWORD
SELECT format('CREATE ROLE zpx_migrator LOGIN PASSWORD %L', :'test_password') \gexec
SELECT format('CREATE ROLE zpx_runtime LOGIN PASSWORD %L', :'test_password') \gexec
CREATE DATABASE zpx_delivery_dev OWNER zpx_migrator;
\connect zpx_delivery_dev
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
CREATE SCHEMA delivery AUTHORIZATION zpx_migrator;
GRANT USAGE ON SCHEMA delivery TO zpx_runtime;
ALTER ROLE zpx_runtime SET search_path TO delivery,pg_catalog;
ALTER ROLE zpx_migrator SET search_path TO delivery,pg_catalog;
'@
    $rolesFile = Join-Path $testRoot 'roles.sql'; [System.IO.File]::WriteAllText($rolesFile,$roles)
    Invoke-TestProgram (Join-Path $postgresBin 'psql.exe') @('-X','-h','127.0.0.1','-p',"$testPort",'-U','postgres','-d','postgres','-v','ON_ERROR_STOP=1','-f',$rolesFile)
    $env:DB_HOST='127.0.0.1'; $env:DB_PORT="$testPort"; $env:DB_NAME='zpx_delivery_dev'; $env:DB_USER='zpx_migrator'; $env:APP_ENV='test'
    $env:AUTH_ENCRYPTION_KEY=[Guid]::NewGuid().ToString('N')+[Guid]::NewGuid().ToString('N')
    $env:AUTH_LOOKUP_KEY=[Guid]::NewGuid().ToString('N')+[Guid]::NewGuid().ToString('N')
    $env:ZPX_ORGANIZATION_ID='1'
    Push-Location (Join-Path $projectRoot 'apps/api')
    try {
        if ($createdFixture) {
            New-Item -ItemType Directory -Force (Split-Path -Parent $fixture) | Out-Null
            Copy-Item -LiteralPath (Join-Path $projectRoot 'docs/handoff/fixtures/pilot.json') -Destination $fixture
        }
        Invoke-TestProgram $Php @('bin/migrate.php','up')
        $env:DB_USER='zpx_runtime'
        Invoke-TestProgram $Php @('tests/http.php')
        if($TerminalHttpTest) {
            if(-not (Test-Path -LiteralPath $TerminalHttpTest)){throw 'Build the native HTTP test executable first'}
            $listener=[System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback,0);$listener.Start();$httpPort=$listener.LocalEndpoint.Port;$listener.Stop()
            $env:ZPX_TERMINAL_HTTP_TEST=[IO.Path]::GetFullPath($TerminalHttpTest);$env:ZPX_TERMINAL_HTTP_FIXTURE=Join-Path $testRoot 'native-http.json';$env:ZPX_TERMINAL_HTTP_ENDPOINT="http://127.0.0.1:$httpPort/"
        }
        Invoke-TestProgram $Php @('-d','display_errors=1','tests/integration.php')
    } finally { Pop-Location }
} finally {
    if ($started) { & (Join-Path $postgresBin 'pg_ctl.exe') -D (Join-Path $testRoot 'data') -m fast -w stop | Out-Null }
    if (Test-Path -LiteralPath $passwordFile) { Remove-Item -LiteralPath $passwordFile }
    if ($createdFixture -and (Test-Path -LiteralPath $fixture)) { Remove-Item -LiteralPath $fixture }
    foreach ($name in $previous.Keys) { [Environment]::SetEnvironmentVariable($name,$previous[$name],'Process') }
    Write-Host "Test cluster stopped. Local diagnostics retained under $testRoot. Shared Mac DB was never used."
}
