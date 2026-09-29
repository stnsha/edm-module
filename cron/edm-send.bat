@echo off
rem ==========================================================================
rem  EDM send queue - runs edm\cron\send.php once (about 50 seconds of work),
rem  then edm\cron\qa.php (automated QA checks, up to about 40 seconds).
rem
rem  Schedule it every minute with Windows Task Scheduler. From an
rem  Administrator command prompt on the server (adjust the path):
rem
rem    schtasks /Create /TN "EDM send queue" /SC MINUTE /MO 1 /RU SYSTEM ^
rem      /TR "\"C:\xampp\htdocs\odb\edm\cron\edm-send.bat\""
rem
rem  Check it:   schtasks /Query /TN "EDM send queue"
rem  Remove it:  schtasks /Delete /TN "EDM send queue" /F
rem
rem  Output is appended to edm\logs\ses-send.log.
rem ==========================================================================

setlocal

rem PHP executable: XAMPP default first, then Laragon, then whatever is on PATH.
set "PHP_EXE=C:\xampp\php\php.exe"
if not exist "%PHP_EXE%" (
    for /d %%D in ("C:\laragon\bin\php\php-*") do set "PHP_EXE=%%D\php.exe"
)
if not exist "%PHP_EXE%" set "PHP_EXE=php"

rem %~dp0 is this file's folder (edm\cron\), so the task works from any
rem working directory.
"%PHP_EXE%" "%~dp0send.php"
set "RC=%ERRORLEVEL%"

rem Automated QA checks on queued campaigns (log: edm\logs\qa.log).
"%PHP_EXE%" "%~dp0qa.php"

endlocal & exit /b %RC%
