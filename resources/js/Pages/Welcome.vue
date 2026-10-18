<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
});

const mobileMenuOpen = ref(false);
const scrolled = ref(false);

const features = [
    { tag: 'Canvas', title: 'Canvas editor', body: 'Drag, resize, and rotate elements on a Konva.js stage, with snapping, z-index control, and undo/redo.' },
    { tag: 'AI', title: 'AI-assisted generation', body: 'Describe a slide in plain English and get a first draft — copy, layout, and styling — to start from instead of a blank canvas.' },
    { tag: 'Presence', title: 'Live presence, safe conflicts', body: 'See who else is viewing a slide right now. If two edits collide, you get a conflict warning instead of a silent overwrite.' },
    { tag: 'Export', title: 'One-click export', body: 'Download a deck as PDF or a native PowerPoint PPTX file — shareable anywhere, any device.' },
    { tag: 'Text', title: 'Rich text engine', body: 'Tiptap-powered inline editing with font controls, colors, alignment, and lists, without leaving the canvas.' },
    { tag: 'Present', title: 'Presentation mode', body: 'A full-screen presenter view with keyboard navigation and a single-key fullscreen toggle.' },
];

onMounted(() => {
    const onScroll = () => { scrolled.value = window.pageYOffset > 24; };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
        const io = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
        document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
    } else {
        document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
    }
});
</script>

