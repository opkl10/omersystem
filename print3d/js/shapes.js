// קטלוג הצורות והמוצרים — כל פריט מגדיר פרמטרים (במ"מ) ופונקציית build
// שמחזירה BufferGeometry בציר Z כלפי מעלה, ממורכזת ב-X/Y ויושבת על Z=0.
import * as THREE from 'three';
import { mergeVertices } from '../vendor/BufferGeometryUtils.js';
import { subtractAll, unionAll } from './csg.js';

const SEG = 96;
const deg = (d) => (d * Math.PI) / 180;
const clamp = (v, a, b) => Math.min(b, Math.max(a, v));

// ---------- עזרי גאומטריה ----------
function roundedRectShape(w, d, r, cx = 0, cy = 0) {
  const s = new THREE.Shape();
  r = clamp(r, 0, Math.min(w, d) / 2 - 0.01);
  const x0 = cx - w / 2, y0 = cy - d / 2, x1 = cx + w / 2, y1 = cy + d / 2;
  if (r <= 0.05) {
    s.moveTo(x0, y0); s.lineTo(x1, y0); s.lineTo(x1, y1); s.lineTo(x0, y1); s.closePath();
    return s;
  }
  s.moveTo(x0 + r, y0);
  s.lineTo(x1 - r, y0);
  s.absarc(x1 - r, y0 + r, r, -Math.PI / 2, 0, false);
  s.lineTo(x1, y1 - r);
  s.absarc(x1 - r, y1 - r, r, 0, Math.PI / 2, false);
  s.lineTo(x0 + r, y1);
  s.absarc(x0 + r, y1 - r, r, Math.PI / 2, Math.PI, false);
  s.lineTo(x0, y0 + r);
  s.absarc(x0 + r, y0 + r, r, Math.PI, Math.PI * 1.5, false);
  return s;
}

function circlePath(r, cx = 0, cy = 0, PathCls = THREE.Shape) {
  const p = new PathCls();
  p.absarc(cx, cy, r, 0, Math.PI * 2, false);
  return p;
}

function polygonShape(pts) {
  const s = new THREE.Shape();
  pts.forEach(([x, y], i) => (i ? s.lineTo(x, y) : s.moveTo(x, y)));
  s.closePath();
  return s;
}

// אקסטרוזיה לאורך Z, מ-z0 עד z0+h
function extrudeZ(shape, h, z0 = 0) {
  const g = new THREE.ExtrudeGeometry(shape, { depth: h, bevelEnabled: false, curveSegments: 24 });
  g.translate(0, 0, z0);
  return g;
}

// פרופיל דו-ממדי (x=עומק, y=גובה) שנמתח לרוחב לאורך ציר Y, ממורכז
function extrudeProfile(shape, width) {
  const g = new THREE.ExtrudeGeometry(shape, { depth: width, bevelEnabled: false, curveSegments: 24 });
  g.rotateX(Math.PI / 2); // y→z, z→-y
  g.translate(0, width / 2, 0);
  return g;
}

function cylinderZ(r, h, z0 = 0, rTop = r) {
  const g = new THREE.CylinderGeometry(rTop, r, h, SEG);
  g.rotateX(Math.PI / 2);
  g.translate(0, 0, z0 + h / 2);
  return g;
}

function boxAt(w, d, h, x = 0, y = 0, z0 = 0) {
  const g = new THREE.BoxGeometry(w, d, h);
  g.translate(x, y, z0 + h / 2);
  return g;
}

// גוף סיבוב מפרופיל סגור (r, z)
function latheZ(profile) {
  const pts = profile.map(([r, z]) => new THREE.Vector2(r, z));
  pts.push(pts[0].clone());
  let g = new THREE.LatheGeometry(pts, SEG);
  g.rotateX(Math.PI / 2);
  g.deleteAttribute('uv');
  g.deleteAttribute('normal');
  g = mergeVertices(g, 1e-4);
  g.computeVertexNormals();
  return g;
}

function lineIntersect(p, u, q, v) {
  // p + s*u = q + t*v
  const den = u[0] * v[1] - u[1] * v[0];
  if (Math.abs(den) < 1e-9) return null;
  const s = ((q[0] - p[0]) * v[1] - (q[1] - p[1]) * v[0]) / den;
  return [p[0] + s * u[0], p[1] + s * u[1]];
}

