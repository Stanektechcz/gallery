[CmdletBinding()]
param(
    [string] $Repository = 'Stanektechcz/gallery',
    [string] $Alias = 'maki',
    [string] $VersionName = '1.0.0',
    [int] $VersionCode = 1,
    [switch] $RunWorkflow
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Find-Keytool {
    $command = Get-Command keytool -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }

    $java = Get-Command java -ErrorAction SilentlyContinue
    if ($java) {
        $javaItem = Get-Item -LiteralPath $java.Source -Force
        $javaTarget = @($javaItem.Target) | Select-Object -First 1
        if ($javaTarget) {
            $candidate = Join-Path (Split-Path -Parent ([string] $javaTarget)) 'keytool.exe'
            if (Test-Path -LiteralPath $candidate) { return $candidate }
        }

        # Java intentionally writes version/property diagnostics to stderr. Merge
        # both streams inside cmd.exe before PowerShell sees them. This avoids both
        # NativeCommandError and the stdout/stderr pipe deadlock of Windows PS 5.1.
        $startInfo = [Diagnostics.ProcessStartInfo]::new()
        $startInfo.FileName = $env:ComSpec
        $startInfo.Arguments = "/d /s /c `"`"$($java.Source)`" -XshowSettings:properties -version 2>&1`""
        $startInfo.UseShellExecute = $false
        $startInfo.CreateNoWindow = $true
        $startInfo.RedirectStandardOutput = $true
        $process = [Diagnostics.Process]::new()
        $process.StartInfo = $startInfo
        [void] $process.Start()
        $settings = $process.StandardOutput.ReadToEnd()
        $process.WaitForExit()
        $match = [regex]::Match($settings, '(?m)^\s*java\.home\s*=\s*(.+?)\s*$')
        if ($match.Success) {
            $candidate = Join-Path $match.Groups[1].Value.Trim() 'bin\keytool.exe'
            if (Test-Path -LiteralPath $candidate) { return $candidate }
        }
    }

    throw 'Keytool nebyl nalezen. Nainstalujte JDK 17 a spusťte skript znovu.'
}

function New-RandomPassword {
    $bytes = [byte[]]::new(32)
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) }
    finally { $generator.Dispose() }
    return -join ($bytes | ForEach-Object { $_.ToString('x2') })
}

function Set-RepositorySecret([string] $Name, [string] $Value) {
    $startInfo = [Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = (Get-Command gh -ErrorAction Stop).Source
    $startInfo.Arguments = "secret set $Name --repo $Repository"
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardInput = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $startInfo
    [void] $process.Start()
    $process.StandardInput.Write($Value)
    $process.StandardInput.Close()
    $stdout = $process.StandardOutput.ReadToEnd()
    $stderr = $process.StandardError.ReadToEnd()
    $process.WaitForExit()
    if ($process.ExitCode -ne 0) {
        throw "GitHub secret $Name se nepodařilo nastavit: $stderr$stdout"
    }
}

if ($VersionName -notmatch '^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$') {
    throw 'VersionName obsahuje nepovolené znaky.'
}
if ($VersionCode -lt 1) { throw 'VersionCode musí být kladné celé číslo.' }
if ($Alias -notmatch '^[0-9A-Za-z._-]{1,64}$') { throw 'Alias klíče obsahuje nepovolené znaky.' }
if ($Repository -notmatch '^[0-9A-Za-z_.-]+/[0-9A-Za-z_.-]+$') { throw 'Repository musí být ve formátu vlastník/název.' }

if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
    throw 'GitHub CLI nebylo nalezeno. Nainstalujte jej z https://cli.github.com/.'
}

& gh auth status
if ($LASTEXITCODE -ne 0) {
    throw 'GitHub CLI není přihlášené. Nejprve spusťte: gh auth login'
}

$keytool = Find-Keytool
$signingRoot = Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'MakiGallery\AndroidSigning'
$keystorePath = Join-Path $signingRoot 'maki-release.keystore'
$passwordPath = Join-Path $signingRoot 'password.dpapi'
New-Item -ItemType Directory -Path $signingRoot -Force | Out-Null

if ((Test-Path -LiteralPath $keystorePath) -xor (Test-Path -LiteralPath $passwordPath)) {
    throw "V $signingRoot je neúplná předchozí konfigurace. Obnovte dvojici keystore + password.dpapi ze zálohy nebo ji bezpečně odstraňte."
}

if (-not (Test-Path -LiteralPath $keystorePath)) {
    $password = New-RandomPassword
    $previousErrorPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        & $keytool -genkeypair -noprompt `
            -storetype PKCS12 `
            -keystore $keystorePath `
            -alias $Alias `
            -keyalg RSA `
            -keysize 3072 `
            -sigalg SHA256withRSA `
            -validity 10000 `
            -storepass $password `
            -keypass $password `
            -dname 'CN=Maki Gallery, OU=Android, O=Stanektech, L=Brno, C=CZ' 2>&1 | Write-Verbose
        $keytoolExitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorPreference
    }
    if ($keytoolExitCode -ne 0 -or -not (Test-Path -LiteralPath $keystorePath)) {
        throw 'Release keystore se nepodařilo vytvořit.'
    }

    $encryptedPassword = ConvertTo-SecureString $password -AsPlainText -Force | ConvertFrom-SecureString
    Set-Content -LiteralPath $passwordPath -Value $encryptedPassword -Encoding UTF8 -NoNewline
} else {
    $encryptedPassword = Get-Content -LiteralPath $passwordPath -Raw -Encoding UTF8
    $securePassword = ConvertTo-SecureString $encryptedPassword
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    try { $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
}

try {
    $previousErrorPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        & $keytool -list -storetype PKCS12 -keystore $keystorePath -alias $Alias -storepass $password 2>&1 | Write-Verbose
        $keytoolExitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorPreference
    }
    if ($keytoolExitCode -ne 0) { throw 'Release keystore nebo jeho lokální heslo nejsou platné.' }

    $keystoreBase64 = [Convert]::ToBase64String([IO.File]::ReadAllBytes($keystorePath))
    Set-RepositorySecret 'ANDROID_KEYSTORE_BASE64' $keystoreBase64
    Set-RepositorySecret 'ANDROID_KEYSTORE_PASSWORD' $password
    Set-RepositorySecret 'ANDROID_KEY_PASSWORD' $password
    Set-RepositorySecret 'ANDROID_KEY_ALIAS' $Alias
} finally {
    $password = $null
    $keystoreBase64 = $null
}

Write-Host ''
Write-Host 'Android signing secrets byly bezpečně nastaveny.' -ForegroundColor Green
Write-Host "Release keystore: $keystorePath"
Write-Warning 'Keystore i password.dpapi nyní zazálohujte společně na bezpečné offline místo. Bez nich nepůjde aplikaci aktualizovat.'

if ($RunWorkflow) {
    & gh workflow run build-android-app.yml `
        --repo $Repository `
        --ref main `
        -f "version_name=$VersionName" `
        -f "version_code=$VersionCode"
    if ($LASTEXITCODE -ne 0) { throw 'Android workflow se nepodařilo spustit.' }
    Write-Host 'Workflow Build signed Android app byl spuštěn.' -ForegroundColor Green
}
