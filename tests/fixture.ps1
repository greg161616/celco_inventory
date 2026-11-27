param (
    [string]$Xampp = 'C:\xampp',
    [ValidateRange(1024,65535)][int]$Port = 0,
    [string]$Stop
)
$ErrorActionPreference = 'Stop'
$php = Join-Path $Xampp 'php\php.exe'
$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')

if ($Stop) {
    $fixture = Get-Content -LiteralPath $Stop -Raw | ConvertFrom-Json
    $resolved = (Resolve-Path -LiteralPath $fixture.directory).Path
    if ([IO.Path]::GetDirectoryName($resolved) -ne $tempRoot -or
        [IO.Path]::GetFileName($resolved) -notmatch '^celco-qa-[a-f0-9]{32}$' -or
        (Resolve-Path -LiteralPath $Stop).Path -ne (Join-Path $resolved 'fixture.json')) {
        throw 'The supplied file does not identify a temporary CELCO fixture.'
    }
    & $php (Join-Path $PSScriptRoot 'schema.php') shutdown $Stop
    if ($LASTEXITCODE -ne 0) { throw 'Fixture identity verification or shutdown failed.' }
    $process = Get-Process -Id $fixture.processId -ErrorAction SilentlyContinue
    if ($process -and -not $process.WaitForExit(15000)) { throw 'Fixture shutdown is still pending.' }
    Remove-Item -LiteralPath $resolved -Force -Recurse
    return
}

if ($Port -eq 0) {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
    $listener.Start()
    $Port = $listener.LocalEndpoint.Port
    $listener.Stop()
}
if ($Port -eq 3306) { throw 'Choose a dedicated test port.' }
$fixtureRoot = Join-Path $tempRoot ('celco-qa-' + [Guid]::NewGuid().ToString('N'))
$dataDir = Join-Path $fixtureRoot 'data'
$null = New-Item -ItemType Directory -Path $fixtureRoot
$server = Join-Path $Xampp 'mysql\bin\mysqld.exe'
$installer = Join-Path $Xampp 'mysql\bin\mysql_install_db.exe'
& $installer "--datadir=$dataDir" "--port=$Port" --silent | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'MariaDB could not initialize the isolated data directory.' }

$random = [Security.Cryptography.RandomNumberGenerator]::Create()
$bytes = New-Object byte[] 32
$random.GetBytes($bytes)
$random.Dispose()
$password = ([BitConverter]::ToString($bytes)).Replace('-','')
$secretFile = Join-Path $fixtureRoot 'bootstrap.sql'
$utf8 = New-Object Text.UTF8Encoding($false)
$bootstrapSql = @"
UPDATE mysql.global_priv SET Priv = JSON_SET(Priv, '$.plugin', 'mysql_native_password', '$.authentication_string', PASSWORD('$password')) WHERE User='root';
FLUSH PRIVILEGES;
"@
[IO.File]::WriteAllText($secretFile,$bootstrapSql, $utf8)
$arguments = @(
    '--no-defaults', '--standalone', "--basedir=`"$(Join-Path $Xampp 'mysql')`"", "--datadir=`"$dataDir`"",
    "--port=$Port", "--socket=celco-fixture-$Port", '--bind-address=127.0.0.1', '--skip-name-resolve',
    '--innodb-buffer-pool-size=32M', '--max-connections=32', '--table-open-cache=128',
    "--init-file=`"$secretFile`"", "--log-error=`"$(Join-Path $fixtureRoot 'server.log')`""
)
$process = Start-Process -FilePath $server -ArgumentList $arguments -WindowStyle Hidden -PassThru
$manifest = Join-Path $fixtureRoot 'fixture.json'
$configuration = @{
    host = '127.0.0.1'; port = $Port; database = 'celco_ims_test'; user = 'root'
    password = $password; directory = $fixtureRoot; processId = $process.Id
}
[IO.File]::WriteAllText($manifest, ($configuration | ConvertTo-Json), $utf8)
try {
    & $php (Join-Path $PSScriptRoot 'schema.php') initialize $manifest
    if ($LASTEXITCODE -ne 0) { throw "Could not initialize the test schema. See $fixtureRoot\server.log." }
} catch {
    if (-not $process.HasExited) { $process.Kill(); $null = $process.WaitForExit(15000) }
    throw
} finally {
    Remove-Item -LiteralPath $secretFile -Force
}
Write-Output $manifest
