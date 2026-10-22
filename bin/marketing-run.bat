@echo off
REM Basemall marketing automations for Windows Task Scheduler (run every 15 minutes).
REM Appends output to storage\logs\marketing.log. Safe to run repeatedly or concurrently.
cd /d "%~dp0.."
"C:\xampp\php\php.exe" "%~dp0marketing-run.php" --quiet >> "%~dp0..\storage\logs\marketing.log" 2>&1
