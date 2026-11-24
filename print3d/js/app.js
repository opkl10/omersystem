// סטודיו הדפסה — עורך מודלים פשוט למדפסת תלת־ממד
import * as THREE from 'three';
import { OrbitControls } from '../vendor/OrbitControls.js';
import { TransformControls } from '../vendor/TransformControls.js';
import { STLExporter } from '../vendor/STLExporter.js';
import { CATALOG, buildGeometry, defaultParams } from './shapes.js';
import { combineParts, prep, volumeOf } from './csg.js';

const STORAGE_KEY = 'print3d.project.v1';
const MATERIALS = { PLA: 1.24, PETG: 1.27, ABS: 1.04, TPU: 1.21 };
const PALETTE = ['#4f7cff', '#f08a24', '#2fb37a', '#c457d6', '#e0b400', '#20a4c4', '#e5566b'];
const DEFAULT_SETTINGS = { bedW: 220, bedD: 220, bedH: 250, nozzle: 0.4, tolerance: 0.2, material: 'PLA' };

const $ = (id) => document.getElementById(id);
const fmt = (n, d = 1) => (Math.round(n * 10 ** d) / 10 ** d).toLocaleString('he-IL', { maximumFractionDigits: d });
const r2 = (n) => Math.round(n * 100) / 100;
const uid = () => Math.random().toString(36).slice(2, 9);

// ---------- מצב ----------
let state = { name: 'פרויקט חדש', parts: [], settings: { ...DEFAULT_SETTINGS } };
let selectedId = null;
let resultMode = false;
const history = { stack: [], index: -1 };

// ---------- סצנה ----------
const viewportEl = $('viewport');
const renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
viewportEl.appendChild(renderer.domElement);

const scene = new THREE.Scene();
const camera = new THREE.PerspectiveCamera(40, 1, 0.5, 6000);
camera.up.set(0, 0, 1);
const orbit = new OrbitControls(camera, renderer.domElement);
orbit.enableDamping = true;
orbit.dampingFactor = 0.12;

scene.add(new THREE.HemisphereLight(0xffffff, 0x8a8f99, 1.6));
const sun = new THREE.DirectionalLight(0xffffff, 1.6);
sun.position.set(150, -200, 300);
scene.add(sun);
const fill = new THREE.DirectionalLight(0xffffff, 0.5);
fill.position.set(-200, 150, 120);
scene.add(fill);

const bedGroup = new THREE.Group();
scene.add(bedGroup);
const partsGroup = new THREE.Group();
scene.add(partsGroup);
let resultMesh = null;

const tc = new TransformControls(camera, renderer.domElement);
tc.setTranslationSnap(1);
tc.setRotationSnap(THREE.MathUtils.degToRad(15));
tc.setSize(0.8);
scene.add(tc.getHelper());

const meshes = new Map(); // id -> mesh

function isDark() {
  return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function buildBed() {
  bedGroup.clear();
  const { bedW: W, bedD: D, bedH: H } = state.settings;
  const dark = isDark();
  scene.background = new THREE.Color(dark ? 0x1f242d : 0xe9ecf1);
  const plate = new THREE.Mesh(
    new THREE.PlaneGeometry(W, D),
    new THREE.MeshStandardMaterial({ color: dark ? 0x2b313c : 0xfafbfc, roughness: 0.95 }),
  );
  plate.position.z = -0.05;
  bedGroup.add(plate);

  const minor = [], major = [];
  for (let x = -W / 2; x <= W / 2 + 1e-6; x += 10) {
    (Math.round(x) % 50 === 0 ? major : minor).push(x, -D / 2, 0, x, D / 2, 0);
  }
  for (let y = -D / 2; y <= D / 2 + 1e-6; y += 10) {
    (Math.round(y) % 50 === 0 ? major : minor).push(-W / 2, y, 0, W / 2, y, 0);
  }
  const lines = (arr, color, opacity) => {
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.Float32BufferAttribute(arr, 3));
    return new THREE.LineSegments(g, new THREE.LineBasicMaterial({ color, transparent: true, opacity }));
  };
  bedGroup.add(lines(minor, dark ? 0x5a6272 : 0xb9c0cc, 0.45));
  bedGroup.add(lines(major, dark ? 0x7a8396 : 0x8d97a8, 0.7));

  const vol = new THREE.Box3Helper(new THREE.Box3(new THREE.Vector3(-W / 2, -D / 2, 0), new THREE.Vector3(W / 2, D / 2, H)), dark ? 0x56607a : 0xc4cad6);
  vol.material.transparent = true;
  vol.material.opacity = 0.5;
  bedGroup.add(vol);

  // צירים בפינה הקדמית-שמאלית
  const o = new THREE.Vector3(-W / 2, -D / 2, 0.1);
  const axis = (dir, color) => new THREE.ArrowHelper(dir, o, 30, color, 6, 4);
  bedGroup.add(axis(new THREE.Vector3(1, 0, 0), 0xe5484d));
  bedGroup.add(axis(new THREE.Vector3(0, 1, 0), 0x30a46c));
  bedGroup.add(axis(new THREE.Vector3(0, 0, 1), 0x3e63dd));
}

