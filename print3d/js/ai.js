// עוזר AI: מתארים במילים מה רוצים, Claude מרכיב את המודל מחלקי הסטודיו.
// כשהעמוד רץ בתוך claude.ai — משתמשים ביכולת sample (בלי מפתח).
// כשהוא רץ על שרת רגיל — Claude API עם מפתח אישי שנשמר רק בדפדפן.
import { CATALOG } from './shapes.js';

const KEY_STORAGE = 'print3d.anthropicKey';
const MODEL = 'claude-opus-5-5';
const SDK_URL = 'https://cdn.jsdelivr.net/npm/@anthropic-ai/sdk@1.6.0/+esm';

const EXAMPLES = [
  'מחזיק עטים עגול בקוטר 8 ס"מ עם 3 תאים',
  'שלט לדלת עם השם "נועה" ופינות מעוגלות',
  'מעמד לאוזניות לשולחן',
  'קופסה לכרטיסי ביקור עם מכסה',
  'מגש סבון עם חורי ניקוז',
];

// ---------- הנחיות למודל ----------
function catalogSpec() {
  const lines = [];
  for (const [type, def] of Object.entries(CATALOG)) {
    if (type === 'mesh' || type === 'group') continue;
    const ps = def.params.map((q) =>
      q.options
        ? `${q.key} (${q.label}): one of ${q.options.map(([v, t]) => `${v}=${t}`).join(', ')}; default ${q.def}`
        : `${q.key} (${q.label}): ${q.min}..${q.max}${q.unit === '%' ? '%' : q.unit === '°' ? '°' : ''}, default ${q.def}`,
    );
    let extra = '';
    if (def.defaultSketch) extra = def.revolve
      ? ' Needs "sketch": {"points": [[r, z], ...], "smooth": bool} — a closed half cross-section; r >= 0 is the distance from the vertical axis.'
      : ' Needs "sketch": {"points": [[x, y], ...], "smooth": bool} — a closed polygon in mm (3..200 points, not self-intersecting), extruded up along +Z from z=0. Center it around (0,0).';
    if (def.defaultText !== undefined) extra = ' Needs "text": "..." (Hebrew and/or Latin, one line). Centered on X/Y, reads along +X, viewed from above.';
    lines.push(`- ${type} — ${def.name}${def.desc ? ': ' + def.desc : ''}.${extra}\n    params: ${ps.join('; ') || 'none'}`);
  }
  return lines.join('\n');
}

export function systemPrompt(settings) {
  return `You are the modeling engine inside "סטודיו הדפסה", a browser tool that designs simple, printable objects for FDM 3D printers.
The user describes an object in Hebrew (sometimes English). You answer with a JSON plan that the tool builds from its own parametric parts. The user can then edit every part by hand, so prefer clear, well-named parts over clever tricks.

# Coordinate system
- Units are millimetres. Z points up. The print bed is ${settings.bedW} × ${settings.bedD} mm centred on (0,0), max height ${settings.bedH} mm.
- Every part is built in its own local frame: its footprint is centred on the local X/Y origin and its bottom sits at local z=0 (exceptions noted per type).
- Placement: world = translate(pos) · rotate(rot, Euler order XYZ, degrees) · scale(scale). "pos" is where the local origin lands, so a part with pos z=5 starts 5 mm above the bed.
- Orientation notes: phoneStand — base depth along X with the front lip at −X, width along Y. hook — back plate at −X, the hook opens toward +X, width along Y. bracket — vertical leg at −X, horizontal leg toward +X, width along Y. wedge — high side at −X. cylinder, tube, cone, pot and organizer stand along Z. torus lies flat. text lies flat, letters face up.

# How parts combine
- All parts with "hole": false are unioned into one solid.
- Then every part with "hole": true is subtracted from that solid (a hole cuts every solid it touches).
- Let solids overlap by about 0.5 mm where they should fuse. Make holes and cut-outs extend at least 1 mm past the surfaces they cut, so no thin skin is left.
- Optional: {"type":"group","op":"union"|"intersect","name":...,"pos":...,"children":[parts whose pos is relative to the group]} — use "intersect" only when you need the common volume of shapes.

# Part types
${catalogSpec()}

# Printability (printer nozzle ${settings.nozzle} mm, fit tolerance ${settings.tolerance} mm)
- Flat, stable base on z=0. Walls at least ${Math.max(1.2, settings.nozzle * 3).toFixed(1)} mm, floors at least 1.2 mm, raised or engraved text at least 5 mm tall and 0.8 mm deep.
- Avoid unsupported overhangs steeper than 45° and bridges longer than about 20 mm.
- Parts that fit into each other get the fit tolerance as clearance on each side.
- Use the user's dimensions exactly when given; otherwise choose realistic everyday sizes. Keep everything on the bed.
- Prefer a ready-made product type when it matches the request; otherwise compose shapes, sketches and holes.

# Editing
The user message includes the current scene as JSON. Each existing part has an "id".
- To change or extend the current model, answer with "action": "replace" and the full new list of parts. For every existing part you keep, include its "id"; fields you include override it, and a part given as only {"id": "..."} is kept unchanged. Parts you leave out are deleted.
- To add a separate new object next to what exists, answer with "action": "add" and only the new parts.
- An empty scene always uses "replace".

# Answer format
Answer with ONE JSON object and nothing else — no markdown, no code fences:
{"message": "<one or two short sentences in the user's language: what you built and the key dimensions>",
 "action": "replace" | "add",
 "parts": [{"id"?: string, "type": string, "name": "<short Hebrew name>", "params": {...}, "pos": [x,y,z], "rot": [x,y,z], "scale": [1,1,1], "hole": false, "color": "#rrggbb", "sketch"?: {...}, "text"?: string}]}
Omit params you leave at their defaults. Use pleasant distinct colors for solid parts. If the request is unclear or impossible to print, still build the closest sensible version and say what you assumed in "message".`;
}

