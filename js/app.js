/* ===== מערכת כתוביות — לוגיקה ===== */
'use strict';

// ---------- מצב האפליקציה ----------
const DEFAULT_STYLE = {
  fontFamily: 'Arial',
  fontSize: 32,
  textColor: '#ffffff',
  outlineColor: '#000000',
  outlineWidth: 2,
  bgColor: '#000000',
  bgOpacity: 0,
  bold: false,
  italic: false,
  position: 85,          // אחוז מגובה המסך (מלמעלה)
  effect: 'none',
  effectDuration: 0.4,
};

const BUILTIN_FONTS = [
  'Arial', 'Arial Black', 'Verdana', 'Tahoma', 'Georgia',
  'Times New Roman', 'Courier New', 'Impact', 'Comic Sans MS',
  'Trebuchet MS', 'Segoe UI',
];

const state = {
  subtitles: [],          // { id, start, end, text, style: null | {...} }
  globalStyle: { ...DEFAULT_STYLE },
  customFonts: [],        // { name, dataUrl }
  selectedId: null,
  nextId: 1,
  duration: 0,
  hasMedia: false,
};

// ---------- אלמנטים ----------
const $ = (id) => document.getElementById(id);
const video = $('video');
const overlay = $('subtitle-overlay');
const subtitleListEl = $('subtitle-list');
const timelineEl = $('timeline');
const timelineCursor = $('timeline-cursor');
const seekBar = $('seek-bar');

// ---------- כלי עזר לזמן ----------
function formatTime(sec, forDisplay = true) {
  if (!isFinite(sec) || sec < 0) sec = 0;
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = Math.floor(sec % 60);
  const ms = Math.round((sec - Math.floor(sec)) * 1000);
  const pad = (n, l = 2) => String(n).padStart(l, '0');
  if (forDisplay && h === 0) return `${pad(m)}:${pad(s)}.${pad(ms, 3)}`;
  return `${pad(h)}:${pad(m)}:${pad(s)}.${pad(ms, 3)}`;
}

function parseTime(str) {
  // תומך ב: SS / MM:SS / HH:MM:SS עם אופציה ל-.ms
  const m = String(str).trim().match(/^(?:(\d+):)?(?:(\d+):)?(\d+)(?:[.,](\d{1,3}))?$/);
  if (!m) return null;
  const parts = [m[1], m[2], m[3]].filter(v => v !== undefined).map(Number);
  let sec = 0;
  for (const p of parts) sec = sec * 60 + p;
  if (m[4]) sec += Number(m[4].padEnd(3, '0')) / 1000;
  return sec;
}

// ---------- ניהול כתוביות ----------
function addSubtitle(start, end, text = 'טקסט חדש') {
  const sub = { id: state.nextId++, start, end, text, style: null };
  state.subtitles.push(sub);
  sortSubtitles();
  selectSubtitle(sub.id);
  renderAll();
  return sub;
}

function sortSubtitles() {
  state.subtitles.sort((a, b) => a.start - b.start);
}

function deleteSubtitle(id) {
  state.subtitles = state.subtitles.filter(s => s.id !== id);
  if (state.selectedId === id) state.selectedId = null;
  renderAll();
}

function getSubtitle(id) {
  return state.subtitles.find(s => s.id === id) || null;
}

function selectSubtitle(id) {
  state.selectedId = id;
  syncStylePanel();
  renderSubtitleList();
  renderTimeline();
}

function effectiveStyle(sub) {
  return (sub && sub.style) ? sub.style : state.globalStyle;
}