function homeView() {
  const { bedW: W, bedD: D } = state.settings;
  const box = solidsBox();
  const target = box && !box.isEmpty() ? box.getCenter(new THREE.Vector3()) : new THREE.Vector3(0, 0, 20);
  const size = box && !box.isEmpty() ? box.getSize(new THREE.Vector3()).length() : 0;
  const dist = Math.max(size * 2.2, Math.max(W, D) * 1.5, 120);
  camera.position.set(target.x + dist * 0.45, target.y - dist * 0.75, target.z + dist * 0.55);
  orbit.target.copy(target);
  orbit.update();
}

function resize() {
  const { clientWidth: w, clientHeight: h } = viewportEl;
  if (!w || !h) return;
  renderer.setSize(w, h, false);
  renderer.domElement.style.width = w + 'px';
  renderer.domElement.style.height = h + 'px';
  camera.aspect = w / h;
  camera.updateProjectionMatrix();
}
new ResizeObserver(resize).observe(viewportEl);

renderer.setAnimationLoop(() => {
  orbit.update();
  renderer.render(scene, camera);
});

// ---------- גאומטריה וחלקים ----------
const geoCache = new Map();
function geometryFor(part) {
  const key = part.type + JSON.stringify(part.params) + state.settings.tolerance;
  let g = geoCache.get(key);
  if (!g) {
    g = buildGeometry(part.type, part.params, state.settings);
    if (!g.attributes.normal) g.computeVertexNormals();
    geoCache.set(key, g);
    if (geoCache.size > 120) geoCache.delete(geoCache.keys().next().value);
  }
  return g;
}

// קווי מתאר: רק קצוות שמשותפים לשני משולשים בזווית חדה.
// קצוות "יתומים" (תפרים שנוצרים בחיתוך CSG) לא מצוירים.
function edgesFor(geo, thresholdDeg = 35) {
  const pos = geo.attributes.position;
  const idx = geo.index;
  const n = idx ? idx.count : pos.count;
  const cos = Math.cos(THREE.MathUtils.degToRad(thresholdDeg));
  const k = (i) => `${Math.round(pos.getX(i) * 1000)},${Math.round(pos.getY(i) * 1000)},${Math.round(pos.getZ(i) * 1000)}`;
  const a = new THREE.Vector3(), b = new THREE.Vector3(), c = new THREE.Vector3();
  const map = new Map();
  const out = [];
  for (let t = 0; t < n; t += 3) {
    const ids = [0, 1, 2].map((j) => (idx ? idx.getX(t + j) : t + j));
    a.fromBufferAttribute(pos, ids[0]); b.fromBufferAttribute(pos, ids[1]); c.fromBufferAttribute(pos, ids[2]);
    const nrm = new THREE.Vector3().subVectors(c, b).cross(new THREE.Vector3().subVectors(a, b));
    if (nrm.lengthSq() < 1e-12) continue;
    nrm.normalize();
    const keys = ids.map(k);
    for (let e = 0; e < 3; e++) {
      const k1 = keys[e], k2 = keys[(e + 1) % 3];
      if (k1 === k2) continue;
      const key = k1 < k2 ? k1 + '|' + k2 : k2 + '|' + k1;
      const prev = map.get(key);
      if (prev) {
        if (prev.n.dot(nrm) < cos) {
          const i1 = ids[e], i2 = ids[(e + 1) % 3];
          out.push(pos.getX(i1), pos.getY(i1), pos.getZ(i1), pos.getX(i2), pos.getY(i2), pos.getZ(i2));
        }
        map.delete(key);
      } else map.set(key, { n: nrm });
    }
  }
  const g = new THREE.BufferGeometry();
  g.setAttribute('position', new THREE.Float32BufferAttribute(out, 3));
  return new THREE.LineSegments(g, new THREE.LineBasicMaterial({ color: 0x000000, transparent: true, opacity: 0.2 }));
}

function materialFor(part, selected) {
  if (part.hole) {
    return new THREE.MeshStandardMaterial({
      color: 0xe5484d, transparent: true, opacity: selected ? 0.55 : 0.35, depthWrite: false, roughness: 0.6,
    });
  }
  return new THREE.MeshStandardMaterial({
    color: part.color, roughness: 0.55, metalness: 0.05,
    emissive: selected ? new THREE.Color(part.color).multiplyScalar(0.25) : 0x000000,
  });
}

function syncMesh(part) {
  let mesh = meshes.get(part.id);
  const geo = geometryFor(part);
  if (!mesh) {
    mesh = new THREE.Mesh(geo, materialFor(part, part.id === selectedId));
    mesh.userData.id = part.id;
    meshes.set(part.id, mesh);
    partsGroup.add(mesh);
  }
  if (mesh.geometry !== geo) {
    mesh.geometry = geo;
    mesh.children.forEach((c) => c.geometry.dispose());
    mesh.clear();
  }
  if (!mesh.children.length) {
    mesh.add(edgesFor(geo));
  }
  mesh.material.dispose();
  mesh.material = materialFor(part, part.id === selectedId);
  mesh.position.fromArray(part.pos);
  mesh.rotation.set(...part.rot.map(THREE.MathUtils.degToRad), 'XYZ');
  mesh.updateMatrixWorld(true);
  return mesh;
}

