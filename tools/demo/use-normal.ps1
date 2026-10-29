# Switch this checkout back to your normal setup: backend-ci4\.env  <-  backend-ci4\.env.normal
$ErrorActionPreference = 'Stop'
$root   = Join-Path $PSScriptRoot '..\..\backend-ci4' | Resolve-Path
$env    = Join-Path $root '.env'
$normal = Join-Path $root '.env.normal'

if (-not (Test-Path $normal)) { Write-Host 'Nothing to restore: you are already on your normal .env.'; return }
Copy-Item $normal $env -Force
Remove-Item $normal
Write-Host 'Demo mode OFF (normal .env restored).'