// ---------- רינדור רשימת כתוביות ----------
function renderSubtitleList() {
  subtitleListEl.innerHTML = '';
  if (state.subtitles.length === 0) {
    subtitleListEl.innerHTML = '<p class="hint">אין כתוביות עדיין. לחצו על "כתובית חדשה" כדי להתחיל.</p>';
    return;
  }
  for (const sub of state.subtitles) {
    const item = document.createElement('div');
    item.className = 'subtitle-item' + (sub.id === state.selectedId ? ' selected' : '');

    const times = document.createElement('div');
    times.className = 'subtitle-item-times';
    const startInput = document.createElement('input');
    startInput.value = formatTime(sub.start);
    startInput.title = 'זמן התחלה';
    const arrow = document.createElement('span');
    arrow.textContent = '→';
    const endInput = document.createElement('input');
    endInput.value = formatTime(sub.end);
    endInput.title = 'זמן סיום';

    startInput.addEventListener('change', () => {
      const t = parseTime(startInput.value);
      if (t !== null) { sub.start = t; sortSubtitles(); renderAll(); }
      else startInput.value = formatTime(sub.start);
    });
    endInput.addEventListener('change', () => {
      const t = parseTime(endInput.value);
      if (t !== null && t > sub.start) { sub.end = t; renderAll(); }
      else endInput.value = formatTime(sub.end);
    });
    times.append(startInput, arrow, endInput);

    const textarea = document.createElement('textarea');
    textarea.value = sub.text;
    textarea.addEventListener('input', () => {
      sub.text = textarea.value;
      renderTimeline();
      renderOverlay(true);
    });

    const actions = document.createElement('div');
    actions.className = 'subtitle-item-actions';

    const jumpBtn = document.createElement('button');
    jumpBtn.className = 'mini-btn';
    jumpBtn.textContent = '⏩ קפיצה';
    jumpBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      seekTo(sub.start);
    });

    const setStartBtn = document.createElement('button');
    setStartBtn.className = 'mini-btn';
    setStartBtn.textContent = '⏱ התחלה=עכשיו';
    setStartBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      sub.start = currentTime();
      if (sub.end <= sub.start) sub.end = sub.start + 2;
      sortSubtitles(); renderAll();
    });

    const setEndBtn = document.createElement('button');
    setEndBtn.className = 'mini-btn';
    setEndBtn.textContent = '⏱ סיום=עכשיו';
    setEndBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const t = currentTime();
      if (t > sub.start) { sub.end = t; renderAll(); }
    });

    const spacer = document.createElement('span');
    spacer.className = 'spacer';

    const badge = document.createElement('span');
    badge.className = 'custom-style-badge';
    if (sub.style) badge.textContent = '🎨 עיצוב מותאם';

    const delBtn = document.createElement('button');
    delBtn.className = 'mini-btn btn-danger';
    delBtn.textContent = '🗑 מחיקה';
    delBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      deleteSubtitle(sub.id);
    });

    actions.append(jumpBtn, setStartBtn, setEndBtn, spacer, badge, delBtn);
    item.append(times, textarea, actions);
    item.addEventListener('click', () => selectSubtitle(sub.id));
    subtitleListEl.appendChild(item);
  }
}

// ---------- ציר זמן ----------
function timelineDuration() {
  const lastEnd = state.subtitles.reduce((m, s) => Math.max(m, s.end), 0);
  return Math.max(state.duration, lastEnd, 10);
}

function renderTimeline() {
  timelineEl.querySelectorAll('.timeline-block').forEach(el => el.remove());
  const dur = timelineDuration();
  for (const sub of state.subtitles) {
    const block = document.createElement('div');
    block.className = 'timeline-block' + (sub.id === state.selectedId ? ' selected' : '');
    block.style.left = (sub.start / dur * 100) + '%';
    block.style.width = Math.max((sub.end - sub.start) / dur * 100, 0.5) + '%';
    block.textContent = sub.text;
    block.title = `${formatTime(sub.start)} → ${formatTime(sub.end)}\n${sub.text}`;
    block.addEventListener('click', (e) => {
      e.stopPropagation();
      selectSubtitle(sub.id);
      seekTo(sub.start);
    });
    timelineEl.appendChild(block);
  }
  updateTimelineCursor();
}

function updateTimelineCursor() {
  timelineCursor.style.left = (currentTime() / timelineDuration() * 100) + '%';
}

timelineEl.addEventListener('click', (e) => {
  const rect = timelineEl.getBoundingClientRect();
  const frac = (e.clientX - rect.left) / rect.width;
  seekTo(frac * timelineDuration());
});