function syncAll() {
  const ids = new Set(state.parts.map((p) => p.id));
  for (const [id, mesh] of meshes) {
    if (!ids.has(id)) {
      partsGroup.remove(mesh);
      mesh.material.dispose();
      meshes.delete(id);
    }
  }
  state.parts.forEach(syncMesh);
  if (selectedId && !ids.has(selectedId)) selectedId = null;
  attachGizmo();
  renderPartsList();
}

function partById(id) {
  return state.parts.find((p) => p.id === id);
}

function worldGeo(part) {
  const mesh = meshes.get(part.id) || syncMesh(part);
  mesh.updateMatrixWorld(true);
  return prep(mesh.geometry).applyMatrix4(mesh.matrixWorld);
}

function solidsBox(parts = state.parts.filter((p) => !p.hole)) {
  const box = new THREE.Box3();
  parts.forEach((p) => {
    const m = meshes.get(p.id);
    if (m) box.expandByObject(m, true);
  });
  return box;
}

// ---------- תוצאה, סטטיסטיקה ואזהרות ----------
function computeResult() {
  const solids = state.parts.filter((p) => !p.hole).map(worldGeo);
  const holes = state.parts.filter((p) => p.hole).map(worldGeo);
  return combineParts(solids, holes);
}

let statsTimer = null;
function scheduleStats() {
  clearTimeout(statsTimer);
  statsTimer = setTimeout(updateStats, 250);
}

function updateStats() {
  const statsEl = $('stats');
  const warnEl = $('warnings');
  warnEl.innerHTML = '';
  if (!state.parts.length) {
    statsEl.hidden = true;
    clearResultMesh();
    return;
  }
  let geo = null;
  try {
    geo = computeResult();
  } catch (err) {
    console.error(err);
  }
  const warnings = [];
  const s = state.settings;
  if (!geo) {
    statsEl.hidden = true;
    if (state.parts.length) warnings.push('יש רק חורים בפרויקט — הוסף צורה מוצקה שממנה יחוסרו');
  } else {
    geo.computeBoundingBox();
    const bb = geo.boundingBox;
    const size = bb.getSize(new THREE.Vector3());
    const vol = Math.abs(volumeOf(geo)) / 1000; // סמ"ק
    const grams = vol * (MATERIALS[s.material] || 1.24);
    const meters = (vol * 1000) / (Math.PI * 0.875 ** 2) / 1000;
    statsEl.hidden = false;
    statsEl.innerHTML = `
      <span>מידות X×Y×Z (מ"מ)</span><b>${fmt(size.x)} × ${fmt(size.y)} × ${fmt(size.z)}</b>
      <span>נפח (סמ"ק)</span><b>${fmt(vol, 1)}</b>
      <span>משקל מוצק ${s.material} (גרם)</span><b>~${fmt(grams, 0)}</b>
      <span>פילמנט 1.75 (מטר)</span><b>~${fmt(meters, 1)}</b>`;
    if (size.x > s.bedW || size.y > s.bedD) warnings.push(`המודל רחב מהמשטח (${s.bedW}×${s.bedD}) — הקטן או סובב אותו`);
    else if (bb.min.x < -s.bedW / 2 - 0.5 || bb.max.x > s.bedW / 2 + 0.5 || bb.min.y < -s.bedD / 2 - 0.5 || bb.max.y > s.bedD / 2 + 0.5)
      warnings.push('המודל חורג מגבולות המשטח — לחץ "מרכז"');
    if (size.z > s.bedH) warnings.push(`המודל גבוה מגובה ההדפסה (${s.bedH} מ"מ)`);
    if (bb.min.z > 0.05) warnings.push(`המודל מרחף ${fmt(bb.min.z)} מ"מ מעל המשטח — לחץ "הנח על המשטח"`);
    if (bb.min.z < -0.05) warnings.push('חלק מהמודל מתחת למשטח ויחתך — לחץ "הנח על המשטח"');
    if (resultMode) showResultMesh(geo);
  }
  for (const p of state.parts) {
    if (p.hole) continue;
    const chk = CATALOG[p.type].checks?.({ ...defaultParams(p.type), ...p.params }, s) || [];
    chk.forEach((w) => warnings.push(`${CATALOG[p.type].name}: ${w}`));
  }
  if (!warnings.length) {
    const li = document.createElement('li');
    li.className = 'ok';
    li.textContent = '✓ נראה מוכן להדפסה';
    warnEl.appendChild(li);
  }
  warnings.forEach((w) => {
    const li = document.createElement('li');
    li.textContent = '⚠ ' + w;
    warnEl.appendChild(li);
  });
}

