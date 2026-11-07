@echo off
title Stop CallGen Dashboard
cd /d "%~dp0"

echo Stopping local CallGen server on port 3000...

for /f "tokens=5" %%a in ('netstat -aon ^| findstr :3000 ^| findstr LISTENING') do (
    taskkill /F /PID %%a >nul 2>&1
)

echo CallGen local server stopped.
pause