// ---------- שעון פנימי (עובד גם בלי וידאו) ----------
let clockTime = 0;
let clockPlaying = false;
let lastTick = null;

function currentTime() {
  return state.hasMedia ? video.currentTime : clockTime;
}

function seekTo(t) {
  t = Math.max(0, Math.min(t, timelineDuration()));
  if (state.hasMedia) video.currentTime = t;
  else clockTime = t;
  updateTimeUI();
  renderOverlay(true);
}

function togglePlay() {
  if (state.hasMedia) {
    if (video.paused) video.play(); else video.pause();
  } else {
    clockPlaying = !clockPlaying;
    lastTick = performance.now();
  }
  updatePlayButton();
}

function updatePlayButton() {
  const playing = state.hasMedia ? !video.paused : clockPlaying;
  $('btn-play').textContent = playing ? '⏸️' : '▶️';
}

function tick(now) {
  if (!state.hasMedia && clockPlaying) {
    clockTime += (now - lastTick) / 1000;
    if (clockTime >= timelineDuration()) { clockTime = 0; clockPlaying = false; updatePlayButton(); }
  }
  lastTick = now;
  updateTimeUI();
  renderOverlay();
  requestAnimationFrame(tick);
}
requestAnimationFrame(tick);

function updateTimeUI() {
  $('time-display').textContent = formatTime(currentTime());
  $('duration-display').textContent = formatTime(timelineDuration());
  seekBar.value = Math.round(currentTime() / timelineDuration() * 1000);
  updateTimelineCursor();
}

// ---------- תצוגת כתוביות (Overlay) ----------
let lastActiveKey = '';

function renderOverlay(force = false) {
  const t = currentTime();
  const active = state.subtitles.filter(s => t >= s.start && t < s.end);
  const key = active.map(s => `${s.id}:${s.text}:${JSON.stringify(effectiveStyle(s))}`).join('|');

  if (!force && key === lastActiveKey) {
    updateTypewriters(t);
    return;
  }
  lastActiveKey = key;
  overlay.innerHTML = '';

  for (const sub of active) {
    const st = effectiveStyle(sub);
    const line = document.createElement('div');
    line.className = 'subtitle-line';
    line.style.top = st.position + '%';
    line.style.setProperty('--fx-dur', st.effectDuration + 's');

    if (st.effect !== 'none' && st.effect !== 'typewriter') {
      line.classList.add('fx-' + st.effect);
    }

    const span = document.createElement('span');
    span.style.fontFamily = `"${st.fontFamily}", sans-serif`;
    span.style.fontSize = st.fontSize + 'px';
    span.style.fontWeight = st.bold ? '700' : '400';
    span.style.fontStyle = st.italic ? 'italic' : 'normal';
    if (st.effect !== 'rainbow') span.style.color = st.textColor;

    if (st.outlineWidth > 0) {
      const w = st.outlineWidth, c = st.outlineColor;
      span.style.textShadow =
        `${-w}px ${-w}px 0 ${c}, ${w}px ${-w}px 0 ${c}, ` +
        `${-w}px ${w}px 0 ${c}, ${w}px ${w}px 0 ${c}, ` +
        `0 ${-w}px 0 ${c}, 0 ${w}px 0 ${c}, ${-w}px 0 0 ${c}, ${w}px 0 0 ${c}`;
    }
    if (st.bgOpacity > 0) {
      span.style.backgroundColor = hexToRgba(st.bgColor, st.bgOpacity / 100);
    }

    if (st.effect === 'typewriter') {
      line.classList.add('fx-typewriter');
      line.dataset.subId = sub.id;
      line.dataset.start = sub.start;
      line.dataset.dur = st.effectDuration;
      for (const ch of sub.text) {
        const chSpan = document.createElement('span');
        chSpan.className = 'tw-char';
        chSpan.textContent = ch;
        chSpan.style.padding = '0';
        chSpan.style.borderRadius = '0';
        span.appendChild(chSpan);
      }
    } else {
      span.textContent = sub.text;
    }

    line.appendChild(span);
    overlay.appendChild(line);
  }
  updateTypewriters(t);
}

