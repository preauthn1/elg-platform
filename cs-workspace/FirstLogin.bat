@echo off
:: CS Workspace first-login setup (run as the CS user, no admin needed)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup_user.ps1"
pause
