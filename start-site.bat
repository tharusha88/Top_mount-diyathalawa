@echo off
rem Double-click to run the whole site locally with PHP: the website, the booking page,
rem the admin panel (/admin/) and the POS (/pos). Press Ctrl+C to stop.
cd /d "%~dp0"

set "PHP=php"
if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if exist "D:\xampp\php\php.exe" set "PHP=D:\xampp\php\php.exe"

if not exist ".env" (
  echo No .env file yet. Copy .env.example to .env first, and for a quick local copy set
  echo DB_CONNECTION=sqlite in it ^(no database server needed^). See README.md.
  pause
  exit /b 1
)

echo.
echo   Top Mountain Resort is running at http://localhost:3100/
echo   Admin panel: http://localhost:3100/admin/    POS: http://localhost:3100/pos
echo   Press Ctrl+C to stop.
echo.
start "" http://localhost:3100/
"%PHP%" -d extension=gd -S localhost:3100 -t public public/index.php
if errorlevel 1 (
  echo.
  echo Could not start. Make sure PHP 8.1+ is installed ^(XAMPP works^), or that port 3100 is free.
)
pause