function updateTypewriters(t) {
  overlay.querySelectorAll('.fx-typewriter').forEach(line => {
    const start = Number(line.dataset.start);
    const dur = Math.max(Number(line.dataset.dur), 0.1);
    const chars = line.querySelectorAll('.tw-char');
    const progress = Math.min(Math.max((t - start) / dur, 0), 1);
    const visibleCount = Math.ceil(progress * chars.length);
    chars.forEach((ch, i) => ch.classList.toggle('tw-hidden', i >= visibleCount));
  });
}

function hexToRgba(hex, alpha) {
  const n = parseInt(hex.slice(1), 16);
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
}

// ---------- פאנל עיצוב ----------
const styleInputs = {
  fontFamily: $('sel-font'),
  fontSize: $('rng-font-size'),
  textColor: $('clr-text'),
  outlineColor: $('clr-outline'),
  outlineWidth: $('rng-outline'),
  bgColor: $('clr-bg'),
  bgOpacity: $('rng-bg-opacity'),
  bold: $('chk-bold'),
  italic: $('chk-italic'),
  position: $('rng-position'),
  effect: $('sel-effect'),
  effectDuration: $('rng-effect-duration'),
};

function currentStyleTarget() {
  const sub = getSubtitle(state.selectedId);
  if (sub && $('chk-override').checked && sub.style) return sub.style;
  return state.globalStyle;
}

function syncStylePanel() {
  const sub = getSubtitle(state.selectedId);
  $('override-row').hidden = !sub;
  $('chk-override').checked = !!(sub && sub.style);
  $('style-target-note').textContent = (sub && sub.style)
    ? `עיצוב מותאם לכתובית: "${sub.text.slice(0, 25)}"`
    : 'עיצוב גלובלי (חל על כל הכתוביות ללא עיצוב מותאם)';

  const st = (sub && sub.style) ? sub.style : state.globalStyle;
  styleInputs.fontFamily.value = st.fontFamily;
  styleInputs.fontSize.value = st.fontSize;
  styleInputs.textColor.value = st.textColor;
  styleInputs.outlineColor.value = st.outlineColor;
  styleInputs.outlineWidth.value = st.outlineWidth;
  styleInputs.bgColor.value = st.bgColor;
  styleInputs.bgOpacity.value = st.bgOpacity;
  styleInputs.bold.checked = st.bold;
  styleInputs.italic.checked = st.italic;
  styleInputs.position.value = st.position;
  styleInputs.effect.value = st.effect;
  styleInputs.effectDuration.value = st.effectDuration;
  updateStyleLabels();
}

function updateStyleLabels() {
  $('font-size-value').textContent = styleInputs.fontSize.value;
  $('outline-width-value').textContent = styleInputs.outlineWidth.value;
  $('bg-opacity-value').textContent = styleInputs.bgOpacity.value;
  $('position-value').textContent = styleInputs.position.value;
  $('effect-duration-value').textContent = styleInputs.effectDuration.value;
}

function readStyleFromPanel(target) {
  target.fontFamily = styleInputs.fontFamily.value;
  target.fontSize = Number(styleInputs.fontSize.value);
  target.textColor = styleInputs.textColor.value;
  target.outlineColor = styleInputs.outlineColor.value;
  target.outlineWidth = Number(styleInputs.outlineWidth.value);
  target.bgColor = styleInputs.bgColor.value;
  target.bgOpacity = Number(styleInputs.bgOpacity.value);
  target.bold = styleInputs.bold.checked;
  target.italic = styleInputs.italic.checked;
  target.position = Number(styleInputs.position.value);
  target.effect = styleInputs.effect.value;
  target.effectDuration = Number(styleInputs.effectDuration.value);
}

for (const input of Object.values(styleInputs)) {
  input.addEventListener('input', () => {
    readStyleFromPanel(currentStyleTarget());
    updateStyleLabels();
    renderOverlay(true);
    renderSubtitleList();
  });
}

