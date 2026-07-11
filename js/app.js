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
  maxLines: 0,           // 0 = בלי הגבלה; אחרת הכתובית מתחלפת בדפים של N שורות
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
  cuts: [],               // קטעים שקטים למחיקה: { start, end }
  audioData: null,        // מטמון PCM 16kHz של האודיו (לגל קול, שקט, תמלול)
  speakers: {},           // מזהה דובר -> שם (מזיהוי דוברים בתמלול)
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
  const lastEnd = state.subtitles.reduce((m, s) => Math.max(m, s.end || 0), 0);
  const dur = isFinite(state.duration) ? state.duration : 0;
  return Math.max(dur, lastEnd, 10);
}

function renderTimeline() {
  timelineEl.querySelectorAll('.timeline-block, .timeline-cut').forEach(el => el.remove());
  const dur = timelineDuration();
  for (const cut of state.cuts) {
    const el = document.createElement('div');
    el.className = 'timeline-cut';
    el.style.left = (cut.start / dur * 100) + '%';
    el.style.width = ((cut.end - cut.start) / dur * 100) + '%';
    el.title = `קטע שקט שיימחק: ${formatTime(cut.start)} → ${formatTime(cut.end)}\nלחיצה מבטלת את החיתוך הזה`;
    el.addEventListener('click', (e) => {
      e.stopPropagation();
      state.cuts = state.cuts.filter(c => c !== cut);
      $('cuts-status').textContent = 'החיתוך בוטל. נשארו ' + state.cuts.length + ' קטעים למחיקה.';
      renderTimeline();
    });
    timelineEl.appendChild(el);
  }
  for (const sub of state.subtitles) {
    const block = document.createElement('div');
    block.className = 'timeline-block' + (sub.id === state.selectedId ? ' selected' : '');
    block.style.left = (sub.start / dur * 100) + '%';
    block.style.width = Math.max((sub.end - sub.start) / dur * 100, 0.5) + '%';
    block.textContent = sub.text;
    block.title = `${formatTime(sub.start)} → ${formatTime(sub.end)}\nגרירה = הזזה, קצוות = שינוי משך`;
    const hl = document.createElement('div');
    hl.className = 'tb-handle tb-handle-l';
    const hr = document.createElement('div');
    hr.className = 'tb-handle tb-handle-r';
    block.append(hl, hr);
    block.addEventListener('mousedown', (e) => startBlockDrag(e, sub, block));
    block.addEventListener('click', (e) => {
      e.stopPropagation();
      if (blockDragMoved) return;
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
  if (blockDragMoved) return;
  const rect = timelineEl.getBoundingClientRect();
  const frac = (e.clientX - rect.left) / rect.width;
  seekTo(frac * timelineDuration());
});

// ---------- גרירת בלוקים על ציר הזמן ----------
let blockDragMoved = false;

function startBlockDrag(e, sub, block) {
  if (exportingVideo || !isFinite(timelineDuration())) return;
  e.preventDefault();
  e.stopPropagation();
  const mode = e.target.classList.contains('tb-handle-l') ? 'start'
    : e.target.classList.contains('tb-handle-r') ? 'end' : 'move';
  const rect = timelineEl.getBoundingClientRect();
  const dur = timelineDuration();
  const x0 = e.clientX;
  const orig = { start: sub.start, end: sub.end };
  blockDragMoved = false;
  selectSubtitle(sub.id);

  const onMove = (ev) => {
    const dt = (ev.clientX - x0) / rect.width * dur;
    if (Math.abs(ev.clientX - x0) > 2) blockDragMoved = true;
    if (mode === 'move') {
      const len = orig.end - orig.start;
      sub.start = Math.max(0, Math.min(orig.start + dt, dur - len));
      sub.end = sub.start + len;
    } else if (mode === 'start') {
      sub.start = Math.max(0, Math.min(orig.start + dt, sub.end - 0.2));
    } else {
      sub.end = Math.max(sub.start + 0.2, Math.min(orig.end + dt, dur));
    }
    block.style.left = (sub.start / dur * 100) + '%';
    block.style.width = Math.max((sub.end - sub.start) / dur * 100, 0.5) + '%';
    renderOverlay(true);
  };
  const onUp = () => {
    document.removeEventListener('mousemove', onMove);
    document.removeEventListener('mouseup', onUp);
    sortSubtitles();
    renderAll();
    // מניעת ה-click שנורה מיד אחרי גרירה
    setTimeout(() => { blockDragMoved = false; }, 50);
  };
  document.addEventListener('mousemove', onMove);
  document.addEventListener('mouseup', onUp);
}

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
  // דילוג על רגעים שקטים בתצוגה מקדימה (לא בזמן ייצוא — שם הלולאה מטפלת בזה)
  if (!exportingVideo && state.cuts.length && $('chk-skip-silences').checked) {
    const playing = state.hasMedia ? !video.paused : clockPlaying;
    if (playing) {
      const cut = activeCutAt(currentTime());
      if (cut) {
        if (state.hasMedia) video.currentTime = cut.end + 0.01;
        else clockTime = cut.end + 0.01;
      }
    }
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

// הדגשה של כמה מילים יחד ("*שתי מילים*") מפורקת לסימון פר-מילה,
// כדי ששבירת שורות באמצע ההדגשה לא תשבור את הזיהוי
function normalizeHighlights(text) {
  return text.replace(/\*([^*\n]+)\*/g, (m, inner) =>
    inner.split(/\s+/).filter(Boolean).map(w => '*' + w + '*').join(' '));
}

// הטקסט המוצג של כתובית בזמן t: שבירת שורות לפי "מילים בשורה",
// ואם יש יותר שורות מ"שורות בכל פעם" — הכתובית מתחלפת בדפים לאורך משך הזמן שלה
function subtitleDisplayText(sub, st, t) {
  const wrapped = wrapWords(normalizeHighlights(sub.text), st.maxWordsPerLine);
  const lines = wrapped.split('\n');
  if (!st.maxLines || lines.length <= st.maxLines) return wrapped;
  const pages = [];
  for (let i = 0; i < lines.length; i += st.maxLines) {
    pages.push(lines.slice(i, i + st.maxLines).join('\n'));
  }
  const dur = Math.max(sub.end - sub.start, 0.001);
  let idx = Math.floor((t - sub.start) / dur * pages.length);
  idx = Math.max(0, Math.min(pages.length - 1, idx));
  return pages[idx];
}

// חלוקת כתובית לדפים עם פרוסות זמן — לייצוא SRT/VTT
function subtitlePages(sub, st) {
  const wrapped = wrapWords(normalizeHighlights(sub.text), st.maxWordsPerLine);
  const lines = wrapped.split('\n');
  if (!st.maxLines || lines.length <= st.maxLines) {
    return [{ start: sub.start, end: sub.end, text: wrapped }];
  }
  const pages = [];
  for (let i = 0; i < lines.length; i += st.maxLines) {
    pages.push(lines.slice(i, i + st.maxLines).join('\n'));
  }
  const dur = (sub.end - sub.start) / pages.length;
  return pages.map((text, i) => ({
    start: sub.start + i * dur,
    end: sub.start + (i + 1) * dur,
    text,
  }));
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
  const key = active.map(s =>
    `${s.id}:${subtitleDisplayText(s, effectiveStyle(s), t)}:${JSON.stringify(effectiveStyle(s))}`
  ).join('|');

  if (!force && key === lastActiveKey) {
    updateTypewriters(t);
    updateKaraoke(t);
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
    line.title = 'גרירה למעלה/למטה משנה את מיקום הכתובית';
    line.addEventListener('mousedown', (e) => startSubtitleDrag(e, sub));

    if (st.effect !== 'none' && st.effect !== 'typewriter' && st.effect !== 'karaoke') {
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

    const displayText = subtitleDisplayText(sub, st, t);
    const segments = parseHighlights(displayText);
    const hlStyle = (el) => {
      el.style.color = st.highlightColor;
      if (st.highlightBold) el.style.fontWeight = '700';
    };

    if (st.effect === 'karaoke') {
      line.classList.add('fx-karaoke');
      line.dataset.start = sub.start;
      line.dataset.end = sub.end;
      line.dataset.hl = st.highlightColor;
      line.dataset.hlBold = st.highlightBold ? '1' : '';
      // מילים כיחידות נפרדות; המילה הנוכחית נצבעת לפי ההתקדמות בזמן
      const plain = displayText.replace(/\*/g, '');
      const lineTexts = plain.split('\n');
      lineTexts.forEach((lt, li) => {
        if (li > 0) span.appendChild(document.createTextNode('\n'));
        lt.split(/\s+/).filter(Boolean).forEach((w, wi, arr) => {
          const wSpan = document.createElement('span');
          wSpan.className = 'kar-word';
          wSpan.textContent = w;
          wSpan.style.padding = '0';
          wSpan.style.borderRadius = '0';
          span.appendChild(wSpan);
          if (wi < arr.length - 1) span.appendChild(document.createTextNode(' '));
        });
      });
    } else if (st.effect === 'typewriter') {
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
  updateKaraoke(t);
}

function updateKaraoke(t) {
  overlay.querySelectorAll('.fx-karaoke').forEach(line => {
    const start = Number(line.dataset.start);
    const end = Number(line.dataset.end);
    const words = line.querySelectorAll('.kar-word');
    if (!words.length) return;
    const progress = Math.min(Math.max((t - start) / Math.max(end - start, 0.001), 0), 1);
    const idx = Math.min(words.length - 1, Math.floor(progress * words.length));
    words.forEach((w, i) => {
      if (i <= idx) {
        w.style.color = line.dataset.hl;
        if (line.dataset.hlBold) w.style.fontWeight = '700';
      } else {
        w.style.color = '';
        w.style.fontWeight = '';
      }
    });
  });
}

// גרירת כתובית על הווידאו — שינוי המיקום האנכי בעיצוב המתאים (מותאם או גלובלי)
function startSubtitleDrag(e, sub) {
  if (exportingVideo) return;
  e.preventDefault();
  e.stopPropagation();
  const container = $('video-container').getBoundingClientRect();
  const target = sub.style || state.globalStyle;

  const onMove = (ev) => {
    const pos = Math.round(Math.max(5, Math.min(95, (ev.clientY - container.top) / container.height * 100)));
    target.position = pos;
    renderOverlay(true);
  };
  const onUp = () => {
    document.removeEventListener('mousemove', onMove);
    document.removeEventListener('mouseup', onUp);
    syncStylePanel();
  };
  document.addEventListener('mousemove', onMove);
  document.addEventListener('mouseup', onUp);
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
  maxLines: $('rng-max-lines'),
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
  styleInputs.maxLines.value = st.maxLines;
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
  const ml = Number(styleInputs.maxLines.value);
  $('max-lines-value').textContent = ml === 0 ? 'בלי הגבלה' : ml;
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
  target.maxLines = Number(styleInputs.maxLines.value);
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
  state.audioData = null;
  $('video-placeholder').classList.add('hidden');
  buildWaveform();
}

// מטמון האודיו — משמש את גל הקול, זיהוי השקט והתמלול בלי לפענח שוב ושוב
async function getAudioData() {
  if (!state.audioData) state.audioData = await extractAudio(state.mediaFile);
  return state.audioData;
}

// ---------- גל קול על ציר הזמן ----------
async function buildWaveform() {
  try {
    const audio = await getAudioData();
    const canvas = $('waveform-canvas');
    const buckets = 1200;
    const peaks = new Float32Array(buckets);
    const per = Math.max(1, Math.floor(audio.length / buckets));
    for (let b = 0; b < buckets; b++) {
      let max = 0;
      const start = b * per, end = Math.min(start + per, audio.length);
      for (let i = start; i < end; i += 4) {
        const v = Math.abs(audio[i]);
        if (v > max) max = v;
      }
      peaks[b] = max;
    }
    const rect = canvas.parentElement.getBoundingClientRect();
    canvas.width = Math.max(rect.width, 600) * 2;
    canvas.height = 112;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = 'rgba(79, 124, 255, 0.35)';
    const mid = canvas.height / 2;
    const bw = canvas.width / buckets;
    for (let b = 0; b < buckets; b++) {
      const h = Math.max(1, peaks[b] * canvas.height * 0.95);
      ctx.fillRect(b * bw, mid - h / 2, Math.max(bw - 1, 1), h);
    }
  } catch (_) { /* בלי גל קול — לא קריטי */ }
}

$('input-video').addEventListener('change', (e) => {
  if (e.target.files[0]) loadVideoFile(e.target.files[0]);
});
$('input-video-2').addEventListener('change', (e) => {
  if (e.target.files[0]) loadVideoFile(e.target.files[0]);
});

video.addEventListener('loadedmetadata', () => {
  if (!isFinite(video.duration)) {
    // קבצים שהוקלטו בדפדפן (MediaRecorder) מדווחים משך אינסופי עד שקופצים לסוף —
    // קפיצה לזמן עצום מאלצת את הדפדפן לחשב את המשך האמיתי
    video.currentTime = 1e7;
    video.addEventListener('seeked', () => {
      state.duration = isFinite(video.duration) ? video.duration : 0;
      video.currentTime = 0;
      renderTimeline();
      updateTimeUI();
    }, { once: true });
    return;
  }
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

// ---------- טאבים (עם תמיכת מקלדת ו-ARIA) ----------
const allTabs = [...document.querySelectorAll('.tab')];

function activateTab(tab) {
  allTabs.forEach(t => {
    t.classList.remove('active');
    t.setAttribute('aria-selected', 'false');
    t.setAttribute('tabindex', '-1');
  });
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  tab.classList.add('active');
  tab.setAttribute('aria-selected', 'true');
  tab.setAttribute('tabindex', '0');
  $('tab-' + tab.dataset.tab).classList.add('active');
}

allTabs.forEach((tab, i) => {
  tab.setAttribute('tabindex', tab.classList.contains('active') ? '0' : '-1');
  tab.addEventListener('click', () => activateTab(tab));
  // ניווט בין טאבים עם חיצי המקלדת (בממשק RTL חץ שמאלה מתקדם)
  tab.addEventListener('keydown', (e) => {
    let next = null;
    if (e.key === 'ArrowLeft') next = allTabs[(i + 1) % allTabs.length];
    if (e.key === 'ArrowRight') next = allTabs[(i - 1 + allTabs.length) % allTabs.length];
    if (e.key === 'Home') next = allTabs[0];
    if (e.key === 'End') next = allTabs[allTabs.length - 1];
    if (next) {
      e.preventDefault();
      activateTab(next);
      next.focus();
    }
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

// טקסט דף בודד לייצוא, עם תגיות הדגשה בפורמט המתאים
function pageExportText(text, st, format) {
  return parseHighlights(text).map(seg => {
    if (!seg.hl) return seg.text;
    if (format === 'srt') {
      const inner = st.highlightBold ? `<b>${seg.text}</b>` : seg.text;
      return `<font color="${st.highlightColor}">${inner}</font>`;
    }
    return `<b>${seg.text}</b>`;
  }).join('');
}

// כל רשומות הייצוא: דפים לפי "שורות בכל פעם", עם התאמת זמנים לסרטון חתוך
function buildExportCues(format) {
  sortSubtitles();
  const cues = [];
  for (const sub of state.subtitles) {
    const st = effectiveStyle(sub);
    for (const page of subtitlePages(sub, st)) {
      let start = page.start, end = page.end;
      if (state.cuts.length) {
        start -= removedBefore(start);
        end -= removedBefore(end);
        if (end - start < 0.05) continue; // הדף כולו בתוך קטע שנמחק
      }
      cues.push({ start, end, text: pageExportText(page.text, st, format), position: st.position });
    }
  }
  return cues;
}

function exportSRT() {
  const out = buildExportCues('srt').map((c, i) =>
    `${i + 1}\n${srtTime(c.start)} --> ${srtTime(c.end)}\n${c.text}\n`
  ).join('\n');
  downloadFile('subtitles.srt', out, 'text/plain');
}

function exportVTT() {
  let out = 'WEBVTT\n\n';
  out += buildExportCues('vtt').map((c, i) =>
    `${i + 1}\n${vttTime(c.start)} --> ${vttTime(c.end)} line:${Math.round(c.position)}%\n${c.text}\n`
  ).join('\n');
  downloadFile('subtitles.vtt', out, 'text/vtt');
}

function saveProject() {
  const data = {
    version: 2,
    subtitles: state.subtitles,
    globalStyle: state.globalStyle,
    customFonts: state.customFonts,
    cuts: state.cuts,
    speakers: state.speakers,
  };
  downloadFile('subtitle-project.json', JSON.stringify(data, null, 2), 'application/json');
}

async function importProject(file) {
  try {
    const data = JSON.parse(await file.text());
    state.subtitles = (data.subtitles || []).map(s => ({
      id: s.id, start: s.start, end: s.end, text: s.text || '',
      style: s.style ? { ...DEFAULT_STYLE, ...s.style } : null,
      speaker: s.speaker || null,
    }));
    state.globalStyle = { ...DEFAULT_STYLE, ...(data.globalStyle || {}) };
    state.cuts = Array.isArray(data.cuts) ? data.cuts : [];
    state.speakers = data.speakers || {};
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
    renderSpeakersUI(Object.keys(state.speakers).length > 1);
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

async function fetchWithTimeout(url, ms = 15000, options = {}) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), ms);
  try {
    return await fetch(url, { ...options, signal: ctrl.signal });
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

// ---------- תרגום עם Gemini ----------
$('sel-translate-engine').addEventListener('change', () => {
  $('gemini-key-group').hidden = $('sel-translate-engine').value !== 'gemini';
});
$('input-gemini-key').addEventListener('change', () => {
  try { localStorage.setItem('gemini-api-key', $('input-gemini-key').value.trim()); } catch (_) {}
});
try {
  const savedGeminiKey = localStorage.getItem('gemini-api-key');
  if (savedGeminiKey) $('input-gemini-key').value = savedGeminiKey;
} catch (_) {}

const LANG_NAMES = {
  he: 'Hebrew', en: 'English', ar: 'Arabic', ru: 'Russian', fr: 'French',
  es: 'Spanish', de: 'German', it: 'Italian', pt: 'Portuguese', 'zh-CN': 'Chinese',
  ja: 'Japanese', ko: 'Korean', hi: 'Hindi', tr: 'Turkish', uk: 'Ukrainian', am: 'Amharic',
};

// תרגום קבוצת שורות בבת אחת — Gemini מקבל הקשר מלא ולכן התרגום עקבי יותר
async function translateBatchWithGemini(texts, target, apiKey) {
  const url = serverKeys.gemini
    ? '/api/gemini'
    : 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
      + encodeURIComponent(apiKey);
  const prompt = 'Translate the following subtitle lines to ' + (LANG_NAMES[target] || target) + '.\n'
    + 'These are consecutive subtitles from one video, so keep the translation consistent across lines.\n'
    + 'Keep any *word* asterisk markers around the same words in the translation.\n'
    + 'Return ONLY a JSON array of strings, one translated string per input line, same order and count.\n\n'
    + JSON.stringify(texts);
  const res = await fetchWithTimeout(url, 60000, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      contents: [{ parts: [{ text: prompt }] }],
      generationConfig: { responseMimeType: 'application/json', temperature: 0.2 },
    }),
  });
  if (res.status === 400 || res.status === 403) throw new Error('מפתח ה-API של Gemini לא תקין');
  if (res.status === 429) throw new Error('חריגה ממכסת הבקשות של Gemini — המתינו דקה ונסו שוב');
  if (!res.ok) throw new Error('HTTP ' + res.status);
  const data = await res.json();
  const out = JSON.parse(data.candidates[0].content.parts[0].text);
  if (!Array.isArray(out) || out.length !== texts.length) throw new Error('תשובה לא תקינה מהמודל');
  return out.map(String);
}

async function translateAllWithGemini(subs, target, mode, status) {
  const apiKey = serverKeys.gemini ? '' : ($('input-gemini-key')?.value || '').trim();
  if (!apiKey && !serverKeys.gemini) throw new Error('הזינו מפתח API של Gemini (מקבלים בחינם ב-aistudio.google.com)');
  const BATCH = 40;
  let done = 0;
  for (let i = 0; i < subs.length; i += BATCH) {
    const batch = subs.slice(i, i + BATCH);
    status.textContent = `מתרגם עם Gemini... ${Math.min(i + BATCH, subs.length)} מתוך ${subs.length}`;
    const translated = await translateBatchWithGemini(batch.map(s => s.text), target, apiKey);
    batch.forEach((sub, j) => {
      sub.text = (mode === 'append') ? sub.text + '\n' + translated[j] : translated[j];
    });
    done += batch.length;
    renderSubtitleList();
    renderTimeline();
    renderOverlay(true);
  }
  return done;
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

  if ($('sel-translate-engine').value === 'gemini') {
    try {
      const done = await translateAllWithGemini(queue, target, mode, status);
      status.textContent = `✅ תורגמו כל ${done} הכתוביות עם Gemini.`;
    } catch (err) {
      status.textContent = '❌ תרגום Gemini נכשל: ' + (err.message || err);
    } finally {
      translating = false;
      $('btn-translate-all').disabled = false;
      $('btn-translate-selected').disabled = false;
    }
    return;
  }

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

// ---------- מחיקת רגעים שקטים ----------
function activeCutAt(t) {
  return state.cuts.find(c => t >= c.start && t < c.end) || null;
}

// כמה זמן הוסר מהסרטון לפני הזמן t (למיפוי תזמון הכתוביות לסרטון החתוך)
function removedBefore(t) {
  let removed = 0;
  for (const c of state.cuts) {
    if (t >= c.end) removed += c.end - c.start;
    else if (t > c.start) removed += t - c.start;
  }
  return removed;
}

// עוצמת RMS בדציבלים לכל חלון של 50ms
function windowLevelsDb(audio, sampleRate) {
  const win = Math.round(sampleRate * 0.05);
  const dbs = [];
  for (let i = 0; i < audio.length; i += win) {
    const end = Math.min(i + win, audio.length);
    let sum = 0;
    for (let j = i; j < end; j++) sum += audio[j] * audio[j];
    const rms = Math.sqrt(sum / (end - i));
    dbs.push(rms > 0 ? 20 * Math.log10(rms) : -100);
  }
  return dbs;
}

// סף אוטומטי גלובלי: נגזר מהתפלגות רמות הקול של הסרטון עצמו —
// רצפת הרעש (אחוזון 10) ורמת הדיבור (אחוזון 90), והסף ביניהן
function autoThresholdDb(dbs) {
  const sorted = [...dbs].sort((a, b) => a - b);
  const q = (p) => sorted[Math.min(sorted.length - 1, Math.floor(p * sorted.length))];
  const noiseFloor = q(0.1);
  const speechLevel = q(0.9);
  if (speechLevel - noiseFloor < 8) return null; // אין הפרש ברור בין דיבור לשקט
  return noiseFloor + (speechLevel - noiseFloor) * 0.35;
}

// זיהוי קטעים שקטים לפי סף בדציבלים, עם הגנות נגד זיהוי שגוי:
// החלקת מדיאן (נגד קפיצות רגעיות), היסטרזיס (יציאה משקט רק 4dB מעל הסף),
// וסגירת הבלחות רעש קצרות בתוך שקט
function detectSilences(dbs, sampleRate, thresholdDb, minDur, pad) {
  const winSec = 0.05;

  const smooth = dbs.map((d, i) => {
    const a = dbs[Math.max(0, i - 1)], c = dbs[Math.min(dbs.length - 1, i + 1)];
    return [a, d, c].sort((x, y) => x - y)[1];
  });

  const mask = new Array(smooth.length);
  let silent = false;
  for (let i = 0; i < smooth.length; i++) {
    if (silent) {
      if (smooth[i] > thresholdDb + 4) silent = false;
    } else if (smooth[i] < thresholdDb) {
      silent = true;
    }
    mask[i] = silent;
  }

  // הבלחת רעש קצרה מ-0.25s בתוך שקט לא שוברת את הקטע
  const blip = Math.round(0.25 / winSec);
  let runStart = null;
  for (let i = 0; i <= mask.length; i++) {
    const loud = i < mask.length ? !mask[i] : false;
    if (loud && runStart === null) runStart = i;
    else if (!loud && runStart !== null) {
      const before = runStart > 0 && mask[runStart - 1];
      const after = i < mask.length && mask[i];
      if (before && after && i - runStart <= blip) {
        for (let j = runStart; j < i; j++) mask[j] = true;
      }
      runStart = null;
    }
  }

  const silences = [];
  let segStart = null;
  for (let i = 0; i < mask.length; i++) {
    const t = i * winSec;
    if (mask[i]) {
      if (segStart === null) segStart = t;
    } else if (segStart !== null) {
      if (t - segStart >= minDur) silences.push({ start: segStart, end: t });
      segStart = null;
    }
  }
  const total = mask.length * winSec;
  if (segStart !== null && total - segStart >= minDur) silences.push({ start: segStart, end: total });

  // ריפוד אדפטיבי: בשקט ארוך משאירים שוליים מלאים סביב הדיבור,
  // ובשקט קצר הריפוד מתכווץ כדי שהקטע עדיין ייחתך ולא ייבלע
  return silences
    .map(c => {
      const len = c.end - c.start;
      const p = Math.min(pad, Math.max((len - 0.12) / 2, 0));
      return { start: c.start + p, end: c.end - p };
    })
    .filter(c => c.end - c.start > 0.08);
}

$('sel-silence-mode').addEventListener('change', () => {
  $('silence-threshold-group').hidden = $('sel-silence-mode').value !== 'manual';
});

// החסרת טווחי כתוביות (דיבור) מקטעי החיתוך, עם שוליים של 0.15s לכל צד
function subtractSpeechSpans(cuts) {
  const spans = state.subtitles
    .filter(s => isFinite(s.start) && isFinite(s.end))
    .map(s => ({ start: s.start - 0.15, end: s.end + 0.15 }));
  let out = cuts;
  for (const sp of spans) {
    const next = [];
    for (const c of out) {
      if (sp.end <= c.start || sp.start >= c.end) { next.push(c); continue; }
      if (sp.start - c.start > 0.3) next.push({ start: c.start, end: sp.start });
      if (c.end - sp.end > 0.3) next.push({ start: sp.end, end: c.end });
    }
    out = next;
  }
  return out;
}

async function detectSilencesInMedia() {
  const status = $('cuts-status');
  if (!state.mediaFile) {
    status.textContent = 'טענו קודם קובץ וידאו או אודיו.';
    return;
  }
  $('btn-detect-silence').disabled = true;
  status.textContent = 'מנתח את האודיו...';
  try {
    const audio = await getAudioData();
    const dbs = windowLevelsDb(audio, 16000);

    let thresholdDb;
    let thresholdNote = '';
    if ($('sel-silence-mode').value === 'auto') {
      const auto = autoThresholdDb(dbs);
      if (auto === null) {
        status.textContent = 'לא נמצא הפרש ברור בין דיבור לשקט בסרטון הזה. נסו את השיטה הידנית עם סף גבוה.';
        return;
      }
      thresholdDb = auto;
      thresholdNote = ` (סף אוטומטי: ${auto.toFixed(0)}dB)`;
    } else {
      thresholdDb = Number($('rng-silence-threshold').value);
    }

    const minDur = Number($('rng-silence-mindur').value);
    const pad = Number($('rng-silence-pad').value);
    const rawCuts = detectSilences(dbs, 16000, thresholdDb, minDur, pad);

    // הגנת דיבור: קטעים שחופפים לכתוביות מתוזמנות לא נחתכים —
    // הכתוביות מסמנות איפה יש דיבור, אז מחסירים אותן מהחיתוך
    state.cuts = subtractSpeechSpans(rawCuts);
    const protectedCount = rawCuts.length - state.cuts.length;

    const saved = state.cuts.reduce((n, c) => n + (c.end - c.start), 0);
    const total = audio.length / 16000;
    const protectedNote = state.subtitles.length
      ? (protectedCount > 0 || rawCuts.length !== state.cuts.length
        ? ' קטעים שחופפים לכתוביות הוגנו ולא ייחתכו.'
        : ' אף קטע לא חפף לכתוביות.')
      : '';
    status.textContent = state.cuts.length
      ? `✂️ נמצאו ${state.cuts.length} קטעים שקטים — סה"כ ${saved.toFixed(1)} שניות (${Math.round(saved / total * 100)}% מהסרטון) יימחקו בייצוא${thresholdNote}.${protectedNote}`
      : `לא נמצאו קטעים שקטים למחיקה${thresholdNote}.`;
    renderTimeline();
  } catch (err) {
    status.textContent = '❌ הניתוח נכשל: ' + (err.message || err);
  } finally {
    $('btn-detect-silence').disabled = false;
  }
}

$('btn-detect-silence').addEventListener('click', detectSilencesInMedia);
$('btn-clear-cuts').addEventListener('click', () => {
  state.cuts = [];
  $('cuts-status').textContent = 'החיתוך נוקה.';
  renderTimeline();
});

for (const id of ['rng-silence-threshold', 'rng-silence-mindur', 'rng-silence-pad']) {
  $(id).addEventListener('input', () => {
    $('silence-threshold-value').textContent = $('rng-silence-threshold').value;
    $('silence-mindur-value').textContent = $('rng-silence-mindur').value;
    $('silence-pad-value').textContent = $('rng-silence-pad').value;
  });
}

// עוצמת אפקט המעבר (0..1) לפי הקרבה לנקודת חיתוך
function cutTransitionIntensity(t, style) {
  if (style === 'none' || !state.cuts.length) return 0;
  const D = style === 'flash' ? 0.12 : 0.18;
  let k = 0;
  for (const c of state.cuts) {
    const before = c.start - t;
    const after = t - c.end;
    if (before >= 0 && before < D) k = Math.max(k, 1 - before / D);
    if (after >= 0 && after < D) k = Math.max(k, 1 - after / D);
  }
  return k;
}

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
    const wrapped = subtitleDisplayText(sub, st, t);
    const lines = wrapped.split('\n').map(parseHighlights);
    const rtl = /[֐-ࣿ]/.test(wrapped);

    let charBudget = Infinity;
    if (st.effect === 'typewriter') {
      const totalChars = lines.flat().reduce((n, s) => n + s.text.length, 0);
      charBudget = Math.ceil(visFrac * totalChars);
    }

    // קריוקי: אינדקס המילה הנוכחית לפי ההתקדמות במשך הכתובית
    let karaokeIdx = -1;
    let karaokeCounter = 0;
    if (st.effect === 'karaoke') {
      const totalWords = lines.reduce((n, segs) =>
        n + segs.reduce((m, s) => m + s.text.split(/\s+/).filter(Boolean).length, 0), 0);
      const progress = Math.min(Math.max((t - sub.start) / Math.max(sub.end - sub.start, 0.001), 0), 1);
      karaokeIdx = Math.min(totalWords - 1, Math.floor(progress * totalWords));
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
        const isKaraokeHl = st.effect === 'karaoke' && karaokeCounter++ <= karaokeIdx;
        if (drawText) {
          ctx.font = segFont(tok.hl || isKaraokeHl);
          const color = (tok.hl || isKaraokeHl) ? st.highlightColor : (colorOverride || st.textColor);
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
    const t = video.currentTime;

    // דילוג על רגעים שקטים — הקפיצה לא מוקלטת, כך שהם נמחקים מהתוצאה
    const cut = activeCutAt(t);
    if (cut && cut.end < video.duration - 0.1) {
      video.currentTime = cut.end + 0.01;
    } else if (cut) {
      // הקטע השקט מגיע עד סוף הסרטון — מסיימים כאן
      if (exportRecorder.state !== 'inactive') exportRecorder.stop();
      return;
    }

    const transition = $('sel-cut-transition').value;
    const k = cutTransitionIntensity(t, transition);

    if (transition === 'zoom' && k > 0) {
      ctx.save();
      const z = 1 + 0.08 * k;
      ctx.translate(W / 2, H / 2);
      ctx.scale(z, z);
      ctx.translate(-W / 2, -H / 2);
      ctx.drawImage(video, 0, 0, W, H);
      ctx.restore();
    } else {
      ctx.drawImage(video, 0, 0, W, H);
    }

    drawSubtitlesOnCanvas(ctx, W, H, t, scale);

    if (k > 0 && (transition === 'fade-black' || transition === 'flash')) {
      ctx.fillStyle = transition === 'flash'
        ? `rgba(255,255,255,${(k * 0.9).toFixed(3)})`
        : `rgba(0,0,0,${k.toFixed(3)})`;
      ctx.fillRect(0, 0, W, H);
    }

    $('export-video-progress').style.width = (t / video.duration * 100) + '%';
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
    transformersPromise = import(TRANSFORMERS_URL).then(m => {
      // בלי זה הספרייה מחפשת את המודל קודם בנתיב /models/ בשרת המקומי,
      // מקבלת 404 ועלולה להיכשל במקום לרדת ישירות מהאינטרנט
      if (m.env) m.env.allowLocalModels = false;
      return m;
    }).catch(err => {
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

// ---------- מפתחות בצד השרת ----------
// כשהמערכת רצה מהשרת המקומי (server.py) והמפתחות מוגדרים ב-server-config.json,
// הבקשות עוברות דרך השרת והמשתמש לא צריך להזין מפתח בממשק
let serverKeys = { elevenlabs: false, gemini: false };

(async function detectServerKeys() {
  if (!location.protocol.startsWith('http')) return;
  try {
    const res = await fetch('/api/has-keys');
    if (!res.ok) return;
    serverKeys = await res.json();
    if (serverKeys.elevenlabs) {
      const group = $('elevenlabs-key-group');
      group.innerHTML = '<p class="hint">✓ מפתח ElevenLabs מוגדר בשרת (server-config.json) — אין צורך להזין כאן.</p>';
    }
    if (serverKeys.gemini) {
      const group = $('gemini-key-group');
      group.innerHTML = '<p class="hint">✓ מפתח Gemini מוגדר בשרת (server-config.json) — אין צורך להזין כאן.</p>';
      group.hidden = false;
    }
  } catch (_) { /* אין שרת עם API — ממשיכים עם מפתחות בממשק */ }
})();

// ---------- תמלול עם ElevenLabs Scribe ----------
$('sel-transcribe-engine').addEventListener('change', () => {
  const el = $('sel-transcribe-engine').value === 'elevenlabs';
  $('elevenlabs-key-group').hidden = !el;
  $('whisper-model-group').hidden = el;
});

$('input-elevenlabs-key').addEventListener('change', () => {
  try { localStorage.setItem('elevenlabs-api-key', $('input-elevenlabs-key').value.trim()); } catch (_) {}
});
try {
  const savedKey = localStorage.getItem('elevenlabs-api-key');
  if (savedKey) $('input-elevenlabs-key').value = savedKey;
} catch (_) {}

// בניית כתוביות מרשימת מילים עם תזמון: שבירה אחרי maxWords מילים,
// בסוף משפט, בהפסקת דיבור ארוכה או בהחלפת דובר
function wordsToSubtitles(words, maxWords = 10) {
  const subs = [];
  let cur = null;
  for (const w of words) {
    if (w.type && w.type !== 'word') continue;
    const text = (w.text || '').trim();
    if (!text) continue;
    const speaker = w.speaker_id || null;
    if (cur && (w.start - cur.end > 0.8 || (speaker && cur.speaker && speaker !== cur.speaker))) {
      subs.push(cur);
      cur = null;
    }
    if (!cur) {
      cur = { start: w.start, end: w.end, text, count: 1, speaker };
    } else {
      cur.text += ' ' + text;
      cur.end = w.end;
      cur.count++;
    }
    if (/[.!?…]$/.test(text) || cur.count >= maxWords) { subs.push(cur); cur = null; }
  }
  if (cur) subs.push(cur);
  return subs.map(s => ({ start: s.start, end: Math.max(s.end, s.start + 0.4), text: s.text, speaker: s.speaker }));
}

// גודל הקבוצה שנבחר בטאב התמלול: מספר מילים, או null עבור "שורה שלמה"
function transcribeGranularity() {
  const v = $('sel-transcribe-granularity').value;
  return v === 'sentence' ? null : Number(v);
}

// אכיפת מרווחים בין כתוביות עוקבות: מרווח רגיל בין כתוביות,
// ומרווח גדול יותר אחרי סוף משפט. גם מבטיח שאין חפיפות.
function applyGaps(pieces) {
  const wordGap = Number($('rng-word-gap').value);
  const sentenceGap = Number($('rng-sentence-gap').value);
  const placement = $('sel-gap-placement').value;
  for (let i = 0; i < pieces.length - 1; i++) {
    const cur = pieces[i], next = pieces[i + 1];
    const gap = /[.!?…]\s*$/.test(cur.text) ? sentenceGap : wordGap;
    const missing = gap - (next.start - cur.end);
    if (missing <= 0) continue;
    if (placement === 'delay-next') {
      next.start = Math.min(next.end - 0.25, next.start + missing);
    } else if (placement === 'split') {
      cur.end = Math.max(cur.start + 0.25, cur.end - missing / 2);
      next.start = Math.min(next.end - 0.25, next.start + missing / 2);
    } else { // trim-prev
      cur.end = Math.max(cur.start + 0.25, next.start - gap);
    }
  }
  return pieces;
}

// תיקון חפיפות: כתובית שנמשכת לתוך הבאה מתקצרת עד תחילתה
function fixOverlaps() {
  sortSubtitles();
  let fixed = 0;
  for (let i = 0; i < state.subtitles.length - 1; i++) {
    const cur = state.subtitles[i], next = state.subtitles[i + 1];
    if (cur.end > next.start) {
      cur.end = Math.max(cur.start + 0.15, next.start - 0.01);
      fixed++;
    }
  }
  return fixed;
}

$('btn-fix-overlaps').addEventListener('click', () => {
  const fixed = fixOverlaps();
  renderAll();
  $('subtitles-status').textContent = fixed
    ? `✅ תוקנו ${fixed} חפיפות בין כתוביות.`
    : 'אין חפיפות — הכול תקין.';
});

// קידוד מקטע PCM מונו 16kHz ל-WAV (למשלוח מנה של פודקאסט)
function float32ToWavBlob(samples, sampleRate) {
  const len = samples.length * 2;
  const out = new ArrayBuffer(44 + len);
  const dv = new DataView(out);
  const wStr = (o, s) => { for (let i = 0; i < s.length; i++) dv.setUint8(o + i, s.charCodeAt(i)); };
  wStr(0, 'RIFF'); dv.setUint32(4, 36 + len, true); wStr(8, 'WAVE');
  wStr(12, 'fmt '); dv.setUint32(16, 16, true); dv.setUint16(20, 1, true);
  dv.setUint16(22, 1, true); dv.setUint32(24, sampleRate, true);
  dv.setUint32(28, sampleRate * 2, true); dv.setUint16(32, 2, true); dv.setUint16(34, 16, true);
  wStr(36, 'data'); dv.setUint32(40, len, true);
  for (let i = 0; i < samples.length; i++) {
    const v = Math.max(-1, Math.min(1, samples[i]));
    dv.setInt16(44 + i * 2, v < 0 ? v * 0x8000 : v * 0x7fff, true);
  }
  return new Blob([out], { type: 'audio/wav' });
}

async function elevenLabsRequest(fileBlob, apiKey) {
  const form = new FormData();
  form.append('file', fileBlob);
  form.append('model_id', 'scribe_v1');
  form.append('timestamps_granularity', 'word');
  if ($('chk-diarize').checked) form.append('diarize', 'true');
  const lang = $('sel-speech-lang').value;
  if (lang !== 'auto') form.append('language_code', lang);

  const viaServer = serverKeys.elevenlabs;
  const res = await fetchWithTimeout(
    viaServer ? '/api/speech-to-text' : 'https://api.elevenlabs.io/v1/speech-to-text',
    30 * 60 * 1000,
    {
      method: 'POST',
      headers: viaServer ? {} : { 'xi-api-key': apiKey },
      body: form,
    });
  if (res.status === 401) throw new Error('מפתח ה-API לא תקין או שפג תוקפו');
  if (!res.ok) {
    let detail = 'HTTP ' + res.status;
    try { detail = (await res.json()).detail?.message || detail; } catch (_) {}
    throw new Error(detail);
  }
  return await res.json();
}

// מצב פודקאסט: חלוקת האודיו למנות של 10 דקות, תמלול כל מנה בנפרד
// עם מד התקדמות, ואיחוד המילים עם היסט הזמן הנכון
async function transcribeLongWithElevenLabs(apiKey, status) {
  const CHUNK_SEC = 600;
  const SR = 16000;
  const audio = await getAudioData();
  const chunks = Math.max(1, Math.ceil(audio.length / (CHUNK_SEC * SR)));
  const allWords = [];
  for (let i = 0; i < chunks; i++) {
    status.textContent = `🎧 מצב פודקאסט: מתמלל מנה ${i + 1} מתוך ${chunks} (כ-10 דקות למנה)...`;
    setTranscribeProgress(i / chunks);
    const seg = audio.subarray(i * CHUNK_SEC * SR, Math.min((i + 1) * CHUNK_SEC * SR, audio.length));
    const data = await elevenLabsRequest(float32ToWavBlob(seg, SR), apiKey);
    const offset = i * CHUNK_SEC;
    for (const w of (data.words || [])) {
      if (w.start != null) w.start += offset;
      if (w.end != null) w.end += offset;
      allWords.push(w);
    }
  }
  setTranscribeProgress(null);
  const maxWords = transcribeGranularity() || 10;
  return wordsToSubtitles(allWords, maxWords);
}

async function transcribeWithElevenLabs(status) {
  const apiKey = serverKeys.elevenlabs ? '' : ($('input-elevenlabs-key')?.value || '').trim();
  if (!apiKey && !serverKeys.elevenlabs) throw new Error('הזינו מפתח API של ElevenLabs');

  if ($('chk-podcast-mode').checked) {
    return await transcribeLongWithElevenLabs(apiKey, status);
  }

  status.textContent = 'מעלה את הקובץ ל-ElevenLabs ומתמלל...';
  const data = await elevenLabsRequest(state.mediaFile, apiKey);
  const maxWords = transcribeGranularity() || 10;
  if (data.words && data.words.length) return wordsToSubtitles(data.words, maxWords);
  if (data.text && data.text.trim()) {
    // בלי תזמון מילים — מפזרים על אורך הסרטון
    return splitTranscriptChunk(data.text.trim(), 0, state.duration || 60);
  }
  return [];
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
    let pieces = [];

    if ($('sel-transcribe-engine').value === 'elevenlabs') {
      pieces = await transcribeWithElevenLabs(status);
    } else {
      status.textContent = 'טוען את ספריית התמלול...';
      setTranscribeProgress(0);
      const transcriber = await getTranscriber($('sel-whisper-model').value, status);

      status.textContent = 'מחלץ אודיו מהסרטון...';
      setTranscribeProgress(null);
      const audio = await getAudioData();

      status.textContent = 'מתמלל... (בסרטון ארוך זה עשוי לקחת כמה דקות)';
      const lang = $('sel-speech-lang').value;
      const granularity = transcribeGranularity();
      const result = await transcriber(audio, {
        chunk_length_s: 30,
        stride_length_s: 5,
        // חלוקה לפי מילים דורשת תזמון ברמת מילה; לפי משפטים מספיק תזמון לקטע
        return_timestamps: granularity ? 'word' : true,
        ...(lang !== 'auto' ? { language: lang, task: 'transcribe' } : {}),
      });

      if (granularity) {
        const words = (result.chunks || [])
          .map(c => ({ text: (c.text || '').trim(), start: c.timestamp[0], end: c.timestamp[1] }))
          .filter(w => w.text);
        let prev = 0;
        for (const w of words) {
          if (w.start == null || !isFinite(w.start)) w.start = prev;
          if (w.end == null || !isFinite(w.end)) w.end = w.start + 0.3;
          prev = w.end;
        }
        pieces = wordsToSubtitles(words, granularity);
      } else {
        let prevEnd = 0;
        for (const c of (result.chunks || []).filter(ch => (ch.text || '').trim())) {
          const start = c.timestamp[0] ?? prevEnd;
          const end = c.timestamp[1] ?? (start + 3);
          pieces.push(...splitTranscriptChunk(c.text.trim(), start, end));
          prevEnd = end;
        }
      }
    }

    if (!pieces.length) {
      status.textContent = 'לא זוהה דיבור בסרטון.';
      return;
    }

    applyGaps(pieces);

    if ($('chk-clear-before-transcribe').checked) {
      state.subtitles = [];
      state.selectedId = null;
    }

    // דוברים שזוהו מקבלים תווית זמנית ("דובר 1") שאפשר להחליף בשם אמיתי
    const speakerIds = [...new Set(pieces.map(p => p.speaker).filter(Boolean))];
    state.speakers = {};
    speakerIds.forEach((id, i) => { state.speakers[id] = 'דובר ' + (i + 1); });

    const created = [];
    for (const piece of pieces) {
      const sub = {
        id: state.nextId++,
        start: piece.start,
        end: piece.end,
        text: piece.speaker && speakerIds.length > 1
          ? state.speakers[piece.speaker] + ': ' + piece.text
          : piece.text,
        style: null,
        speaker: piece.speaker || null,
      };
      state.subtitles.push(sub);
      created.push(sub);
    }
    fixOverlaps();
    sortSubtitles();
    renderAll();
    renderSpeakersUI(speakerIds.length >= 1);
    status.textContent = `✅ נוצרו ${created.length} כתוביות מהתמלול` +
      (speakerIds.length > 1 ? ` וזוהו ${speakerIds.length} דוברים — הזינו את שמותיהם למטה.` : '.') +
      ' אפשר לערוך בטאב "כתוביות" או לתרגם בטאב "תרגום".';
  } catch (err) {
    status.textContent = '❌ התמלול נכשל: ' + (err.message || err) +
      ' — ודאו חיבור לאינטרנט. (בגרסה המתארחת ב-Artifact הגישה לרשת חסומה — הורידו את הקובץ ופתחו מקומית)';
  } finally {
    setTranscribeProgress(null);
    transcribing = false;
    $('btn-transcribe').disabled = false;
  }
}

for (const [rid, vid] of [['rng-word-gap', 'word-gap-value'], ['rng-sentence-gap', 'sentence-gap-value']]) {
  $(rid).addEventListener('input', () => { $(vid).textContent = $(rid).value; });
}

$('btn-transcribe').addEventListener('click', transcribeVideo);

// ---------- שמות דוברים ----------
function renderSpeakersUI(show) {
  const section = $('speakers-section');
  const list = $('speakers-list');
  section.hidden = !show;
  if (!show) return;
  list.innerHTML = '';
  for (const [id, label] of Object.entries(state.speakers)) {
    const group = document.createElement('div');
    group.className = 'form-group';
    const lbl = document.createElement('label');
    lbl.textContent = label;
    lbl.setAttribute('for', 'speaker-input-' + id);
    const input = document.createElement('input');
    input.type = 'text';
    input.id = 'speaker-input-' + id;
    input.value = label;
    input.dataset.speakerId = id;
    group.append(lbl, input);
    list.appendChild(group);
  }
}

$('btn-apply-speakers').addEventListener('click', () => {
  const inputs = $('speakers-list').querySelectorAll('input[data-speaker-id]');
  for (const input of inputs) {
    const id = input.dataset.speakerId;
    const oldLabel = state.speakers[id];
    const newLabel = input.value.trim() || oldLabel;
    if (newLabel === oldLabel) continue;
    for (const sub of state.subtitles) {
      if (sub.speaker !== id) continue;
      if (sub.text.startsWith(oldLabel + ': ')) {
        sub.text = newLabel + ': ' + sub.text.slice(oldLabel.length + 2);
      } else if (!sub.text.startsWith(newLabel + ': ')) {
        // כתוביות של דובר יחיד נוצרות בלי קידומת — מוסיפים אותה עכשיו
        sub.text = newLabel + ': ' + sub.text;
      }
    }
    state.speakers[id] = newLabel;
  }
  renderSpeakersUI(true);
  renderAll();
  $('transcribe-status').textContent = '✅ שמות הדוברים הוחלו על הכתוביות.';
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

// ---------- זיכרון אוטומטי: העבודה נשמרת מקומית ומשוחזרת בפתיחה ----------
const AUTOSAVE_KEY = 'subtitle-editor-autosave';
let lastAutosave = '';

function snapshotProject() {
  return JSON.stringify({
    version: 2,
    subtitles: state.subtitles,
    globalStyle: state.globalStyle,
    customFonts: state.customFonts,
    cuts: state.cuts,
    speakers: state.speakers,
    nextId: state.nextId,
  });
}

async function restoreAutosave() {
  let raw = null;
  try { raw = localStorage.getItem(AUTOSAVE_KEY); } catch (_) {}
  if (!raw) return;
  try {
    const data = JSON.parse(raw);
    if (!data.subtitles || !data.subtitles.length) return;
    state.subtitles = data.subtitles.map(s => ({
      id: s.id, start: s.start, end: s.end, text: s.text || '',
      style: s.style ? { ...DEFAULT_STYLE, ...s.style } : null,
      speaker: s.speaker || null,
    }));
    state.globalStyle = { ...DEFAULT_STYLE, ...(data.globalStyle || {}) };
    state.cuts = Array.isArray(data.cuts) ? data.cuts : [];
    state.speakers = data.speakers || {};
    state.nextId = data.nextId || state.subtitles.reduce((m, s) => Math.max(m, s.id || 0), 0) + 1;
    for (const f of (data.customFonts || [])) {
      try {
        await loadCustomFont(f.name, f.dataUrl);
        state.customFonts.push(f);
      } catch (_) {}
    }
    lastAutosave = raw;
    populateFontSelect();
    renderFontLists();
    renderAll();
    syncStylePanel();
    renderSpeakersUI(Object.keys(state.speakers).length > 1);
    $('subtitles-status').textContent = '💾 העבודה הקודמת שוחזרה אוטומטית (' + state.subtitles.length + ' כתוביות). טענו את קובץ הווידאו כדי להמשיך.';
  } catch (_) { /* שחזור נכשל — מתחילים נקי */ }
}

setInterval(() => {
  const snap = snapshotProject();
  if (snap === lastAutosave) return;
  try {
    localStorage.setItem(AUTOSAVE_KEY, snap);
    lastAutosave = snap;
  } catch (_) {
    // מכסת האחסון מלאה (בדרך כלל פונטים כבדים) — שומרים בלי הפונטים
    try {
      const slim = JSON.parse(snap);
      slim.customFonts = [];
      localStorage.setItem(AUTOSAVE_KEY, JSON.stringify(slim));
      lastAutosave = snap;
    } catch (_) {}
  }
}, 2500);

// ---------- Service Worker: התקנה כאפליקציה ועבודה לא-מקוונת ----------
if ('serviceWorker' in navigator && location.protocol.startsWith('http')) {
  navigator.serviceWorker.register('sw.js').catch(() => {});
}

populateFontSelect();
renderFontLists();
syncStylePanel();
renderAll();
restoreAutosave();
