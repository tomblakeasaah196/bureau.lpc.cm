<#
.SYNOPSIS
    Bureau LPC ERP -- build and verify a release zip before it ever reaches the server.

.DESCRIPTION
    Replaces the old copy/paste PowerShell block for packaging a release. The
    v1.0.0 zip shipped without api/v1/ and assets/css/tailwind.css and that
    wasn't caught until deploy.sh ran on the live server -- by then the old
    folder had already been moved aside and the site was down. This script
    verifies the zip's actual contents (not just the source folder's) before
    you ever run scp/upload, so a bad zip never leaves your laptop.

    v2: external commands (composer/npm/npx) no longer fail silently --
    PowerShell does NOT treat a non-zero exit code from a native command as a
    terminating error even with $ErrorActionPreference = "Stop", so v1 kept
    going after `npm ci` failed and shipped a stale tailwind.css without
    telling you. Every native call now checks $LASTEXITCODE explicitly.
    The zip-content check also no longer assumes Compress-Archive prefixes
    entries with the folder name -- it checks both conventions and prints
    sample entries so you can see the real structure.

.PARAMETER Version
    Version tag used in the zip filename, e.g. v1.0.1

.EXAMPLE
    .\scripts\package-release.ps1 -Version v1.0.1
#>
param(
    [Parameter(Mandatory=$true)][string]$Version
)

$ErrorActionPreference = "Stop"
$repoRoot = Split-Path -Parent $PSScriptRoot
$stamp    = Get-Date -Format "yyyyMMdd-HHmm"
$zipName  = "bureau.lpc.cm-$Version-$stamp.zip"
$zipPath  = Join-Path $env:USERPROFILE "Desktop\$zipName"

function Section($msg) { Write-Host "`n== $msg ==" -ForegroundColor Cyan }

function Invoke-Checked($exe, $argsArray) {
    Write-Host "  > $exe $($argsArray -join ' ')"
    & $exe @argsArray
    if ($LASTEXITCODE -ne 0) {
        throw "'$exe $($argsArray -join ' ')' failed with exit code $LASTEXITCODE"
    }
}

Section "1. PHP deps (composer install)"
Push-Location $repoRoot
try {
    Invoke-Checked "composer" @("install", "--no-dev", "--optimize-autoloader")
} finally {
    Pop-Location
}
if (-not (Test-Path "$repoRoot\vendor\autoload.php")) {
    throw "vendor/autoload.php missing after composer install. Aborting."
}

Section "2. Tailwind build"
$rebuilt = $false
Push-Location $repoRoot
try {
    if (Test-Path "package-lock.json") {
        Invoke-Checked "npm" @("ci")
    } else {
        Write-Host "  no package-lock.json in this repo -- 'npm ci' can't run. Using 'npm install' instead." -ForegroundColor Yellow
        Write-Host "  (commit the package-lock.json this generates so future runs can use the faster/safer 'npm ci'.)" -ForegroundColor Yellow
        Invoke-Checked "npm" @("install")
    }
    Invoke-Checked "npx" @("tailwindcss", "-i", "./assets/css/src/input.css", "-o", "./assets/css/tailwind.css", "--minify")
    $rebuilt = $true
} catch {
    Write-Host "  Tailwind rebuild failed: $($_.Exception.Message)" -ForegroundColor Yellow
    Write-Host "  Falling back to the tailwind.css already committed in the repo (it's committed on purpose -- see .gitignore)." -ForegroundColor Yellow
    Write-Host "  If you changed any Tailwind classes since the last commit, this zip will ship STALE CSS. Fix npm/node and re-run if so." -ForegroundColor Yellow
} finally {
    Pop-Location
}
if (-not (Test-Path "$repoRoot\assets\css\tailwind.css")) {
    throw "No assets/css/tailwind.css available at all (build failed and none is committed). Cannot proceed."
}
$cssSize = (Get-Item "$repoRoot\assets\css\tailwind.css").Length
$builtNote = if ($rebuilt) { "(freshly built)" } else { "(EXISTING committed file -- not rebuilt this run)" }
Write-Host "  tailwind.css: $cssSize bytes $builtNote"
if ($cssSize -lt 1000) { throw "tailwind.css is essentially empty ($cssSize bytes). Aborting." }

