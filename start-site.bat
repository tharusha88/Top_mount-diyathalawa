@echo off
rem Double-click to preview the site locally.
cd /d "%~dp0"
python serve.py
if errorlevel 1 (
  echo.
  echo Could not start. Make sure Python is installed and on PATH.
)
pause
