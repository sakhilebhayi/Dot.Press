<script setup>
// The public page: Dot.Press in one screen and a scroll. The page is itself a spine. A rail runs beside it with one bead
// per section, the line fills as you scroll, beads turn solid once their section is behind you and the one you are in is
// amber. That is the product's own idea (a deck is a line of claims, a bead is solid when its slide holds evidence), and
// the page's one signature moment. Everything on it is real: the slides are drawn by the real renderer from a real
// template, the beads come from the real evidence function, the review issues and the preflight counts are the output of
// the real services, and the compatibility table is read from the export code.
//
// Decisions (kept here because DESIGN.md is not ours to edit)
//  - The page is still at rest. Its life is the rail answering the scroll and the beads settling; nothing loops.
//  - No scroll reveals: all copy is on screen and readable at once, with or without motion.
//  - Under reduced motion the rail fill and the bead states change without travel, and in-page links jump.
//  - ?og=1 draws the first screen at 1200 by 630 for the share image (scripts/make-icons.mjs --og).
import '../../css/press/tokens.css';
import '../../css/press/fonts-ui.css';
import '../../css/press/base.css';
import '../../css/press/landing.css';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import Wordmark from '@/press/brand/Wordmark.vue';
import LandingRail from '@/press/brand/LandingRail.vue';
import SlideSheet from '@/press/brand/SlideSheet.vue';
import SpineLine from '@/press/brand/SpineLine.vue';
import CompatTable from '@/press/brand/CompatTable.vue';
import ProposalDemo from '@/press/brand/ProposalDemo.vue';
import MiniPlayer from '@/press/brand/MiniPlayer.vue';
import ThemeToggle from '@/press/editor/ui/ThemeToggle.vue';
import { SAMPLE_DECK, SAMPLE_PREFLIGHT, SAMPLE_REVIEW } from '@/press/brand/sampleDeck.js';
import { SECTIONS, currentSection, railFill, sampleBeads } from '@/press/brand/landingModel.js';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
});

const page = usePage();
const signedIn = computed(() => !!page.props.auth?.user);
const has = (name) => { try { return route().has(name); } catch { return false; } };
const loginHref = computed(() => (has('login') ? route('login') : '/login'));
const libraryHref = computed(() => (has('dashboard') ? route('dashboard') : '/dashboard'));
const ctaHref = computed(() => (signedIn.value ? libraryHref.value : loginHref.value));
const ctaLabel = computed(() => (signedIn.value ? 'Open your library' : 'Sign in to start a deck'));

const deck = SAMPLE_DECK.deck;
const beads = sampleBeads(SAMPLE_DECK);
const heroAt = ref(2);
const heroBeads = computed(() => beads.map((b) => ({ state: b.state, label: `Slide ${b.index + 1}: ${b.title}. ${b.summary}` })));
const hero = computed(() => beads[heroAt.value]);

const exploreAt = ref(2);
const explore = computed(() => beads[exploreAt.value]);

const levelCount = (f, level) => SAMPLE_PREFLIGHT[f][level];
const pdfNote = SAMPLE_PREFLIGHT.pdf.warnings?.[0];
const slideNo = (id) => Number(String(id).replace('sd', ''));
const reviewShown = SAMPLE_REVIEW.filter((r) => ['role-size', 'unsupported-claim', 'topic-title', 'acronym'].includes(r.kind)).slice(0, 4);

const og = ref(false);
const root = ref(null);
const current = ref(0);
const fill = ref(0);
let tops = [];

function measure() {
    tops = SECTIONS.map((s) => {
        const el = document.getElementById(s.id);
        return el ? el.getBoundingClientRect().top + window.scrollY : 0;
    });
    update();
}
let queued = false;
function update() {
    queued = false;
    if (!tops.length) return;
    const y = window.scrollY;
    current.value = currentSection(tops, y, 0.4, window.innerHeight);
    fill.value = railFill(tops, y, 0.4, window.innerHeight);
}
function onScroll() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(update);
}

