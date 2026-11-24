// עורך שרטוט דו-ממדי: ציור מצולע בנקודות, גרירה, הוספה ומחיקה, הצמדה לרשת
import { sketchOutline, selfIntersects, polygonArea, SKETCH_PRESETS } from './shapes.js';

const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();

export function openSketchEditor({ sketch, revolve = false, title = 'עריכת שרטוט', onDone }) {
  let pts = sketch.points.map((p) => [...p]);
  let smooth = !!sketch.smooth;
  let snap = 1;
  const undoStack = [];
  const pushUndo = () => {
    undoStack.push(JSON.stringify({ pts, smooth }));
    if (undoStack.length > 80) undoStack.shift();
  };

  // ---------- DOM ----------
  const ov = document.createElement('div');
  ov.className = 'sk-overlay';
  ov.dir = 'rtl';
  ov.setAttribute('role', 'dialog');
  ov.setAttribute('aria-label', title);
  const presets = SKETCH_PRESETS[revolve ? 'revolve' : 'extrude'];
  ov.innerHTML = `
    <div class="sk-bar">
      <strong class="sk-title"></strong>
      <label class="sk-ctl">התחל מ:
        <select id="skPreset"><option value="">— צורה מוכנה —</option>
          ${Object.keys(presets).map((k) => `<option>${k}</option>`).join('')}</select>
      </label>
      <label class="sk-ctl">הצמדה:
        <select id="skSnap">
          <option value="0">בלי</option><option value="0.5">0.5 מ"מ</option>
          <option value="1" selected>1 מ"מ</option><option value="5">5 מ"מ</option><option value="10">10 מ"מ</option>
        </select>
      </label>
      <label class="sk-ctl toggle"><input type="checkbox" id="skSmooth"> קווים מעוגלים</label>
      <span class="sk-spacer"></span>
      <button id="skUndo" title="בטל (Ctrl+Z)">↶ בטל</button>
      <button id="skFit" title="התאם תצוגה">⤢ התאם</button>
      <button id="skCancel">ביטול</button>
      <button id="skDone" class="primary">✓ סיום</button>
    </div>
    <div class="sk-main">
      <canvas class="sk-canvas" tabindex="0" aria-label="משטח השרטוט"></canvas>
      <div class="sk-help">
        <b>לחיצה</b> על משטח ריק — מוסיפה נקודה ·
        <b>גרירה</b> של נקודה — מזיזה ·
        <b>גרירה על קו</b> — מוסיפה נקודה באמצע ·
        <b>לחיצה כפולה / ימנית</b> על נקודה — מוחקת ·
        <b>גרירה ברקע</b> — הזזת תצוגה · <b>גלגלת</b> — זום
        ${revolve ? '<br>הקו הכחול הוא <b>ציר הסיבוב</b>: מציירים רק חצי חתך מימינו, והוא יסתובב סביבו.' : ''}
      </div>
      <div class="sk-info" aria-live="polite"></div>
    </div>
    <div class="sk-point">
      <span>נקודה נבחרת:</span>
      <label>X <input type="number" id="skPX" step="0.5"></label>
      <label>Y <input type="number" id="skPY" step="0.5"></label>
      <button id="skPDel" class="danger">מחק נקודה</button>
    </div>`;
  ov.querySelector('.sk-title').textContent = title;
  document.body.appendChild(ov);
  const cv = ov.querySelector('canvas');
  const ctx = cv.getContext('2d');
  const info = ov.querySelector('.sk-info');
  const q = (id) => ov.querySelector('#' + id);
  q('skSmooth').checked = smooth;

  // ---------- תצוגה ----------
  let scale = 6; // פיקסלים למ"מ
  let ox = 0, oy = 0; // מרכז המסך במ"מ
  let selected = -1, hover = -1, hoverEdge = null;
  const W = () => cv.clientWidth, H = () => cv.clientHeight;
  const toScreen = ([x, y]) => [W() / 2 + (x - ox) * scale, H() / 2 - (y - oy) * scale];
  const toWorld = (sx, sy) => [(sx - W() / 2) / scale + ox, -(sy - H() / 2) / scale + oy];
  const snapV = (v) => (snap > 0 ? Math.round(v / snap) * snap : Math.round(v * 100) / 100);
  const clampPt = (p) => (revolve ? [Math.max(0, p[0]), p[1]] : p);

  function fit() {
    const xs = pts.map((p) => p[0]), ys = pts.map((p) => p[1]);
    if (revolve) xs.push(0);
    const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys);
    ox = (minX + maxX) / 2;
    oy = (minY + maxY) / 2;
    scale = Math.max(0.5, Math.min(40, Math.min(W() / ((maxX - minX) || 10), H() / ((maxY - minY) || 10)) * 0.6));
    draw();
  }

  function resize() {
    const dpr = Math.min(devicePixelRatio || 1, 2);
    cv.width = W() * dpr;
    cv.height = H() * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    draw();
  }

  function draw() {
    const w = W(), h = H();
    ctx.clearRect(0, 0, w, h);
    ctx.fillStyle = css('--view') || '#eee';
    ctx.fillRect(0, 0, w, h);
    // רשת
    const [x0, y1] = toWorld(0, 0), [x1, y0] = toWorld(w, h);
    let step = 1;
    while (step * scale < 8) step *= step === 1 ? 5 : 2;
    const major = step * 10 >= 10 ? Math.max(10, step * 5) : 10;
    ctx.lineWidth = 1;
    for (let x = Math.floor(x0 / step) * step; x <= x1; x += step) {
      const sx = Math.round(toScreen([x, 0])[0]) + 0.5;
      ctx.strokeStyle = Math.abs(x % major) < 1e-6 ? css('--grid-major') : css('--grid-minor');
      ctx.beginPath(); ctx.moveTo(sx, 0); ctx.lineTo(sx, h); ctx.stroke();
    }
    for (let y = Math.floor(y0 / step) * step; y <= y1; y += step) {
      const sy = Math.round(toScreen([0, y])[1]) + 0.5;
      ctx.strokeStyle = Math.abs(y % major) < 1e-6 ? css('--grid-major') : css('--grid-minor');
      ctx.beginPath(); ctx.moveTo(0, sy); ctx.lineTo(w, sy); ctx.stroke();
    }
    // צירים
    const [ax, ay] = toScreen([0, 0]);
    ctx.lineWidth = revolve ? 2.5 : 1.5;
    ctx.strokeStyle = revolve ? '#3e63dd' : 'rgba(229,72,77,.7)';
    ctx.beginPath(); ctx.moveTo(ax, 0); ctx.lineTo(ax, h); ctx.stroke();
    ctx.lineWidth = 1.5;
    ctx.strokeStyle = revolve ? 'rgba(127,127,127,.6)' : 'rgba(48,164,108,.7)';
    ctx.beginPath(); ctx.moveTo(0, ay); ctx.lineTo(w, ay); ctx.stroke();
    if (revolve) {
      ctx.fillStyle = 'rgba(62,99,221,.07)';
      ctx.fillRect(0, 0, ax, h);
    }
    // סרגל קנה מידה
    ctx.fillStyle = css('--muted');
    ctx.font = '12px system-ui, sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText(`משבצת = ${step} מ"מ`, 10, h - 10);

    // הצורה
    const outline = sketchOutline({ points: pts, smooth });
    const bad = selfIntersects(outline);
    if (outline.length >= 2) {
      ctx.beginPath();
      outline.forEach((p, i) => { const [sx, sy] = toScreen(p); i ? ctx.lineTo(sx, sy) : ctx.moveTo(sx, sy); });
      ctx.closePath();
      ctx.fillStyle = bad ? 'rgba(229,72,77,.18)' : css('--sk-fill');
      ctx.fill();
      ctx.lineWidth = 2;
      ctx.strokeStyle = bad ? '#e5484d' : css('--accent');
      ctx.stroke();
    }
    // קווי שלד (כשהצורה מעוגלת)
    if (smooth) {
      ctx.setLineDash([4, 4]);
      ctx.lineWidth = 1;
      ctx.strokeStyle = css('--muted');
      ctx.beginPath();
      pts.forEach((p, i) => { const [sx, sy] = toScreen(p); i ? ctx.lineTo(sx, sy) : ctx.moveTo(sx, sy); });
      ctx.closePath();
      ctx.stroke();
      ctx.setLineDash([]);
    }
    // מידת צלע מסומנת
    const edgeIdx = hoverEdge?.i ?? (selected >= 0 ? selected : -1);
    if (edgeIdx >= 0 && !smooth && pts.length > 1) {
      const a = pts[edgeIdx], b = pts[(edgeIdx + 1) % pts.length];
      const len = Math.hypot(b[0] - a[0], b[1] - a[1]);
      const [sx, sy] = toScreen([(a[0] + b[0]) / 2, (a[1] + b[1]) / 2]);
      label(`${fmt(len)} מ"מ`, sx, sy - 10);
    }
    // נקודות
    pts.forEach((p, i) => {
      const [sx, sy] = toScreen(p);
      ctx.beginPath();
      ctx.arc(sx, sy, i === selected ? 7 : i === hover ? 6.5 : 5, 0, Math.PI * 2);
      ctx.fillStyle = i === selected ? css('--accent') : css('--panel');
      ctx.fill();
      ctx.lineWidth = 2;
      ctx.strokeStyle = css('--accent');
      ctx.stroke();
    });
    if (selected >= 0) {
      const [sx, sy] = toScreen(pts[selected]);
      label(`(${fmt(pts[selected][0])}, ${fmt(pts[selected][1])})`, sx, sy + 22);
    }
    if (hoverEdge && hover < 0) {
      const [sx, sy] = toScreen(hoverEdge.p);
      ctx.beginPath();
      ctx.arc(sx, sy, 4, 0, Math.PI * 2);
      ctx.fillStyle = css('--accent');
      ctx.globalAlpha = 0.5; ctx.fill(); ctx.globalAlpha = 1;
    }
    // מידע
    const xs = outline.map((p) => p[0]), ys = outline.map((p) => p[1]);
    const wMM = Math.max(...xs) - Math.min(...xs), hMM = Math.max(...ys) - Math.min(...ys);
    const parts = revolve
      ? [`קוטר: ${fmt(Math.max(...xs) * 2)} מ"מ`, `גובה: ${fmt(hMM)} מ"מ`]
      : [`רוחב: ${fmt(wMM)} מ"מ`, `עומק: ${fmt(hMM)} מ"מ`, `שטח: ${fmt(Math.abs(polygonArea(outline)))} מ"מ²`];
    parts.push(`${pts.length} נקודות`);
    if (bad) parts.push('⚠ הצורה חותכת את עצמה');
    info.textContent = parts.join(' · ');
    info.classList.toggle('bad', bad);
    // פאנל נקודה
    const pp = ov.querySelector('.sk-point');
    pp.classList.toggle('off', selected < 0);
    if (selected >= 0 && document.activeElement !== q('skPX') && document.activeElement !== q('skPY')) {
      q('skPX').value = pts[selected][0];
      q('skPY').value = pts[selected][1];
    }
  }

  function label(text, x, y) {
    ctx.font = '12px system-ui, sans-serif';
    ctx.textAlign = 'center';
    const w = ctx.measureText(text).width + 10;
    ctx.fillStyle = css('--panel');
    ctx.globalAlpha = 0.9;
    ctx.fillRect(x - w / 2, y - 9, w, 18);
    ctx.globalAlpha = 1;
    ctx.fillStyle = css('--ink');
    ctx.fillText(text, x, y + 4);
  }

  const fmt = (n) => (Math.round(n * 10) / 10).toString();

  // ---------- פגיעה ----------
  function hitPoint(sx, sy) {
    let best = -1, bd = 12;
    pts.forEach((p, i) => {
      const [px, py] = toScreen(p);
      const d = Math.hypot(px - sx, py - sy);
      if (d < bd) { bd = d; best = i; }
    });
    return best;
  }
  function nearestEdge(sx, sy) {
    let best = null;
    for (let i = 0; i < pts.length; i++) {
      const a = toScreen(pts[i]), b = toScreen(pts[(i + 1) % pts.length]);
      const dx = b[0] - a[0], dy = b[1] - a[1];
      const L = dx * dx + dy * dy || 1;
      const t = Math.max(0, Math.min(1, ((sx - a[0]) * dx + (sy - a[1]) * dy) / L));
      const px = a[0] + t * dx, py = a[1] + t * dy;
      const d = Math.hypot(sx - px, sy - py);
      if (!best || d < best.d) best = { i, d, p: toWorld(px, py) };
    }
    return best;
  }

  // ---------- אינטראקציה ----------
  let drag = null;
  cv.addEventListener('pointerdown', (e) => {
    cv.focus();
    const r = cv.getBoundingClientRect();
    const sx = e.clientX - r.left, sy = e.clientY - r.top;
    if (e.button === 2) return;
    cv.setPointerCapture(e.pointerId);
    const hp = hitPoint(sx, sy);
    if (hp >= 0) {
      pushUndo();
      selected = hp;
      drag = { kind: 'point', moved: false };
    } else {
      const ne = nearestEdge(sx, sy);
      if (ne && ne.d < 8) {
        pushUndo();
        const p = clampPt(ne.p.map(snapV));
        pts.splice(ne.i + 1, 0, p);
        selected = ne.i + 1;
        drag = { kind: 'point', moved: true };
      } else {
        drag = { kind: 'pan', sx, sy, ox, oy, moved: false };
      }
    }
    draw();
  });
  cv.addEventListener('pointermove', (e) => {
    const r = cv.getBoundingClientRect();
    const sx = e.clientX - r.left, sy = e.clientY - r.top;
    if (!drag) {
      hover = hitPoint(sx, sy);
      const ne = hover < 0 ? nearestEdge(sx, sy) : null;
      hoverEdge = ne && ne.d < 8 ? ne : null;
      cv.style.cursor = hover >= 0 ? 'grab' : hoverEdge ? 'copy' : 'crosshair';
      draw();
      return;
    }
    if (drag.kind === 'point') {
      drag.moved = true;
      pts[selected] = clampPt(toWorld(sx, sy).map(snapV));
    } else {
      if (Math.hypot(sx - drag.sx, sy - drag.sy) > 4) drag.moved = true;
      ox = drag.ox - (sx - drag.sx) / scale;
      oy = drag.oy + (sy - drag.sy) / scale;
    }
    draw();
  });
  cv.addEventListener('pointerup', (e) => {
    if (!drag) return;
    const r = cv.getBoundingClientRect();
    const sx = e.clientX - r.left, sy = e.clientY - r.top;
    if (drag.kind === 'pan' && !drag.moved) {
      // לחיצה על משטח ריק: נקודה חדשה, מוכנסת בצלע הקרובה ביותר
      pushUndo();
      const p = clampPt(toWorld(sx, sy).map(snapV));
      let bestI = pts.length - 1, bestCost = Infinity;
      for (let i = 0; i < pts.length; i++) {
        const a = pts[i], b = pts[(i + 1) % pts.length];
        const cost = Math.hypot(p[0] - a[0], p[1] - a[1]) + Math.hypot(p[0] - b[0], p[1] - b[1]) - Math.hypot(b[0] - a[0], b[1] - a[1]);
        if (cost < bestCost) { bestCost = cost; bestI = i; }
      }
      pts.splice(bestI + 1, 0, p);
      selected = bestI + 1;
    } else if (drag.kind === 'point' && !drag.moved) {
      undoStack.pop();
    }
    drag = null;
    draw();
  });
  const delPoint = (i) => {
    if (i < 0) return;
    if (pts.length <= 3) {
      info.textContent = 'צריך לפחות 3 נקודות';
      return;
    }
    pushUndo();
    pts.splice(i, 1);
    selected = -1;
    hover = -1;
    draw();
  };
  cv.addEventListener('dblclick', (e) => {
    const r = cv.getBoundingClientRect();
    delPoint(hitPoint(e.clientX - r.left, e.clientY - r.top));
  });
  cv.addEventListener('contextmenu', (e) => {
    e.preventDefault();
    const r = cv.getBoundingClientRect();
    delPoint(hitPoint(e.clientX - r.left, e.clientY - r.top));
  });
  cv.addEventListener('wheel', (e) => {
    e.preventDefault();
    const r = cv.getBoundingClientRect();
    const sx = e.clientX - r.left, sy = e.clientY - r.top;
    const before = toWorld(sx, sy);
    scale = Math.max(0.3, Math.min(80, scale * Math.exp(-e.deltaY * 0.0015)));
    const after = toWorld(sx, sy);
    ox += before[0] - after[0];
    oy += before[1] - after[1];
    draw();
  }, { passive: false });

  function undo() {
    const s = undoStack.pop();
    if (!s) return;
    ({ pts, smooth } = JSON.parse(s));
    q('skSmooth').checked = smooth;
    selected = -1;
    draw();
  }

  const onKey = (e) => {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); undo(); }
    else if ((e.key === 'Delete' || e.key === 'Backspace') && selected >= 0) { e.preventDefault(); delPoint(selected); }
    else if (e.key === 'Escape') close(false);
    else if (e.key === 'Enter') close(true);
    else if (selected >= 0 && e.key.startsWith('Arrow')) {
      e.preventDefault();
      pushUndo();
      const st = (snap || 0.5) * (e.shiftKey ? 10 : 1);
      const d = { ArrowLeft: [-st, 0], ArrowRight: [st, 0], ArrowUp: [0, st], ArrowDown: [0, -st] }[e.key];
      pts[selected] = clampPt([snapV(pts[selected][0] + d[0]), snapV(pts[selected][1] + d[1])]);
      draw();
    }
  };
  window.addEventListener('keydown', onKey, true);

  q('skPreset').addEventListener('change', (e) => {
    const pr = presets[e.target.value];
    if (!pr) return;
    pushUndo();
    pts = pr.points.map((p) => [...p]);
    smooth = pr.smooth;
    q('skSmooth').checked = smooth;
    selected = -1;
    e.target.value = '';
    fit();
  });
  q('skSnap').addEventListener('change', (e) => { snap = parseFloat(e.target.value); });
  q('skSmooth').addEventListener('change', (e) => { pushUndo(); smooth = e.target.checked; draw(); });
  q('skUndo').addEventListener('click', undo);
  q('skFit').addEventListener('click', fit);
  q('skCancel').addEventListener('click', () => close(false));
  q('skDone').addEventListener('click', () => close(true));
  q('skPDel').addEventListener('click', () => delPoint(selected));
  ['skPX', 'skPY'].forEach((id, axis) =>
    q(id).addEventListener('change', (e) => {
      const v = parseFloat(e.target.value);
      if (selected < 0 || Number.isNaN(v)) return;
      pushUndo();
      pts[selected][axis] = v;
      pts[selected] = clampPt(pts[selected]);
      draw();
    }),
  );

  const ro = new ResizeObserver(resize);
  ro.observe(cv);

  function close(ok) {
    window.removeEventListener('keydown', onKey, true);
    ro.disconnect();
    ov.remove();
    if (ok) onDone({ points: pts.map((p) => p.map((v) => Math.round(v * 100) / 100)), smooth });
  }

  requestAnimationFrame(() => { resize(); fit(); cv.focus(); });
}
