<script setup>
// Small stroke icons for the stage chrome (24 grid, round caps). Slide icons live in ../../icons.js; these are interface only.
defineProps({ name: { type: String, required: true } });
const P = {
    bold: ['M7 5h6a3.5 3.5 0 0 1 0 7H7z', 'M7 12h7a3.5 3.5 0 0 1 0 7H7z'],
    italic: ['M14 5h-4', 'M14 19h-4', 'M15 5l-6 14'],
    underline: ['M7 4v7a5 5 0 0 0 10 0V4', 'M5 20h14'],
    strike: ['M5 12h14', 'M16.5 7.2A4.5 4 0 0 0 12 5c-2.5 0-4.5 1.3-4.5 3.2 0 1.7 1.3 2.5 4.5 3.3'],
    link: ['M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1', 'M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1'],
    'align-left': ['M4 6h16', 'M4 10h10', 'M4 14h16', 'M4 18h10'],
    'align-center': ['M4 6h16', 'M7 10h10', 'M4 14h16', 'M7 18h10'],
    'align-right': ['M4 6h16', 'M10 10h10', 'M4 14h16', 'M10 18h10'],
    'align-justify': ['M4 6h16', 'M4 10h16', 'M4 14h16', 'M4 18h16'],
    chevron: ['M7 10l5 5 5-5'],
    replace: ['M4 8h13l-3-3', 'M20 16H7l3 3'],
    crop: ['M7 3v14h14', 'M3 7h14v14'],
    mask: ['M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16z', 'M12 4v16'],
    opacity: ['M12 4c3.5 4 6 6.8 6 10a6 6 0 0 1-12 0c0-3.2 2.5-6 6-10z', 'M12 4v16'],
    fill: ['M5 13l7-8 7 8-6 6a2.5 2.5 0 0 1-3.5 0z', 'M19 17c1 1.4 1.5 2.3 1.5 3a1.5 1.5 0 0 1-3 0c0-.7.5-1.6 1.5-3z'],
    stroke: ['M4 20l4-1 11-11a2.1 2.1 0 0 0-3-3L5 16z', 'M14 7l3 3'],
    radius: ['M5 19V9a4 4 0 0 1 4-4h10'],
    chart: ['M4 20V10', 'M10 20V4', 'M16 20v-8', 'M22 20H2'],
    'row-add': ['M4 6h16v5H4z', 'M4 15h16', 'M12 15v5', 'M9.5 17.5h5'],
    'col-add': ['M6 4v16h5V4z', 'M15 4v16', 'M20 9v6', 'M17 12h6'],
    trash: ['M5 7h14', 'M10 7V5h4v2', 'M7 7l1 12h8l1-12', 'M10 11v5', 'M14 11v5'],
    merge: ['M4 5h7v14H4z', 'M13 5h7v14h-7z', 'M10 12h4', 'M12 10l2 2-2 2'],
    'line-width': ['M4 6h16', 'M4 11h16', 'M4 17h16'],
    ends: ['M4 12h13', 'M14 7l6 5-6 5'],
    dash: ['M3 12h3', 'M10 12h4', 'M18 12h3'],
    swap: ['M7 7h10l-3-3', 'M17 17H7l3 3'],
    play: ['M8 5l11 7-11 7z'],
    pause: ['M8 5v14', 'M16 5v14'],
    poster: ['M4 5h16v14H4z', 'M4 15l5-5 4 4 3-3 4 4', 'M15 9h.01'],
    loop: ['M17 2l3 3-3 3', 'M4 11V9a4 4 0 0 1 4-4h12', 'M7 22l-3-3 3-3', 'M20 13v2a4 4 0 0 1-4 4H4'],
    ungroup: ['M3 3h8v8H3z', 'M13 13h8v8h-8z'],
    group: ['M4 4h16v16H4z', 'M8 8h8v8H8z'],
    align: ['M4 4v16', 'M8 7h12', 'M8 14h7'],
    distribute: ['M4 4v16', 'M20 4v16', 'M9 8h6v8H9z'],
    size: ['M4 9V4h5', 'M20 15v5h-5', 'M4 4l6 6', 'M20 20l-6-6'],
    edit: ['M4 20l4-1 11-11a2.1 2.1 0 0 0-3-3L5 16z'],
    text: ['M5 6V4h14v2', 'M12 4v16', 'M9 20h6'],
    plus: ['M12 5v14', 'M5 12h14'],
    minus: ['M5 12h14'],
    comment: ['M4 5h16v11h-7l-5 4v-4H4z'],
};
</script>

<template>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path v-for="(d, i) in P[name] || []" :key="i" :d="d" />
    </svg>
</template>
