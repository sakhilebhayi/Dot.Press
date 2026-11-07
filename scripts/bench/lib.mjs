// Shared helpers for the Dot.Press benchmark scripts (scripts/bench/*.mjs). Plain node, no dependencies.
import { writeFileSync } from 'node:fs';

export function median(values) {
    const v = [...values].sort((a, b) => a - b);
    return v.length ? v[Math.floor(v.length / 2)] : null;
}

export function percentile(values, p) {
    const v = [...values].sort((a, b) => a - b);
    return v.length ? v[Math.min(v.length - 1, Math.floor(p * v.length))] : null;
}

export const round = (n, d = 2) => (typeof n === 'number' ? Math.round(n * 10 ** d) / 10 ** d : n);

/** Parses `--name value` and `--flag` arguments. */
export function args(argv = process.argv.slice(2)) {
    const out = {};
    for (let i = 0; i < argv.length; i++) {
        if (!argv[i].startsWith('--')) continue;
        const key = argv[i].slice(2);
        const next = argv[i + 1];
        if (next === undefined || next.startsWith('--')) out[key] = true;
        else { out[key] = next; i++; }
    }
    return out;
}

/** Collects `section.metric = value` pairs, prints them, and writes JSON when asked. */
export function recorder(title) {
    const results = {};
    return {
        results,
        put(section, key, value) {
            (results[section] ||= {})[key] = round(value);
        },
        print() {
            console.log(`\n${title}`);
            for (const [section, metrics] of Object.entries(results)) {
                console.log(`[${section}]`);
                for (const [k, v] of Object.entries(metrics)) console.log(`  ${k.padEnd(46)} ${String(v).slice(0, 200)}`);
            }
        },
        save(path) {
            if (path && path !== true) { writeFileSync(path, JSON.stringify(results, null, 2)); console.log(`\nResults written to ${path}`); }
        },
    };
}

/** Time `fn` `runs` times and return the median in ms. */
export function time(fn, runs = 10) {
    const t = [];
    for (let i = 0; i < runs; i++) {
        const s = performance.now();
        fn();
        t.push(performance.now() - s);
    }
    return median(t);
}
