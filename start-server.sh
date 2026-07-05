#!/bin/bash
# ===== מערכת כתוביות — הפעלת שרת מקומי (Mac / Linux) =====
# הרצה: ./start-server.sh
cd "$(dirname "$0")"
echo
echo " המערכת עולה... פתחו דפדפן בכתובת http://localhost:8000"
echo " להפסקת השרת: Ctrl+C"
echo
(sleep 1 && (open http://localhost:8000 2>/dev/null || xdg-open http://localhost:8000 2>/dev/null)) &
python3 -m http.server 8000
