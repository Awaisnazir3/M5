@echo off
title CallGen Dashboard Launcher
cd /d "%~dp0"

echo ===================================================
echo     Starting Call Generator Local Dashboard...
echo ===================================================

:: Start Node.js local server in the background if not already running
netstat -ano | findstr :3000 >nul 2>&1
if %errorlevel% neq 0 (
    echo Starting local background server on http://localhost:3000 ...
    start /b node server.js > server.log 2>&1
    timeout /t 2 /nobreak >nul
) else (
    echo Local server is already running on port 3000.
)

:: Try opening as a dedicated desktop app window with Edge or Chrome
if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" (
    echo Opening dashboard in Edge App Window...
    start "" "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" --app=http://localhost:3000
) else if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" (
    echo Opening dashboard in Chrome App Window...
    start "" "%ProgramFiles%\Google\Chrome\Application\chrome.exe" --app=http://localhost:3000
) else (
    echo Opening dashboard in default browser...
    start http://localhost:3000
)

echo.
echo Dashboard is now open in a local window!
echo URL: http://localhost:3000/
echo Default Login Password: admin
echo.

