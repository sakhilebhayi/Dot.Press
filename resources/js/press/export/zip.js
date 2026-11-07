// A tiny ZIP writer for the image export. STORE only (no compression: PNG files are already compressed), pure
// JavaScript with no DOM, so it runs under node:test. Limits are the classic ZIP ones (65535 entries, 4 GiB), far
// above what a deck of PNG files needs; createZip throws a plain error if they would be crossed.
//
//   crc32(bytes, previous = 0)        CRC-32 (IEEE) of a Uint8Array, optionally continuing a previous value
//   createZip(files, {now})           files [{name, data: Uint8Array | string}] -> Uint8Array
//   zipParts(files, {now})            same bytes as an array of Uint8Array parts (hand them to new Blob(parts))
//   uniqueNames(names)                keep names unique inside one archive (a, a-2, a-3)
//   safeFileName(text, fallback)      a short, portable file name stem
//   slideFileName(index, count, title, ext)   "slide-07-the-claim.png", index is 1 based and padded to the count

const TABLE = (() => {
    const t = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        t[n] = c >>> 0;
    }
    return t;
})();

export function crc32(bytes, previous = 0) {
    let c = (previous ^ 0xffffffff) >>> 0;
    for (let i = 0; i < bytes.length; i++) c = TABLE[(c ^ bytes[i]) & 0xff] ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
}

const enc = new TextEncoder();
const u8 = (d) => (typeof d === 'string' ? enc.encode(d) : d instanceof Uint8Array ? d : new Uint8Array(d));

/** MS-DOS date and time words for a Date (local time, 2 second resolution, years 1980 to 2107). */
export function dosDateTime(date) {
    const y = Math.min(2107, Math.max(1980, date.getFullYear()));
    const time = (date.getHours() << 11) | (date.getMinutes() << 5) | (date.getSeconds() >> 1);
    const day = ((y - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate();
    return { time: time & 0xffff, date: day & 0xffff };
}

export function safeFileName(text, fallback = 'slide') {
    const s = String(text ?? '')
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 48)
        .replace(/-+$/g, '');
    return s || fallback;
}

export function slideFileName(index, count, title, ext = 'png') {
    const width = Math.max(2, String(Math.max(1, count)).length);
    const n = String(index).padStart(width, '0');
    const stem = safeFileName(title, '');
    return `slide-${n}${stem ? `-${stem}` : ''}.${ext}`;
}

export function uniqueNames(names) {
    const seen = new Map();
    return names.map((name) => {
        const key = name.toLowerCase();
        const n = (seen.get(key) || 0) + 1;
        seen.set(key, n);
        if (n === 1) return name;
        const dot = name.lastIndexOf('.');
        return dot > 0 ? `${name.slice(0, dot)}-${n}${name.slice(dot)}` : `${name}-${n}`;
    });
}

/** A fixed size record filled through a little endian DataView. */
function header(size, fill) {
    const buf = new Uint8Array(size);
    fill(new DataView(buf.buffer));
    return buf;
}

export function zipParts(files, { now = new Date() } = {}) {
    if (!Array.isArray(files) || files.length === 0) throw new Error('A ZIP file needs at least one file.');
    if (files.length > 65535) throw new Error('Too many files for one ZIP file.');
    const { time, date } = dosDateTime(now);
    const parts = [];
    const central = [];
    let offset = 0;
    for (const f of files) {
        const name = enc.encode(String(f.name));
        if (name.length === 0 || name.length > 65535) throw new Error('A file name in the ZIP is empty or too long.');
        const data = u8(f.data);
        if (data.length > 0xfffffffe || offset > 0xfffffffe) throw new Error('The ZIP file would be larger than 4 GB.');
        const crc = crc32(data);
        // bit 11: file names are UTF-8
        const local = header(30, (v) => {
            v.setUint32(0, 0x04034b50, true);
            v.setUint16(4, 20, true);
            v.setUint16(6, 0x0800, true);
            v.setUint16(8, 0, true);
            v.setUint16(10, time, true);
            v.setUint16(12, date, true);
            v.setUint32(14, crc, true);
            v.setUint32(18, data.length, true);
            v.setUint32(22, data.length, true);
            v.setUint16(26, name.length, true);
            v.setUint16(28, 0, true);
        });
        central.push({ name, crc, size: data.length, offset });
        parts.push(local, name, data);
        offset += local.length + name.length + data.length;
    }
    const cdStart = offset;
    for (const c of central) {
        const rec = header(46, (v) => {
            v.setUint32(0, 0x02014b50, true);
            v.setUint16(4, 20, true);
            v.setUint16(6, 20, true);
            v.setUint16(8, 0x0800, true);
            v.setUint16(10, 0, true);
            v.setUint16(12, time, true);
            v.setUint16(14, date, true);
            v.setUint32(16, c.crc, true);
            v.setUint32(20, c.size, true);
            v.setUint32(24, c.size, true);
            v.setUint16(28, c.name.length, true);
            v.setUint16(30, 0, true);
            v.setUint16(32, 0, true);
            v.setUint16(34, 0, true);
            v.setUint16(36, 0, true);
            v.setUint32(38, 0, true);
            v.setUint32(42, c.offset, true);
        });
        parts.push(rec, c.name);
        offset += rec.length + c.name.length;
    }
    const cdSize = offset - cdStart;
    parts.push(header(22, (v) => {
        v.setUint32(0, 0x06054b50, true);
        v.setUint16(4, 0, true);
        v.setUint16(6, 0, true);
        v.setUint16(8, central.length, true);
        v.setUint16(10, central.length, true);
        v.setUint32(12, cdSize, true);
        v.setUint32(16, cdStart, true);
        v.setUint16(20, 0, true);
    }));
    return parts;
}

export function createZip(files, opts) {
    const parts = zipParts(files, opts);
    const total = parts.reduce((n, p) => n + p.length, 0);
    const out = new Uint8Array(total);
    let o = 0;
    for (const p of parts) { out.set(p, o); o += p.length; }
    return out;
}
