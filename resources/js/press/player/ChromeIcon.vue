<script setup>
// The handful of glyphs the player chrome uses. 24 x 24, stroke only; colour and size come from the button.
defineProps({ name: { type: String, required: true } });
const PATHS = {
    prev: ['M14.5 5.5L8 12l6.5 6.5'],
    next: ['M9.5 5.5L16 12l-6.5 6.5'],
    grid: ['M4.5 4.5h6v6h-6z', 'M13.5 4.5h6v6h-6z', 'M4.5 13.5h6v6h-6z', 'M13.5 13.5h6v6h-6z'],
    notes: ['M6 3.5h9l3.5 3.5v13.5H6z', 'M9 11h6.5', 'M9 14.5h6.5', 'M9 18h4'],
    fullscreen: ['M4 9V4h5', 'M20 9V4h-5', 'M4 15v5h5', 'M20 15v5h-5'],
    'fullscreen-exit': ['M9 4v5H4', 'M15 4v5h5', 'M9 20v-5H4', 'M15 20v-5h5'],
    close: ['M6.5 6.5l11 11', 'M17.5 6.5l-11 11'],
    laser: ['M12 12v.01', 'M12 6.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11z', 'M12 2.5v2', 'M12 19.5v2', 'M2.5 12h2', 'M19.5 12h2'],
    black: ['M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17z', 'M12 3.5v17'],
    window: ['M3.5 5h17v11h-17z', 'M9 20h6', 'M12 16v4'],
    phone: ['M8 3.5h8a1.5 1.5 0 0 1 1.5 1.5v14A1.5 1.5 0 0 1 16 20.5H8A1.5 1.5 0 0 1 6.5 19V5A1.5 1.5 0 0 1 8 3.5z', 'M11 17.5h2'],
    up: ['M6 14.5l6-6 6 6'],
    down: ['M6 9.5l6 6 6-6'],
    play: ['M8 5.5v13l11-6.5z'],
    clock: ['M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17z', 'M12 7.5V12l3 2'],
    copy: ['M8.5 8.5h10v11h-10z', 'M5.5 15.5v-11h10'],
};
</script>

<template>
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path v-for="(d, i) in PATHS[name] || []" :key="i" :d="d" /></svg>
</template>