$('chk-override').addEventListener('change', () => {
  const sub = getSubtitle(state.selectedId);
  if (!sub) return;
  if ($('chk-override').checked) {
    sub.style = { ...effectiveStyle(sub) };
  } else {
    sub.style = null;
  }
  syncStylePanel();
  renderOverlay(true);
  renderSubtitleList();
});

// ---------- פונטים ----------
function populateFontSelect() {
  const sel = styleInputs.fontFamily;
  const current = sel.value;
  sel.innerHTML = '';
  const builtinGroup = document.createElement('optgroup');
  builtinGroup.label = 'פונטים מובנים';
  for (const f of BUILTIN_FONTS) {
    const opt = document.createElement('option');
    opt.value = f;
    opt.textContent = f;
    builtinGroup.appendChild(opt);
  }
  sel.appendChild(builtinGroup);

  if (state.customFonts.length) {
    const customGroup = document.createElement('optgroup');
    customGroup.label = 'פונטים שהועלו';
    for (const f of state.customFonts) {
      const opt = document.createElement('option');
      opt.value = f.name;
      opt.textContent = f.name;
      customGroup.appendChild(opt);
    }
    sel.appendChild(customGroup);
  }
  if ([...sel.options].some(o => o.value === current)) sel.value = current;
}

function renderFontLists() {
  const builtinList = $('builtin-font-list');
  builtinList.innerHTML = '';
  for (const f of BUILTIN_FONTS) {
    const li = document.createElement('li');
    li.style.fontFamily = f;
    li.textContent = f + ' — אבג ABC 123';
    builtinList.appendChild(li);
  }

  const customList = $('custom-font-list');
  customList.innerHTML = '';
  if (!state.customFonts.length) {
    customList.innerHTML = '<li class="empty-note">לא הועלו פונטים עדיין</li>';
    return;
  }
  for (const f of state.customFonts) {
    const li = document.createElement('li');
    li.style.fontFamily = `"${f.name}"`;
    const label = document.createElement('span');
    label.textContent = f.name + ' — אבג ABC 123';
    const del = document.createElement('button');
    del.className = 'mini-btn btn-danger';
    del.textContent = '🗑';
    del.title = 'הסרת פונט';
    del.addEventListener('click', () => removeCustomFont(f.name));
    li.append(label, del);
    customList.appendChild(li);
  }
}

async function loadCustomFont(name, dataUrl) {
  const face = new FontFace(name, `url(${dataUrl})`);
  await face.load();
  document.fonts.add(face);
}

async function addFontFiles(files) {
  for (const file of files) {
    const name = file.name.replace(/\.(ttf|otf|woff2?|)$/i, '');
    const dataUrl = await new Promise((res, rej) => {
      const r = new FileReader();
      r.onload = () => res(r.result);
      r.onerror = rej;
      r.readAsDataURL(file);
    });
    try {
      await loadCustomFont(name, dataUrl);
      state.customFonts = state.customFonts.filter(f => f.name !== name);
      state.customFonts.push({ name, dataUrl });
    } catch (err) {
      alert(`שגיאה בטעינת הפונט "${file.name}": ${err.message || err}`);
    }
  }
  populateFontSelect();
  renderFontLists();
}

function removeCustomFont(name) {
  state.customFonts = state.customFonts.filter(f => f.name !== name);
  document.fonts.forEach(f => { if (f.family === name) document.fonts.delete(f); });
  if (state.globalStyle.fontFamily === name) state.globalStyle.fontFamily = 'Arial';
  for (const sub of state.subtitles) {
    if (sub.style && sub.style.fontFamily === name) sub.style.fontFamily = 'Arial';
  }
  populateFontSelect();
  renderFontLists();
  syncStylePanel();
  renderOverlay(true);
}

$('input-font').addEventListener('change', (e) => {
  addFontFiles([...e.target.files]);
  e.target.value = '';
});

