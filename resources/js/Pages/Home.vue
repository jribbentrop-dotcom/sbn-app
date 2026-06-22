<script setup lang="ts">
import { defineAsyncComponent } from 'vue';
import { Link, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import type { RhythmPatternData } from '@/Components/Library/RhythmPattern.vue';
import type { LeadsheetBar } from '@/Components/SyncedPlayer/SyncedPlayer.vue';
import type { ChordShape } from '@/Components/Home/ChordRain.vue';

const SyncedHero    = defineAsyncComponent(() => import('@/Components/Home/SyncedHero.vue'));
const ChordRain     = defineAsyncComponent(() => import('@/Components/Home/ChordRain.vue'));
const GradesTeaser  = defineAsyncComponent(() => import('@/Components/Home/GradesTeaser.vue'));
const GradesSlider  = defineAsyncComponent(() => import('@/Components/Home/GradesSlider.vue'));

defineOptions({ layout: PublicLayout });

const props = defineProps<{
    rhythmPattern: RhythmPatternData | null;
    heroBars: LeadsheetBar[] | null;
    heroRhythm: RhythmPatternData | null;
    rainChords: ChordShape[];
}>();
</script>

<template>
    <Head>
        <title>Soul Bossa Nova — Guitar Lessons for Bossa Nova &amp; Latin Jazz</title>
        <meta name="description" content="Learn Bossa Nova guitar with interactive leadsheets, chord library, rhythm patterns and video courses. For beginners and advanced players." />
        <meta property="og:title" content="Soul Bossa Nova — Guitar Lessons for Bossa Nova & Latin Jazz" />
        <meta property="og:description" content="Interactive Bossa Nova guitar platform with leadsheets, theory widgets, chord library and courses." />
        <meta property="og:type" content="website" />
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
                        <Link href="/register" class="btn btn-solid btn-lg">Start playing free →</Link>
                        <a href="#" class="btn btn-ghost btn-lg">▶ Watch the tour</a>
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
                    />
                </div>
            </div>
        </section>

        <!-- ── Grades teaser (retired) ───────────────────── -->
        <!-- <GradesTeaser /> -->

        <!-- ── Chord rain ─────────────────────────────────── -->
        <ChordRain v-if="rainChords?.length" :chords="rainChords" />

        <!-- ── Rhythm strip (hidden) ─────────────────────── -->
        <!--
        <section class="home-section">
            ...
        </section>
        -->

        <!-- ── Grades slider ─────────────────────────────── -->
        <GradesSlider />

        <!-- ── Feature cards ──────────────────────────────── -->
        <section class="home-section" style="padding-top:0">
            <div class="home-wrap">
                <div class="section-head">
                    <div class="eyebrow">Everything in one place</div>
                    <h2>Built for teachers and students</h2>
                </div>
                <div class="feature-cards">
                    <Link href="/admin/leadsheets" class="feature-card">
                        <div class="card-icon">🎼</div>
                        <h3>Interactive Tab Editor</h3>
                        <p>A Soundslice-style canvas where the chart, the tab, and the audio stay in sync as you edit.</p>
                        <span class="card-more">Explore the editor →</span>
                    </Link>
                    <Link href="/library/chords" class="feature-card">
                        <div class="card-icon">🗂️</div>
                        <h3>Every chord, every context</h3>
                        <p>Follow any chord through its inversions, transpose it to any key, and see exactly where it lives in the repertoire.</p>
                        <span class="card-more">Browse voicings →</span>
                    </Link>
                    <a href="#" class="feature-card">
                        <div class="card-icon">📊</div>
                        <h3>Analysis Panel</h3>
                        <p>Automatic function labelling, key-fit scoring and ii–V detection that explains <em>why</em> a progression works.</p>
                        <span class="card-more">See it work →</span><!-- TODO: wire to analysis route -->
                    </a>
                </div>
            </div>
        </section>

    </div>
</template>
