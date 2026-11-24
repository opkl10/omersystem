// טקסט תלת-ממדי: גופן Heebo (עברית + לטינית) דרך opentype.js
import * as THREE from 'three';
import opentype from '../vendor/opentype.module.js';

const FONT_URLS = { he: 'fonts/heebo-hebrew-700-normal.woff', latin: 'fonts/heebo-latin-700-normal.woff' };
const fonts = {};
let loading = null;

export function fontsReady() {
  return !!(fonts.he && fonts.latin);
}

export function loadFonts() {
  if (!loading) {
    loading = Promise.all(
      Object.entries(FONT_URLS).map(async ([k, url]) => {
        const res = await fetch(url);
        if (!res.ok) throw new Error('הגופן לא נטען');
        fonts[k] = opentype.parse(await res.arrayBuffer());
      }),
    );
  }
  return loading;
}

const isRTL = (ch) => /[֐-׿יִ-ﭏ]/.test(ch);
const isNeutral = (ch) => /[\s.,!?:;'"()\-–—]/.test(ch);

// סידור חזותי פשוט: מקטעים של עברית הפוכים, מקטעי לטינית/ספרות נשמרים
function visualOrder(text) {
  const chars = [...text];
  const baseRTL = chars.some(isRTL);
  const runs = [];
  for (const ch of chars) {
    const dir = isRTL(ch) ? 'r' : isNeutral(ch) ? 'n' : 'l';
    const last = runs[runs.length - 1];
    if (last && (last.dir === dir || dir === 'n')) last.chars.push(ch);
    else runs.push({ dir: dir === 'n' ? (baseRTL ? 'r' : 'l') : dir, chars: [ch] });
  }
  if (!baseRTL) return chars;
  return runs.reverse().flatMap((r) => (r.dir === 'r' ? r.chars.reverse() : r.chars));
}

function glyphFor(ch) {
  const primary = isRTL(ch) ? fonts.he : fonts.latin;
  const g = primary.charToGlyph(ch);
  if (g.index) return { font: primary, g };
  const other = primary === fonts.he ? fonts.latin : fonts.he;
  return { font: other, g: other.charToGlyph(ch) };
}

// מחזיר מערך THREE.Shape של הטקסט, בגובה אותיות capHeight (מ"מ), ממורכז סביב 0
export function textShapes(text, capHeight, spacingPct = 0) {
  if (!fontsReady() || !text.trim()) return [];
  const lat = fonts.latin;
  const capUnits = lat.tables.os2?.sCapHeight || lat.unitsPerEm * 0.7;
  const all = [];
  let x = 0;
  for (const ch of visualOrder(text)) {
    const { font, g } = glyphFor(ch);
    const size = (capHeight * font.unitsPerEm) / capUnits;
    const path = g.getPath(x, 0, size);
    const sp = new THREE.ShapePath();
    for (const c of path.commands) {
      if (c.type === 'M') sp.moveTo(c.x, -c.y);
      else if (c.type === 'L') sp.lineTo(c.x, -c.y);
      else if (c.type === 'Q') sp.quadraticCurveTo(c.x1, -c.y1, c.x, -c.y);
      else if (c.type === 'C') sp.bezierCurveTo(c.x1, -c.y1, c.x2, -c.y2, c.x, -c.y);
    }
    all.push(...sp.toShapes(font.outlinesFormat === 'cff'));
    x += (g.advanceWidth * size) / font.unitsPerEm + (capHeight * spacingPct) / 100;
  }
  return all;
}
