#!/usr/bin/env python3
# ===== מערכת כתוביות — שרת מקומי עם מפתחות API בצד השרת =====
#
# המפתחות מוגדרים בקובץ server-config.json (ליד הקובץ הזה, לא נכנס ל-git):
#   { "elevenlabs_api_key": "sk_...", "gemini_api_key": "AIza..." }
#
# כשמפתח מוגדר כאן — לא צריך להזין אותו בממשק: האפליקציה מזהה זאת
# אוטומטית ושולחת את הבקשות דרך השרת, שמוסיף את המפתח בדרך.
#
# הרצה:  python3 server.py   (או דרך start-server.sh)

import json
import os
import sys
import urllib.request
import urllib.error
import urllib.parse
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_PATH = os.path.join(BASE_DIR, 'server-config.json')
PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8000


def load_config():
    if os.path.exists(CONFIG_PATH):
        try:
            with open(CONFIG_PATH, encoding='utf-8') as f:
                return json.load(f)
        except Exception as e:
            print(f'⚠️  server-config.json לא תקין: {e}')
    return {}


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=BASE_DIR, **kwargs)

    def _json(self, status, payload):
        body = json.dumps(payload).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _forward(self, url, body, headers):
        req = urllib.request.Request(url, data=body, method='POST')
        for k, v in headers.items():
            req.add_header(k, v)
        try:
            with urllib.request.urlopen(req, timeout=1800) as res:
                data = res.read()
                self.send_response(res.status)
                self.send_header('Content-Type', res.headers.get('Content-Type', 'application/json'))
                self.send_header('Content-Length', str(len(data)))
                self.end_headers()
                self.wfile.write(data)
        except urllib.error.HTTPError as e:
            data = e.read()
            self.send_response(e.code)
            self.send_header('Content-Type', e.headers.get('Content-Type', 'application/json'))
            self.send_header('Content-Length', str(len(data)))
            self.end_headers()
            self.wfile.write(data)
        except Exception as e:
            self._json(502, {'error': str(e)})

    def do_GET(self):
        if self.path == '/api/has-keys':
            cfg = load_config()
            self._json(200, {
                'elevenlabs': bool(cfg.get('elevenlabs_api_key')),
                'gemini': bool(cfg.get('gemini_api_key')),
            })
            return
        super().do_GET()

    def do_POST(self):
        cfg = load_config()
        length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(length) if length else b''

        if self.path == '/api/speech-to-text':
            key = cfg.get('elevenlabs_api_key')
            if not key:
                return self._json(500, {'detail': {'message': 'מפתח ElevenLabs לא מוגדר ב-server-config.json'}})
            self._forward(
                'https://api.elevenlabs.io/v1/speech-to-text',
                body,
                {'xi-api-key': key, 'Content-Type': self.headers.get('Content-Type', '')},
            )
            return

        if self.path == '/api/gemini':
            key = cfg.get('gemini_api_key')
            if not key:
                return self._json(500, {'error': 'מפתח Gemini לא מוגדר ב-server-config.json'})
            self._forward(
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
                + urllib.parse.quote(key),
                body,
                {'Content-Type': 'application/json'},
            )
            return

        self._json(404, {'error': 'unknown endpoint'})


if __name__ == '__main__':
    cfg = load_config()
    print(f'🎬 מערכת כתוביות — http://localhost:{PORT}')
    print(f"   מפתח ElevenLabs בשרת: {'✓ מוגדר' if cfg.get('elevenlabs_api_key') else '✗ לא מוגדר (server-config.json)'}")
    print(f"   מפתח Gemini בשרת:    {'✓ מוגדר' if cfg.get('gemini_api_key') else '✗ לא מוגדר'}")
    print('   לעצירה: Ctrl+C')
    ThreadingHTTPServer(('', PORT), Handler).serve_forever()
