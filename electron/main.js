// מערכת כתוביות — עטיפת אפליקציה שולחנית (Electron)
//
// עדכון אוטומטי מרחוק: בכל פתיחה האפליקציה מורידה את קובצי המערכת
// העדכניים ישירות מהרפוזיטורי ב-GitHub לתיקיית מטמון מקומית וטוענת משם.
// אם אין אינטרנט — נטען המטמון האחרון, ואם אין כזה — הגרסה המובנית בדיסק.
// הזיכרון (localStorage) נשמר בתיקיית המשתמש וחי בין עדכונים.
const { app, BrowserWindow, shell, net } = require('electron');
const path = require('path');
const fs = require('fs');

const RAW_BASE = 'https://raw.githubusercontent.com/opkl10/omersystem/claude/subtitle-system-fonts-effects-qgdv6h/';
const APP_FILES = [
  'index.html',
  'css/style.css',
  'js/app.js',
  'manifest.webmanifest',
  'icons/icon-192.png',
  'icons/icon-512.png',
];

function cacheDir() {
  return path.join(app.getPath('userData'), 'app-cache');
}

// הורדת כל הקבצים; כותבים לדיסק רק אם כולם ירדו תקינים
async function updateCache() {
  const results = [];
  for (const f of APP_FILES) {
    const res = await net.fetch(RAW_BASE + f, { signal: AbortSignal.timeout(10000) });
    if (!res.ok) throw new Error('HTTP ' + res.status + ' for ' + f);
    results.push([f, Buffer.from(await res.arrayBuffer())]);
  }
  if (!results[0][1].toString('utf8').includes('מערכת כתוביות')) {
    throw new Error('unexpected index.html content');
  }
  for (const [f, buf] of results) {
    const p = path.join(cacheDir(), f);
    fs.mkdirSync(path.dirname(p), { recursive: true });
    fs.writeFileSync(p, buf);
  }
}

async function loadApp(win) {
  try {
    await updateCache();
  } catch (_) { /* אין רשת או הורדה נכשלה — נשתמש במה שיש */ }

  const cachedIndex = path.join(cacheDir(), 'index.html');
  try {
    if (fs.existsSync(cachedIndex)) {
      await win.loadFile(cachedIndex);
      return;
    }
  } catch (_) { /* מטמון פגום — נופלים לגרסה המובנית */ }
  await win.loadFile(path.join(__dirname, '..', 'index.html'));
}

function createWindow() {
  const win = new BrowserWindow({
    width: 1440,
    height: 900,
    minWidth: 900,
    minHeight: 600,
    title: 'מערכת כתוביות',
    backgroundColor: '#14151a',
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
    },
  });

  loadApp(win);
  win.setMenuBarVisibility(false);

  // קישורים חיצוניים נפתחים בדפדפן, לא בתוך האפליקציה
  win.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: 'deny' };
  });
}

app.whenReady().then(() => {
  createWindow();
  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