function clearResultMesh() {
  if (resultMesh) {
    scene.remove(resultMesh);
    resultMesh.geometry.dispose();
    resultMesh = null;
  }
}

function showResultMesh(geo) {
  clearResultMesh();
  const color = state.parts.find((p) => !p.hole)?.color || '#4f7cff';
  resultMesh = new THREE.Mesh(geo, new THREE.MeshStandardMaterial({ color, roughness: 0.55 }));
  resultMesh.add(edgesFor(geo));
  scene.add(resultMesh);
}

function setResultMode(on) {
  resultMode = on;
  $('btnResult').classList.toggle('active', on);
  partsGroup.visible = !on;
  if (!on) clearResultMesh();
  attachGizmo();
  updateStats();
}

// ---------- היסטוריה ושמירה ----------
function snapshot() {
  return JSON.stringify(state);
}

function pushHistory() {
  const snap = snapshot();
  if (history.stack[history.index] === snap) return;
  history.stack = history.stack.slice(0, history.index + 1);
  history.stack.push(snap);
  if (history.stack.length > 100) history.stack.shift();
  history.index = history.stack.length - 1;
  updateUndoButtons();
}

function updateUndoButtons() {
  $('btnUndo').disabled = history.index <= 0;
  $('btnRedo').disabled = history.index >= history.stack.length - 1;
}

function restore(snap) {
  const prevSettings = JSON.stringify(state.settings);
  state = JSON.parse(snap);
  $('projectName').value = state.name;
  if (JSON.stringify(state.settings) !== prevSettings) buildBed();
  syncAll();
  renderProps();
  persist();
  scheduleStats();
}

function undo() {
  if (history.index <= 0) return;
  history.index--;
  restore(history.stack[history.index]);
  updateUndoButtons();
}

function redo() {
  if (history.index >= history.stack.length - 1) return;
  history.index++;
  restore(history.stack[history.index]);
  updateUndoButtons();
}

function persist() {
  try {
    localStorage.setItem(STORAGE_KEY, snapshot());
  } catch { /* אחסון לא זמין */ }
}

let commitTimer = null;
function commit(delay = 0) {
  clearTimeout(commitTimer);
  const run = () => {
    pushHistory();
    persist();
    scheduleStats();
  };
  if (delay) commitTimer = setTimeout(run, delay);
  else run();
}

// ---------- פעולות על חלקים ----------
function addPart(type) {
  const def = CATALOG[type];
  const solidsCount = state.parts.filter((p) => !p.hole).length;
  const part = {
    id: uid(),
    type,
    name: def.name,
    params: defaultParams(type),
    pos: [0, 0, 0],
    rot: [0, 0, 0],
    color: PALETTE[solidsCount % PALETTE.length],
    hole: false,
  };
  if (state.parts.length) part.pos = findFreeSpot(geometryFor(part));
  state.parts.push(part);
  if (resultMode) setResultMode(false);
  select(part.id);
  syncAll();
  commit();
  toast(`נוסף: ${def.name}`);
}

// מחפש מקום פנוי על המשטח (בספירלה מהמרכז) שלא חופף לחלקים קיימים
function findFreeSpot(geo) {
  geo.computeBoundingBox();
  const gb = geo.boundingBox;
  const taken = state.parts
    .map((p) => meshes.get(p.id))
    .filter(Boolean)
    .map((m) => new THREE.Box3().expandByObject(m, true));
  const { bedW, bedD } = state.settings;
  const gap = 6;
  const m = 2; // שוליים מקצה המשטח
  const fits = (x, y) => {
    const a = { x0: x + gb.min.x - gap, x1: x + gb.max.x + gap, y0: y + gb.min.y - gap, y1: y + gb.max.y + gap };
    if (x + gb.min.x < -bedW / 2 + m || x + gb.max.x > bedW / 2 - m || y + gb.min.y < -bedD / 2 + m || y + gb.max.y > bedD / 2 - m) return false;
    return taken.every((b) => a.x1 <= b.min.x || a.x0 >= b.max.x || a.y1 <= b.min.y || a.y0 >= b.max.y);
  };
  const step = 5;
  const maxR = Math.max(bedW, bedD);
  for (let r = 0; r <= maxR; r += step) {
    for (let i = -r; i <= r; i += step) {
      for (const [x, y] of [[i, -r], [i, r], [-r, i], [r, i]]) if (fits(x, y)) return [x, y, 0];
    }
  }
  const all = new THREE.Box3();
  taken.forEach((b) => all.union(b));
  return [r2(all.max.x + gap - gb.min.x), 0, 0];
}

function deletePart(id) {
  state.parts = state.parts.filter((p) => p.id !== id);
  if (selectedId === id) selectedId = null;
  syncAll();
  renderProps();
  commit();
}

function duplicatePart(id) {
  const src = partById(id);
  if (!src) return;
  const copy = JSON.parse(JSON.stringify(src));
  copy.id = uid();
  copy.pos = [r2(src.pos[0] + 10), r2(src.pos[1] + 10), src.pos[2]];
  state.parts.push(copy);
  select(copy.id);
  syncAll();
  commit();
}