let ro = null;
onMounted(async () => {
    og.value = new URLSearchParams(window.location.search).get('og') === '1';
    if (og.value) {
        document.documentElement.setAttribute('data-theme', 'light');
        document.documentElement.style.colorScheme = 'light';
    }
    document.documentElement.classList.add('lp-smooth');
    await nextTick();
    measure();
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', measure);
    if ('ResizeObserver' in window) {
        ro = new ResizeObserver(measure);
        ro.observe(root.value);
    }
    // Fonts change line breaks and so section heights.
    document.fonts?.ready?.then(measure);
});
onBeforeUnmount(() => {
    document.documentElement.classList.remove('lp-smooth');
    window.removeEventListener('scroll', onScroll);
    window.removeEventListener('resize', measure);
    ro?.disconnect();
});
</script>

<template>
    <Head title="Dot.Press: presentations built as a line of claims" />

    <div ref="root" class="pr lp" :class="{ 'lp--og': og }">
        <a class="pr-skip" href="#start">Skip to the page</a>

        <header class="lp-top">
            <div class="lp-top-bar">
                <Link href="/" class="lp-home" aria-label="Dot.Press, top of the page">
                    <Wordmark />
                </Link>
                <div class="lp-top-end">
                    <ThemeToggle />
                    <Link :href="ctaHref" class="lp-btn lp-btn--solid">{{ signedIn ? 'Library' : 'Sign in' }}</Link>
                </div>
            </div>
            <LandingRail :sections="SECTIONS" :current="current" :fill="fill" />
        </header>

        <main id="main" class="lp-main">
            <!-- 1. Start: a statement and the first sheet -->
            <section id="start" class="lp-sec lp-hero" aria-labelledby="h-start">
                <div class="lp-hero-copy">
                    <Wordmark v-if="og" class="lp-og-mark" size="lg" />
                    <h1 id="h-start" class="lp-h1"><span>Every slide is a claim.</span> <span>Back it up.</span></h1>
                    <p class="lp-lede">
                        Dot.Press is a presentation editor with a line down the side of the deck: one bead for each slide.
                        A bead stays hollow until its slide holds a chart, a table, a picture or a figure, and a title that says something.
                    </p>
                    <div class="lp-actions">
                        <Link :href="ctaHref" class="lp-cta"><i class="lp-cta-bead" aria-hidden="true" />{{ ctaLabel }}</Link>
                        <a href="#spine" class="lp-textlink">See how a bead fills</a>
                    </div>
                    <p class="lp-fine">Accounts come from InfoDot sign-in. There is no separate sign-up.</p>
                </div>
                <div class="lp-hero-stage">
                    <SlideSheet :slide="SAMPLE_DECK.slides[heroAt]" :deck="deck" eager />
                    <SpineLine :beads="heroBeads" :current="heroAt" label="Slides of the sample deck" @select="(i) => (heroAt = i)" />
                    <p class="pr-state lp-hero-state" aria-live="polite">
                        slide {{ heroAt + 1 }} of {{ beads.length }} · {{ hero.state === 'solid' ? 'solid' : 'hollow' }}
                    </p>
                    <p class="lp-fine lp-hero-cap">Sample deck, drawn by the editor's own renderer. It is the Quarterly business review template and its figures are placeholders.</p>
                </div>
            </section>

            <!-- 2. The Spine -->
            <section id="spine" class="lp-sec lp-spine" aria-labelledby="h-spine">
                <div class="lp-spine-head">
                    <h2 id="h-spine" class="lp-h2">The deck is an argument. The line shows where it is thin.</h2>
                    <p class="lp-p">
                        The rail on the left of the editor holds every slide in order, grouped into sections. Drag a bead and the slide moves with it.
                        While you present, the same line becomes the progress line the audience sees.
                    </p>
                </div>
                <div class="lp-spine-grid">
                    <div class="lp-spine-stage">
                        <SlideSheet :slide="SAMPLE_DECK.slides[exploreAt]" :deck="deck" />
                    </div>
                    <div class="lp-spine-list">
                        <ol class="lp-rows" aria-label="Slides of the sample deck and whether each holds evidence">
                            <li v-for="b in beads" :key="b.id">
                                <button type="button" class="lp-row" :aria-current="exploreAt === b.index ? 'true' : undefined" @click="exploreAt = b.index">
                                    <span class="pr-bead" :data-state="exploreAt === b.index ? 'current' : b.state" :data-evidence="b.state" aria-hidden="true" />
                                    <span class="lp-row-text">
                                        <span class="lp-row-title">{{ b.title }}</span>
                                        <span class="pr-state lp-row-state">{{ exploreAt === b.index ? 'here, ' : '' }}{{ b.state }}</span>
                                        <span class="lp-row-why">{{ b.summary }}</span>
                                    </span>
                                </button>
                            </li>
                        </ol>
                        <dl class="lp-legend">
                            <div><dt><span class="pr-bead pr-bead--sm" data-state="hollow" aria-hidden="true" /> hollow</dt><dd>A claim with nothing behind it yet, or a title that only names a topic.</dd></div>
                            <div><dt><span class="pr-bead pr-bead--sm" data-state="solid" aria-hidden="true" /> solid</dt><dd>A chart, table, picture, video, figure or quotation sits on the slide, and the title states something.</dd></div>
                            <div><dt><span class="pr-bead pr-bead--sm" data-state="current" data-evidence="solid" aria-hidden="true" /> here</dt><dd>The slide you are on, with its verdict inside the amber.</dd></div>
                        </dl>
                        <p class="lp-p lp-honest">This checks that you said something and showed something. It cannot tell you that the claim is true.</p>
                        <p class="lp-fine">The verdict on each bead is computed by the editor's own function, on this deck, in your browser.</p>
                    </div>
                </div>
            </section>

            <!-- 3. Honest exports -->
            <section id="exports" class="lp-sec lp-exports" aria-labelledby="h-exports">
                <h2 id="h-exports" class="lp-h2">Know what changes before you export.</h2>
                <div class="lp-exports-intro">
                    <p class="lp-p">
                        Every export starts with a preflight. It lists, element by element, what carries over exactly, what is approximated,
                        what is flattened into a picture and what is not exported at all, each with its reason.
                        PowerPoint files are written directly, so charts and tables stay editable in PowerPoint.
                    </p>
                    <p class="lp-p">
                        Import runs the other way. A PowerPoint file is read directly, and what could not be read is listed instead of dropped without a word.
                    </p>
                </div>

                <div class="lp-pre" role="group" aria-label="Preflight for the sample deck">
                    <h3 class="lp-h3">Preflight for the sample deck</h3>
                    <div class="lp-pre-cols">
                        <div>
                            <p class="lp-pre-fmt">PowerPoint</p>
                            <p class="lp-pre-big"><span>{{ levelCount('pptx', 'native') }}</span> of {{ SAMPLE_PREFLIGHT.pptx.total }} elements native</p>
                            <ul class="lp-pre-notes">
                                <li v-for="g in SAMPLE_PREFLIGHT.pptx.general" :key="g">{{ g }}</li>
                            </ul>
                        </div>
                        <div>
                            <p class="lp-pre-fmt">PDF</p>
                            <p class="lp-pre-big"><span>{{ levelCount('pdf', 'native') }}</span> native, <span>{{ levelCount('pdf', 'approximated') }}</span> approximated</p>
                            <ul class="lp-pre-notes">
                                <li v-if="pdfNote">{{ pdfNote.text }} ({{ pdfNote.count }} elements)</li>
                                <li v-for="g in SAMPLE_PREFLIGHT.pdf.general" :key="g">{{ g }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <h3 class="lp-h3 lp-compat-h">What each format keeps</h3>
                <CompatTable />
                <p class="lp-fine">Read from the export code: each line is a case of the preflight, so the table and the preflight cannot disagree.</p>
            </section>

            <!-- 4. Consistency review -->
            <section id="review" class="lp-sec lp-review" aria-labelledby="h-review">
                <h2 id="h-review" class="lp-h2 lp-h2--big">Drift shows up in review before it shows up in the room.</h2>
                <div class="lp-review-grid">
                    <div>
                        <p class="lp-p">
                            Review reads the whole deck and reports what it finds: fonts, type sizes, colours, alignment and margins, text that may overflow,
                            small or low contrast text, slides with too many words, titles that only name a topic, bullets repeated across slides, claims with no source,
                            leftover placeholder text, missing alt text and slides that animate too much.
                        </p>
                        <p class="lp-p">
                            It runs on the server as plain rules, with no model, so the same deck always gets the same answer.
                            A claim is never marked true or false. When a figure has no source, review asks where it comes from.
                        </p>
                    </div>
                    <div>
                        <h3 class="lp-h3">What review said about the sample deck</h3>
                        <ul class="lp-issues">
                            <li v-for="r in reviewShown" :key="r.kind + r.slide" class="lp-issue">
                                <span class="pr-bead" data-state="issue" aria-hidden="true" />
                                <div>
                                    <p class="pr-state">slide {{ slideNo(r.slide) }} · {{ r.kind.replace('-', ' ') }}</p>
                                    <p class="lp-issue-msg">{{ r.message }}</p>
                                    <p v-if="r.questions" class="lp-issue-q">{{ r.questions[0].replace(/^"[^"]*": /, '') }}</p>
                                </div>
                            </li>
                        </ul>
                        <p class="lp-fine">These are the findings of the real review run on the sample deck. A square bead marks a slide with an open issue.</p>
                    </div>
                </div>
            </section>

            <!-- 5. Proposals, not rewrites -->
            <section id="proposals" class="lp-sec lp-proposals" aria-labelledby="h-proposals">
                <div class="lp-prop-head">
                    <h2 id="h-proposals" class="lp-h2">Suggestions arrive as proposals. You decide.</h2>
                    <div>
                        <p class="lp-p">
                            A review fix, an outline built from your notes, a suggested title: each one shows what it changes and why, and nothing touches the deck until you accept it.
                            Accepting is one step and so is undoing it.
                        </p>
                        <p class="lp-p">
                            The review and the outline run as local rules on the server. A language model is used only when the server has a key for one, and every answer says which it came from.
                        </p>
                    </div>
                </div>
                <ProposalDemo />
            </section>

            <!-- 6. A deck that cannot fail on the day -->
            <section id="present" class="lp-sec lp-present" aria-labelledby="h-present">
                <div class="lp-present-copy">
                    <h2 id="h-present" class="lp-h2">A deck that cannot fail on the day.</h2>
                    <ul class="lp-points">
                        <li>
                            <h3 class="lp-h3">It runs with no network.</h3>
                            <p class="lp-p">Download one HTML file with the slides, the pictures and the fonts inside it. It opens and plays with no connection.</p>
                        </li>
                        <li>
                            <h3 class="lp-h3">Two windows, one position.</h3>
                            <p class="lp-p">The presenter console and the audience window stay in step through the browser itself, with no server in between.</p>
                        </li>
                        <li>
                            <h3 class="lp-h3">A phone as the clicker.</h3>
                            <p class="lp-p">Open the remote link on a phone and it moves the slide. People following on their own screens ask the server once a second, so they trail the presenter by about that much.</p>
                        </li>
                    </ul>
                </div>
                <div class="lp-present-stage">
                    <MiniPlayer />
                </div>
            </section>

            <!-- 7. Sign in -->
            <section id="signin" class="lp-sec lp-final" aria-labelledby="h-signin">
                <h2 id="h-signin" class="lp-h2 lp-h2--big">Start with one claim.</h2>
                <p class="lp-lede">Dot.Press accounts come from InfoDot. Sign in the way you do for the rest of the Dot ecosystem.</p>
                <div class="lp-actions">
                    <Link :href="ctaHref" class="lp-cta"><i class="lp-cta-bead" aria-hidden="true" />{{ ctaLabel }}</Link>
                </div>
                <div class="lp-end" aria-hidden="true"><i /></div>
            </section>
        </main>

        <footer class="lp-foot">
            <Wordmark size="sm" />
            <nav aria-label="Legal">
                <Link v-if="has('terms.show')" :href="route('terms.show')">Terms</Link>
                <Link v-if="has('policy.show')" :href="route('policy.show')">Privacy</Link>
                <Link v-if="has('cookies')" :href="route('cookies')">Cookies</Link>
            </nav>
            <p class="lp-fine">Dot.Press is part of the Dot ecosystem.</p>
        </footer>
    </div>
</template>
