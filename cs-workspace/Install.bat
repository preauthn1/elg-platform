@echo off
:: CS Workspace installer (admin stage) - auto elevate and run setup_cs_env.ps1
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process powershell -ArgumentList '-NoProfile -ExecutionPolicy Bypass -File \"%~dp0setup_cs_env.ps1\"' -Verb RunAs"
