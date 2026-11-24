// פעולות בוליאניות (חיבור/חיסור) בעזרת three-bvh-csg
import * as THREE from 'three';
import { Brush, Evaluator, ADDITION, SUBTRACTION, INTERSECTION } from 'three-bvh-csg';

const evaluator = new Evaluator();
evaluator.attributes = ['position', 'normal'];
evaluator.useGroups = false;

// מכין גאומטריה ל-CSG: רק position+normal, בלי קבוצות
export function prep(geo) {
  const g = geo.index ? geo.toNonIndexed() : geo.clone();
  for (const name of Object.keys(g.attributes)) {
    if (name !== 'position') g.deleteAttribute(name);
  }
  g.clearGroups();
  // מסירים משולשים מנוונים (שטח אפס) שמפילים את החישוב הבוליאני
  const a = g.attributes.position.array;
  const keep = [];
  for (let i = 0; i < a.length; i += 9) {
    const ux = a[i + 3] - a[i], uy = a[i + 4] - a[i + 1], uz = a[i + 5] - a[i + 2];
    const vx = a[i + 6] - a[i], vy = a[i + 7] - a[i + 1], vz = a[i + 8] - a[i + 2];
    const cx = uy * vz - uz * vy, cy = uz * vx - ux * vz, cz = ux * vy - uy * vx;
    if (cx * cx + cy * cy + cz * cz > 1e-12) for (let k = 0; k < 9; k++) keep.push(a[i + k]);
  }
  if (keep.length !== a.length) g.setAttribute('position', new THREE.Float32BufferAttribute(keep, 3));
  g.computeVertexNormals();
  return g;
}

// הזזה זעירה (אלפיות מ"מ) לכל גוף נוסף: מונעת פאות שמתלכדות בדיוק,
// שגורמות לחישוב הבוליאני להיתקע. לא משפיעה על ההדפסה.
function brush(geo, i = 0) {
  const g = prep(geo);
  if (i) {
    const k = (i % 7) + 1;
    g.translate(k * 0.00031, k * 0.00047, -k * 0.00029);
  }
  const b = new Brush(g);
  b.updateMatrixWorld();
  return b;
}

export function unionAll(geos) {
  if (!geos.length) return null;
  if (geos.length === 1) return prep(geos[0]);
  let acc = brush(geos[0]);
  for (let i = 1; i < geos.length; i++) acc = evaluator.evaluate(acc, brush(geos[i], i), ADDITION);
  return acc.geometry;
}

export function subtractAll(base, cuts) {
  let acc = brush(base);
  cuts.forEach((c, i) => { acc = evaluator.evaluate(acc, brush(c, i + 1), SUBTRACTION); });
  return acc.geometry;
}

export function intersectAll(geos) {
  if (!geos.length) return null;
  let acc = brush(geos[0]);
  for (let i = 1; i < geos.length; i++) acc = evaluator.evaluate(acc, brush(geos[i], i), INTERSECTION);
  return acc.geometry;
}

// משלב את כל החלקים: איחוד של המוצקים ואז חיסור של ה"חורים"
export function combineParts(solids, holes) {
  if (!solids.length) return null;
  const merged = unionAll(solids);
  if (!holes.length) return merged;
  return subtractAll(merged, holes);
}

export function volumeOf(geo) {
  const pos = geo.attributes.position;
  const idx = geo.index;
  const a = new THREE.Vector3(), b = new THREE.Vector3(), c = new THREE.Vector3();
  let v = 0;
  const n = idx ? idx.count : pos.count;
  for (let i = 0; i < n; i += 3) {
    const i0 = idx ? idx.getX(i) : i, i1 = idx ? idx.getX(i + 1) : i + 1, i2 = idx ? idx.getX(i + 2) : i + 2;
    a.fromBufferAttribute(pos, i0); b.fromBufferAttribute(pos, i1); c.fromBufferAttribute(pos, i2);
    v += a.dot(b.clone().cross(c)) / 6;
  }
  return v;
}