function shiftAll(dx, dy, dz) {
  state.parts.forEach((p) => {
    p.pos = [r2(p.pos[0] + dx), r2(p.pos[1] + dy), r2(p.pos[2] + dz)];
  });
  syncAll();
  renderProps();
  commit();
}

function dropAll() {
  const box = solidsBox();
  if (box.isEmpty()) return;
  shiftAll(0, 0, -box.min.z);
  toast('המודל הונח על המשטח');
}

function centerAll() {
  const box = solidsBox();
  if (box.isEmpty()) return;
  const c = box.getCenter(new THREE.Vector3());
  shiftAll(-c.x, -c.y, 0);
}

function dropPart(part) {
  syncMesh(part);
  const box = new THREE.Box3().expandByObject(meshes.get(part.id), true);
  part.pos[2] = r2(part.pos[2] - box.min.z);
  syncMesh(part);
}

function rotatePart(part, axis, by) {
  part.rot[axis] = ((part.rot[axis] + by + 540) % 360) - 180;
  dropPart(part);
  renderProps();
  commit();
}

// ---------- בחירה וגרירה ----------
function select(id) {
  const prev = selectedId;
  selectedId = id;
  if (prev && partById(prev)) syncMesh(partById(prev));
  if (id && partById(id)) syncMesh(partById(id));
  attachGizmo();
  renderPartsList();
  renderProps();
}

function attachGizmo() {
  const mesh = selectedId && !resultMode ? meshes.get(selectedId) : null;
  if (mesh) tc.attach(mesh);
  else tc.detach();
}

tc.addEventListener('dragging-changed', (e) => {
  orbit.enabled = !e.value;
  if (!e.value) {
    const part = partById(selectedId);
    if (part && tc.mode === 'rotate') dropPart(part);
    renderProps();
    commit();
  }
});
tc.addEventListener('objectChange', () => {
  const part = partById(selectedId);
  const mesh = meshes.get(selectedId);
  if (!part || !mesh) return;
  part.pos = mesh.position.toArray().map(r2);
  part.rot = [mesh.rotation.x, mesh.rotation.y, mesh.rotation.z].map((r) => Math.round(THREE.MathUtils.radToDeg(r)));
  updateTransformInputs(part);
});

function setGizmoMode(mode) {
  tc.setMode(mode);
  $('modeMove').classList.toggle('active', mode === 'translate');
  $('modeRotate').classList.toggle('active', mode === 'rotate');
}

