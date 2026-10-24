@echo off
:: CS Workspace manual upgrade - align marketplace and apply latest managed settings
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0update_cs_env.ps1"
pause
