@echo off
rem ===== מערכת כתוביות — הפעלת שרת מקומי (Windows) =====
rem דורש Python מותקן (python.org). לחיצה כפולה על הקובץ מפעילה את המערכת.
rem מפתחות API אפשר להגדיר בקובץ server-config.json (ראו server-config.example.json)
cd /d "%~dp0"
echo.
echo  המערכת עולה... נפתח דפדפן בכתובת http://localhost:8000
echo  להפסקת השרת: סגרו חלון זה או הקישו Ctrl+C
echo.
start "" http://localhost:8000
python server.py 8000 2>nul || py server.py 8000
pause