// לחיצה בתצוגה בוחרת חלק
const ray = new THREE.Raycaster();
let downAt = null;
renderer.domElement.addEventListener('pointerdown', (e) => {
  downAt = [e.clientX, e.clientY];
});
renderer.domElement.addEventListener('pointerup', (e) => {
  if (!downAt || tc.dragging) return;
  const moved = Math.hypot(e.clientX - downAt[0], e.clientY - downAt[1]);
  downAt = null;
  if (moved > 4 || resultMode) return;
  const rect = renderer.domElement.getBoundingClientRect();
  const ndc = new THREE.Vector2(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
  ray.setFromCamera(ndc, camera);
  const hits = ray.intersectObjects([...meshes.values()], false);
  select(hits.length ? hits[0].object.userData.id : null);
});

// ---------- ממשק: קטלוג ורשימת חלקים ----------
let currentTab = 'product';
function renderCatalog() {
  const el = $('catalog');
  el.innerHTML = '';
  for (const [type, def] of Object.entries(CATALOG)) {
    if (def.cat !== currentTab) continue;
    const b = document.createElement('button');
    b.className = 'cat-item';
    b.title = def.desc || def.name;
    b.innerHTML = `<span class="ic" aria-hidden="true">${def.icon}</span><span class="nm">${def.name}</span>`;
    b.addEventListener('click', () => addPart(type));
    el.appendChild(b);
  }
}

document.querySelectorAll('.tab').forEach((t) =>
  t.addEventListener('click', () => {
    currentTab = t.dataset.tab;
    document.querySelectorAll('.tab').forEach((x) => {
      x.classList.toggle('active', x === t);
      x.setAttribute('aria-selected', x === t);
    });
    renderCatalog();
  }),
);

function renderPartsList() {
  const ul = $('partsList');
  ul.innerHTML = '';
  $('emptyHint').hidden = state.parts.length > 0;
  for (const p of state.parts) {
    const li = document.createElement('li');
    const b = document.createElement('button');
    b.className = p.id === selectedId ? 'sel' : '';
    const sw = document.createElement('span');
    sw.className = 'swatch';
    sw.style.background = p.hole ? 'transparent' : p.color;
    if (p.hole) sw.style.border = '1.5px dashed #e5484d';
    const nm = document.createElement('span');
    nm.textContent = `${CATALOG[p.type].icon} ${p.name || CATALOG[p.type].name}`;
    b.append(sw, nm);
    if (p.hole) {
      const bd = document.createElement('span');
      bd.className = 'badge';
      bd.textContent = 'חור';
      b.appendChild(bd);
    }
    b.addEventListener('click', () => {
      if (resultMode) setResultMode(false);
      select(p.id);
    });
    li.appendChild(b);
    ul.appendChild(li);
  }
}

// ---------- ממשק: פאנל מאפיינים ----------
function renderProps() {
  const el = $('props');
  const part = partById(selectedId);
  if (!part) {
    el.innerHTML = `
      <div class="empty-props">
        <span class="big" aria-hidden="true">🧊</span>
        בחר חלק כדי לערוך את המידות שלו,<br>או הוסף מוצר מהרשימה.
      </div>
      <div class="group kbd-help">
        <h3>קיצורים</h3>
        <kbd>W</kbd> הזזה · <kbd>E</kbd> סיבוב<br>
        <kbd>←↑→↓</kbd> הזזה במ"מ (<kbd>Shift</kbd> = 10)<br>
        <kbd>PgUp</kbd>/<kbd>PgDn</kbd> למעלה/למטה<br>
        <kbd>Ctrl+D</kbd> שכפול · <kbd>Delete</kbd> מחיקה<br>
        <kbd>Ctrl+Z</kbd> ביטול · <kbd>Ctrl+Y</kbd> חזרה<br>
        גרירה בעכבר: סיבוב מבט · ימני: הזזת מבט · גלגלת: זום
      </div>`;
    return;
  }
  const def = CATALOG[part.type];
  const p = { ...defaultParams(part.type), ...part.params };
  el.innerHTML = '';

  const head = document.createElement('div');
  head.innerHTML = `
    <div class="props-head"><span class="ic" aria-hidden="true">${def.icon}</span><h2></h2></div>
    ${def.desc ? `<p class="props-desc">${def.desc}</p>` : ''}`;
  head.querySelector('h2').textContent = def.name;
  el.appendChild(head);

  // שם
  const nameGroup = group('שם החלק');
  const nameIn = document.createElement('input');
  nameIn.type = 'text';
  nameIn.value = part.name || def.name;
  nameIn.setAttribute('aria-label', 'שם החלק');
  nameIn.addEventListener('input', () => {
    part.name = nameIn.value;
    renderPartsList();
    commit(500);
  });
  nameGroup.appendChild(nameIn);
  el.appendChild(nameGroup);

  // מידות
  const dims = group('מידות');
  for (const q of def.params) {
    const row = document.createElement('div');
    row.className = 'param';
    const idR = `p_${q.key}`;
    row.innerHTML = `<label for="${idR}">${q.label}<span class="u">${q.unit}</span></label>`;
    const range = document.createElement('input');
    Object.assign(range, { type: 'range', id: idR, min: q.min, max: q.max, step: q.step, value: p[q.key] });
    const num = document.createElement('input');
    Object.assign(num, { type: 'number', min: q.min, max: q.max, step: q.step, value: p[q.key] });
    num.setAttribute('aria-label', q.label);
    const apply = (v, src) => {
      v = parseFloat(v);
      if (Number.isNaN(v)) return;
      v = Math.min(q.max, Math.max(q.min, v));
      if (src !== range) range.value = v;
      if (src !== num) num.value = v;
      part.params[q.key] = v;
      try {
        syncMesh(part);
      } catch (err) {
        console.error(err);
      }
      commit(400);
    };
    range.addEventListener('input', () => apply(range.value, range));
    num.addEventListener('change', () => apply(num.value, num));
    row.append(range, num);
    dims.appendChild(row);
  }
  const resetBtn = document.createElement('button');
  resetBtn.textContent = 'איפוס מידות';
  resetBtn.addEventListener('click', () => {
    part.params = defaultParams(part.type);
    syncMesh(part);
    renderProps();
    commit();
  });
  dims.appendChild(resetBtn);
  el.appendChild(dims);

  // מיקום וסיבוב
  const tr = group('מיקום (מ"מ) וסיבוב (°)');
  tr.appendChild(xyzInputs('pos', part.pos, (i, v) => {
    part.pos[i] = v;
    syncMesh(part);
    commit();
  }));
  tr.appendChild(xyzInputs('rot', part.rot, (i, v) => {
    part.rot[i] = v;
    syncMesh(part);
    commit();
  }));
  const rotRow = document.createElement('div');
  rotRow.className = 'row';
  rotRow.style.marginTop = '8px';
  ['X', 'Y', 'Z'].forEach((ax, i) => {
    const b = document.createElement('button');
    b.textContent = `⟳ 90° ${ax}`;
    b.title = `סובב 90° סביב ציר ${ax} והנח על המשטח`;
    b.addEventListener('click', () => rotatePart(part, i, 90));
    rotRow.appendChild(b);
  });
  tr.appendChild(rotRow);
  const dropRow = document.createElement('div');
  dropRow.className = 'row';
  dropRow.style.marginTop = '6px';
  const dropB = document.createElement('button');
  dropB.textContent = '⤓ הורד את החלק למשטח';
  dropB.addEventListener('click', () => {
    dropPart(part);
    renderProps();
    commit();
  });
  dropRow.appendChild(dropB);
  tr.appendChild(dropRow);
  el.appendChild(tr);

  // מראה וסוג
  const look = group('סוג');
  const holeLbl = document.createElement('label');
  holeLbl.className = 'toggle';
  holeLbl.innerHTML = '<input type="checkbox"> <span>חור — לחסר את הצורה מהחלקים האחרים</span>';
  const holeIn = holeLbl.querySelector('input');
  holeIn.checked = part.hole;
  holeIn.addEventListener('change', () => {
    part.hole = holeIn.checked;
    syncMesh(part);
    renderPartsList();
    renderProps();
    commit();
  });
  look.appendChild(holeLbl);
  if (!part.hole) {
    const colorLbl = document.createElement('label');
    colorLbl.className = 'toggle';
    colorLbl.style.marginTop = '8px';
    colorLbl.innerHTML = '<input type="color" style="width:36px;height:26px;padding:0;border:0;background:none"> <span>צבע תצוגה</span>';
    const ci = colorLbl.querySelector('input');
    ci.value = part.color;
    ci.addEventListener('input', () => {
      part.color = ci.value;
      syncMesh(part);
      renderPartsList();
      commit(400);
    });
    look.appendChild(colorLbl);
  }
  el.appendChild(look);

  const act = group('');
  const row = document.createElement('div');
  row.className = 'row';
  const dup = document.createElement('button');
  dup.textContent = '⧉ שכפל';
  dup.addEventListener('click', () => duplicatePart(part.id));
  const del = document.createElement('button');
  del.textContent = '🗑 מחק';
  del.className = 'danger';
  del.addEventListener('click', () => deletePart(part.id));
  row.append(dup, del);
  act.appendChild(row);
  el.appendChild(act);
}

function group(title) {
  const g = document.createElement('div');
  g.className = 'group';
  if (title) {
    const h = document.createElement('h3');
    h.textContent = title;
    g.appendChild(h);
  }
  return g;
}

function xyzInputs(kind, values, onChange) {
  const wrap = document.createElement('div');
  wrap.className = 'xyz';
  wrap.style.marginBottom = '6px';
  ['X', 'Y', 'Z'].forEach((ax, i) => {
    const lbl = document.createElement('label');
    lbl.textContent = kind === 'pos' ? ax : `סיבוב ${ax}`;
    const inp = document.createElement('input');
    inp.type = 'number';
    inp.step = kind === 'pos' ? 1 : 15;
    inp.value = values[i];
    inp.dataset.kind = kind;
    inp.dataset.axis = i;
    inp.addEventListener('change', () => {
      const v = parseFloat(inp.value);
      if (!Number.isNaN(v)) onChange(i, v);
    });
    lbl.appendChild(inp);
    wrap.appendChild(lbl);
  });
  return wrap;
}

function updateTransformInputs(part) {
  document.querySelectorAll('#props input[data-kind]').forEach((inp) => {
    const arr = inp.dataset.kind === 'pos' ? part.pos : part.rot;
    inp.value = arr[+inp.dataset.axis];
  });
}

// ---------- ייצוא, שמירה, פתיחה ----------
function safeName() {
  return (state.name || 'model').replace(/[\\/:*?"<>|]+/g, '_').trim() || 'model';
}

function download(blob, filename) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

function exportSTL() {
  if (!state.parts.some((p) => !p.hole)) {
    toast('אין מה לייצא — הוסף מוצר או צורה');
    return;
  }
  const wrap = document.querySelector('.viewport-wrap');
  wrap.classList.add('busy');
  setTimeout(() => {
    try {
      const geo = computeResult();
      const data = new STLExporter().parse(new THREE.Mesh(geo), { binary: true });
      download(new Blob([data], { type: 'model/stl' }), `${safeName()}.stl`);
      toast('קובץ STL ירד — פתח אותו בתוכנת החיתוך (Cura / PrusaSlicer / Bambu)');
    } catch (err) {
      console.error(err);
      toast('הייצוא נכשל: ' + err.message);
    } finally {
      wrap.classList.remove('busy');
    }
  }, 30);
}

function saveProject() {
  download(new Blob([JSON.stringify({ app: 'print3d', version: 1, ...state }, null, 2)], { type: 'application/json' }), `${safeName()}.json`);
  toast('הפרויקט נשמר');
}

function loadProjectData(data) {
  if (!data || !Array.isArray(data.parts)) throw new Error('הקובץ אינו פרויקט תקין');
  const parts = data.parts
    .filter((p) => CATALOG[p.type])
    .map((p) => ({
      id: p.id || uid(),
      type: p.type,
      name: String(p.name || CATALOG[p.type].name),
      params: { ...defaultParams(p.type), ...(p.params || {}) },
      pos: (p.pos || [0, 0, 0]).map(Number),
      rot: (p.rot || [0, 0, 0]).map(Number),
      color: p.color || PALETTE[0],
      hole: !!p.hole,
    }));
  state = { name: String(data.name || 'פרויקט'), parts, settings: { ...DEFAULT_SETTINGS, ...(data.settings || {}) } };
  selectedId = null;
  $('projectName').value = state.name;
  buildBed();
  syncAll();
  renderProps();
  homeView();
}

// ---------- הגדרות ----------
const dlg = $('settingsDlg');
$('btnSettings').addEventListener('click', () => {
  const s = state.settings;
  $('setBedW').value = s.bedW;
  $('setBedD').value = s.bedD;
  $('setBedH').value = s.bedH;
  $('setNozzle').value = s.nozzle;
  $('setTol').value = s.tolerance;
  $('setMat').value = s.material;
  dlg.showModal();
});
dlg.addEventListener('close', () => {
  if (dlg.returnValue !== 'ok') return;
  const num = (id, def) => {
    const v = parseFloat($(id).value);
    return Number.isFinite(v) && v > 0 ? v : def;
  };
  state.settings = {
    bedW: num('setBedW', 220),
    bedD: num('setBedD', 220),
    bedH: num('setBedH', 250),
    nozzle: num('setNozzle', 0.4),
    tolerance: Math.max(0, parseFloat($('setTol').value) || 0),
    material: $('setMat').value,
  };
  buildBed();
  syncAll();
  commit();
});

// ---------- כפתורים ומקלדת ----------
$('btnUndo').addEventListener('click', undo);
$('btnRedo').addEventListener('click', redo);
$('btnNew').addEventListener('click', () => {
  if (state.parts.length && !confirm('להתחיל פרויקט חדש? שינויים שלא נשמרו לקובץ יימחקו.')) return;
  loadProjectData({ name: 'פרויקט חדש', parts: [], settings: state.settings });
  commit();
});
$('btnSave').addEventListener('click', saveProject);
$('btnOpen').addEventListener('click', () => $('fileOpen').click());
$('fileOpen').addEventListener('change', async (e) => {
  const f = e.target.files[0];
  e.target.value = '';
  if (!f) return;
  try {
    loadProjectData(JSON.parse(await f.text()));
    commit();
    toast('הפרויקט נטען');
  } catch (err) {
    toast('לא ניתן לפתוח: ' + err.message);
  }
});
$('btnExport').addEventListener('click', exportSTL);
$('btnResult').addEventListener('click', () => setResultMode(!resultMode));
$('btnDrop').addEventListener('click', dropAll);
$('btnCenter').addEventListener('click', centerAll);
$('btnHome').addEventListener('click', homeView);
$('modeMove').addEventListener('click', () => setGizmoMode('translate'));
$('modeRotate').addEventListener('click', () => setGizmoMode('rotate'));
$('projectName').addEventListener('input', (e) => {
  state.name = e.target.value;
  commit(600);
});

window.addEventListener('keydown', (e) => {
  const tag = e.target.tagName;
  if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || dlg.open) return;
  const mod = e.ctrlKey || e.metaKey;
  const part = partById(selectedId);
  if (mod && e.key.toLowerCase() === 'z') {
    e.preventDefault();
    e.shiftKey ? redo() : undo();
  } else if (mod && e.key.toLowerCase() === 'y') {
    e.preventDefault();
    redo();
  } else if (mod && e.key.toLowerCase() === 's') {
    e.preventDefault();
    saveProject();
  } else if (mod && e.key.toLowerCase() === 'd' && part) {
    e.preventDefault();
    duplicatePart(part.id);
  } else if ((e.key === 'Delete' || e.key === 'Backspace') && part) {
    e.preventDefault();
    deletePart(part.id);
  } else if (e.key === 'Escape') {
    select(null);
  } else if (e.key.toLowerCase() === 'w') {
    setGizmoMode('translate');
  } else if (e.key.toLowerCase() === 'e') {
    setGizmoMode('rotate');
  } else if (part && ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'PageUp', 'PageDown'].includes(e.key)) {
    e.preventDefault();
    const st = e.shiftKey ? 10 : 1;
    const d = { ArrowLeft: [0, -st], ArrowRight: [0, st], ArrowUp: [1, st], ArrowDown: [1, -st], PageUp: [2, st], PageDown: [2, -st] }[e.key];
    part.pos[d[0]] = r2(part.pos[d[0]] + d[1]);
    syncMesh(part);
    updateTransformInputs(part);
    commit(400);
  }
});

let toastTimer = null;
function toast(msg) {
  const t = $('toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 2600);
}

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', buildBed);

// ---------- הפעלה ----------
function init() {
  let loaded = false;
  try {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved) {
      loadProjectData(JSON.parse(saved));
      loaded = true;
    }
  } catch { /* אין שמירה קודמת */ }
  if (!loaded) loadProjectData({ name: 'פרויקט חדש', parts: [], settings: DEFAULT_SETTINGS });
  renderCatalog();
  resize();
  homeView();
  pushHistory();
  scheduleStats();
}
init();

// לבדיקות ולשימוש מהקונסול
window.print3d = { get state() { return state; }, addPart, exportSTL, computeResult, select, setResultMode };