// ---------- וידאו ----------
function loadVideoFile(file) {
  const url = URL.createObjectURL(file);
  video.src = url;
  state.hasMedia = true;
  $('video-placeholder').classList.add('hidden');
}

$('input-video').addEventListener('change', (e) => {
  if (e.target.files[0]) loadVideoFile(e.target.files[0]);
});
$('input-video-2').addEventListener('change', (e) => {
  if (e.target.files[0]) loadVideoFile(e.target.files[0]);
});

video.addEventListener('loadedmetadata', () => {
  state.duration = video.duration;
  renderTimeline();
  updateTimeUI();
});
video.addEventListener('play', updatePlayButton);
video.addEventListener('pause', updatePlayButton);

$('btn-play').addEventListener('click', togglePlay);
$('video-container').addEventListener('click', (e) => {
  if (state.hasMedia && e.target === video) togglePlay();
});

seekBar.addEventListener('input', () => {
  seekTo(seekBar.value / 1000 * timelineDuration());
});

// ---------- כפתורי כתוביות ----------
$('btn-add-subtitle').addEventListener('click', () => {
  const lastEnd = state.subtitles.reduce((m, s) => Math.max(m, s.end), 0);
  addSubtitle(lastEnd, lastEnd + 3);
});

$('btn-add-at-time').addEventListener('click', () => {
  const t = currentTime();
  addSubtitle(t, t + 3);
});

// ---------- טאבים ----------
document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    tab.classList.add('active');
    $('tab-' + tab.dataset.tab).classList.add('active');
  });
});

// ---------- ייצוא ----------
function srtTime(sec) {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = Math.floor(sec % 60);
  const ms = Math.round((sec - Math.floor(sec)) * 1000);
  const pad = (n, l = 2) => String(n).padStart(l, '0');
  return `${pad(h)}:${pad(m)}:${pad(s)},${pad(ms, 3)}`;
}

function vttTime(sec) {
  return srtTime(sec).replace(',', '.');
}

function exportSRT() {
  sortSubtitles();
  const out = state.subtitles.map((s, i) =>
    `${i + 1}\n${srtTime(s.start)} --> ${srtTime(s.end)}\n${s.text}\n`
  ).join('\n');
  downloadFile('subtitles.srt', out, 'text/plain');
}

function exportVTT() {
  sortSubtitles();
  let out = 'WEBVTT\n\n';
  out += state.subtitles.map((s, i) => {
    const st = effectiveStyle(s);
    const line = st.position <= 50 ? Math.round(st.position) : Math.round(st.position);
    return `${i + 1}\n${vttTime(s.start)} --> ${vttTime(s.end)} line:${line}%\n${s.text}\n`;
  }).join('\n');
  downloadFile('subtitles.vtt', out, 'text/vtt');
}

function saveProject() {
  const data = {
    version: 1,
    subtitles: state.subtitles,
    globalStyle: state.globalStyle,
    customFonts: state.customFonts,
  };
  downloadFile('subtitle-project.json', JSON.stringify(data, null, 2), 'application/json');
}

async function importProject(file) {
  try {
    const data = JSON.parse(await file.text());
    state.subtitles = (data.subtitles || []).map(s => ({
      id: s.id, start: s.start, end: s.end, text: s.text || '',
      style: s.style ? { ...DEFAULT_STYLE, ...s.style } : null,
    }));
    state.globalStyle = { ...DEFAULT_STYLE, ...(data.globalStyle || {}) };
    state.customFonts = [];
    for (const f of (data.customFonts || [])) {
      try {
        await loadCustomFont(f.name, f.dataUrl);
        state.customFonts.push(f);
      } catch { /* פונט פגום — מדלגים */ }
    }
    state.nextId = state.subtitles.reduce((m, s) => Math.max(m, s.id || 0), 0) + 1;
    state.selectedId = null;
    populateFontSelect();
    renderFontLists();
    renderAll();
    syncStylePanel();
  } catch (err) {
    alert('שגיאה בטעינת הפרויקט: ' + (err.message || err));
  }
}

