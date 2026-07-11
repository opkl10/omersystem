// מערכת כתוביות — עטיפת אפליקציה שולחנית (Electron)
// רץ מקומית לגמרי: הקבצים נטענים מהדיסק, והנתונים (localStorage) נשמרים
// בתיקיית המשתמש של האפליקציה — העבודה נזכרת בין הפעלות.
const { app, BrowserWindow, shell } = require('electron');
const path = require('path');

// עדכון אוטומטי מרחוק: האפליקציה טוענת קודם את הגרסה העדכנית מהאינטרנט
// (GitHub Pages — מתעדכנת בכל דחיפת קוד), ואם אין חיבור — הגרסה המובנית בדיסק.
const REMOTE_URL = 'https://opkl10.github.io/omersystem/';

async function loadApp(win) {
  try {
    await win.loadURL(REMOTE_URL);
  } catch (_) {
    await win.loadFile(path.join(__dirname, '..', 'index.html'));
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
