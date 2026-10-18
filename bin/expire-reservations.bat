@echo off
REM Waimart reservation expiry worker for Windows Task Scheduler (run every minute).
REM Appends output to storage\logs\expiry.log. Safe to run repeatedly or concurrently.
cd /d "%~dp0.."
"C:\xampp\php\php.exe" "%~dp0expire-reservations.php" --quiet >> "%~dp0..\storage\logs\expiry.log" 2>&1
