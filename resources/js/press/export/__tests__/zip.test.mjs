import test from 'node:test';
import assert from 'node:assert/strict';
import { crc32, createZip, zipParts, uniqueNames, safeFileName, slideFileName, dosDateTime } from '../zip.js';

const bytes = (s) => new TextEncoder().encode(s);

test('crc32 matches the published check values', () => {
    assert.equal(crc32(bytes('123456789')), 0xcbf43926);
    assert.equal(crc32(new Uint8Array(0)), 0);
    assert.equal(crc32(bytes('The quick brown fox jumps over the lazy dog')), 0x414fa339);
    assert.equal(crc32(bytes('a')), 0xe8b7be43);
});

test('crc32 can continue a previous value', () => {
    const whole = crc32(bytes('123456789'));
    const first = crc32(bytes('1234'));
    assert.equal(crc32(bytes('56789'), first), whole);
});

/** A small reader that trusts nothing: finds the end record, walks the central directory, checks the local headers. */
function readZip(zip) {
    const v = new DataView(zip.buffer, zip.byteOffset, zip.byteLength);
    let e = zip.length - 22;
    assert.equal(v.getUint32(e, true), 0x06054b50, 'end of central directory signature');
    const count = v.getUint16(e + 10, true);
    assert.equal(v.getUint16(e + 8, true), count);
    const cdSize = v.getUint32(e + 12, true);
    const cdOffset = v.getUint32(e + 16, true);
    assert.equal(cdOffset + cdSize, e, 'central directory ends where the end record starts');
    const out = [];
    let p = cdOffset;
    for (let i = 0; i < count; i++) {
        assert.equal(v.getUint32(p, true), 0x02014b50);
        const flags = v.getUint16(p + 8, true);
        const method = v.getUint16(p + 10, true);
        const crc = v.getUint32(p + 16, true);
        const csize = v.getUint32(p + 20, true);
        const usize = v.getUint32(p + 24, true);
        const nlen = v.getUint16(p + 28, true);
        const xlen = v.getUint16(p + 30, true);
        const clen = v.getUint16(p + 32, true);
        const lho = v.getUint32(p + 42, true);
        const name = new TextDecoder().decode(zip.subarray(p + 46, p + 46 + nlen));
        assert.equal(v.getUint32(lho, true), 0x04034b50);
        assert.equal(v.getUint16(lho + 8, true), method);
        assert.equal(v.getUint32(lho + 14, true), crc);
        const lnlen = v.getUint16(lho + 26, true);
        const lxlen = v.getUint16(lho + 28, true);
        const start = lho + 30 + lnlen + lxlen;
        const data = zip.subarray(start, start + csize);
        out.push({ name, flags, method, crc, csize, usize, data });
        p += 46 + nlen + xlen + clen;
    }
    return out;
}

test('createZip writes a STORE archive that reads back byte for byte', () => {
    const png = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0, 1, 2, 3, 255, 254]);
    const zip = createZip([
        { name: 'slide-01-intro.png', data: png },
        { name: 'notes/readme.txt', data: 'Café notes' },
        { name: 'empty.bin', data: new Uint8Array(0) },
    ], { now: new Date(2026, 10, 7, 12, 30, 10) });
    const files = readZip(zip);
    assert.deepEqual(files.map((f) => f.name), ['slide-01-intro.png', 'notes/readme.txt', 'empty.bin']);
    assert.ok(files.every((f) => f.method === 0), 'STORE only');
    assert.ok(files.every((f) => f.csize === f.usize));
    assert.deepEqual([...files[0].data], [...png]);
    assert.equal(new TextDecoder().decode(files[1].data), 'Café notes');
    assert.equal(files[0].crc, crc32(png));
    assert.equal(files[2].crc, 0);
    assert.equal(files[0].flags & 0x0800, 0x0800, 'UTF-8 names are flagged');
});

test('zipParts and createZip give the same bytes', () => {
    const files = [{ name: 'a.txt', data: 'one' }, { name: 'b.txt', data: 'two' }];
    const now = new Date(2026, 0, 2, 3, 4, 6);
    const parts = zipParts(files, { now });
    const joined = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
    let o = 0;
    for (const p of parts) { joined.set(p, o); o += p.length; }
    assert.deepEqual([...joined], [...createZip(files, { now })]);
});

test('dosDateTime packs local time into two words', () => {
    const { time, date } = dosDateTime(new Date(2026, 10, 7, 12, 30, 10));
    assert.equal(time, (12 << 11) | (30 << 5) | 5);
    assert.equal(date, ((2026 - 1980) << 9) | (11 << 5) | 7);
});

test('createZip refuses an empty list and an empty name', () => {
    assert.throws(() => createZip([]), /at least one/);
    assert.throws(() => createZip([{ name: '', data: 'x' }]), /empty or too long/);
});

test('file names: slugs, padding, uniqueness', () => {
    assert.equal(safeFileName('Q3 revenue: up 12%!'), 'q3-revenue-up-12');
    assert.equal(safeFileName('Été à Zürich'), 'ete-a-zurich');
    assert.equal(safeFileName('   '), 'slide');
    assert.equal(safeFileName('???', 'x'), 'x');
    assert.equal(slideFileName(7, 12, 'The claim'), 'slide-07-the-claim.png');
    assert.equal(slideFileName(7, 120, ''), 'slide-007.png');
    assert.equal(slideFileName(1, 3, null, 'jpg'), 'slide-01.jpg');
    assert.deepEqual(uniqueNames(['a.png', 'b.png', 'A.png', 'a.png']), ['a.png', 'b.png', 'A-2.png', 'a-3.png']);
});