function extractJSON(text) {
  const s = text.indexOf('{');
  const e = text.lastIndexOf('}');
  if (s < 0 || e <= s) throw new Error('התשובה לא הכילה מודל');
  return JSON.parse(text.slice(s, e + 1));
}

// ---------- ספקים ----------
let sampleCap; // undefined = לא נבדק; null = לא זמין
async function getSample() {
  if (sampleCap === undefined) {
    try {
      sampleCap = (await window.claude?.use?.('sample')) ?? null;
    } catch {
      sampleCap = null;
    }
  }
  return sampleCap;
}

function getKey() {
  try { return localStorage.getItem(KEY_STORAGE) || ''; } catch { return ''; }
}
function setKey(k) {
  try { k ? localStorage.setItem(KEY_STORAGE, k) : localStorage.removeItem(KEY_STORAGE); } catch { /* אחסון חסום */ }
}

let sdkPromise = null;
async function askWithApi({ system, messages, signal }) {
  const apiKey = getKey();
  if (!apiKey) throw Object.assign(new Error('צריך מפתח API של Anthropic'), { code: 'no_key' });
  sdkPromise ||= import(SDK_URL);
  const { default: Anthropic } = await sdkPromise;
  // המפתח נשאר בדפדפן של המשתמש ונשלח רק ל-api.anthropic.com
  const client = new Anthropic({ apiKey, dangerouslyAllowBrowser: true });
  const stream = client.beta.messages.stream(
    {
      model: MODEL,
      max_tokens: 32000,
      betas: ['server-side-fallback-2026-07-01'],
      fallbacks: 'default',
      output_config: { effort: 'high' },
      system,
      messages,
    },
    { signal },
  );
  const msg = await stream.finalMessage();
  if (msg.stop_reason === 'refusal') throw new Error('Claude סירב לבקשה הזו');
  if (msg.stop_reason === 'max_tokens') throw new Error('התשובה נקטעה — נסה בקשה פשוטה יותר');
  return msg.content.filter((b) => b.type === 'text').map((b) => b.text).join('');
}

async function ask({ system, messages, signal, onText }) {
  const sample = await getSample();
  if (sample) {
    // sample לא שומר הקשר ואין לו system — ההנחיות נכנסות להודעה האחרונה
    const input = messages.map((m, i) => (i === messages.length - 1 ? { role: 'user', content: `${system}\n\n---\n\n${m.content}` } : m));
    const data = await sample.json(input, { modelTier: 'complex', signal, onText });
    return JSON.stringify(data);
  }
  return askWithApi({ system, messages, signal });
}