function downloadFile(filename, content, mime) {
  const blob = new Blob([content], { type: mime + ';charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 5000);
}

$('btn-export-srt').addEventListener('click', exportSRT);
$('btn-export-vtt').addEventListener('click', exportVTT);
$('btn-save-project').addEventListener('click', saveProject);
$('btn-import-project').addEventListener('click', () => $('input-import-project').click());
$('input-import-project').addEventListener('change', (e) => {
  if (e.target.files[0]) importProject(e.target.files[0]);
  e.target.value = '';
});

// ---------- תרגום אוטומטי ----------
async function translateText(text, target) {
  try {
    const url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto'
      + '&tl=' + encodeURIComponent(target) + '&dt=t&q=' + encodeURIComponent(text);
    const res = await fetch(url);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    return data[0].filter(Boolean).map(seg => seg[0]).join('');
  } catch (_) {
    const url2 = 'https://api.mymemory.translated.net/get?q=' + encodeURIComponent(text)
      + '&langpair=Autodetect|' + encodeURIComponent(target);
    const res2 = await fetch(url2);
    if (!res2.ok) throw new Error('HTTP ' + res2.status);
    const data2 = await res2.json();
    if (data2.responseStatus !== 200) throw new Error(data2.responseDetails || 'התרגום נכשל');
    return data2.responseData.translatedText;
  }
}

let translating = false;

async function translateSubtitles(subs) {
  if (translating) return;
  const status = $('translate-status');
  if (!subs.length) { status.textContent = 'אין כתוביות לתרגום.'; return; }

  translating = true;
  $('btn-translate-all').disabled = true;
  $('btn-translate-selected').disabled = true;

  const target = $('sel-target-lang').value;
  const mode = document.querySelector('input[name="translate-mode"]:checked').value;
  let done = 0, failed = 0;

  for (const sub of subs) {
    status.textContent = `מתרגם... ${done + failed + 1} מתוך ${subs.length}`;
    try {
      const translated = await translateText(sub.text, target);
      sub.text = (mode === 'append') ? sub.text + '\n' + translated : translated;
      done++;
      renderSubtitleList();
      renderTimeline();
      renderOverlay(true);
    } catch (_) {
      failed++;
    }
    // השהיה קצרה כדי לא להיחסם על ידי שירות התרגום
    await new Promise(r => setTimeout(r, 250));
  }

  translating = false;
  $('btn-translate-all').disabled = false;
  $('btn-translate-selected').disabled = false;

  if (failed === subs.length) {
    status.textContent = '❌ לא ניתן לגשת לשירות התרגום. בדקו חיבור לאינטרנט. ' +
      '(בגרסה המתארחת ב-Artifact הגישה לרשת חסומה — הורידו את הקובץ ופתחו אותו מקומית)';
  } else if (failed > 0) {
    status.textContent = `✅ תורגמו ${done} כתוביות, ${failed} נכשלו — נסו שוב.`;
  } else {
    status.textContent = `✅ תורגמו ${done} כתוביות בהצלחה.`;
  }
}

$('btn-translate-all').addEventListener('click', () => translateSubtitles([...state.subtitles]));
$('btn-translate-selected').addEventListener('click', () => {
  const sub = getSubtitle(state.selectedId);
  if (!sub) { $('translate-status').textContent = 'בחרו קודם כתובית בטאב "כתוביות".'; return; }
  translateSubtitles([sub]);
});

// ---------- קיצורי מקלדת ----------
document.addEventListener('keydown', (e) => {
  if (e.target.matches('input, textarea, select')) return;
  if (e.code === 'Space') { e.preventDefault(); togglePlay(); }
  if (e.code === 'ArrowLeft') seekTo(currentTime() - (e.shiftKey ? 1 : 0.1));
  if (e.code === 'ArrowRight') seekTo(currentTime() + (e.shiftKey ? 1 : 0.1));
});

// ---------- אתחול ----------
function renderAll() {
  renderSubtitleList();
  renderTimeline();
  renderOverlay(true);
  updateTimeUI();
}

populateFontSelect();
renderFontLists();
syncStylePanel();
renderAll();
