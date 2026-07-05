@echo off
rem ===== מערכת כתוביות — הפעלת שרת מקומי (Windows) =====
rem דורש Python מותקן (python.org). לחיצה כפולה על הקובץ מפעילה את המערכת.
cd /d "%~dp0"
echo.
echo  המערכת עולה... נפתח דפדפן בכתובת http://localhost:8000
echo  להפסקת השרת: סגרו חלון זה או הקישו Ctrl+C
echo.
start "" http://localhost:8000
python -m http.server 8000 2>nul || py -m http.server 8000
pause