<template>
    <Head title="Dot.Press — The canvas-first presentation tool for the Dot Ecosystem">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Public+Sans:wght@400;500;600;700&family=Roboto+Mono:wght@400;500;600&display=swap" rel="stylesheet">
        <meta name="description" content="A canvas-first presentation platform with AI-assisted generation, live presence, and one-click PDF & PPTX export. Go from idea to deck in minutes.">
    </Head>

    <div class="press-page antialiased min-h-screen">

        <!-- Nav -->
        <header
            :class="scrolled ? 'bg-[#180f08]/95 backdrop-blur-md border-b border-[var(--line)]' : 'border-b border-transparent'"
            class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300"
        >
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <Link href="/" class="flex items-center gap-2.5 press-btn">
                    <img src="/images/logo-light.png" alt="Dot.Press" class="h-14 sm:h-[4.5rem] w-auto">
                </Link>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--mist)]">
                    <a href="#capabilities" class="link-underline hover:text-[var(--paper)] pb-0.5">Capabilities</a>
                    <a href="#workflow" class="link-underline hover:text-[var(--paper)] pb-0.5">Workflow</a>
                </div>

                <div class="flex items-center gap-3">
                    <template v-if="$page.props.auth.user">
                        <Link :href="route('dashboard')" class="press-btn flex items-center gap-2 px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#180f08] text-sm font-display font-semibold rounded-lg transition-colors">
                            Dashboard
                        </Link>
                    </template>
                    <template v-else>
                        <Link v-if="canLogin" :href="route('login')" class="hidden sm:block text-sm font-medium text-[var(--mist)] hover:text-[var(--paper)] transition-colors">
                            Sign in
                        </Link>
                        <Link v-if="canRegister" :href="route('register')" class="press-btn px-5 py-2.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#180f08] text-sm font-display font-semibold rounded-lg transition-colors">
                            Get started
                        </Link>
                    </template>

                    <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden press-btn p-2 -mr-2 text-[var(--paper)]" aria-label="Toggle menu" :aria-expanded="mobileMenuOpen">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path v-if="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16" />
                            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </nav>

            <div v-show="mobileMenuOpen" class="md:hidden border-t border-[var(--line)] bg-[#180f08]">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#capabilities" class="px-3 py-2.5 text-[var(--mist)] hover:text-[var(--paper)]">Capabilities</a>
                    <a href="#workflow" class="px-3 py-2.5 text-[var(--mist)] hover:text-[var(--paper)]">Workflow</a>
                    <Link v-if="canLogin && !$page.props.auth.user" :href="route('login')" class="px-3 py-2.5 text-[var(--mist)] hover:text-[var(--paper)]">Sign in</Link>
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative min-h-[100dvh] flex items-end overflow-hidden">
            <!-- Photo: designer working across a canvas of colorful slide/card layouts, by Swello, unsplash.com/photos/J4LeErSKC_0 -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1759215524484-89c8d7ae28f2?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(24,15,8,0.55) 0%, rgba(24,15,8,0.75) 45%, #180f08 92%);"></div>
            <div class="absolute inset-0" style="background: linear-gradient(90deg, #180f08 0%, rgba(24,15,8,0.6) 40%, transparent 70%);"></div>
            <div class="absolute inset-0" style="background: radial-gradient(ellipse 80% 60% at 15% 0%, rgba(232,137,15,0.14) 0%, transparent 60%);"></div>

            <!-- Signature element: line-art easel + rising line — echoes the logo's own presentation-board icon -->
            <svg class="hidden lg:block absolute right-[5%] bottom-[8%] h-[62%] w-auto opacity-[0.16] pointer-events-none" viewBox="0 0 260 260" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <rect x="20" y="30" width="200" height="120" rx="10" stroke="#f5efe4" stroke-width="4"/>
                <path d="M60 115L100 80L130 105L180 55" stroke="#e8890f" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M100 150L85 230M140 150L155 230" stroke="#f5efe4" stroke-width="4" stroke-linecap="round"/>
                <path d="M75 230H165" stroke="#f5efe4" stroke-width="4" stroke-linecap="round"/>
            </svg>

            <div class="relative z-10 max-w-[1400px] mx-auto px-5 sm:px-8 pt-32 pb-16 sm:pb-20 w-full">
                <div class="max-w-2xl reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--orange)] mb-6">
                        Canvas-first presentation tool
                    </p>

                    <h1 class="font-display font-semibold text-4xl sm:text-5xl lg:text-6xl leading-[1.08] sm:leading-none tracking-tight text-[var(--paper)] mb-6">
                        Slides that look designed,<br>not defaulted.
                    </h1>

                    <p class="text-lg text-[var(--mist)] leading-relaxed max-w-xl mb-10">
                        Build decks on a real canvas — text, shapes, and images you position by hand — with AI-assisted drafting when you want a starting point, and one-click export to PDF or PPTX when you're done.
                    </p>

                    <div class="flex flex-wrap items-center gap-4">
                        <Link v-if="canRegister" :href="route('register')" class="press-btn px-7 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#180f08] font-display font-semibold rounded-lg transition-colors">
                            Start building
                        </Link>
                        <a href="#capabilities" class="press-btn flex items-center gap-2 px-7 py-3.5 text-[var(--paper)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--mist)] transition-colors">
                            See what it does
                        </a>
                    </div>
                </div>
            </div>

            <!-- Element strip — real canvas primitives, not fabricated metrics -->
            <div class="relative z-10 w-full border-t border-[var(--line)] bg-[#180f08]/60 backdrop-blur-sm">
                <div class="max-w-[1400px] mx-auto px-5 sm:px-8 py-4 flex flex-wrap gap-x-8 gap-y-2 font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--mist)]">
                    <span>Text</span>
                    <span class="text-[var(--orange)]">·</span>
                    <span>Shapes</span>
                    <span class="text-[var(--orange)]">·</span>
                    <span>Images</span>
                    <span class="text-[var(--orange)]">·</span>
                    <span>PDF export</span>
                    <span class="text-[var(--orange)]">·</span>
                    <span>PPTX export</span>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section id="capabilities" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--orange)] mb-4">What it does</p>
                    <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight sm:leading-10">
                        Everything a deck needs, on one canvas
                    </h2>
                </div>

                <div class="grid md:grid-cols-2 border-t border-[var(--line)]">
                    <div v-for="(f, i) in features" :key="f.title" :class="['row-hover border-b border-[var(--line)] px-1 py-8 sm:py-10 transition-colors reveal', i % 2 === 0 ? 'md:border-r' : '']" data-reveal>
                        <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--orange)] mb-3">{{ f.tag }}</p>
                        <h3 class="font-display font-semibold text-xl text-[var(--paper)] mb-2.5">{{ f.title }}</h3>
                        <p class="text-[var(--mist)] leading-relaxed max-w-md">{{ f.body }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Workflow -->
        <section id="workflow" class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--ink-soft)] border-y border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.6fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--orange)] mb-4">From idea to deck</p>
                        <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight sm:leading-10 mb-5">
                            A project holds every deck you build
                        </h2>
                        <p class="text-[var(--mist)] leading-relaxed max-w-sm">
                            Projects contain decks, decks contain slides, slides hold the elements you place — one straightforward structure, no folders to invent.
                        </p>
                    </div>

                    <div class="reveal overflow-x-auto" data-reveal>
                        <div class="flex items-stretch gap-0 min-w-[520px] font-mono text-xs uppercase tracking-[0.1em]">
                            <div v-for="(s, i) in [
                                    { label: 'Project', note: 'A container for related decks' },
                                    { label: 'Deck', note: 'A presentation, with its own theme' },
                                    { label: 'Slide', note: 'One canvas — layout and elements' },
                                    { label: 'Export', note: 'PDF or PPTX, ready to share' },
                                ]" :key="s.label" :class="['flex-1', i > 0 ? 'border-l border-[var(--line)] pl-5' : '', i < 3 ? 'pr-5' : '']">
                                <div class="flex items-center gap-2 mb-3">
                                    <span class="text-[var(--gold)]">{{ String(i + 1).padStart(2, '0') }}</span>
                                    <span class="text-[var(--paper)] font-display normal-case text-sm font-semibold tracking-normal">{{ s.label }}</span>
                                </div>
                                <p class="text-[var(--mist)] normal-case tracking-normal leading-relaxed">{{ s.note }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-36 px-5 sm:px-8 overflow-hidden">
            <!-- Photo: a presenter at full-screen deck in front of an audience, by Teemu Paananen, unsplash.com/photos/bzdhc5b3Bxs -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1505373877841-8d25f7d46678?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, #180f08 0%, rgba(24,15,8,0.85) 50%, #180f08 100%);"></div>
            <div class="absolute inset-0" style="background: radial-gradient(ellipse 70% 50% at 50% 100%, rgba(240,195,58,0.08) 0%, transparent 65%);"></div>

            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display font-semibold text-3xl sm:text-4xl text-[var(--paper)] leading-tight sm:leading-10 mb-5">
                    Go from idea to deck in minutes
                </h2>
                <p class="text-[var(--mist)] leading-relaxed mb-10 max-w-lg mx-auto">
                    Start on a blank canvas, or let AI draft a first pass. Either way, you're exporting a real deck by the end.
                </p>

                <div class="flex flex-wrap justify-center gap-4">
                    <Link v-if="canRegister" :href="route('register')" class="press-btn px-8 py-3.5 bg-[var(--gold)] hover:bg-[var(--gold-soft)] text-[#180f08] font-display font-semibold rounded-lg transition-colors">
                        Create your first deck
                    </Link>
                    <Link v-if="canLogin" :href="route('login')" class="press-btn px-8 py-3.5 text-[var(--paper)] font-medium rounded-lg border border-[var(--line)] hover:border-[var(--mist)] transition-colors">
                        Sign in
                    </Link>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <Link href="/" class="flex items-center gap-2.5">
                    <img src="/images/logo-light.png" alt="Dot.Press" class="h-11 w-auto opacity-90">
                </Link>
                <div class="flex items-center gap-6 font-mono text-xs tracking-wide uppercase text-[var(--mist)]">
                    <Link :href="route('policy.show')" class="hover:text-[var(--paper)] transition-colors">Privacy</Link>
                    <Link :href="route('cookies')" class="hover:text-[var(--paper)] transition-colors">Cookies</Link>
                    <Link :href="route('terms.show')" class="hover:text-[var(--paper)] transition-colors">Terms</Link>
                </div>
                <p class="font-mono text-xs tracking-wide text-[var(--mist)]">
                    &copy; {{ new Date().getFullYear() }} Dot.Press. The canvas-first presentation tool for the Dot Ecosystem.
                </p>
            </div>
        </footer>
    </div>
</template>

<style>
.press-page {
    --ink: #180f08;
    --ink-soft: #201409;
    --gold: #f0c33a;
    --gold-soft: #f5d573;
    --orange: #e8890f;
    --orange-soft: #f0a13f;
    --paper: #f5efe4;
    --mist: #b8a692;
    --line: rgba(245, 239, 228, 0.1);
    --font-display: 'Fraunces', Georgia, serif;
    --font-body: 'Public Sans', system-ui, sans-serif;
    --font-mono: 'Roboto Mono', ui-monospace, monospace;
    --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
    font-family: var(--font-body);
    background: var(--ink);
    color: var(--paper);
}
.press-page .font-display { font-family: var(--font-display); font-optical-sizing: auto; }
.press-page .font-mono { font-family: var(--font-mono); }
.press-page .press-btn { transition: transform 160ms var(--ease-out); }
.press-page .press-btn:active { transform: scale(0.97); }
@media (prefers-reduced-motion: no-preference) {
    .press-page .reveal { opacity: 0; transform: translateY(14px); transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out); }
    .press-page .reveal.is-visible { opacity: 1; transform: translateY(0); }
}
@media (prefers-reduced-motion: reduce) { .press-page .reveal { opacity: 1; transform: none; } }
@media (hover: hover) and (pointer: fine) {
    .press-page .row-hover:hover { background: rgba(245, 239, 228, 0.03); }
    .press-page .link-underline { background-size: 0% 1px; }
    .press-page .link-underline:hover { background-size: 100% 1px; }
}
.press-page .link-underline { background-image: linear-gradient(currentColor, currentColor); background-position: 0 100%; background-repeat: no-repeat; transition: background-size 220ms var(--ease-out); }
</style>
