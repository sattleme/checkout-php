// The reference for Decimal::shortest (SPEC.md §25: a JSON number's text is ECMAScript's
// Number::toString): positive doubles, as their IEEE 754 bits, and their text by Node's String().
// Every power of two from 2^-1074 to 2^1023 (a lopsided rounding interval each), doubles of random
// bits, and random prices from 1 to 10^9. Seeded: the same file each run.
//   node tools/shortest/generate.mjs > tests/fixtures/ecmascript-shortest.json
let state = 0x9e3779b97f4a7c15n;
const next = () => {
  // xorshift64*
  state ^= state >> 12n;
  state ^= (state << 25n) & 0xffffffffffffffffn;
  state ^= state >> 27n;
  return (state * 0x2545f4914f6cdd1dn) & 0xffffffffffffffffn;
};
const view = new DataView(new ArrayBuffer(8));
const bitsOf = (x) => {
  view.setFloat64(0, x);
  return view.getBigUint64(0).toString(16).padStart(16, "0");
};
const ofBits = (bits) => {
  view.setBigUint64(0, bits);
  return view.getFloat64(0);
};
const cases = [];
const add = (x) => {
  if (Number.isFinite(x) && x > 0) cases.push([bitsOf(x), String(x)]);
};
for (let n = -1074; n <= 1023; n++) add(2 ** n);
for (let i = 0; i < 3000; i++) add(ofBits(next() & 0x7fffffffffffffffn));
for (let i = 0; i < 2000; i++) add(1 + Number(next() >> 11n) / 2 ** 53 * 1e9);
process.stdout.write(JSON.stringify({ generator: "tools/shortest/generate.mjs", node: process.version, cases }) + "\n");
