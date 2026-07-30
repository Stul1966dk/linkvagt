param()

$ErrorActionPreference = 'Stop'
$appRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$dashboardUrl = 'http://127.0.0.1:4173/'

try {
    $connection = Get-NetTCPConnection -LocalPort 4173 -State Listen -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($connection) {
        Write-Host 'Stopper LinkVagt...'
        Stop-Process -Id $connection.OwningProcess
        Start-Sleep -Seconds 2
    }

    Set-Location $appRoot
    & node.exe 'scripts/restore-backup.mjs'
    $restoreResult = $LASTEXITCODE

    & (Join-Path $appRoot 'Start-LinkVagt.ps1') -NoBrowser
    if ($restoreResult -eq 0) {
        Start-Process $dashboardUrl
        Write-Host "`nGendannelsen er færdig, og LinkVagt er startet igen." -ForegroundColor Green
    }
    elseif ($restoreResult -eq 2) {
        Write-Host "`nIngen data blev ændret. LinkVagt er startet igen." -ForegroundColor Yellow
    }
    else {
        throw "Gendannelsen fejlede med kode $restoreResult. LinkVagt blev startet med de eksisterende data."
    }
}
catch {
    Write-Host "`n$($_.Exception.Message)" -ForegroundColor Red
}

Read-Host "`nTryk Enter for at lukke"

