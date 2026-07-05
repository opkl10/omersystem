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
  maxWordsPerLine: 0,    // 0 = אוטומטי (בלי שבירת שורות)
  highlightColor: '#ffd400',
  highlightBold: true,
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
  if (exportingVideo) return;
  t = Math.max(0, Math.min(t, timelineDuration()));
  if (state.hasMedia) video.currentTime = t;
  else clockTime = t;
  updateTimeUI();
  renderOverlay(true);
}

function togglePlay() {
  if (exportingVideo) return;
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

// ---------- שבירת שורות והדגשת מילים ----------
// שבירת הטקסט לשורות של עד N מילים (0 = בלי שבירה)
function wrapWords(text, maxWords) {
  if (!maxWords || maxWords < 1) return text;
  return text.split('\n').map(line => {
    const words = line.split(/\s+/).filter(Boolean);
    const rows = [];
    for (let i = 0; i < words.length; i += maxWords) {
      rows.push(words.slice(i, i + maxWords).join(' '));
    }
    return rows.join('\n');
  }).join('\n');
}

// פירוק טקסט לקטעים רגילים ומודגשים לפי תחביר *מילה*
function parseHighlights(text) {
  const parts = [];
  const re = /\*([^*\n]+)\*/g;
  let last = 0, m;
  while ((m = re.exec(text))) {
    if (m.index > last) parts.push({ text: text.slice(last, m.index), hl: false });
    parts.push({ text: m[1], hl: true });
    last = m.index + m[0].length;
  }
  if (last < text.length) parts.push({ text: text.slice(last), hl: false });
  return parts;
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

    const displayText = wrapWords(sub.text, st.maxWordsPerLine);
    const segments = parseHighlights(displayText);
    const hlStyle = (el) => {
      el.style.color = st.highlightColor;
      if (st.highlightBold) el.style.fontWeight = '700';
    };

    if (st.effect === 'typewriter') {
      line.classList.add('fx-typewriter');
      line.dataset.subId = sub.id;
      line.dataset.start = sub.start;
      line.dataset.dur = st.effectDuration;
      for (const seg of segments) {
        for (const ch of seg.text) {
          const chSpan = document.createElement('span');
          chSpan.className = 'tw-char';
          chSpan.textContent = ch;
          chSpan.style.padding = '0';
          chSpan.style.borderRadius = '0';
          if (seg.hl && st.effect !== 'rainbow') hlStyle(chSpan);
          span.appendChild(chSpan);
        }
      }
    } else {
      for (const seg of segments) {
        const segSpan = document.createElement('span');
        segSpan.textContent = seg.text;
        segSpan.style.padding = '0';
        segSpan.style.borderRadius = '0';
        if (seg.hl && st.effect !== 'rainbow') hlStyle(segSpan);
        span.appendChild(segSpan);
      }
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
  maxWordsPerLine: $('rng-words-per-line'),
  highlightColor: $('clr-highlight'),
  highlightBold: $('chk-highlight-bold'),
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
  styleInputs.maxWordsPerLine.value = st.maxWordsPerLine;
  styleInputs.highlightColor.value = st.highlightColor;
  styleInputs.highlightBold.checked = st.highlightBold;
  styleInputs.effect.value = st.effect;
  styleInputs.effectDuration.value = st.effectDuration;
  updateStyleLabels();
}

function updateStyleLabels() {
  $('font-size-value').textContent = styleInputs.fontSize.value;
  $('outline-width-value').textContent = styleInputs.outlineWidth.value;
  $('bg-opacity-value').textContent = styleInputs.bgOpacity.value;
  $('position-value').textContent = styleInputs.position.value;
  const wpl = Number(styleInputs.maxWordsPerLine.value);
  $('words-per-line-value').textContent = wpl === 0 ? 'אוטומטי' : wpl;
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
  target.maxWordsPerLine = Number(styleInputs.maxWordsPerLine.value);
  target.highlightColor = styleInputs.highlightColor.value;
  target.highlightBold = styleInputs.highlightBold.checked;
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
  state.mediaFile = file;
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

// טקסט לייצוא: שבירת שורות לפי "מילים בשורה" + תגיות הדגשה
function subtitleExportText(sub, format) {
  const st = effectiveStyle(sub);
  const wrapped = wrapWords(sub.text, st.maxWordsPerLine);
  return parseHighlights(wrapped).map(seg => {
    if (!seg.hl) return seg.text;
    if (format === 'srt') {
      const inner = st.highlightBold ? `<b>${seg.text}</b>` : seg.text;
      return `<font color="${st.highlightColor}">${inner}</font>`;
    }
    return `<b>${seg.text}</b>`;
  }).join('');
}

function exportSRT() {
  sortSubtitles();
  const out = state.subtitles.map((s, i) =>
    `${i + 1}\n${srtTime(s.start)} --> ${srtTime(s.end)}\n${subtitleExportText(s, 'srt')}\n`
  ).join('\n');
  downloadFile('subtitles.srt', out, 'text/plain');
}

function exportVTT() {
  sortSubtitles();
  let out = 'WEBVTT\n\n';
  out += state.subtitles.map((s, i) => {
    const st = effectiveStyle(s);
    return `${i + 1}\n${vttTime(s.start)} --> ${vttTime(s.end)} line:${Math.round(st.position)}%\n${subtitleExportText(s, 'vtt')}\n`;
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
const delay = (ms) => new Promise(r => setTimeout(r, ms));

async function fetchWithTimeout(url, ms = 15000) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), ms);
  try {
    return await fetch(url, { signal: ctrl.signal });
  } finally {
    clearTimeout(timer);
  }
}

async function translateText(text, target) {
  try {
    const url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto'
      + '&tl=' + encodeURIComponent(target) + '&dt=t&q=' + encodeURIComponent(text);
    const res = await fetchWithTimeout(url);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    const out = data[0].filter(Boolean).map(seg => seg[0]).join('');
    if (!out.trim()) throw new Error('תשובה ריקה');
    return out;
  } catch (_) {
    const url2 = 'https://api.mymemory.translated.net/get?q=' + encodeURIComponent(text)
      + '&langpair=Autodetect|' + encodeURIComponent(target);
    const res2 = await fetchWithTimeout(url2);
    if (!res2.ok) throw new Error('HTTP ' + res2.status);
    const data2 = await res2.json();
    if (data2.responseStatus !== 200) throw new Error(data2.responseDetails || 'התרגום נכשל');
    const out2 = (data2.responseData.translatedText || '').trim();
    if (!out2) throw new Error('תשובה ריקה');
    return out2;
  }
}

let translating = false;

async function translateSubtitles(subs) {
  if (translating) return;
  const status = $('translate-status');
  const queue = subs.filter(s => s.text.trim());
  if (!queue.length) { status.textContent = 'אין כתוביות לתרגום.'; return; }

  translating = true;
  $('btn-translate-all').disabled = true;
  $('btn-translate-selected').disabled = true;

  const target = $('sel-target-lang').value;
  const mode = document.querySelector('input[name="translate-mode"]:checked').value;
  const total = queue.length;
  let done = 0;
  let pending = [...queue];

  // עד 3 סבבים: שורות שנכשלו (חסימת קצב, תקלת רשת) מנוסות שוב עם השהיה גדלה
  for (let attempt = 0; attempt < 3 && pending.length; attempt++) {
    if (attempt > 0) {
      status.textContent = `ממתין וחוזר על ${pending.length} שורות שנכשלו (ניסיון ${attempt + 1} מתוך 3)...`;
      await delay(2500 * attempt);
    }
    const failedThisRound = [];
    for (const sub of pending) {
      status.textContent = `מתרגם... ${done + 1} מתוך ${total}` + (attempt > 0 ? ` (ניסיון ${attempt + 1})` : '');
      try {
        const translated = await translateText(sub.text, target);
        sub.text = (mode === 'append') ? sub.text + '\n' + translated : translated;
        done++;
        renderSubtitleList();
        renderTimeline();
        renderOverlay(true);
      } catch (_) {
        failedThisRound.push(sub);
      }
      // השהיה בין בקשות כדי לא להיחסם; גדלה בניסיונות חוזרים
      await delay(300 + attempt * 700);
    }
    pending = failedThisRound;
  }

  translating = false;
  $('btn-translate-all').disabled = false;
  $('btn-translate-selected').disabled = false;

  if (done === 0) {
    status.textContent = '❌ לא ניתן לגשת לשירות התרגום. בדקו חיבור לאינטרנט. ' +
      '(בגרסה המתארחת ב-Artifact הגישה לרשת חסומה — הורידו את הקובץ ופתחו אותו מקומית)';
  } else if (pending.length > 0) {
    const nums = pending.map(sub => state.subtitles.indexOf(sub) + 1).join(', ');
    status.textContent = `⚠️ תורגמו ${done} מתוך ${total}. שורות שלא תורגמו: ${nums}. ` +
      'המתינו כדקה ולחצו שוב על "תרגום כל הכתוביות" — שורות שכבר תורגמו במצב החלפה יישארו בשפת היעד.';
  } else {
    status.textContent = `✅ תורגמו כל ${done} הכתוביות בהצלחה.`;
  }
}

$('btn-translate-all').addEventListener('click', () => translateSubtitles([...state.subtitles]));
$('btn-translate-selected').addEventListener('click', () => {
  const sub = getSubtitle(state.selectedId);
  if (!sub) { $('translate-status').textContent = 'בחרו קודם כתובית בטאב "כתוביות".'; return; }
  translateSubtitles([sub]);
});

// ---------- ייצוא וידאו עם כתוביות צרובות ----------
let exportingVideo = false;
let exportRecorder = null;
let exportAudioCtx = null;
let exportSourceNode = null;

// ציור הכתוביות הפעילות על קנבס בזמן t, בסגנון ובאפקטים של התצוגה המקדימה
function drawSubtitlesOnCanvas(ctx, W, H, t, scale) {
  const active = state.subtitles.filter(s => t >= s.start && t < s.end);
  for (const sub of active) {
    const st = effectiveStyle(sub);
    const dur = Math.max(st.effectDuration, 0.05);
    const p = Math.min(Math.max((t - sub.start) / dur, 0), 1);
    const et = t - sub.start;

    let alpha = 1, offX = 0, offY = 0, zoom = 1, shadowBlur = 0, colorOverride = null, visFrac = 1;
    switch (st.effect) {
      case 'fade': alpha = p; break;
      case 'slide-up': alpha = p; offY = (1 - p) * 40 * scale; break;
      case 'slide-down': alpha = p; offY = -(1 - p) * 40 * scale; break;
      case 'zoom': alpha = p; zoom = 0.3 + 0.7 * p; break;
      case 'bounce':
        alpha = Math.min(p / 0.6, 1);
        if (p < 0.6) offY = (1 - p / 0.6) * 60 * scale;
        else if (p < 0.8) offY = -12 * ((p - 0.6) / 0.2) * scale * (1 - (p - 0.6) / 0.2) * 4;
        else offY = 5 * (1 - (p - 0.8) / 0.2) * scale;
        break;
      case 'typewriter': visFrac = p; break;
      case 'glow': shadowBlur = 18 * scale; break;
      case 'pulse': zoom = 1 + 0.06 * Math.sin(et * 2 * Math.PI / 1.2); break;
      case 'shake': offX = 6 * scale * Math.sin(et * 2 * Math.PI / 0.35); break;
      case 'rainbow': colorOverride = `hsl(${Math.round(et * 140) % 360}, 90%, 65%)`; break;
    }

    const fontPx = st.fontSize * scale;
    const lineH = fontPx * 1.35;
    const wrapped = wrapWords(sub.text, st.maxWordsPerLine);
    const lines = wrapped.split('\n').map(parseHighlights);
    const rtl = /[֐-ࣿ]/.test(wrapped);

    let charBudget = Infinity;
    if (st.effect === 'typewriter') {
      const totalChars = lines.flat().reduce((n, s) => n + s.text.length, 0);
      charBudget = Math.ceil(visFrac * totalChars);
    }

    const centerX = W / 2 + offX;
    const centerY = st.position / 100 * H + offY;
    const totalH = lines.length * lineH;

    const segFont = (hl) => `${st.italic ? 'italic ' : ''}${(st.bold || (hl && st.highlightBold)) ? '700' : '400'} ${fontPx}px "${st.fontFamily}", sans-serif`;

    ctx.save();
    ctx.globalAlpha = alpha;
    if (zoom !== 1) {
      ctx.translate(centerX, centerY);
      ctx.scale(zoom, zoom);
      ctx.translate(-centerX, -centerY);
    }
    ctx.textBaseline = 'middle';

    lines.forEach((segs, li) => {
      const y = centerY - totalH / 2 + lineH * (li + 0.5);

      // פירוק למילים עם רווחים מפורשים — כך רוחב הרווח לא תלוי בכיווניות הטקסט
      const tokens = [];
      for (const seg of segs) {
        for (const w of seg.text.split(/\s+/)) {
          if (w) tokens.push({ w, hl: seg.hl });
        }
      }
      if (!tokens.length) return;

      ctx.font = segFont(false);
      const spaceW = ctx.measureText(' ').width;
      const widths = tokens.map(tok => {
        ctx.font = segFont(tok.hl);
        return ctx.measureText(tok.w).width;
      });
      const lineW = widths.reduce((a, b) => a + b, 0) + spaceW * (tokens.length - 1);

      if (st.bgOpacity > 0) {
        ctx.save();
        ctx.globalAlpha = alpha * st.bgOpacity / 100;
        ctx.fillStyle = st.bgColor;
        ctx.fillRect(centerX - lineW / 2 - fontPx * 0.3, y - lineH / 2, lineW + fontPx * 0.6, lineH);
        ctx.restore();
      }

      // ב-RTL המילה הלוגית הראשונה מצוירת מימין; ב-LTR משמאל
      let x = rtl ? centerX + lineW / 2 : centerX - lineW / 2;
      ctx.textAlign = rtl ? 'right' : 'left';

      tokens.forEach((tok, ti) => {
        let drawText = tok.w;
        if (charBudget !== Infinity) {
          drawText = charBudget <= 0 ? '' : tok.w.slice(0, charBudget);
          charBudget -= tok.w.length + 1; // כולל הרווח שאחרי המילה
        }
        if (drawText) {
          ctx.font = segFont(tok.hl);
          const color = tok.hl ? st.highlightColor : (colorOverride || st.textColor);
          if (shadowBlur > 0) {
            ctx.shadowColor = color;
            ctx.shadowBlur = shadowBlur;
          }
          if (st.outlineWidth > 0) {
            ctx.strokeStyle = st.outlineColor;
            ctx.lineWidth = st.outlineWidth * 2 * scale;
            ctx.lineJoin = 'round';
            ctx.strokeText(drawText, x, y);
          }
          ctx.fillStyle = color;
          ctx.fillText(drawText, x, y);
          ctx.shadowBlur = 0;
        }
        x += (rtl ? -1 : 1) * (widths[ti] + spaceW);
      });
    });
    ctx.restore();
  }
}

async function exportVideoWithSubtitles() {
  if (exportingVideo) return;
  if (!state.hasMedia || !video.videoWidth) {
    alert('טענו קודם קובץ וידאו (לא רק אודיו) כדי לייצא אותו עם כתוביות.');
    return;
  }

  exportingVideo = true;
  $('btn-export-video').disabled = true;
  $('export-video-bar').hidden = false;
  $('export-video-progress').style.width = '0%';

  const W = video.videoWidth, H = video.videoHeight;
  const canvas = document.createElement('canvas');
  canvas.width = W;
  canvas.height = H;
  const ctx = canvas.getContext('2d');
  // יחס בין רזולוציית הווידאו לגובה התצוגה המקדימה — כדי שגודל הטקסט יתאים למה שרואים
  const scale = H / (overlay.clientHeight || 540);

  const stream = canvas.captureStream(30);
  try {
    if (!exportAudioCtx) {
      exportAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
      exportSourceNode = exportAudioCtx.createMediaElementSource(video);
      exportSourceNode.connect(exportAudioCtx.destination);
    }
    await exportAudioCtx.resume();
    const dest = exportAudioCtx.createMediaStreamDestination();
    exportSourceNode.connect(dest);
    const audioTrack = dest.stream.getAudioTracks()[0];
    if (audioTrack) stream.addTrack(audioTrack);
  } catch (_) { /* ממשיכים בלי אודיו */ }

  const mime = [
    'video/mp4;codecs=avc1,mp4a.40.2', 'video/mp4',
    'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm',
  ].find(m => MediaRecorder.isTypeSupported(m)) || '';
  const ext = mime.startsWith('video/mp4') ? 'mp4' : 'webm';

  const chunks = [];
  exportRecorder = new MediaRecorder(stream, {
    ...(mime ? { mimeType: mime } : {}),
    videoBitsPerSecond: 8_000_000,
  });
  exportRecorder.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };

  let cancelled = false;
  exportRecorder.onstop = () => {
    if (!cancelled && chunks.length) {
      const blob = new Blob(chunks, { type: mime || 'video/webm' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'video-with-subtitles.' + ext;
      a.click();
      setTimeout(() => URL.revokeObjectURL(a.href), 10000);
    }
    exportingVideo = false;
    exportRecorder = null;
    $('btn-export-video').disabled = false;
    $('export-video-bar').hidden = true;
    video.pause();
    updatePlayButton();
  };

  $('btn-cancel-export').onclick = () => {
    cancelled = true;
    if (exportRecorder && exportRecorder.state !== 'inactive') exportRecorder.stop();
  };

  // חזרה לתחילת הסרטון והקלטה בזמן אמת
  video.pause();
  video.currentTime = 0;
  await new Promise(r => {
    if (video.currentTime === 0 && video.readyState >= 2) r();
    else video.addEventListener('seeked', r, { once: true });
  });

  exportRecorder.start(1000);
  await video.play();
  updatePlayButton();

  const drawLoop = () => {
    if (!exportingVideo || !exportRecorder) return;
    ctx.drawImage(video, 0, 0, W, H);
    drawSubtitlesOnCanvas(ctx, W, H, video.currentTime, scale);
    $('export-video-progress').style.width = (video.currentTime / video.duration * 100) + '%';
    if (video.ended) {
      if (exportRecorder.state !== 'inactive') exportRecorder.stop();
      return;
    }
    requestAnimationFrame(drawLoop);
  };
  drawLoop();
}

$('btn-export-video').addEventListener('click', exportVideoWithSubtitles);

// ---------- תמלול אוטומטי (Whisper בדפדפן) ----------
const WHISPER_MODELS = {
  tiny: 'Xenova/whisper-tiny',
  base: 'Xenova/whisper-base',
  small: 'Xenova/whisper-small',
};
const TRANSFORMERS_URL = window.TRANSFORMERS_URL
  || 'https://cdn.jsdelivr.net/npm/@xenova/transformers@2.17.2/dist/transformers.min.js';

let transformersPromise = null;
const transcriberCache = {};
let transcribing = false;

function loadTransformersLib() {
  if (!transformersPromise) {
    transformersPromise = import(TRANSFORMERS_URL).catch(err => {
      transformersPromise = null;
      throw err;
    });
  }
  return transformersPromise;
}

// חילוץ האודיו מקובץ הווידאו כ-PCM מונו ב-16kHz (הקצב ש-Whisper מצפה לו)
async function extractAudio(file) {
  const arrayBuf = await file.arrayBuffer();
  const AudioCtx = window.AudioContext || window.webkitAudioContext;
  const ctx = new AudioCtx({ sampleRate: 16000 });
  try {
    const audioBuf = await ctx.decodeAudioData(arrayBuf);
    if (audioBuf.numberOfChannels === 1) return audioBuf.getChannelData(0);
    const ch0 = audioBuf.getChannelData(0);
    const ch1 = audioBuf.getChannelData(1);
    const mono = new Float32Array(ch0.length);
    for (let i = 0; i < ch0.length; i++) mono[i] = (ch0[i] + ch1[i]) / 2;
    return mono;
  } finally {
    ctx.close();
  }
}

function setTranscribeProgress(frac) {
  const wrap = $('transcribe-progress-wrap');
  wrap.hidden = frac === null;
  if (frac !== null) $('transcribe-progress').style.width = Math.round(frac * 100) + '%';
}

async function getTranscriber(modelKey, statusEl) {
  if (transcriberCache[modelKey]) return transcriberCache[modelKey];
  const { pipeline } = await loadTransformersLib();
  const fileProgress = {};
  const transcriber = await pipeline('automatic-speech-recognition', WHISPER_MODELS[modelKey], {
    quantized: true,
    progress_callback: (p) => {
      if (p.status === 'progress' && p.total) {
        fileProgress[p.file] = { loaded: p.loaded, total: p.total };
        let loaded = 0, total = 0;
        for (const f of Object.values(fileProgress)) { loaded += f.loaded; total += f.total; }
        statusEl.textContent = `מוריד את מודל התמלול... ${(loaded / 1048576).toFixed(0)}MB / ${(total / 1048576).toFixed(0)}MB`;
        setTranscribeProgress(total ? loaded / total : 0);
      }
    },
  });
  transcriberCache[modelKey] = transcriber;
  return transcriber;
}

// פיצול בלוק תמלול ארוך לכתוביות קצרות: לפי משפטים, ומשפטים ארוכים לפי מילים.
// הזמן מחולק פרופורציונלית לאורך הטקסט של כל חלק.
function splitTranscriptChunk(text, start, end) {
  if (!text) return [];
  const sentences = (text.match(/[^.!?…]+[.!?…]*/g) || [text]).map(s => s.trim()).filter(Boolean);
  const parts = [];
  for (const s of sentences) {
    const words = s.split(/\s+/);
    if (words.length > 14) {
      for (let i = 0; i < words.length; i += 10) {
        parts.push(words.slice(i, i + 10).join(' '));
      }
    } else {
      parts.push(s);
    }
  }
  const totalChars = parts.reduce((n, p) => n + p.length, 0) || 1;
  const dur = Math.max(end - start, 0.3);
  const out = [];
  let t = start;
  for (const p of parts) {
    const d = dur * (p.length / totalChars);
    out.push({ start: t, end: t + d, text: p });
    t += d;
  }
  return out;
}

async function transcribeVideo() {
  if (transcribing) return;
  const status = $('transcribe-status');
  if (!state.mediaFile) {
    status.textContent = 'טענו קודם קובץ וידאו או אודיו.';
    return;
  }

  transcribing = true;
  $('btn-transcribe').disabled = true;

  try {
    status.textContent = 'טוען את ספריית התמלול...';
    setTranscribeProgress(0);
    const transcriber = await getTranscriber($('sel-whisper-model').value, status);

    status.textContent = 'מחלץ אודיו מהסרטון...';
    setTranscribeProgress(null);
    const audio = await extractAudio(state.mediaFile);

    status.textContent = 'מתמלל... (בסרטון ארוך זה עשוי לקחת כמה דקות)';
    const lang = $('sel-speech-lang').value;
    const result = await transcriber(audio, {
      chunk_length_s: 30,
      stride_length_s: 5,
      return_timestamps: true,
      ...(lang !== 'auto' ? { language: lang, task: 'transcribe' } : {}),
    });

    const chunks = (result.chunks || []).filter(c => (c.text || '').trim());
    if (!chunks.length) {
      status.textContent = 'לא זוהה דיבור בסרטון.';
      return;
    }

    if ($('chk-clear-before-transcribe').checked) {
      state.subtitles = [];
      state.selectedId = null;
    }

    let prevEnd = 0;
    let created = 0;
    for (const c of chunks) {
      const start = c.timestamp[0] ?? prevEnd;
      const end = c.timestamp[1] ?? (start + 3);
      for (const piece of splitTranscriptChunk(c.text.trim(), start, end)) {
        state.subtitles.push({ id: state.nextId++, start: piece.start, end: piece.end, text: piece.text, style: null });
        created++;
      }
      prevEnd = end;
    }
    sortSubtitles();
    renderAll();
    status.textContent = `✅ נוצרו ${created} כתוביות מהתמלול. אפשר לערוך אותן בטאב "כתוביות" או לתרגם בטאב "תרגום".`;
  } catch (err) {
    status.textContent = '❌ התמלול נכשל: ' + (err.message || err) +
      ' — ודאו חיבור לאינטרנט להורדת המודל. (בגרסה המתארחת ב-Artifact הגישה לרשת חסומה — הורידו את הקובץ ופתחו מקומית)';
  } finally {
    setTranscribeProgress(null);
    transcribing = false;
    $('btn-transcribe').disabled = false;
  }
}

$('btn-transcribe').addEventListener('click', transcribeVideo);

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
