<script setup lang="ts">
import { defineAsyncComponent } from 'vue';
import { Link, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import type { RhythmPatternData } from '@/Components/Library/RhythmPattern.vue';
import type { LeadsheetBar } from '@/Components/SyncedPlayer/SyncedPlayer.vue';
import type { ProgressionChord } from '@/Components/Library/ChordProgressionViewer.vue';

const SyncedHero    = defineAsyncComponent(() => import('@/Components/Home/SyncedHero.vue'));
const SkillPathSection = defineAsyncComponent(() => import('@/Components/Home/SkillPathSection.vue'));
const RhythmPattern   = defineAsyncComponent(() => import('@/Components/Library/RhythmPattern.vue'));
const ChordProgressionViewer = defineAsyncComponent(() => import('@/Components/Library/ChordProgressionViewer.vue'));
const CircleOfFifths  = defineAsyncComponent(() => import('@/edu/widgets/CircleOfFifths.vue'));

defineOptions({ layout: PublicLayout });

interface LibraryChord {
    name: string;
    frets: string;
    intervalLabels: string;
    position: number;
}

interface LibraryBoxes {
    chordCount: number;
    chords: LibraryChord[];
    songCount: number;
    songs: { title: string; cover: string }[];
    rhythmCount: number;
    rhythm: RhythmPatternData | null;
    progressionCount: number;
    progression: { name: string; chords: ProgressionChord[] } | null;
    courseCount: number;
    courses: { title: string; cover: string }[];
}

const props = defineProps<{
    rhythmPattern: RhythmPatternData | null;
    heroBars: LeadsheetBar[] | null;
    heroRhythm: RhythmPatternData | null;
    heroRhythmSlug: string | null;
    heroRhythmCaption: string | null;
    heroCitation: string | null;
    libraryBoxes: LibraryBoxes;
}>();

function chordDiagramSvg(chord: LibraryChord): string {
    const fn = (window as any).sbnRenderDiagramSVG;
    if (!fn) return '';
    return fn(
        { frets: chord.frets, position: chord.position },
        { showFingers: false, intervalLabels: chord.intervalLabels },
    );
}

function chordNameHtml(name: string): string {
    const fn = (window as any).sbnFormatChordHtml;
    return fn ? fn(name) : name;
}

// JSON-LD: Organization + WebSite, so Google can build a knowledge panel and
// sitelinks searchbox for brand-name queries ("Soul Bossa Nova").
const orgJsonLd = JSON.stringify({
    '@context': 'https://schema.org',
    '@graph': [
        {
            '@type': 'Organization',
            '@id': 'https://www.soulbossanova.com/#organization',
            name: 'Soul Bossa Nova',
            url: 'https://www.soulbossanova.com/',
            logo: 'https://www.soulbossanova.com/images/logoplain.png',
            sameAs: [],
        },
        {
            '@type': 'WebSite',
            '@id': 'https://www.soulbossanova.com/#website',
            name: 'Soul Bossa Nova',
            url: 'https://www.soulbossanova.com/',
            publisher: { '@id': 'https://www.soulbossanova.com/#organization' },
        },
    ],
}).replace(/</g, '\\u003c');
</script>

<template>
    <Head>
        <title>Soul Bossa Nova — Guitar Lessons for Bossa Nova &amp; Latin Jazz</title>
        <meta name="description" content="Learn Bossa Nova guitar with interactive leadsheets, chord library, rhythm patterns and video courses. For beginners and advanced players." />
        <meta property="og:title" content="Soul Bossa Nova — Guitar Lessons for Bossa Nova & Latin Jazz" />
        <meta property="og:description" content="Interactive Bossa Nova guitar platform with leadsheets, theory widgets, chord library and courses." />
        <meta property="og:type" content="website" />
        <component :is="'script'" type="application/ld+json">{{ orgJsonLd }}</component>
    </Head>

    <div class="home-page">

        <!-- ── Hero ──────────────────────────────────────── -->
        <section class="home-hero">
            <div class="hero-bg">
                <div class="blob b3"></div>
            </div>
            <div class="home-wrap hero-inner">
                <!-- Left: copy -->
                <div>
                    <div class="eyebrow reveal d1">A teaching hub for guitarists</div>
                    <h1 class="reveal d2">Learn the <em>language</em><br>of bossa &amp; jazz guitar.</h1>
                    <p class="hero-lead reveal d3">
                        Interactive chord diagrams, living leadsheets, and rhythm tools built
                        for the way the music actually feels — not just how it looks on paper.
                    </p>
                    <div class="hero-cta reveal d4">
                        <Link href="/top10/bossa-nova-songs" class="btn btn-solid btn-lg">Explore the most popular songs →</Link>
                    </div>
                    <div class="hero-stats reveal d5">
                        <div class="stat"><div class="stat-n">240+</div><div class="stat-l">Voicings mapped</div></div>
                        <div class="stat"><div class="stat-n">60</div><div class="stat-l">Annotated standards</div></div>
                        <div class="stat"><div class="stat-n">∞</div><div class="stat-l">Ways to practice</div></div>
                    </div>
                </div>

                <!-- Right: synced demo -->
                <div class="reveal d3">
                    <SyncedHero
                        :bars="props.heroBars ?? undefined"
                        :rhythm-pattern="props.heroRhythm ?? undefined"
                        :muted="true"
                        :rhythm-caption="props.heroRhythmCaption ?? undefined"
                        :rhythm-link="props.heroRhythmSlug ? `/library/rhythms/${props.heroRhythmSlug}` : undefined"
                        :citation="props.heroCitation ?? undefined"
                    />
                </div>
            </div>
        </section>

        <!-- ── Grades teaser (retired) ───────────────────── -->
        <!-- <GradesTeaser /> -->

        <!-- ── Rhythm strip (hidden) ─────────────────────── -->
        <!--
        <section class="home-section">
            ...
        </section>
        -->

        <!-- ── Skill path ──────────────────────────────────── -->
        <SkillPathSection />

        <!-- ── Library showcase ─────────────────────────────── -->
        <section class="home-section" style="padding-top:0">
            <div class="home-wrap">
                <div class="section-head">
                    <div class="eyebrow">Explore the library</div>
                    <h2>Everything you need to play</h2>
                </div>
                <div class="lib-boxes">

                    <Link href="/library/chords" class="lib-box lib-box-chord">
                        <div class="lib-box-preview lib-chord-hero-row sbn-synced-hero-card">
                            <div
                                v-for="(chord, i) in libraryBoxes.chords"
                                :key="chord.name"
                                class="lib-chord-slot"
                                :class="i === 2 ? 'is-hero' : (i === 1 || i === 3) ? 'is-inner' : 'is-outer'"
                            >
                                <div class="lib-chord-name" v-html="chordNameHtml(chord.name)"></div>
                                <div class="lib-chord-svg" v-html="chordDiagramSvg(chord)"></div>
                            </div>
                        </div>
                        <h3>Chord Library</h3>
                        <p>{{ libraryBoxes.chordCount }}+ voicings, mapped through every inversion and key.</p>
                        <span class="card-more">Browse voicings →</span>
                    </Link>

                    <Link href="/library/songs" class="lib-box lib-box-song">
                        <div class="lib-box-preview lib-song-filmstrip">
                            <img
                                v-for="s in libraryBoxes.songs"
                                :key="s.title"
                                :src="s.cover"
                                :alt="s.title"
                                loading="lazy"
                            />
                        </div>
                        <h3>Song Library</h3>
                        <p>{{ libraryBoxes.songCount }}+ annotated leadsheets, from first standards to advanced arrangements.</p>
                        <span class="card-more">Browse songs →</span>
                    </Link>

                    <Link href="/library/rhythms" class="lib-box lib-box-rhythm">
                        <div class="lib-box-preview lib-box-preview-widget sbn-synced-hero-card">
                            <RhythmPattern
                                v-if="libraryBoxes.rhythm"
                                :pattern="libraryBoxes.rhythm"
                                :playable="false"
                            />
                        </div>
                        <h3>Rhythm Patterns</h3>
                        <p>{{ libraryBoxes.rhythmCount }}+ strumming and fingerpicking patterns, from bossa to samba to swing.</p>
                        <span class="card-more">Browse rhythms →</span>
                    </Link>

                    <!-- Not a <Link> — ChordProgressionViewer renders its own <button> chord
                         chips, which can't legally nest inside an <a>. -->
                    <div class="lib-box lib-box-progression">
                        <div class="lib-box-preview lib-box-preview-widget">
                            <ChordProgressionViewer
                                v-if="libraryBoxes.progression"
                                :chords="libraryBoxes.progression.chords"
                                :name="libraryBoxes.progression.name"
                                :interactive="false"
                                compact
                            />
                        </div>
                        <h3>Progressions</h3>
                        <p>{{ libraryBoxes.progressionCount }}+ chord progressions, explained and mapped to real songs.</p>
                        <Link href="/library/progressions" class="card-more">Browse progressions →</Link>
                    </div>

                    <Link href="/theory" class="lib-box lib-box-theory">
                        <div class="lib-box-preview lib-box-preview-widget">
                            <CircleOfFifths />
                        </div>
                        <h3>Theory &amp; Analysis</h3>
                        <p>Interactive lessons on scales, harmony and function — the why behind every chord you play.</p>
                        <span class="card-more">Explore theory →</span>
                    </Link>

                    <Link href="/learn" class="lib-box lib-box-course">
                        <div class="lib-box-preview lib-song-filmstrip">
                            <img
                                v-for="c in libraryBoxes.courses"
                                :key="c.title"
                                :src="c.cover"
                                :alt="c.title"
                                loading="lazy"
                            />
                        </div>
                        <h3>Courses</h3>
                        <p>{{ libraryBoxes.courseCount }}+ structured courses, from first chords to advanced repertoire.</p>
                        <span class="card-more">Browse courses →</span>
                    </Link>

                </div>
            </div>
        </section>

    </div>
</template>