// ---------- ממשק ----------
export function initAI(app) {
  const $ = (id) => document.getElementById(id);
  const panel = $('aiPanel');
  const log = $('aiLog');
  const input = $('aiInput');
  const sendBtn = $('aiSend');
  const status = $('aiStatus');
  const keyBox = $('aiKeyBox');
  const history = []; // {role, content} — טקסט קצר בלבד
  let busy = null;

  const bubble = (role, text, extra) => {
    const d = document.createElement('div');
    d.className = `ai-msg ai-${role}`;
    const t = document.createElement('div');
    t.textContent = text;
    d.appendChild(t);
    if (extra) d.appendChild(extra);
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
    return d;
  };

  function renderExamples() {
    const box = $('aiExamples');
    box.innerHTML = '';
    box.hidden = history.length > 0;
    for (const ex of EXAMPLES) {
      const b = document.createElement('button');
      b.className = 'ai-chip';
      b.textContent = ex;
      b.addEventListener('click', () => {
        input.value = ex;
        input.focus();
      });
      box.appendChild(b);
    }
  }

  async function refreshProvider() {
    const sample = await getSample();
    keyBox.hidden = !!sample || !!getKey();
    $('aiKeyNote').hidden = !!sample || !getKey();
  }

  async function open() {
    panel.hidden = false;
    $('btnAI').classList.add('active');
    renderExamples();
    input.focus();
    await refreshProvider();
  }
  function close() {
    panel.hidden = true;
    $('btnAI').classList.remove('active');
  }

  $('btnAI').addEventListener('click', () => (panel.hidden ? open() : close()));
  $('aiClose').addEventListener('click', close);
  $('aiKeySave').addEventListener('click', () => {
    const k = $('aiKeyInput').value.trim();
    if (!k.startsWith('sk-')) {
      app.toast('המפתח צריך להתחיל ב-sk-');
      return;
    }
    setKey(k);
    $('aiKeyInput').value = '';
    refreshProvider();
    app.toast('המפתח נשמר בדפדפן הזה');
  });
  $('aiKeyForget').addEventListener('click', () => {
    setKey('');
    refreshProvider();
  });

  async function send() {
    const text = input.value.trim();
    if (!text || busy) return;
    input.value = '';
    $('aiExamples').hidden = true;
    bubble('user', text);
    const ctrl = new AbortController();
    busy = ctrl;
    sendBtn.textContent = 'עצור';
    const t0 = Date.now();
    const tick = setInterval(() => {
      status.textContent = `Claude ממדל… ${Math.round((Date.now() - t0) / 1000)} שנ'`;
    }, 500);
    status.hidden = false;
    status.textContent = 'Claude ממדל…';
    try {
      const scene = app.describeScene();
      const messages = [
        ...history.slice(-8),
        { role: 'user', content: `Current scene:\n${JSON.stringify(scene)}\n\nRequest:\n${text}` },
      ];
      const reply = await ask({ system: systemPrompt(app.settings()), messages, signal: ctrl.signal });
      const plan = extractJSON(reply);
      const result = app.applyPlan(plan);
      history.push({ role: 'user', content: text });
      history.push({ role: 'assistant', content: JSON.stringify({ message: plan.message, action: plan.action, parts: result.count }) });
      const undoBtn = document.createElement('button');
      undoBtn.className = 'ai-undo';
      undoBtn.textContent = '↶ בטל את השינוי';
      undoBtn.addEventListener('click', () => {
        app.undo();
        undoBtn.disabled = true;
        undoBtn.textContent = 'בוטל';
      });
      const msg = (plan.message || 'המודל מוכן.') + (result.dropped ? ` (${result.dropped} חלקים לא תקינים דולגו)` : '');
      bubble('assistant', msg, undoBtn);
      if (result.warnings.length) bubble('note', 'שים לב: ' + result.warnings.join(' · '));
    } catch (err) {
      if (err.code !== 'no_key' && !ctrl.signal.aborted) console.error(err);
      if (ctrl.signal.aborted) bubble('note', 'הבקשה בוטלה.');
      else if (err.code === 'no_key') {
        bubble('note', 'כדי להשתמש בעוזר מחוץ ל-claude.ai צריך מפתח API של Anthropic. הדבק אותו בתיבה למטה.');
        keyBox.hidden = false;
      } else if (err.code === 'not_granted') bubble('note', 'לא ניתנה הרשאה לשאול את Claude מהעמוד הזה.');
      else if (err.code === 'rate_limited') bubble('note', 'יותר מדי בקשות כרגע — נסה שוב בעוד דקה.');
      else if (err.status === 401) bubble('note', 'המפתח לא התקבל. בדוק אותו ושמור שוב.');
      else bubble('note', 'משהו השתבש: ' + (err.message || err));
    } finally {
      clearInterval(tick);
      status.hidden = true;
      busy = null;
      sendBtn.textContent = 'שלח';
    }
  }

  sendBtn.addEventListener('click', () => (busy ? busy.abort() : send()));
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      send();
    }
  });
}
