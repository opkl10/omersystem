// סטודיו הדפסה — עורך מודלים פשוט למדפסת תלת־ממד
import * as THREE from 'three';
import { OrbitControls } from '../vendor/OrbitControls.js';
import { TransformControls } from '../vendor/TransformControls.js';
import { STLExporter } from '../vendor/STLExporter.js';
import { CATALOG, buildGeometry, defaultParams, encodeMesh } from './shapes.js';
import { STLLoader } from '../vendor/STLLoader.js';
import { openSketchEditor } from './sketch.js';
import { combineParts, prep, volumeOf, unionAll, subtractAll, intersectAll } from './csg.js';
import { loadFonts } from './text.js';

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
let selectedId = null; // החלק הראשי (האחרון שנבחר)
let selectedIds = new Set(); // כל החלקים הנבחרים
const isSel = (id) => selectedIds.has(id);
let gizmoMode = 'translate';
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
tc.setScaleSnap(0.05);
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
  if (part.type === 'group') {
    const gkey = 'group' + part.op + JSON.stringify(part.children) + state.settings.tolerance;
    let gg = geoCache.get(gkey);
    if (!gg) {
      gg = buildGroup(part);
      geoCache.set(gkey, gg);
    }
    return gg;
  }
  const key = part.type + JSON.stringify(part.params) + state.settings.tolerance + (part.text ?? '') +
    (part.sketch ? JSON.stringify(part.sketch) : '') + (part.mesh ? part.mesh.length + part.mesh.slice(0, 64) + part.mesh.slice(-64) : '');
  let g = geoCache.get(key);
  if (!g) {
    g = buildGeometry(part.type, part.params, state.settings, part);
    if (!g.attributes.normal) g.computeVertexNormals();
    geoCache.set(key, g);
    if (geoCache.size > 120) geoCache.delete(geoCache.keys().next().value);
  }
  return g;
}

// ---------- קבוצות: כמה חלקים שמאוחדים לגוף אחד ----------
function partMatrix(p) {
  return new THREE.Matrix4().compose(
    new THREE.Vector3(...p.pos),
    new THREE.Quaternion().setFromEuler(new THREE.Euler(...p.rot.map(THREE.MathUtils.degToRad), 'XYZ')),
    new THREE.Vector3(...(p.scale || [1, 1, 1])),
  );
}

function flipWinding(g) {
  const a = g.attributes.position.array;
  for (let i = 0; i < a.length; i += 9) {
    for (let k = 0; k < 3; k++) {
      const t = a[i + 3 + k]; a[i + 3 + k] = a[i + 6 + k]; a[i + 6 + k] = t;
    }
  }
  g.computeVertexNormals();
  return g;
}

function bakedGeo(p, matrix) {
  const g = prep(geometryFor(p)).applyMatrix4(matrix);
  return matrix.determinant() < 0 ? flipWinding(g) : g;
}

