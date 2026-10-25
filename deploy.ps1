<#
.SYNOPSIS
  Deploy static site to remote server via SSH.
.DESCRIPTION
  Reads config from .env, packages the project root and
  syncs to remote server via tar over SSH.
.PARAMETER DryRun
  Show commands without executing.
.EXAMPLE
  .\deploy.ps1
  .\deploy.ps1 -DryRun
#>

param(
  [switch]$DryRun
)

$ErrorActionPreference = 'Stop'

# --- Deploy marker: writes version.json into the deployed payload ---
# Records commit/branch/tree/dirty/deployedAt/via so the deployed content can be
# compared with the local repository later (see na-web/check-deploy.ps1).
function New-DeployMarker {
  param(
    [Parameter(Mandatory)][string]$ProjectDir,
    [Parameter(Mandatory)][string]$OutFile,
    [string]$Via = 'deploy.ps1'
  )
  $commit = $null; $branch = $null; $tree = $null; $dirty = $null; $changed = $null
  if (Test-Path (Join-Path $ProjectDir '.git')) {
    $prevEap = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
      $commit  = (& git -C $ProjectDir rev-parse HEAD 2>$null)
      $branch  = (& git -C $ProjectDir rev-parse --abbrev-ref HEAD 2>$null)
      $tracked   = @(& git -C $ProjectDir diff HEAD --numstat 2>$null)
      $untracked = @(& git -C $ProjectDir ls-files -o --exclude-standard 2>$null)
      $changed   = $tracked.Count + $untracked.Count
      $dirty     = ($changed -gt 0)
      if ($dirty) {
        $stash = (& git -C $ProjectDir stash create 2>$null)
        if ($stash) { $tree = (& git -C $ProjectDir rev-parse "$($stash.Trim())^{tree}" 2>$null) }
      } else {
        $tree = (& git -C $ProjectDir rev-parse 'HEAD^{tree}' 2>$null)
      }
    } finally { $ErrorActionPreference = $prevEap }
  }
  $marker = [ordered]@{
    project    = Split-Path -Leaf $ProjectDir
    commit     = if ($commit) { $commit.Trim() } else { $null }
    branch     = if ($branch) { $branch.Trim() } else { $null }
    tree       = if ($tree)   { $tree.Trim() }   else { $null }
    dirty      = $dirty
    dirtyFiles = $changed
    deployedAt = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    deployedBy = $env:USERNAME
    via        = $Via
  }
  New-Item -ItemType Directory -Force -Path (Split-Path -Parent $OutFile) | Out-Null
  [System.IO.File]::WriteAllText($OutFile, ($marker | ConvertTo-Json -Depth 3),
    (New-Object System.Text.UTF8Encoding($false)))
}

# --- 1. Load .env ---
$envFile = Join-Path $PSScriptRoot '.env'
if (Test-Path $envFile) {
  Get-Content $envFile | ForEach-Object {
    if ($_ -match '^\s*([^#=]+?)\s*=\s*(.+?)\s*$') {
      [Environment]::SetEnvironmentVariable($matches[1], $matches[2])
    }
  }
}

$sshHost  = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_HOST')
$sshPort  = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_PORT')
if (-not $sshPort) { $sshPort = '22' }
$sshUser  = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_USER')
$remotePath = [Environment]::GetEnvironmentVariable('DEPLOY_REMOTE_PATH')
if ($remotePath) { $remotePath = $remotePath.TrimEnd('/') }

if (-not $sshHost -or -not $sshUser -or -not $remotePath) {
  Write-Host "ERROR: Set DEPLOY_SSH_HOST, DEPLOY_SSH_USER and DEPLOY_REMOTE_PATH in .env" -ForegroundColor Red
  exit 1
}

$identityFile = [Environment]::GetEnvironmentVariable('DEPLOY_SSH_KEY')
if ($identityFile -and (Test-Path $identityFile)) {
  $identityFile = (Resolve-Path $identityFile).Path
}

$remote = "${sshUser}@${sshHost}"

