// מערכת כתוביות — עטיפת אפליקציה שולחנית (Electron)
//
// עדכון אוטומטי מרחוק: בכל פתיחה האפליקציה מורידה את קובצי המערכת
// העדכניים ישירות מהרפוזיטורי ב-GitHub לתיקיית מטמון מקומית וטוענת משם.
// בנוסף, בזמן ריצה נבדק כל 15 דקות אם יצאה גרסה חדשה — ומוצעת טעינה מחדש.
// אם אין אינטרנט — נטען המטמון האחרון, ואם אין כזה — הגרסה המובנית בדיסק.
// הזיכרון (localStorage) נשמר בתיקיית המשתמש וחי בין עדכונים.
const { app, BrowserWindow, shell, net, dialog } = require('electron');
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
const UPDATE_CHECK_MINUTES = 15;

let loadedVersion = null; // הגרסה שרצה כרגע בחלון

function cacheDir() {
  return path.join(app.getPath('userData'), 'app-cache');
}

function extractVersion(appJsText) {
  const m = appJsText.match(/APP_VERSION\s*=\s*'([^']+)'/);
  return m ? m[1] : null;
}

async function fetchRaw(file) {
  // פרמטר זמן עוקף את מטמון ה-CDN של raw (עד 5 דקות של גרסה ישנה)
  const res = await net.fetch(RAW_BASE + file + '?t=' + Date.now(), {
    signal: AbortSignal.timeout(10000),
  });
  if (!res.ok) throw new Error('HTTP ' + res.status + ' for ' + file);
  return Buffer.from(await res.arrayBuffer());
}

// הורדת כל הקבצים; כותבים לדיסק רק אם כולם ירדו תקינים
async function updateCache() {
  const results = [];
  for (const f of APP_FILES) {
    results.push([f, await fetchRaw(f)]);
  }
  if (!results[0][1].toString('utf8').includes('מערכת כתוביות')) {
    throw new Error('unexpected index.html content');
  }
  for (const [f, buf] of results) {
    const p = path.join(cacheDir(), f);
    fs.mkdirSync(path.dirname(p), { recursive: true });
    fs.writeFileSync(p, buf);
  }
  const appJs = results.find(([f]) => f === 'js/app.js');
  return appJs ? extractVersion(appJs[1].toString('utf8')) : null;
}

async function loadApp(win) {
  try {
    loadedVersion = await updateCache();
  } catch (_) { /* אין רשת או הורדה נכשלה — נשתמש במה שיש */ }

  const cachedIndex = path.join(cacheDir(), 'index.html');
  try {
    if (fs.existsSync(cachedIndex)) {
      if (!loadedVersion) {
        try {
          loadedVersion = extractVersion(fs.readFileSync(path.join(cacheDir(), 'js/app.js'), 'utf8'));
        } catch (_) {}
      }
      await win.loadFile(cachedIndex);
      return;
    }
  } catch (_) { /* מטמון פגום — נופלים לגרסה המובנית */ }
  await win.loadFile(path.join(__dirname, '..', 'index.html'));
}

// בדיקת עדכון בזמן ריצה: משווים את הגרסה ברפוזיטורי לגרסה שנטענה
async function checkForUpdate(win, manual = false) {
  let remoteVersion = null;
  try {
    remoteVersion = extractVersion((await fetchRaw('js/app.js')).toString('utf8'));
  } catch (_) {
    if (manual) dialog.showMessageBox(win, { message: 'לא ניתן לבדוק עדכון — אין חיבור לאינטרנט.', buttons: ['אישור'] });
    return;
  }
  if (!remoteVersion || remoteVersion === loadedVersion) {
    if (manual) dialog.showMessageBox(win, { message: `אתם על הגרסה העדכנית (${loadedVersion || 'לא ידועה'}).`, buttons: ['אישור'] });
    return;
  }
  const { response } = await dialog.showMessageBox(win, {
    type: 'info',
    message: `עדכון חדש זמין: ${remoteVersion}`,
    detail: 'העבודה שלכם שמורה אוטומטית ולא תלך לאיבוד.',
    buttons: ['עדכון עכשיו', 'אחר כך'],
    defaultId: 0,
    cancelId: 1,
  });
  if (response === 0) {
    await loadApp(win);
  }
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

  // Cmd+R = בדיקת עדכון וטעינה מחדש ידניות
  win.webContents.on('before-input-event', (event, input) => {
    if (input.meta && input.key.toLowerCase() === 'r') {
      event.preventDefault();
      checkForUpdate(win, true);
    }
  });

  // בדיקת עדכון תקופתית ברקע
  const timer = setInterval(() => {
    if (!win.isDestroyed()) checkForUpdate(win, false);
  }, UPDATE_CHECK_MINUTES * 60 * 1000);
  win.on('closed', () => clearInterval(timer));

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
