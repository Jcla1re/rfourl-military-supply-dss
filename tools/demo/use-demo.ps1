# Switch this checkout to the local demo setup: backend-ci4\.env  <-  backend-ci4\.env.demo
# Your normal .env is saved once as .env.normal (never overwritten while demo mode is on).
$ErrorActionPreference = 'Stop'
$root   = Join-Path $PSScriptRoot '..\..\backend-ci4' | Resolve-Path
$env    = Join-Path $root '.env'
$normal = Join-Path $root '.env.normal'
$demo   = Join-Path $root '.env.demo'

if (-not (Test-Path $demo)) { throw ".env.demo not found in $root. See backend-ci4\env.demo.example." }
if ((Test-Path $env) -and (Select-String -Path $env -Pattern '^\s*DEMO_MODE\s*=\s*true' -Quiet)) {
    Write-Host 'Already in demo mode.'; return
}
Copy-Item $env $normal -Force
Copy-Item $demo $env -Force
Write-Host 'Demo mode ON  (.env now points at the demo database). Switch back with tools\demo\use-normal.ps1'
