param(
    [switch]$NoBrowser
)

$ErrorActionPreference = 'Stop'

$appRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$dashboardUrl = 'http://127.0.0.1:4173/'
$logDirectory = Join-Path $appRoot 'data'

function Test-LinkVagt {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri "$dashboardUrl`api/summary" -TimeoutSec 2
        return $response.StatusCode -eq 200
    }
    catch {
        return $false
    }
}

function Show-LinkVagtError([string]$message) {
    $shell = New-Object -ComObject WScript.Shell
    $null = $shell.Popup($message, 0, 'LinkVagt kunne ikke starte', 16)
}

try {
    if (-not (Test-LinkVagt)) {
        $node = (Get-Command node.exe -ErrorAction Stop).Source
        New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null

        $process = Start-Process `
            -FilePath $node `
            -ArgumentList 'src/server.js' `
            -WorkingDirectory $appRoot `
            -WindowStyle Hidden `
            -RedirectStandardOutput (Join-Path $logDirectory 'server.log') `
            -RedirectStandardError (Join-Path $logDirectory 'server-error.log') `
            -PassThru

        $ready = $false
        for ($attempt = 0; $attempt -lt 40; $attempt++) {
            Start-Sleep -Milliseconds 250
            if ($process.HasExited) {
                throw 'LinkVagt-processen stoppede under opstart.'
            }
            if (Test-LinkVagt) {
                $ready = $true
                break
            }
        }

        if (-not $ready) {
            Stop-Process -Id $process.Id -ErrorAction SilentlyContinue
            throw 'LinkVagt svarede ikke inden for 10 sekunder.'
        }
    }

    if (-not $NoBrowser) {
        Start-Process $dashboardUrl
    }
}
catch {
    Show-LinkVagtError "LinkVagt kunne ikke startes.`n`n$($_.Exception.Message)"
    exit 1
}
