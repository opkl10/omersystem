// פעולות בוליאניות (חיבור/חיסור) בעזרת three-bvh-csg
import * as THREE from 'three';
import { Brush, Evaluator, ADDITION, SUBTRACTION } from 'three-bvh-csg';

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
  g.computeVertexNormals();
  return g;
}

function brush(geo) {
  const b = new Brush(prep(geo));
  b.updateMatrixWorld();
  return b;
}

export function unionAll(geos) {
  if (!geos.length) return null;
  if (geos.length === 1) return prep(geos[0]);
  let acc = brush(geos[0]);
  for (let i = 1; i < geos.length; i++) acc = evaluator.evaluate(acc, brush(geos[i]), ADDITION);
  return acc.geometry;
}

export function subtractAll(base, cuts) {
  let acc = brush(base);
  for (const c of cuts) acc = evaluator.evaluate(acc, brush(c), SUBTRACTION);
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