# Build SSH base args
$sshArgs = @()
if ($sshPort -ne '22') { $sshArgs += "-P $sshPort" }
if ($identityFile) { $sshArgs += "-i"; $sshArgs += "`"$identityFile`"" }
$sshArgStr = $sshArgs -join ' '

$scpArgs = @()
if ($sshPort -ne '22') { $scpArgs += "-P $sshPort" }
if ($identityFile) { $scpArgs += "-i"; $scpArgs += "`"$identityFile`"" }
$scpArgStr = $scpArgs -join ' '

# Fix SSH key permissions (Windows OpenSSH requires restrictive ACLs)
if ($identityFile -and (Test-Path $identityFile)) {
  $identityFullPath = (Resolve-Path $identityFile).Path
  icacls $identityFullPath /reset 2>$null
  icacls $identityFullPath /inheritance:r 2>$null
  icacls $identityFullPath /grant "${env:USERNAME}:(R)" 2>$null
}

# --- 2. Ensure remote db/archive directories exist (outside docroot) ---
$remoteDbPath = $remotePath -replace '/public/?$', '/db'
$remoteArchivePath = $remotePath -replace '/public/?$', '/archive'
$siteUrl = [Environment]::GetEnvironmentVariable('SITE_URL')

if (-not $DryRun) {
  Write-Host "`n==> Ensuring remote db/archive directories exist ..." -ForegroundColor Cyan
  $mkdirArgs = @()
  if ($sshPort -ne '22') { $mkdirArgs += "-P $sshPort" }
  if ($identityFile) { $mkdirArgs += "-i"; $mkdirArgs += $identityFile }
  $mkdirArgs += $remote
  $mkdirArgs += "mkdir -p $remoteDbPath $remoteArchivePath 2>/dev/null; chmod 775 $remoteDbPath $remoteArchivePath 2>/dev/null; chown www-data:www-data $remoteDbPath $remoteArchivePath 2>/dev/null || true"
  & ssh @mkdirArgs
}

# --- 2.5. Upload .env OUTSIDE docroot (secrets must not live in public/) ---
$envLocal = Join-Path $PSScriptRoot '.env'
if (-not $DryRun -and (Test-Path $envLocal)) {
  $envRemoteDir = $remotePath -replace '/[^/]+$', ''
  Write-Host "`n==> Uploading .env to ${remote}:${envRemoteDir}/.env (outside docroot) ..." -ForegroundColor Cyan
  $scpEnvCmd = "scp $scpArgStr `"$envLocal`" ${remote}:${envRemoteDir}/.env"
  cmd /c $scpEnvCmd
  if ($LASTEXITCODE -ne 0) {
    Write-Host "  WARNING: .env upload failed - admin login via file won't work on server" -ForegroundColor Yellow
  }
}

# --- Deploy marker ---
$markerDir = Join-Path $env:TEMP "marker-$(Get-Random)"
New-DeployMarker -ProjectDir $PSScriptRoot -OutFile (Join-Path $markerDir 'version.json')

# --- 3. Deploy via tar + ssh ---
Write-Host "`n==> Deploying to ${remote}:${remotePath} ..." -ForegroundColor Cyan

if ($DryRun) {
  Write-Host "  [DryRun] tar -czf - -C `"$PSScriptRoot`" . | ssh $sshArgStr $remote <remote-script>" -ForegroundColor Yellow
} else {
  Write-Host "  Archiving and transferring..." -ForegroundColor Gray

  $targz = Join-Path $env:TEMP "deploy-$(Get-Random).tar.gz"
  try {
    & tar -czf $targz -C $PSScriptRoot --exclude '.git' --exclude 'node_modules' --exclude '*.tar.gz' --exclude '.env' --exclude 'deploy.ps1' --exclude 'AGENTS.MD' --exclude 'link.txt' --exclude 'r.nayanovaacademy.ru' --exclude 'fi.jpeg' --exclude 'archive' --exclude '*.xlsm' --exclude '*.xlsx' . -C "$markerDir" version.json
    if ($LASTEXITCODE -ne 0) {
      Write-Host "  Archive creation failed" -ForegroundColor Red
      exit 1
    }

    $bytes = [System.IO.File]::ReadAllBytes($targz)
    $remoteCmd = "rm -rf ${remotePath}/* ${remotePath}/.[!.]* 2>/dev/null; mkdir -p ${remotePath} 2>/dev/null; tar -xzf - -C ${remotePath}"

    $procArgs = @()
    if ($sshPort -ne '22') { $procArgs += "-P $sshPort" }
    if ($identityFile) { $procArgs += "-i"; $procArgs += $identityFile }
    $procArgs += $remote
    $procArgs += $remoteCmd

    $psi = New-Object System.Diagnostics.ProcessStartInfo('ssh', $procArgs)
    $psi.RedirectStandardInput = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $proc = [System.Diagnostics.Process]::Start($psi)

    try {
      $proc.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)
      $proc.StandardInput.Close()
    } catch [System.IO.IOException] {
      $stderr = $proc.StandardError.ReadToEnd()
      $proc.WaitForExit()
      Write-Host "  Deploy failed: $($_.Exception.Message)" -ForegroundColor Red
      if ($stderr) { Write-Host "  SSH: $stderr" -ForegroundColor Red }
      exit 1
    }

    $stdoutTask = $proc.StandardOutput.ReadToEndAsync()
    $stderrTask = $proc.StandardError.ReadToEndAsync()
    $proc.WaitForExit()
    $stdout = $stdoutTask.Result
    $stderr = $stderrTask.Result

    if ($proc.ExitCode -ne 0) {
      Write-Host "  Deploy failed (exit code: $($proc.ExitCode))" -ForegroundColor Red
      if ($stdout) { Write-Host "  SSH stdout: $stdout" -ForegroundColor Red }
      if ($stderr) { Write-Host "  SSH stderr: $stderr" -ForegroundColor Red }
      exit 1
    }
  } finally {
    Remove-Item $targz -ErrorAction SilentlyContinue
  }

  Write-Host "  Done." -ForegroundColor Green
}

# --- Clean up the temporary marker directory ---
Remove-Item -Recurse -Force $markerDir -ErrorAction SilentlyContinue

# --- 4. Deploy nginx config ---
$nginxSite = 'r.nayanovaacademy.ru'
$nginxLocal = Join-Path $PSScriptRoot $nginxSite
$nginxRemote = '/etc/nginx/sites-available/' + $nginxSite

if ($DryRun) {
  Write-Host "  [DryRun] Deploy nginx config: $nginxSite" -ForegroundColor Yellow
} elseif (Test-Path $nginxLocal) {
  Write-Host "`n==> Deploying nginx config ($nginxSite) ..." -ForegroundColor Cyan

  $scpCmd = "scp $scpArgStr `"$nginxLocal`" ${remote}:/tmp/nginx-$nginxSite"
  cmd /c $scpCmd
  if ($LASTEXITCODE -ne 0) { Write-Host "  Nginx config scp failed" -ForegroundColor Red; exit 1 }

  # Установка через root-хелпер: он сам валидирует nginx -t и откатывает
  # конфиг при ошибке.
  $nginxDeploy = "sudo -n /usr/local/sbin/deploy-nginx.sh $nginxSite"
  $sshNginxArgs = @()
  if ($sshPort -ne '22') { $sshNginxArgs += "-P $sshPort" }
  if ($identityFile) { $sshNginxArgs += "-i"; $sshNginxArgs += $identityFile }
  $sshNginxArgs += $remote
  $sshNginxArgs += $nginxDeploy
  & ssh @sshNginxArgs

  if ($LASTEXITCODE -ne 0) {
    Write-Host "  nginx deploy failed (config not applied). Deploy aborted." -ForegroundColor Red
    exit 1
  }
  Write-Host "  Done." -ForegroundColor Green
}

# --- 5. Setup cron job ---
$cronUser = [Environment]::GetEnvironmentVariable('DEPLOY_CRON_USER')
if (-not $cronUser) { $cronUser = 'www-data' }
$cronLog = [Environment]::GetEnvironmentVariable('DEPLOY_CRON_LOG')
if (-not $cronLog) { $cronLog = '/var/log/r-web-import.log' }
$cronContent = "*/5 * * * * $cronUser php ${remotePath}/cron_import.php >> $cronLog 2>&1"

if ($DryRun) {
  Write-Host "`n  [DryRun] Setup cron job for automatic import" -ForegroundColor Yellow
} else {
  Write-Host "`n==> Setting up cron job ..." -ForegroundColor Cyan

  $cronCmd = "echo '$cronContent' > /etc/cron.d/r-web && chmod 644 /etc/cron.d/r-web && touch $cronLog && chown ${cronUser}:${cronUser} $cronLog 2>/dev/null || true"
  $sshCronArgs = @()
  if ($sshPort -ne '22') { $sshCronArgs += "-P $sshPort" }
  if ($identityFile) { $sshCronArgs += "-i"; $sshCronArgs += $identityFile }
  $sshCronArgs += $remote
  $sshCronArgs += $cronCmd
  & ssh @sshCronArgs

  if ($LASTEXITCODE -ne 0) {
    Write-Host "  Cron setup failed (non-fatal)" -ForegroundColor Yellow
  } else {
    Write-Host "  Done." -ForegroundColor Green
  }
}

# --- 6. Smoke check ---
if (-not $DryRun -and $siteUrl) {
  Write-Host "`n==> Smoke check..." -ForegroundColor Cyan
  try {
    $resp = Invoke-WebRequest -Uri $siteUrl -Method Head -TimeoutSec 30 -UseBasicParsing
    if ($resp.StatusCode -eq 200) {
      Write-Host "  Site responds with HTTP 200." -ForegroundColor Green
    } else {
      Write-Host "  WARNING: site responded with HTTP $($resp.StatusCode)" -ForegroundColor Yellow
    }
  } catch {
    Write-Host "  WARNING: smoke check failed: $($_.Exception.Message)" -ForegroundColor Yellow
  }
}

Write-Host "`n==> Deploy complete" -ForegroundColor Green