function buildGroup(part) {
  const solids = [], holes = [];
  for (const c of part.children || []) (c.hole ? holes : solids).push(bakedGeo(c, partMatrix(c)));
  let g = null;
  try {
    if (solids.length) g = part.op === 'intersect' ? intersectAll(solids) : unionAll(solids);
    if (g && holes.length) g = subtractAll(g, holes);
  } catch (err) {
    console.error(err);
  }
  if (!g || !g.attributes.position.count) {
    g = new THREE.BoxGeometry(1, 1, 1);
    g.translate(0, 0, 0.5);
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
    mesh = new THREE.Mesh(geo, materialFor(part, isSel(part.id)));
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
  mesh.material = materialFor(part, isSel(part.id));
  mesh.position.fromArray(part.pos);
  mesh.rotation.set(...part.rot.map(THREE.MathUtils.degToRad), 'XYZ');
  mesh.scale.fromArray(part.scale || [1, 1, 1]);
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
  for (const id of [...selectedIds]) if (!ids.has(id)) selectedIds.delete(id);
  if (!selectedId && selectedIds.size) selectedId = [...selectedIds].pop();
  attachGizmo();
  renderPartsList();
}

function partById(id) {
  return state.parts.find((p) => p.id === id);
}

function worldGeo(part) {
  const mesh = meshes.get(part.id) || syncMesh(part);
  mesh.updateMatrixWorld(true);
  const g = prep(mesh.geometry).applyMatrix4(mesh.matrixWorld);
  // שיקוף הופך את כיוון המשולשים — מחזירים אותו כדי שהגוף יישאר תקין
  return mesh.matrixWorld.determinant() < 0 ? flipWinding(g) : g;
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
    const chk = CATALOG[p.type].checks?.({ ...defaultParams(p.type), ...p.params }, s, p) || [];
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
  } catch {
    if (state.parts.some((p) => p.mesh)) toast('הפרויקט גדול מדי לשמירה אוטומטית — שמור אותו לקובץ');
  }
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
function addPart(type, extra = null) {
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
    scale: [1, 1, 1],
  };
  if (def.defaultSketch) part.sketch = def.defaultSketch();
  if (def.defaultText) part.text = def.defaultText;
  if (extra) Object.assign(part, extra);
  if (state.parts.length) part.pos = findFreeSpot(geometryFor(part));
  state.parts.push(part);
  if (resultMode) setResultMode(false);
  select(part.id);
  syncAll();
  commit();
  toast(`נוסף: ${part.name}`);
  if (def.defaultSketch && !extra) editSketch(part);
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

function deleteSelected() {
  if (!selectedIds.size) return;
  const n = selectedIds.size;
  state.parts = state.parts.filter((p) => !selectedIds.has(p.id));
  selectedIds.clear();
  selectedId = null;
  syncAll();
  renderProps();
  commit();
  if (n > 1) toast(`נמחקו ${n} חלקים`);
}

function duplicateSelected() {
  const src = state.parts.filter((p) => selectedIds.has(p.id));
  if (!src.length) return;
  const copies = src.map((p) => ({ ...JSON.parse(JSON.stringify(p)), id: uid(), pos: [r2(p.pos[0] + 10), r2(p.pos[1] + 10), p.pos[2]] }));
  state.parts.push(...copies);
  selectedIds = new Set(copies.map((c) => c.id));
  selectedId = copies[copies.length - 1].id;
  syncAll();
  renderProps();
  commit();
}

function deletePart(id) {
  state.parts = state.parts.filter((p) => p.id !== id);
  selectedIds.delete(id);
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
// additive: Shift/Ctrl — מוסיף או מסיר מהבחירה
function select(id, additive = false) {
  const before = new Set(selectedIds);
  if (!additive) {
    selectedIds = new Set(id ? [id] : []);
    selectedId = id;
  } else if (id) {
    if (selectedIds.has(id)) {
      selectedIds.delete(id);
      selectedId = [...selectedIds].pop() || null;
    } else {
      selectedIds.add(id);
      selectedId = id;
    }
  }
  for (const x of new Set([...before, ...selectedIds])) {
    const p = partById(x);
    if (p) syncMesh(p);
  }
  attachGizmo();
  renderPartsList();
  renderProps();
}

const pivot = new THREE.Object3D();
scene.add(pivot);
let multiDrag = null;

function selectionBox(ids = selectedIds) {
  const box = new THREE.Box3();
  ids.forEach((id) => {
    const m = meshes.get(id);
    if (m) box.expandByObject(m, true);
  });
  return box;
}

function attachGizmo() {
  if (resultMode || !selectedIds.size) {
    tc.detach();
    return;
  }
  if (selectedIds.size > 1) {
    // כמה חלקים: גוררים את כולם יחד (הזזה בלבד)
    const box = selectionBox();
    box.getCenter(pivot.position);
    pivot.updateMatrixWorld();
    tc.setMode('translate');
    tc.attach(pivot);
    return;
  }
  tc.setMode(gizmoMode);
  const mesh = meshes.get(selectedId);
  if (mesh) tc.attach(mesh);
  else tc.detach();
}

tc.addEventListener('dragging-changed', (e) => {
  orbit.enabled = !e.value;
  if (e.value && selectedIds.size > 1) {
    multiDrag = { start: pivot.position.clone(), pos: new Map([...selectedIds].map((id) => [id, [...partById(id).pos]])) };
  }
  if (!e.value && multiDrag) {
    multiDrag = null;
    commit();
    return;
  }
  if (!e.value) {
    const part = partById(selectedId);
    if (part && tc.mode !== 'translate') dropPart(part);
    renderProps();
    commit();
  }
});
tc.addEventListener('objectChange', () => {
  if (multiDrag) {
    const d = pivot.position.clone().sub(multiDrag.start);
    for (const [id, p0] of multiDrag.pos) {
      const part = partById(id);
      if (!part) continue;
      part.pos = [r2(p0[0] + d.x), r2(p0[1] + d.y), r2(p0[2] + d.z)];
      const m = meshes.get(id);
      m.position.fromArray(part.pos);
      m.updateMatrixWorld(true);
    }
    return;
  }
  const part = partById(selectedId);
  const mesh = meshes.get(selectedId);
  if (!part || !mesh) return;
  part.pos = mesh.position.toArray().map(r2);
  part.rot = [mesh.rotation.x, mesh.rotation.y, mesh.rotation.z].map((r) => Math.round(THREE.MathUtils.radToDeg(r)));
  part.scale = mesh.scale.toArray().map((v) => Math.round(v * 1000) / 1000);
  updateTransformInputs(part);
});

function setGizmoMode(mode) {
  gizmoMode = mode;
  if (selectedIds.size <= 1) tc.setMode(mode);
  $('modeMove').classList.toggle('active', mode === 'translate');
  $('modeRotate').classList.toggle('active', mode === 'rotate');
  $('modeScale').classList.toggle('active', mode === 'scale');
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
  const additive = e.shiftKey || e.ctrlKey || e.metaKey;
  if (!hits.length && additive) return;
  select(hits.length ? hits[0].object.userData.id : null, additive);
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
  if (currentTab === 'custom') {
    const b = document.createElement('button');
    b.className = 'cat-item';
    b.title = 'פתיחת קובץ STL קיים כדי לשנות אותו, לחתוך בו חורים או לשלב אותו עם חלקים אחרים';
    b.innerHTML = '<span class="ic" aria-hidden="true">📥</span><span class="nm">ייבוא STL</span>';
    b.addEventListener('click', () => $('fileStl').click());
    el.appendChild(b);
    const tip = document.createElement('p');
    tip.className = 'hint';
    tip.style.gridColumn = '1 / -1';
    tip.textContent = 'איך ממדלים חופשי: משרטטים צורה, מושכים אותה לגוף, ומשלבים כמה גופים — מוצקים מתחברים, ו"חורים" נחתכים מהם.';
    el.appendChild(tip);
  }
}

// ---------- ייבוא STL ----------
async function importSTL(file) {
  try {
    const buf = await file.arrayBuffer();
    let geo = new STLLoader().parse(buf);
    if (geo.index) geo = geo.toNonIndexed();
    const pos = geo.attributes.position;
    const tris = pos.count / 3;
    if (!tris) throw new Error('הקובץ ריק');
    if (tris > 300000) throw new Error(`יש בקובץ ${Math.round(tris / 1000)} אלף משולשים — זה כבד מדי לעריכה בדפדפן`);
    geo.computeBoundingBox();
    const bb = geo.boundingBox;
    const c = bb.getCenter(new THREE.Vector3());
    geo.translate(-c.x, -c.y, -bb.min.z);
    const positions = new Float32Array(geo.attributes.position.array);
    const name = file.name.replace(/\.stl$/i, '');
    addPart('mesh', { name, mesh: encodeMesh(positions) });
    if (tris > 60000) toast(`יובא מודל כבד (${Math.round(tris / 1000)} אלף משולשים) — חיתוכים יהיו איטיים`);
  } catch (err) {
    console.error(err);
    toast('הייבוא נכשל: ' + err.message);
  }
}
$('fileStl').addEventListener('change', (e) => {
  const f = e.target.files[0];
  e.target.value = '';
  if (f) importSTL(f);
});

function editSketch(part) {
  const def = CATALOG[part.type];
  openSketchEditor({
    sketch: part.sketch,
    revolve: !!def.revolve,
    title: `${def.name}: ${part.name || def.name}`,
    onDone(sk) {
      part.sketch = sk;
      syncMesh(part);
      dropPart(part);
      renderProps();
      commit();
    },
  });
}

// ---------- מערך (שכפול בתבנית) ----------
function makeArray(part, opts) {
  const copies = [];
  for (let k = 1; k < opts.count; k++) {
    const c = JSON.parse(JSON.stringify(part));
    c.id = uid();
    if (opts.kind === 'linear') {
      c.pos = [r2(part.pos[0] + opts.dx * k), r2(part.pos[1] + opts.dy * k), part.pos[2]];
    } else {
      const a = THREE.MathUtils.degToRad((opts.angle / (opts.angle >= 360 ? opts.count : opts.count - 1)) * k);
      const x = part.pos[0] - opts.cx, y = part.pos[1] - opts.cy;
      c.pos = [r2(opts.cx + x * Math.cos(a) - y * Math.sin(a)), r2(opts.cy + x * Math.sin(a) + y * Math.cos(a)), part.pos[2]];
      c.rot = [part.rot[0], part.rot[1], Math.round(part.rot[2] + THREE.MathUtils.radToDeg(a))];
    }
    copies.push(c);
  }
  state.parts.push(...copies);
  syncAll();
  commit();
  toast(`נוצרו ${copies.length} עותקים`);
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
    b.className = isSel(p.id) ? 'sel' : '';
    b.title = 'Shift או Ctrl + לחיצה — בחירת כמה חלקים';
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
    b.addEventListener('click', (e) => {
      if (resultMode) setResultMode(false);
      select(p.id, e.shiftKey || e.ctrlKey || e.metaKey);
    });
    li.appendChild(b);
    ul.appendChild(li);
  }
}

// ---------- ממשק: פאנל מאפיינים ----------
function renderProps() {
  const el = $('props');
  if (selectedIds.size > 1) {
    renderMultiProps(el);
    return;
  }
  const part = partById(selectedId);
  if (!part) {
    el.innerHTML = `
      <div class="empty-props">
        <span class="big" aria-hidden="true">🧊</span>
        בחר חלק כדי לערוך את המידות שלו,<br>או הוסף מוצר מהרשימה.
      </div>
      <div class="group kbd-help">
        <h3>קיצורים</h3>
        <kbd>W</kbd> הזזה · <kbd>E</kbd> סיבוב · <kbd>R</kbd> גודל<br>
        <kbd>←↑→↓</kbd> הזזה במ"מ (<kbd>Shift</kbd> = 10)<br>
        <kbd>PgUp</kbd>/<kbd>PgDn</kbd> למעלה/למטה<br>
        <kbd>Ctrl+D</kbd> שכפול · <kbd>Delete</kbd> מחיקה<br>
        <kbd>Shift</kbd>+לחיצה: בחירת כמה חלקים · <kbd>Ctrl+A</kbd> הכול<br>
        <kbd>Ctrl+G</kbd> קיבוץ · <kbd>Ctrl+Shift+G</kbd> פירוק<br>
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

  if (part.type === 'group') {
    const gr = group(`קבוצה של ${part.children.length} חלקים`);
    const opSel = document.createElement('select');
    opSel.id = 'grpOp';
    opSel.setAttribute('aria-label', 'פעולה');
    opSel.innerHTML = '<option value="union">חיבור — כל המוצקים יחד, החורים נחתכים</option><option value="intersect">חיתוך משותף — רק הנפח שמשותף לכולם</option>';
    opSel.value = part.op || 'union';
    opSel.addEventListener('change', () => {
      part.op = opSel.value;
      syncMesh(part);
      dropPart(part);
      renderProps();
      commit();
    });
    gr.appendChild(opSel);
    const ub = document.createElement('button');
    ub.style.cssText = 'width:100%;margin-top:8px';
    ub.textContent = '🧩 פרק קבוצה (Ctrl+Shift+G)';
    ub.addEventListener('click', () => ungroup(part));
    gr.appendChild(ub);
    el.appendChild(gr);
  }

  if (def.defaultText !== undefined) {
    const tg = group('טקסט');
    const ti = document.createElement('input');
    ti.type = 'text';
    ti.id = 'partText';
    ti.dir = 'auto';
    ti.value = part.text ?? '';
    ti.setAttribute('aria-label', 'הטקסט');
    ti.style.fontSize = '16px';
    let tt = null;
    ti.addEventListener('input', () => {
      clearTimeout(tt);
      tt = setTimeout(() => {
        part.text = ti.value;
        syncMesh(part);
        commit();
      }, 250);
    });
    tg.appendChild(ti);
    el.appendChild(tg);
  }

  if (def.defaultSketch) {
    const sk = group('שרטוט');
    const b = document.createElement('button');
    b.className = 'primary';
    b.style.width = '100%';
    b.textContent = '✏ ערוך שרטוט';
    b.addEventListener('click', () => editSketch(part));
    sk.appendChild(b);
    el.appendChild(sk);
  }

  // מידות
  const dims = group('מידות');
  if (!def.params.length) dims.hidden = true;
  for (const q of def.params) {
    if ((q.key === 'edgeR' || q.key === 'edgeBoth') && !p.edgeType) continue;
    if (q.options) {
      const row = document.createElement('div');
      row.className = 'param';
      const sid = `p_${q.key}`;
      row.innerHTML = `<label for="${sid}">${q.label}</label>`;
      const sel = document.createElement('select');
      sel.id = sid;
      sel.style.gridColumn = '1 / -1';
      q.options.forEach(([v, t]) => sel.add(new Option(t, v)));
      sel.value = p[q.key];
      sel.addEventListener('change', () => {
        part.params[q.key] = Number(sel.value);
        syncMesh(part);
        renderProps();
        commit();
      });
      row.appendChild(sel);
      dims.appendChild(row);
      continue;
    }
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

  // קנה מידה ושיקוף
  part.scale = part.scale || [1, 1, 1];
  const sc = group('קנה מידה ושיקוף');
  sc.appendChild(xyzInputs('scale', part.scale.map((v) => Math.round(v * 1000) / 10), (i, v) => {
    if (!v) return;
    part.scale[i] = v / 100;
    syncMesh(part);
    dropPart(part);
    renderProps();
    commit();
  }));
  const uni = document.createElement('div');
  uni.className = 'row';
  [50, 200].forEach((pct) => {
    const b = document.createElement('button');
    b.textContent = pct < 100 ? '− הקטן פי 2' : '+ הגדל פי 2';
    b.addEventListener('click', () => {
      part.scale = part.scale.map((v) => r2((v * pct) / 100 * 1000) / 1000);
      syncMesh(part);
      dropPart(part);
      renderProps();
      commit();
    });
    uni.appendChild(b);
  });
  sc.appendChild(uni);
  const mir = document.createElement('div');
  mir.className = 'row';
  mir.style.marginTop = '6px';
  ['X', 'Y', 'Z'].forEach((ax, i) => {
    const b = document.createElement('button');
    b.textContent = `⇋ שיקוף ${ax}`;
    b.title = `היפוך החלק כמו במראה לאורך ציר ${ax}`;
    b.addEventListener('click', () => {
      part.scale[i] *= -1;
      syncMesh(part);
      dropPart(part);
      renderProps();
      commit();
    });
    mir.appendChild(b);
  });
  sc.appendChild(mir);
  const sizeBox = new THREE.Box3().expandByObject(meshes.get(part.id), true).getSize(new THREE.Vector3());
  const sizeP = document.createElement('p');
  sizeP.className = 'hint';
  sizeP.textContent = `גודל בפועל: ${fmt(sizeBox.x)} × ${fmt(sizeBox.y)} × ${fmt(sizeBox.z)} מ"מ`;
  sc.appendChild(sizeP);
  el.appendChild(sc);

  // מערך
  const ar = group('שכפול במערך');
  ar.innerHTML += `
    <div class="xyz" style="margin-bottom:6px">
      <label>סוג<select id="arKind"><option value="linear">בשורה</option><option value="circular">במעגל</option></select></label>
      <label>כמות<input type="number" id="arCount" value="4" min="2" max="100" step="1"></label>
      <label class="ar-lin">מרווח X<input type="number" id="arDx" value="20" step="1"></label>
      <label class="ar-lin">מרווח Y<input type="number" id="arDy" value="0" step="1"></label>
      <label class="ar-cir" hidden>זווית כוללת<input type="number" id="arAngle" value="360" step="15"></label>
      <label class="ar-cir" hidden>מרכז X<input type="number" id="arCx" value="0" step="1"></label>
      <label class="ar-cir" hidden>מרכז Y<input type="number" id="arCy" value="0" step="1"></label>
    </div>
    <p class="hint ar-cir" hidden>העותקים מסודרים סביב נקודת המרכז, מסובבים בהתאם — מעולה לחורי ברגים במעגל או לשיניים.</p>
    <button id="arGo" style="width:100%">צור מערך</button>`;
  const ark = ar.querySelector('#arKind');
  ark.addEventListener('change', () => {
    ar.querySelectorAll('.ar-lin').forEach((x) => (x.hidden = ark.value !== 'linear'));
    ar.querySelectorAll('.ar-cir').forEach((x) => (x.hidden = ark.value !== 'circular'));
  });
  ar.querySelector('#arGo').addEventListener('click', () => {
    const v = (id) => parseFloat(ar.querySelector('#' + id).value) || 0;
    const count = Math.max(2, Math.min(100, Math.round(v('arCount'))));
    makeArray(part, { kind: ark.value, count, dx: v('arDx'), dy: v('arDy'), angle: v('arAngle') || 360, cx: v('arCx'), cy: v('arCy') });
  });
  el.appendChild(ar);

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

function renderMultiProps(el) {
  const parts = state.parts.filter((p) => selectedIds.has(p.id));
  const first = parts.find((p) => p.id === [...selectedIds][0]);
  el.innerHTML = '';
  const head = document.createElement('div');
  head.innerHTML = `<div class="props-head"><span class="ic" aria-hidden="true">🧩</span><h2>${parts.length} חלקים נבחרו</h2></div>
    <p class="props-desc">גרירה בתצוגה מזיזה את כולם יחד. Shift או Ctrl + לחיצה מוסיף או מסיר חלק.</p>`;
  el.appendChild(head);

  const comb = group('שילוב לגוף אחד');
  const mk = (txt, title, fn) => {
    const b = document.createElement('button');
    b.textContent = txt;
    b.title = title;
    b.style.width = '100%';
    b.addEventListener('click', fn);
    return b;
  };
  const col = document.createElement('div');
  col.style.cssText = 'display:flex;flex-direction:column;gap:6px';
  col.append(
    mk('🧩 קבץ — חיבור (Ctrl+G)', 'מחבר את כל המוצקים לגוף אחד; חלקים שמסומנים כחור נחתכים', () => groupSelected('union')),
    mk(`➖ חסר מ"${first?.name || 'הראשון'}"`, 'החלק הראשון שבחרת נשאר, וכל השאר נחתכים ממנו', () => groupSelected('union', true)),
    mk('∩ חיתוך משותף', 'נשאר רק הנפח שמשותף לכל החלקים', () => groupSelected('intersect')),
  );
  comb.appendChild(col);
  const hint = document.createElement('p');
  hint.className = 'hint';
  hint.textContent = 'קבוצה נשארת ניתנת לעריכה: אפשר לפרק אותה בחזרה לחלקים בכל רגע.';
  comb.appendChild(hint);
  el.appendChild(comb);

  const al = group('יישור');
  const rows = [
    ['X', 0, ['ימין', 'max'], ['מרכז', 'center'], ['שמאל', 'min']],
    ['Y', 1, ['אחורה', 'max'], ['מרכז', 'center'], ['קדימה', 'min']],
    ['Z', 2, ['למעלה', 'max'], ['מרכז', 'center'], ['תחתית', 'min']],
  ];
  for (const [ax, i, ...opts] of rows) {
    const r = document.createElement('div');
    r.className = 'row';
    r.style.marginBottom = '6px';
    const lb = document.createElement('span');
    lb.textContent = ax;
    lb.style.cssText = 'width:18px;color:var(--muted);font-weight:600';
    r.appendChild(lb);
    for (const [t, w] of opts) {
      const b = document.createElement('button');
      b.textContent = t;
      b.addEventListener('click', () => alignSelected(i, w));
      r.appendChild(b);
    }
    al.appendChild(r);
  }
  el.appendChild(al);

  const act = group('');
  const r1 = document.createElement('div');
  r1.className = 'row';
  const allHoles = parts.every((p) => p.hole);
  r1.append(
    mk(allHoles ? 'סמן כמוצקים' : 'סמן כחורים', '', () => {
      parts.forEach((p) => { p.hole = !allHoles; syncMesh(p); });
      renderPartsList();
      renderProps();
      commit();
    }),
  );
  const r2b = document.createElement('div');
  r2b.className = 'row';
  r2b.style.marginTop = '6px';
  const del = mk('🗑 מחק הכול', '', deleteSelected);
  del.classList.add('danger');
  r2b.append(mk('⧉ שכפל הכול', '', duplicateSelected), del);
  act.append(r1, r2b);
  el.appendChild(act);
}

function groupSelected(op = 'union', subtractRest = false) {
  const order = [...selectedIds];
  const parts = order.map(partById).filter(Boolean);
  if (parts.length < 2) return;
  const box = selectionBox();
  const c = box.getCenter(new THREE.Vector3());
  const o = [r2(c.x), r2(c.y), r2(box.min.z)];
  const children = parts.map((p, i) => {
    const ch = JSON.parse(JSON.stringify(p));
    ch.pos = [r2(p.pos[0] - o[0]), r2(p.pos[1] - o[1]), r2(p.pos[2] - o[2])];
    if (subtractRest) ch.hole = i > 0;
    return ch;
  });
  const base = subtractRest ? parts[0] : parts.find((p) => !p.hole) || parts[0];
  const g = {
    id: uid(), type: 'group', params: {}, pos: o, rot: [0, 0, 0], scale: [1, 1, 1],
    name: op === 'intersect' ? 'חיתוך משותף' : subtractRest ? `${base.name} (חיסור)` : 'קבוצה',
    color: base.color, hole: false, op, children,
  };
  const idx = Math.min(...parts.map((p) => state.parts.indexOf(p)));
  state.parts = state.parts.filter((p) => !selectedIds.has(p.id));
  state.parts.splice(idx, 0, g);
  selectedIds = new Set([g.id]);
  selectedId = g.id;
  syncAll();
  renderProps();
  commit();
  toast(`נוצרה ${g.name} מ-${parts.length} חלקים`);
}

function ungroup(part) {
  if (part.type !== 'group') return;
  const gm = partMatrix(part);
  const kids = part.children.map((c) => {
    const m = gm.clone().multiply(partMatrix(c));
    const pos = new THREE.Vector3(), q = new THREE.Quaternion(), sc = new THREE.Vector3();
    m.decompose(pos, q, sc);
    const e = new THREE.Euler().setFromQuaternion(q, 'XYZ');
    return {
      ...c, id: uid(),
      pos: pos.toArray().map(r2),
      rot: [e.x, e.y, e.z].map((r) => Math.round(THREE.MathUtils.radToDeg(r) * 10) / 10),
      scale: sc.toArray().map((v) => Math.round(v * 1000) / 1000),
      hole: part.hole || c.hole,
    };
  });
  const idx = state.parts.indexOf(part);
  state.parts.splice(idx, 1, ...kids);
  selectedIds = new Set(kids.map((k) => k.id));
  selectedId = kids[kids.length - 1].id;
  syncAll();
  renderProps();
  commit();
  toast(`הקבוצה פורקה ל-${kids.length} חלקים`);
}

function alignSelected(axis, where) {
  const parts = state.parts.filter((p) => selectedIds.has(p.id));
  const all = selectionBox();
  const key = ['x', 'y', 'z'][axis];
  const target = where === 'min' ? all.min[key] : where === 'max' ? all.max[key] : (all.min[key] + all.max[key]) / 2;
  for (const p of parts) {
    const b = new THREE.Box3().expandByObject(meshes.get(p.id), true);
    const cur = where === 'min' ? b.min[key] : where === 'max' ? b.max[key] : (b.min[key] + b.max[key]) / 2;
    p.pos[axis] = r2(p.pos[axis] + target - cur);
    syncMesh(p);
  }
  attachGizmo();
  commit();
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
    lbl.textContent = kind === 'pos' ? ax : kind === 'scale' ? `${ax} (%)` : `סיבוב ${ax}`;
    const inp = document.createElement('input');
    inp.type = 'number';
    inp.step = kind === 'pos' ? 1 : kind === 'scale' ? 5 : 15;
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
    if (inp.dataset.kind === 'scale') inp.value = Math.round((part.scale || [1, 1, 1])[+inp.dataset.axis] * 1000) / 10;
    else inp.value = (inp.dataset.kind === 'pos' ? part.pos : part.rot)[+inp.dataset.axis];
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
      scale: (p.scale || [1, 1, 1]).map(Number),
      ...(p.sketch ? { sketch: p.sketch } : {}),
      ...(p.mesh ? { mesh: String(p.mesh) } : {}),
      ...(p.text !== undefined ? { text: String(p.text) } : {}),
      ...(p.type === 'group' ? { op: p.op === 'intersect' ? 'intersect' : 'union', children: Array.isArray(p.children) ? p.children.filter((c) => CATALOG[c.type]) : [] } : {}),
    }));
  state = { name: String(data.name || 'פרויקט'), parts, settings: { ...DEFAULT_SETTINGS, ...(data.settings || {}) } };
  selectedId = null;
  selectedIds = new Set();
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
$('modeScale').addEventListener('click', () => setGizmoMode('scale'));
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
    duplicateSelected();
  } else if (mod && e.key.toLowerCase() === 'a') {
    e.preventDefault();
    selectedIds = new Set(state.parts.map((p) => p.id));
    selectedId = state.parts.length ? state.parts[state.parts.length - 1].id : null;
    syncAll();
    renderProps();
  } else if (mod && e.key.toLowerCase() === 'g') {
    e.preventDefault();
    if (e.shiftKey) { if (part?.type === 'group') ungroup(part); }
    else groupSelected('union');
  } else if ((e.key === 'Delete' || e.key === 'Backspace') && part) {
    e.preventDefault();
    deleteSelected();
  } else if (e.key === 'Escape') {
    select(null);
  } else if (e.key.toLowerCase() === 'w') {
    setGizmoMode('translate');
  } else if (e.key.toLowerCase() === 'e') {
    setGizmoMode('rotate');
  } else if (e.key.toLowerCase() === 'r') {
    setGizmoMode('scale');
  } else if (part && ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'PageUp', 'PageDown'].includes(e.key)) {
    e.preventDefault();
    const st = e.shiftKey ? 10 : 1;
    const d = { ArrowLeft: [0, -st], ArrowRight: [0, st], ArrowUp: [1, st], ArrowDown: [1, -st], PageUp: [2, st], PageDown: [2, -st] }[e.key];
    for (const id of selectedIds) {
      const p = partById(id);
      p.pos[d[0]] = r2(p.pos[d[0]] + d[1]);
      syncMesh(p);
    }
    attachGizmo();
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
  loadFonts()
    .then(() => {
      geoCache.clear();
      syncAll();
      renderProps();
      scheduleStats();
    })
    .catch(() => toast('גופן הטקסט לא נטען — טקסט לא יוצג'));
}
init();

// לבדיקות ולשימוש מהקונסול
window.print3d = { get state() { return state; }, addPart, exportSTL, computeResult, select, setResultMode };
