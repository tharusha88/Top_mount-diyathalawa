@echo off
rem Top Mountain POS for a Windows computer: opens the POS in Chrome (or Edge) with kiosk printing,
rem so bills, invoices and receipts print straight to the default printer - no print window.
rem Double-click it, or put a shortcut to it on the desktop. Close every other POS window first.
rem   topmountain-pos-windows.bat                      (the live POS: https://www.yourdomain.lk/pos)
rem   topmountain-pos-windows.bat http://localhost:3000/pos
setlocal
set "URL=https://www.yourdomain.lk/pos"
if not "%~1"=="" set "URL=%~1"
set "BROWSER=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if not exist "%BROWSER%" set "BROWSER=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
if not exist "%BROWSER%" set "BROWSER=%LocalAppData%\Google\Chrome\Application\chrome.exe"
if not exist "%BROWSER%" set "BROWSER=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not exist "%BROWSER%" set "BROWSER=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"
if not exist "%BROWSER%" goto nobrowser

rem every print goes to the default printer, so check it is a real printer
set "PRINTER="
for /f "usebackq delims=" %%P in (`powershell -NoProfile -Command "(Get-CimInstance Win32_Printer | Where-Object Default | Select-Object -First 1).Name"`) do set "PRINTER=%%P"
if not defined PRINTER goto noprinter
echo Prints go to: %PRINTER%
echo %PRINTER%| findstr /i /c:"PDF" /c:"XPS" /c:"OneNote" /c:"Fax" >nul
if not errorlevel 1 goto wrongprinter

:open
rem its own profile: kiosk printing only works in a browser window started with it
start "" "%BROWSER%" --user-data-dir="%LocalAppData%\TopMountainPOS" --kiosk-printing --no-first-run --no-default-browser-check --start-maximized --app="%URL%"
endlocal
exit /b 0

:wrongprinter
echo.
echo The default printer is "%PRINTER%", so prints would not reach the Pantum.
echo Make the Pantum the default: Settings, Bluetooth and devices, Printers and scanners,
echo turn off "Let Windows manage my default printer", choose the Pantum, Set as default.
echo.
pause
goto open

:noprinter
echo.
echo No default printer is set. Make the Pantum the default printer first:
echo Settings, Bluetooth and devices, Printers and scanners, choose the Pantum, Set as default.
echo.
pause
goto open

:nobrowser
echo Google Chrome or Microsoft Edge was not found. Install Chrome from https://www.google.com/chrome/
pause
exit /b 1