Section "3. Stage a trimmed copy"
$stagingParent = Join-Path $env:TEMP "bureau_release_$stamp"
$staging = Join-Path $stagingParent "bureau.lpc.cm"
New-Item -ItemType Directory -Force -Path $stagingParent | Out-Null
Copy-Item -Recurse -Force $repoRoot $staging

Remove-Item -Recurse -Force -ErrorAction SilentlyContinue `
  "$staging\.env", "$staging\node_modules", "$staging\docs\archive", "$staging\.git"
Get-ChildItem -Path $staging -Recurse -Force -File -Include *.log,.DS_Store,error_log -ErrorAction SilentlyContinue `
  | Remove-Item -Force -ErrorAction SilentlyContinue

Section "4. Compare source vs staged file counts (catches silent drops)"
$excludePattern = '\\node_modules\\|\\\.git(\\|$)|\\docs\\archive\\'
$srcCount = (Get-ChildItem $repoRoot -Recurse -File | Where-Object {
    $_.FullName -notmatch $excludePattern -and $_.Name -ne '.env'
}).Count
$stagedCount = (Get-ChildItem $staging -Recurse -File).Count
Write-Host "  source: $srcCount   staged: $stagedCount"
if ([Math]::Abs($srcCount - $stagedCount) -gt 5) {
    throw "File count mismatch (source=$srcCount staged=$stagedCount). Something was silently dropped during staging -- investigate before zipping."
}

Section "5. Zip"
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
Compress-Archive -Path $staging -DestinationPath $zipPath -Force

Section "6. Verify the ZIP itself contains the critical paths"
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
$entries = $zip.Entries.FullName
Write-Host "  total entries in zip: $($entries.Count)"
Write-Host "  sample entries (so you can see the real structure):"
$entries | Select-Object -First 8 | ForEach-Object { Write-Host "    $_" }

# Don't assume whether Compress-Archive prefixed entries with "bureau.lpc.cm/"
# or zipped the folder's contents at the archive root -- check both forms.
$mustHaveRel = @(
  'index.php',
  'api/v1/auth.php',
  'api/v1/rbac_controller.php',
  'assets/css/tailwind.css',
  'vendor/autoload.php',
  'composer.lock',
  'migrations/000_init_schema_migrations.sql',
  'migrations/027_signer_otp.sql',
  'scripts/migrate.php',
  'scripts/deploy.sh'
)
function Test-ZipHasRelPath($entryList, $rel) {
    $relFwd = $rel.Replace('\', '/')
    foreach ($e in $entryList) {
        $eFwd = $e.Replace('\', '/')
        if ($eFwd -eq $relFwd -or $eFwd.EndsWith("/$relFwd")) { return $true }
    }
    return $false
}
$missing = @($mustHaveRel | Where-Object { -not (Test-ZipHasRelPath $entries $_) })
$zip.Dispose()
if ($missing.Count -gt 0) {
    Write-Host "  MISSING FROM ZIP:" -ForegroundColor Red
    $missing | ForEach-Object { Write-Host "    $_" -ForegroundColor Red }
    Remove-Item -Recurse -Force $stagingParent -ErrorAction SilentlyContinue
    throw "Zip is missing required files -- DO NOT UPLOAD. Compare against the sample entries printed above."
}
Write-Host "  All required paths present." -ForegroundColor Green

Section "7. SHA-256"
$hash = Get-FileHash $zipPath -Algorithm SHA256
Write-Host "  $($hash.Hash)  $zipName"

Remove-Item -Recurse -Force $stagingParent -ErrorAction SilentlyContinue

Write-Host ""
Write-Host "READY: $zipPath" -ForegroundColor Green
Write-Host "SHA256: $($hash.Hash)"
Write-Host "Upload with:  scp `"$zipPath`" smartqaq@<host>:~/"
