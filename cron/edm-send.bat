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
rem  send.php logs to edm\logs\ses-send.log, qa.php to edm\logs\qa.log.
rem  Everything PHP prints (including a fatal error that stops it before it
rem  can log) is appended to edm\logs\cron.log.
rem ==========================================================================

setlocal

rem PHP executable: the first candidate that is PHP 8.1 or newer (the EDM
rem code needs it - an older PHP, e.g. a leftover XAMPP 5.6, fails with exit
rem code 255). Order: EDM_PHP environment variable, XAMPP, Laragon (newest
rem folder last wins), then php on PATH.
set "PHP_EXE="
if defined EDM_PHP call :try "%EDM_PHP%"
if not defined PHP_EXE call :try "C:\xampp\php\php.exe"
if not defined PHP_EXE (
    for /d %%D in ("C:\laragon\bin\php\php-*") do call :try "%%D\php.exe"
)
if not defined PHP_EXE call :try "php"

rem %~dp0 is this file's folder (edm\cron\), so the task works from any
rem working directory.
set "LOG_DIR=%~dp0..\logs"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
set "CRON_LOG=%LOG_DIR%\cron.log"

if not defined PHP_EXE (
    >>"%CRON_LOG%" echo [%DATE% %TIME%] No PHP 8.1 or newer found - set EDM_PHP to its php.exe.
    endlocal & exit /b 1
)

"%PHP_EXE%" "%~dp0send.php" >>"%CRON_LOG%" 2>&1
set "RC=%ERRORLEVEL%"
if not "%RC%"=="0" >>"%CRON_LOG%" echo [%DATE% %TIME%] send.php exited with code %RC% (%PHP_EXE%).

rem Automated QA checks on queued campaigns (log: edm\logs\qa.log).
"%PHP_EXE%" "%~dp0qa.php" >>"%CRON_LOG%" 2>&1

endlocal & exit /b %RC%

rem --------------------------------------------------------------------------
rem :try "path\php.exe" - sets PHP_EXE when that PHP runs and is 8.1 or newer.
rem Inside the Laragon loop every newer match replaces the previous one.
:try
"%~1" -r "exit(PHP_VERSION_ID >= 80100 ? 0 : 1);" >nul 2>&1
if not errorlevel 1 set "PHP_EXE=%~1"
exit /b 0