const area2 = (pts) =>
  pts.reduce((a, [x, y], i) => {
    const [x2, y2] = pts[(i + 1) % pts.length];
    return a + x * y2 - x2 * y;
  }, 0);

// ---------- הקטלוג ----------
const P = (key, label, def, min, max, step = 1, unit = 'מ"מ') => ({ key, label, def, min, max, step, unit });

export const CATALOG = {
  // ===== מוצרים מוכנים =====
  organizer: {
    name: 'קופסה / מארגן', icon: '📦', cat: 'product',
    desc: 'קופסה פתוחה עם תאים — למגירה, לשולחן או לברגים',
    params: [
      P('w', 'רוחב', 80, 10, 300), P('d', 'עומק', 60, 10, 300), P('h', 'גובה', 35, 5, 250),
      P('wall', 'עובי דופן', 1.6, 0.8, 6, 0.1), P('floor', 'עובי רצפה', 1.6, 0.6, 6, 0.1),
      P('r', 'עיגול פינות', 4, 0, 30, 0.5),
      P('cols', 'תאים לרוחב', 1, 1, 8, 1, ''), P('rows', 'תאים לעומק', 1, 1, 8, 1, ''),
    ],
    build(p) {
      const outer = extrudeZ(roundedRectShape(p.w, p.d, p.r), p.h);
      const cw = (p.w - p.wall * (p.cols + 1)) / p.cols;
      const cd = (p.d - p.wall * (p.rows + 1)) / p.rows;
      if (cw < 1 || cd < 1) return outer;
      const ir = Math.max(0, p.r - p.wall);
      const cells = [];
      for (let i = 0; i < p.cols; i++)
        for (let j = 0; j < p.rows; j++) {
          const cx = -p.w / 2 + p.wall + cw / 2 + i * (cw + p.wall);
          const cy = -p.d / 2 + p.wall + cd / 2 + j * (cd + p.wall);
          const r = p.cols === 1 && p.rows === 1 ? ir : Math.min(ir, 2);
          cells.push(extrudeZ(roundedRectShape(cw, cd, r, cx, cy), p.h, p.floor));
        }
      return subtractAll(outer, cells);
    },
    checks(p, s) {
      const w = [];
      if (p.wall < s.nozzle * 2) w.push(`עובי הדופן (${p.wall}) קטן מפעמיים קוטר הנחיר (${s.nozzle * 2}) — הדופן עלולה להיות שבירה`);
      if ((p.w - p.wall * (p.cols + 1)) / p.cols < 5 || (p.d - p.wall * (p.rows + 1)) / p.rows < 5) w.push('התאים צרים מאוד — הגדל את הקופסה או הפחת תאים');
      return w;
    },
  },

  lid: {
    name: 'מכסה לקופסה', icon: '🧢', cat: 'product',
    desc: 'מכסה עם שפה פנימית שנכנסת לקופסה. מודפס הפוך (השפה למעלה)',
    params: [
      P('w', 'רוחב הקופסה', 80, 10, 300), P('d', 'עומק הקופסה', 60, 10, 300),
      P('r', 'עיגול פינות', 4, 0, 30, 0.5), P('t', 'עובי מכסה', 2, 0.8, 8, 0.1),
      P('wall', 'עובי דופן הקופסה', 1.6, 0.8, 6, 0.1), P('lip', 'גובה שפה', 4, 0, 20, 0.5),
      P('lipT', 'עובי שפה', 1.2, 0.8, 4, 0.1),
    ],
    build(p, s) {
      const plate = extrudeZ(roundedRectShape(p.w, p.d, p.r), p.t);
      if (p.lip <= 0) return plate;
      const iw = p.w - 2 * p.wall - 2 * s.tolerance;
      const id = p.d - 2 * p.wall - 2 * s.tolerance;
      const ir = Math.max(0, p.r - p.wall - s.tolerance);
      if (iw <= 2 * p.lipT + 1 || id <= 2 * p.lipT + 1) return plate;
      const ring = roundedRectShape(iw, id, ir);
      ring.holes.push(roundedRectShape(iw - 2 * p.lipT, id - 2 * p.lipT, Math.max(0, ir - p.lipT)));
      const lipG = extrudeZ(ring, p.lip + 0.01, p.t - 0.01);
      return unionAll([plate, lipG]);
    },
    checks(p, s) {
      return p.lipT < s.nozzle * 2 ? ['השפה דקה מפעמיים קוטר הנחיר — עלולה להישבר'] : [];
    },
  },

  pot: {
    name: 'כוס / עציץ', icon: '🪴', cat: 'product',
    desc: 'כלי עגול עם דפנות משופעות ואפשרות לחור ניקוז',
    params: [
      P('db', 'קוטר תחתון', 60, 10, 250), P('dt', 'קוטר עליון', 80, 10, 250), P('h', 'גובה', 70, 5, 250),
      P('wall', 'עובי דופן', 1.6, 0.8, 6, 0.1), P('floor', 'עובי תחתית', 2, 0.6, 10, 0.1),
      P('drain', 'חור ניקוז (0=בלי)', 0, 0, 30, 0.5),
    ],
    build(p) {
      const rb = p.db / 2, rt = p.dt / 2;
      const rAt = (z) => rb + ((rt - rb) * z) / p.h;
      const hr = Math.min(p.drain / 2, rAt(p.floor) - p.wall - 1);
      const r0 = p.drain > 0 && hr > 0.5 ? hr : 0;
      const prof = [
        [r0, 0], [rb, 0], [rt, p.h], [rt - p.wall, p.h], [rAt(p.floor) - p.wall, p.floor], [r0, p.floor],
      ];
      return latheZ(prof);
    },
    checks(p, s) {
      const w = [];
      if (p.wall < s.nozzle * 2) w.push('הדופן דקה מפעמיים קוטר הנחיר');
      const ang = Math.atan2(Math.abs(p.dt - p.db) / 2, p.h) * 180 / Math.PI;
      if (p.dt > p.db && ang > 50) w.push(`שיפוע הדופן ${ang.toFixed(0)}° — מעל 45° עלול להצריך תמיכות`);
      return w;
    },
  },

  phoneStand: {
    name: 'מעמד לטלפון', icon: '📱', cat: 'product',
    desc: 'מעמד שולחני בזווית מתכווננת עם חריץ לטלפון. אפשר להדפיס כך או על הצד (⟳ 90° X) בלי תמיכות',
    params: [
      P('width', 'רוחב', 70, 20, 200), P('angle', 'זווית', 65, 40, 85, 1, '°'),
      P('L', 'אורך משענת', 80, 30, 180), P('t', 'עובי', 5, 2, 12, 0.5),
      P('gap', 'עובי טלפון (חריץ)', 12, 6, 25, 0.5), P('lipH', 'גובה שפה קדמית', 10, 3, 30, 0.5),
      P('D', 'עומק בסיס מינימלי', 70, 30, 200),
    ],
    build(p) {
      const t = p.t, a = deg(p.angle);
      const u = [Math.cos(a), Math.sin(a)];
      const n = [Math.sin(a), -Math.cos(a)];
      const S0 = [t + p.gap, t];
      const S1 = [S0[0] + p.L * u[0], S0[1] + p.L * u[1]];
      const T = [S1[0] + t * n[0], S1[1] + t * n[1]];
      const D = Math.max(p.D, T[0] + t);
      const outline = [[0, 0], [D, 0], [D, t], T, S1, S0, [t, t], [t, t + p.lipH], [0, t + p.lipH]];
      const shape = polygonShape(outline);
      // חלל פנימי לחיסכון בחומר
      const l1 = [[0, 2 * t], [1, 0]];
      const l2 = [[S0[0] + 2 * t * n[0], S0[1] + 2 * t * n[1]], u];
      const bd = [T[0] - D, T[1] - t];
      const bl = Math.hypot(bd[0], bd[1]);
      const bn = [-bd[1] / bl, bd[0] / bl]; // נורמל פנימה (שמאלה מהכיוון)
      const l3 = [[D + t * bn[0], t + t * bn[1]], bd];
      const h1 = lineIntersect(...l1, ...l2), h2 = lineIntersect(...l2, ...l3), h3 = lineIntersect(...l3, ...l1);
      if (h1 && h2 && h3) {
        const tri = [h1, h3, h2];
        if (Math.abs(area2(tri)) / 2 > 4 * t * t && h2[1] > h1[1] && h3[0] > h1[0] && h3[0] < D) {
          shape.holes.push(polygonShape(tri));
        }
      }
      const g = extrudeProfile(shape, p.width);
      g.translate(-D / 2, 0, 0);
      return g;
    },
  },

  hook: {
    name: 'וו תלייה', icon: '🪝', cat: 'product',
    desc: 'וו לקיר עם חורי ברגים — למגבות, תיקים ומפתחות. טיפ: לחוזק מרבי השכיבו על הצד (⟳ 90° X)',
    params: [
      P('width', 'רוחב', 20, 6, 80), P('H', 'גובה גב', 60, 20, 200), P('t', 'עובי', 5, 2, 12, 0.5),
      P('L', 'עומק הוו', 30, 10, 100), P('tip', 'גובה קצה', 15, 3, 60, 0.5),
      P('holes', 'חורי ברגים', 2, 0, 2, 1, ''), P('hd', 'קוטר בורג', 4, 2, 8, 0.5),
    ],
    build(p) {
      const t = p.t, L = Math.max(p.L, 2 * t + 1);
      const shape = polygonShape([
        [0, 0], [L, 0], [L, p.tip], [L - t, p.tip], [L - t, t], [t, t], [t, p.H], [0, p.H],
      ]);
      const g = extrudeProfile(shape, p.width);
      g.translate(-L / 2, 0, 0);
      if (!p.holes || p.hd >= p.width - 2) return g;
      const zs = p.holes === 1 ? [(p.H + t) / 2 + t] : [p.H - p.hd - 4, t + (p.H - t) * 0.45];
      const cuts = zs.map((z) => {
        const c = new THREE.CylinderGeometry(p.hd / 2, p.hd / 2, t * 4, 48);
        c.rotateZ(Math.PI / 2);
        c.translate(-L / 2 + t / 2, 0, z);
        return c;
      });
      return subtractAll(g, cuts);
    },
  },

  bracket: {
    name: 'זווית / תושבת L', icon: '📐', cat: 'product',
    desc: 'זווית חיזוק למדף עם משולש תמיכה וחורי ברגים. טיפ: לחוזק מרבי השכיבו על הצד (⟳ 90° X)',
    params: [
      P('width', 'רוחב', 20, 8, 100), P('A', 'אורך זרוע אופקית', 50, 15, 200), P('B', 'אורך זרוע אנכית', 50, 15, 200),
      P('t', 'עובי', 4, 2, 12, 0.5), P('g', 'משולש חיזוק', 15, 0, 80, 0.5), P('hd', 'קוטר בורג (0=בלי)', 4, 0, 8, 0.5),
    ],
    build(p) {
      const { t } = p;
      const g = clamp(p.g, 0, Math.min(p.A, p.B) - t - 4);
      const pts = g > 0.5
        ? [[0, 0], [p.A, 0], [p.A, t], [t + g, t], [t, t + g], [t, p.B], [0, p.B]]
        : [[0, 0], [p.A, 0], [p.A, t], [t, t], [t, p.B], [0, p.B]];
      const geo = extrudeProfile(polygonShape(pts), p.width);
      geo.translate(-p.A / 2, 0, 0);
      if (!p.hd || p.hd >= p.width - 2) return geo;
      const hx = -p.A / 2 + (t + g + p.A) / 2;
      const hz = (t + g + p.B) / 2;
      const c1 = new THREE.CylinderGeometry(p.hd / 2, p.hd / 2, t * 4, 48);
      c1.rotateX(Math.PI / 2); c1.translate(hx, 0, t / 2);
      const c2 = new THREE.CylinderGeometry(p.hd / 2, p.hd / 2, t * 4, 48);
      c2.rotateZ(Math.PI / 2); c2.translate(-p.A / 2 + t / 2, 0, hz);
      return subtractAll(geo, [c1, c2]);
    },
  },

  tag: {
    name: 'מחזיק מפתחות / תג', icon: '🔑', cat: 'product',
    desc: 'לוחית שטוחה עם חור לטבעת',
    params: [
      P('w', 'אורך', 50, 15, 150), P('d', 'רוחב', 25, 10, 100), P('t', 'עובי', 3, 1, 10, 0.2),
      P('r', 'עיגול פינות', 5, 0, 30, 0.5), P('hole', 'קוטר חור', 5, 0, 15, 0.5),
    ],
    build(p) {
      const s = roundedRectShape(p.w, p.d, p.r);
      if (p.hole > 0 && p.hole < p.d - 2) {
        const m = Math.max(p.hole / 2 + 2.5, p.r * 0.6);
        s.holes.push(circlePath(p.hole / 2, -p.w / 2 + m, 0, THREE.Path));
      }
      return extrudeZ(s, p.t);
    },
  },

  // ===== צורות בסיס =====
  box: {
    name: 'קובייה / תיבה', icon: '🟦', cat: 'shape',
    params: [P('w', 'רוחב', 20, 0.5, 300, 0.5), P('d', 'עומק', 20, 0.5, 300, 0.5), P('h', 'גובה', 20, 0.5, 300, 0.5), P('r', 'עיגול פינות', 0, 0, 50, 0.5)],
    build: (p) => (p.r > 0.05 ? extrudeZ(roundedRectShape(p.w, p.d, p.r), p.h) : boxAt(p.w, p.d, p.h)),
  },
  cylinder: {
    name: 'גליל', icon: '🛢️', cat: 'shape',
    params: [P('dia', 'קוטר', 20, 0.5, 300, 0.5), P('h', 'גובה', 20, 0.5, 300, 0.5)],
    build: (p) => cylinderZ(p.dia / 2, p.h),
  },
  tube: {
    name: 'צינור / ספייסר', icon: '⭕', cat: 'shape',
    params: [P('od', 'קוטר חיצוני', 20, 2, 300, 0.5), P('id', 'קוטר פנימי', 10, 0.5, 290, 0.5), P('h', 'גובה', 10, 0.5, 300, 0.5)],
    build(p) {
      const s = circlePath(p.od / 2);
      if (p.id < p.od - 0.4) s.holes.push(circlePath(p.id / 2, 0, 0, THREE.Path));
      const g = new THREE.ExtrudeGeometry(s, { depth: p.h, bevelEnabled: false, curveSegments: 48 });
      return g;
    },
  },
  cone: {
    name: 'חרוט', icon: '🔺', cat: 'shape',
    params: [P('db', 'קוטר תחתון', 20, 0.5, 300, 0.5), P('dt', 'קוטר עליון', 0, 0, 300, 0.5), P('h', 'גובה', 20, 0.5, 300, 0.5)],
    build: (p) => cylinderZ(p.db / 2, p.h, 0, Math.max(p.dt / 2, 0.001)),
  },
  sphere: {
    name: 'כדור', icon: '⚪', cat: 'shape',
    params: [P('dia', 'קוטר', 20, 0.5, 300, 0.5)],
    build(p) {
      const g = new THREE.SphereGeometry(p.dia / 2, 64, 32);
      g.translate(0, 0, p.dia / 2);
      return g;
    },
  },
  dome: {
    name: 'חצי כדור', icon: '🌓', cat: 'shape',
    params: [P('dia', 'קוטר', 20, 0.5, 300, 0.5)],
    build(p) {
      const r = p.dia / 2, prof = [[0, 0]];
      for (let i = 0; i <= 24; i++) {
        const a = (i / 24) * (Math.PI / 2);
        prof.push([r * Math.cos(a), r * Math.sin(a)]);
      }
      return latheZ(prof);
    },
  },
  torus: {
    name: 'טבעת (טורוס)', icon: '🍩', cat: 'shape',
    params: [P('D', 'קוטר כולל', 30, 2, 300, 0.5), P('t', 'עובי', 6, 0.5, 100, 0.5)],
    build(p) {
      const R = Math.max((p.D - p.t) / 2, p.t / 2 + 0.1);
      const g = new THREE.TorusGeometry(R, p.t / 2, 32, SEG);
      g.translate(0, 0, p.t / 2);
      return g;
    },
  },
  wedge: {
    name: 'טריז', icon: '◣', cat: 'shape',
    params: [P('w', 'אורך', 20, 0.5, 300, 0.5), P('d', 'רוחב', 20, 0.5, 300, 0.5), P('h', 'גובה', 20, 0.5, 300, 0.5)],
    build(p) {
      const g = extrudeProfile(polygonShape([[0, 0], [p.w, 0], [0, p.h]]), p.d);
      g.translate(-p.w / 2, 0, 0);
      return g;
    },
  },
};

export function defaultParams(type) {
  return Object.fromEntries(CATALOG[type].params.map((q) => [q.key, q.def]));
}

export function buildGeometry(type, params, settings) {
  const def = CATALOG[type];
  const p = { ...defaultParams(type), ...params };
  return def.build(p, settings);
}
