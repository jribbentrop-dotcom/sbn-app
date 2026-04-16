/**
 * SoulBossaNova Course Player v3
 * Sub-section content switching + Overlay bottom bar
 */

(function() {
    'use strict';

    // ========================================================================
    // ALPHATAB LOADER
    // ========================================================================

    const AlphaTabLoader = {
        loaded: false,
        loading: false,
        callbacks: [],

        load(callback) {
            if (this.loaded) {
                callback();
                return;
            }

            this.callbacks.push(callback);

            if (this.loading) return;
            this.loading = true;

            const script = document.createElement('script');
            script.src = SBN_Config.alphaTabCDN + 'alphaTab.js';
            script.async = true;

            script.onload = () => {
                this.loaded = true;
                this.loading = false;
                this.callbacks.forEach(cb => cb());
                this.callbacks = [];
            };

            script.onerror = () => {
                this.loading = false;
                console.error('Failed to load AlphaTab');
            };

            document.head.appendChild(script);
        }
    };

    // ========================================================================
    // YOUTUBE API LOADER
    // ========================================================================

    const YouTubeLoader = {
        loaded: false,
        loading: false,
        callbacks: [],

        load(callback) {
            if (this.loaded) {
                callback();
                return;
            }

            this.callbacks.push(callback);

            if (this.loading) return;
            this.loading = true;

            window.onYouTubeIframeAPIReady = () => {
                this.loaded = true;
                this.loading = false;
                this.callbacks.forEach(cb => cb());
                this.callbacks = [];
            };

            const script = document.createElement('script');
            script.src = 'https://www.youtube.com/iframe_api';
            script.async = true;
            document.head.appendChild(script);
        }
    };

    // ========================================================================
    // COURSE PLAYER
    // ========================================================================

    class CoursePlayer {
        constructor(container) {
            this.container = container;
            this.config = JSON.parse(container.dataset.config || '{}');
            this.currentLesson = null;
            this.currentSubsection = null;
            this.sheetPlayers = [];

            this.elements = {
                sidebar: container.querySelector('.sbn-sidebar'),
                lessonNav: container.querySelector('.sbn-lesson-nav'),
                contentArea: container.querySelector('.sbn-lesson-content'),
                lockOverlay: container.querySelector('.sbn-lock-overlay'),
                progressFill: container.querySelector('.sbn-progress-fill'),
                bottomBar: container.querySelector('.sbn-bottom-bar'),
            };

            this.init();
        }

        init() {
            this.bindEvents();
            this.initBottomBar();
            
            // Load initial lesson
            const initialLesson = this.config.initialLesson;
            const initialSubsection = this.config.initialSubsection;
            
            if (initialLesson) {
                this.showLesson(initialLesson, initialSubsection);
            }
        }

        bindEvents() {
            // Lesson header clicks (expand/collapse)
            this.container.querySelectorAll('.sbn-lesson-header').forEach(header => {
                header.addEventListener('click', (e) => {
                    const item = header.closest('.sbn-lesson-item');
                    const lessonSlug = item.dataset.lesson;
                    const lesson = this.config.lessons[lessonSlug];

                    if (!lesson) return;

                    // If locked, show lock overlay
                    if (!lesson.accessible) {
                        this.showLockOverlay(lesson);
                        return;
                    }

                    // If has subsections, toggle expand
                    if (item.classList.contains('has-subsections')) {
                        if (item.classList.contains('is-active')) {
                            item.classList.toggle('is-expanded');
                        } else {
                            this.showLesson(lessonSlug);
                        }
                    } else {
                        this.showLesson(lessonSlug);
                    }
                });
            });

            // Sub-item clicks - CONTENT SWITCH (not scroll)
            this.container.querySelectorAll('.sbn-sub-item').forEach(subItem => {
                subItem.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const lessonItem = subItem.closest('.sbn-lesson-item');
                    const lessonSlug = lessonItem.dataset.lesson;
                    const sectionSlug = subItem.dataset.section;

                    this.showLesson(lessonSlug, sectionSlug);
                });
            });
        }

        // ====================================================================
        // BOTTOM BAR
        // ====================================================================

        initBottomBar() {
            if (!this.elements.bottomBar) return;

            const tabs = this.elements.bottomBar.querySelectorAll('.sbn-bar-tab');
            const panels = this.elements.bottomBar.querySelectorAll('.sbn-bar-panel');

            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    const panelId = tab.dataset.panel;
                    const panel = this.elements.bottomBar.querySelector(`#panel-${panelId}`);
                    const isOpen = panel && panel.classList.contains('is-open');

                    // Close all panels
                    panels.forEach(p => p.classList.remove('is-open'));
                    tabs.forEach(t => t.classList.remove('is-active'));

                    // If wasn't open, open it
                    if (!isOpen && panel) {
                        panel.classList.add('is-open');
                        tab.classList.add('is-active');
                    }
                });
            });
        }

        // ====================================================================
        // LESSON DISPLAY
        // ====================================================================

        showLesson(slug, subsectionSlug = null) {
            if (!slug || !this.config.lessons[slug]) return;

            const lesson = this.config.lessons[slug];

            // Stop any playing rhythm players
            if (typeof stopAllRhythmPlayers === 'function') {
                stopAllRhythmPlayers();
            }

            // Destroy any existing sheet players
            this.destroySheetPlayers();

            // Update active state in sidebar
            this.container.querySelectorAll('.sbn-lesson-item').forEach(item => {
                const isThis = item.dataset.lesson === slug;
                item.classList.toggle('is-active', isThis);
                item.classList.toggle('is-expanded', isThis && item.classList.contains('has-subsections'));
            });

            // Check access
            if (!lesson.accessible) {
                this.showLockOverlay(lesson);
                this.elements.contentArea.innerHTML = '';
                return;
            }

            // Hide lock overlay
            this.elements.lockOverlay.classList.remove('is-visible');

            // Get template content
            const template = document.querySelector(`.sbn-lesson-template[data-lesson="${slug}"]`);
            if (template) {
                this.elements.contentArea.innerHTML = template.innerHTML;
                
                // Split content into subsection chunks
                this.splitIntoSubsections(lesson);
                
                // Show specific subsection or first one
                if (subsectionSlug) {
                    this.showSubsection(slug, subsectionSlug);
                } else if (lesson.subsections && lesson.subsections.length > 0) {
                    this.showSubsection(slug, lesson.subsections[0].slug);
                }
                
                this.initializeContent();
                this.setupNavigation(slug, subsectionSlug);
            }

            // Update URL
            this.updateURL(slug, subsectionSlug);

            // Update progress
            this.updateProgress();

            this.currentLesson = slug;
            this.currentSubsection = subsectionSlug;
        }

        // ====================================================================
        // SUBSECTION CONTENT SWITCHING
        // ====================================================================

        splitIntoSubsections(lesson) {
            if (!lesson.subsections || lesson.subsections.length === 0) return;

            const contentBody = this.elements.contentArea.querySelector('.sbn-content-body');
            if (!contentBody) return;

            const h2Elements = contentBody.querySelectorAll('h2[id^="section-"]');
            if (h2Elements.length === 0) return;

            // Get all child nodes
            const children = Array.from(contentBody.childNodes);
            
            // Create chunks
            const chunks = [];
            let currentChunk = null;

            children.forEach(child => {
                if (child.nodeType === Node.ELEMENT_NODE && child.tagName === 'H2' && child.id && child.id.startsWith('section-')) {
                    // Start a new chunk
                    const sectionSlug = child.id.replace('section-', '');
                    currentChunk = {
                        slug: sectionSlug,
                        elements: [child]
                    };
                    chunks.push(currentChunk);
                } else if (currentChunk) {
                    // Add to current chunk
                    currentChunk.elements.push(child);
                }
                // Content before first H2 is discarded (or you could add to a default chunk)
            });

            // Clear content body and rebuild with chunk wrappers
            contentBody.innerHTML = '';

            chunks.forEach((chunk, index) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'sbn-subsection-chunk';
                wrapper.dataset.section = chunk.slug;
                
                chunk.elements.forEach(el => {
                    wrapper.appendChild(el.cloneNode ? el.cloneNode(true) : el);
                });
                
                contentBody.appendChild(wrapper);
            });
        }

        showSubsection(lessonSlug, subsectionSlug) {
            const lesson = this.config.lessons[lessonSlug];
            if (!lesson) return;

            // Hide all chunks, show the active one
            const chunks = this.elements.contentArea.querySelectorAll('.sbn-subsection-chunk');
            let activeChunk = null;
            chunks.forEach(chunk => {
                const isActive = chunk.dataset.section === subsectionSlug;
                chunk.classList.toggle('is-active', isActive);
                if (isActive) activeChunk = chunk;
            });

            // Update sidebar sub-items
            const lessonItem = this.container.querySelector(`.sbn-lesson-item[data-lesson="${lessonSlug}"]`);
            if (lessonItem) {
                lessonItem.querySelectorAll('.sbn-sub-item').forEach(item => {
                    item.classList.toggle('is-active', item.dataset.section === subsectionSlug);
                });
            }

            // Update header meta
            if (lesson.subsections) {
                const index = lesson.subsections.findIndex(s => s.slug === subsectionSlug);
                const subsection = lesson.subsections[index];
                
                // Update title to subsection title
                const titleEl = this.elements.contentArea.querySelector('.sbn-content-title');
                if (titleEl && subsection) {
                    titleEl.textContent = subsection.title;
                }
                
                // Update part indicator
                const partEl = this.elements.contentArea.querySelector('.sbn-current-part');
                if (partEl) {
                    partEl.textContent = `Part ${index + 1} of ${lesson.subsections.length}`;
                }
            }

            this.currentSubsection = subsectionSlug;
            this.updateURL(lessonSlug, subsectionSlug);
            
            // Scroll content area to top (not the window)
            if (this.elements.contentArea) {
                this.elements.contentArea.scrollTo({ top: 0, behavior: 'instant' });
            }
            
            // Initialize any sheet players in the now-visible chunk
            if (activeChunk && typeof window.initSBNSheetPlayersInContainer === 'function') {
                // Small delay to ensure CSS has applied and container has dimensions
                setTimeout(() => {
                    window.initSBNSheetPlayersInContainer(activeChunk);
                    // Also initialize leadsheet players
                    if (window.initSBNLeadsheetsInContainer) {
                        window.initSBNLeadsheetsInContainer(activeChunk);
                    }
                }, 50);
            }
        }

        // ====================================================================
        // NAVIGATION
        // ====================================================================

        setupNavigation(lessonSlug, subsectionSlug = null) {
            const navFooter = this.elements.contentArea.querySelector('.sbn-nav-footer');
            if (!navFooter) return;

            const prevBtn = navFooter.querySelector('.sbn-nav-prev');
            const nextBtn = navFooter.querySelector('.sbn-nav-next');

            // Build flat navigation list
            const navItems = this.buildNavItems();
            const currentIndex = this.findCurrentNavIndex(navItems, lessonSlug, subsectionSlug);

            // Previous button
            if (prevBtn) {
                if (currentIndex > 0) {
                    const prev = navItems[currentIndex - 1];
                    prevBtn.classList.remove('is-disabled');
                    prevBtn.onclick = () => this.showLesson(prev.lesson, prev.subsection);
                } else {
                    prevBtn.classList.add('is-disabled');
                    prevBtn.onclick = null;
                }
            }

            // Next button
            if (nextBtn) {
                if (currentIndex < navItems.length - 1) {
                    const next = navItems[currentIndex + 1];
                    if (next.accessible) {
                        nextBtn.classList.remove('is-disabled');
                        nextBtn.textContent = 'Next';
                        nextBtn.classList.remove('is-locked');
                        nextBtn.onclick = () => this.showLesson(next.lesson, next.subsection);
                    } else {
                        nextBtn.classList.add('is-locked');
                        nextBtn.innerHTML = '🔒 Unlock to Continue';
                        nextBtn.onclick = () => this.showLockOverlay(this.config.lessons[next.lesson]);
                    }
                } else {
                    nextBtn.classList.add('is-disabled');
                    nextBtn.textContent = 'Complete!';
                    nextBtn.onclick = null;
                }
            }
        }

        buildNavItems() {
            const items = [];
            const lessonOrder = this.config.lessonOrder || Object.keys(this.config.lessons);

            lessonOrder.forEach(lessonSlug => {
                const lesson = this.config.lessons[lessonSlug];
                if (!lesson) return;

                if (lesson.subsections && lesson.subsections.length > 0) {
                    lesson.subsections.forEach(sub => {
                        items.push({
                            lesson: lessonSlug,
                            subsection: sub.slug,
                            accessible: lesson.accessible
                        });
                    });
                } else {
                    items.push({
                        lesson: lessonSlug,
                        subsection: null,
                        accessible: lesson.accessible
                    });
                }
            });

            return items;
        }

        findCurrentNavIndex(items, lessonSlug, subsectionSlug) {
            return items.findIndex(item => 
                item.lesson === lessonSlug && item.subsection === subsectionSlug
            );
        }

        // ====================================================================
        // LOCK OVERLAY
        // ====================================================================

        showLockOverlay(lesson) {
            this.elements.lockOverlay.classList.add('is-visible');
            
            const titleEl = this.elements.lockOverlay.querySelector('.sbn-lock-title');
            if (titleEl) {
                titleEl.textContent = 'Unlock This Course';
            }
        }

        // ====================================================================
        // PROGRESS
        // ====================================================================

        updateProgress() {
            if (!this.elements.progressFill) return;

            const navItems = this.buildNavItems();
            const accessibleItems = navItems.filter(item => item.accessible);
            const currentIndex = this.findCurrentNavIndex(navItems, this.currentLesson, this.currentSubsection);
            
            // Calculate progress based on current position within accessible content
            const accessibleIndex = accessibleItems.findIndex(item => 
                item.lesson === this.currentLesson && item.subsection === this.currentSubsection
            );
            
            const progress = accessibleItems.length > 0 
                ? ((accessibleIndex + 1) / accessibleItems.length) * 100 
                : 0;

            this.elements.progressFill.style.width = `${progress}%`;

            // Update label
            const label = this.container.querySelector('.sbn-progress-label');
            if (label) {
                label.textContent = `${Math.round(progress)}% Complete`;
            }
        }

        // ====================================================================
        // URL MANAGEMENT
        // ====================================================================

        updateURL(lessonSlug, subsectionSlug) {
            const params = new URLSearchParams(window.location.search);
            params.set('lesson', lessonSlug);
            
            if (subsectionSlug) {
                params.set('section', subsectionSlug);
            } else {
                params.delete('section');
            }

            const newURL = `${window.location.pathname}?${params.toString()}`;
            window.history.replaceState({}, '', newURL);
        }

        // ====================================================================
        // CONTENT INITIALIZATION
        // ====================================================================

        initializeContent() {
            // Initialize sheet players via the new lazy loading system
            // The global function will handle players with data-config (shortcode-based)
            // This also handles AlphaTex containers via SBNTexSnippet class
            const activeChunk = this.elements.contentArea.querySelector('.sbn-subsection-chunk.is-active');
            const scope = activeChunk || this.elements.contentArea;
            
            if (typeof window.initSBNSheetPlayersInContainer === 'function') {
                setTimeout(() => {
                    window.initSBNSheetPlayersInContainer(scope);
                    // Also initialize rhythm players
                    if (window.initSBNRhythmPlayers) {
                        window.initSBNRhythmPlayers();
                    }
                    // Also initialize leadsheet players
                    this.initLeadsheetPlayers(scope);
                }, 50);
            } else {
                // Fallback: init leadsheets directly if sheet player system isn't ready
                setTimeout(() => {
                    this.initLeadsheetPlayers(scope);
                    if (window.initSBNRhythmPlayers) {
                        window.initSBNRhythmPlayers();
                    }
                }, 100);
            }
            
            // Legacy: Initialize old-style sheet players (with data-file attribute)
            this.initSheetPlayers();
        }
        
        /**
         * Initialize leadsheet players in a given scope
         * Handles both cases: when leadsheet.js is loaded and when it's not yet ready
         */
        initLeadsheetPlayers(scope) {
            if (!scope) return;
            
            const containers = scope.querySelectorAll('.sbn-leadsheet-container:not([data-initialized])');
            if (containers.length === 0) return;
            
            // Try the global function first
            if (typeof window.initSBNLeadsheetsInContainer === 'function') {
                window.initSBNLeadsheetsInContainer(scope);
                return;
            }
            
            // Fallback: try to initialize directly if SBNLeadsheetPlayer exists
            if (typeof window.SBNLeadsheetPlayer === 'function') {
                containers.forEach(container => {
                    try {
                        new window.SBNLeadsheetPlayer(container);
                        container.dataset.initialized = 'true';
                    } catch (e) {
                        console.error('[SBN Course] Failed to init leadsheet:', e);
                    }
                });
                return;
            }
            
            // Script not loaded yet - retry after a delay (up to 3 times)
            const retryCount = parseInt(scope.dataset.leadsheetRetry || '0');
            if (retryCount < 3) {
                scope.dataset.leadsheetRetry = retryCount + 1;
                console.log('[SBN Course] Leadsheet script not ready, retrying... (' + (retryCount + 1) + '/3)');
                setTimeout(() => this.initLeadsheetPlayers(scope), 300);
            } else {
                console.warn('[SBN Course] Leadsheet script failed to load after 3 retries');
            }
        }

        initSheetPlayers() {
            const players = this.elements.contentArea.querySelectorAll('.sbn-sheet-player[data-file]');
            
            players.forEach(playerEl => {
                const file = playerEl.dataset.file;
                const youtube = playerEl.dataset.youtube;

                if (file) {
                    this.createSheetPlayer(playerEl, file, youtube);
                }
            });
        }

        createSheetPlayer(container, file, youtubeUrl) {
            const contentEl = container.querySelector('.sbn-sheet-content');
            const loadingEl = container.querySelector('.sbn-sheet-loading');

            AlphaTabLoader.load(() => {
                if (loadingEl) loadingEl.style.display = 'none';

                const settings = {
                    core: {
                        engine: 'svg',
                        logLevel: 1,
                        useWorkers: false
                    },
                    display: {
                        layoutMode: 'page',
                        staveProfile: 'tab',
                        barsPerRow: 4
                    },
                    notation: {
                        notationMode: 'GuitarPro',
                        rhythmMode: 'showWithBars'
                    },
                    player: {
                        enablePlayer: true,
                        enableCursor: true,
                        scrollMode: 'continuous'
                    }
                };

                const api = new alphaTab.AlphaTabApi(contentEl, settings);
                api.load(file);

                // Store reference
                this.sheetPlayers.push({ api, container });

                // Setup controls
                this.setupSheetControls(container, api, youtubeUrl);
            });
        }

        setupSheetControls(container, api, youtubeUrl) {
            const playBtn = container.querySelector('.sbn-play-btn');
            const speedInput = container.querySelector('.sbn-speed-input');
            const speedValue = container.querySelector('.sbn-speed-value');

            if (playBtn) {
                playBtn.addEventListener('click', () => {
                    api.playPause();
                });

                api.playerStateChanged.on((args) => {
                    const isPlaying = args.state === 1;
                    playBtn.textContent = isPlaying ? '⏸ Pause' : '▶ Play';
                    playBtn.classList.toggle('is-active', isPlaying);
                });
            }

            if (speedInput && speedValue) {
                speedInput.addEventListener('input', () => {
                    const speed = parseInt(speedInput.value, 10);
                    api.playbackSpeed = speed / 100;
                    speedValue.textContent = `${speed}%`;
                });
            }

            // YouTube sync
            if (youtubeUrl) {
                this.setupYouTubeSync(container, api, youtubeUrl);
            }
        }

        setupYouTubeSync(container, api, youtubeUrl) {
            const youtubeContainer = container.querySelector('.sbn-youtube-container');
            if (!youtubeContainer) return;

            YouTubeLoader.load(() => {
                const videoId = this.extractYouTubeId(youtubeUrl);
                if (!videoId) return;

                const playerId = 'yt-' + Math.random().toString(36).substr(2, 9);
                youtubeContainer.innerHTML = `<div id="${playerId}"></div>`;

                const player = new YT.Player(playerId, {
                    videoId: videoId,
                    playerVars: {
                        autoplay: 0,
                        controls: 1,
                        modestbranding: 1
                    },
                    events: {
                        onStateChange: (event) => {
                            // Sync with AlphaTab
                            if (event.data === YT.PlayerState.PLAYING) {
                                if (!api.isPlaying) api.play();
                            } else if (event.data === YT.PlayerState.PAUSED) {
                                if (api.isPlaying) api.pause();
                            }
                        }
                    }
                });
            });
        }

        extractYouTubeId(url) {
            const match = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\s]+)/);
            return match ? match[1] : null;
        }

        destroySheetPlayers() {
            this.sheetPlayers.forEach(({ api }) => {
                try {
                    api.destroy();
                } catch (e) {
                    console.warn('Error destroying sheet player:', e);
                }
            });
            this.sheetPlayers = [];
        }
    }

    // ========================================================================
    // RHYTHM PATTERN PLAYER v4.0
    // Simplified: MP3 contains pre-baked loops, plays once. Synth loops continuously.
    // ========================================================================

    // Global registry to track all rhythm players for stopping on lesson change
    const rhythmPlayerRegistry = [];

    class RhythmPlayer {
        constructor(rhythmElement) {
            this.element = rhythmElement;
            this.playBtn = rhythmElement.querySelector('.sbn-rhythm-play-btn');
            this.grid = rhythmElement.querySelector('.sbn-rhythm-grid');
            this.blendSlider = rhythmElement.querySelector('.sbn-blend-slider');
            
            // Parse pattern data
            const rhythmData = rhythmElement.dataset.rhythm || rhythmElement.dataset.fingers || '........';
            this.rhythmPattern = rhythmData.split('');
            this.thumbPattern = (rhythmElement.dataset.thumb || '').split('');
            this.hasThumb = rhythmElement.dataset.hasThumb === 'true';
            this.beats = parseInt(rhythmElement.dataset.beats) || 8;
            
            // Sound settings
            this.soundType = rhythmElement.dataset.sound || 'clave';
            this.bpm = parseInt(rhythmElement.dataset.bpm) || 120;
            this.hihatEnabled = rhythmElement.dataset.hihat === 'on';

            // Percussion sample settings
            this.percTop      = rhythmElement.dataset.percTop  || 'none';
            this.percBass     = rhythmElement.dataset.percBass || 'none';
            this.hasPerc      = this.percTop !== 'none' || this.percBass !== 'none';
            this.percVol      = parseInt(rhythmElement.dataset.percVol  || '70') / 100;
            this.ghostDensity = parseInt(rhythmElement.dataset.ghost    || '40') / 100;
            this.samplesUrl   = rhythmElement.dataset.samplesUrl || '';
            this.ghostSlider  = rhythmElement.querySelector('.sbn-ghost-slider');
            
            // MP3 settings
            this.mp3Url = rhythmElement.dataset.mp3 || null;
            this.hasMp3 = rhythmElement.dataset.hasMp3 === 'true';
            this.mp3Buffer = null;
            this.mp3Source = null;
            this.mp3GainNode = null;
            this.synthGainNode = null;
            this.mp3Loaded = false;
            this.mp3Loading = false;
            this.blend = parseInt(rhythmElement.dataset.blend) || 0;
            
            // Playback state
            this.isPlaying = false;
            this.audioContext = null;
            this.animationFrameId = null;
            this.synthIntervalId = null;
            this.currentBeat = -1;
            this.startTime = 0;
            this.eighthNoteDuration = 0;
            
            // Register for global stop
            rhythmPlayerRegistry.push(this);
            
            this.init();
        }

        init() {
            if (this.playBtn) {
                this.playBtn.addEventListener('click', () => this.togglePlay());
            }
            
            if (this.blendSlider) {
                this.blendSlider.addEventListener('input', (e) => {
                    this.setBlend(parseInt(e.target.value));
                });
                this.updateBlendLabels();
            }
            
            if (this.hasMp3) {
                this.loadMp3();
            }

            // Ghost density slider
            if (this.ghostSlider) {
                // Set initial fill position
                this.ghostSlider.style.setProperty('--val', this.ghostSlider.value + '%');
                this.ghostSlider.addEventListener('input', (e) => {
                    this.ghostDensity = parseInt(e.target.value) / 100;
                    e.target.style.setProperty('--val', e.target.value + '%');
                });
            }

            // Pre-load percussion samples
            if (this.hasPerc && this.samplesUrl && window.SbnPercussion && !SbnPercussion.ready) {
                SbnPercussion.init(this.samplesUrl);
            }
            
            this.setupResponsiveScaling();
        }
        
        setBlend(value) {
            this.blend = Math.max(0, Math.min(100, value));
            
            if (this.isPlaying && this.audioContext) {
                const synthVol = (1 - (this.blend / 100)) * 0.8;
                const mp3Vol = (this.blend / 100) * 0.8;
                
                if (this.synthGainNode) {
                    this.synthGainNode.gain.setValueAtTime(synthVol, this.audioContext.currentTime);
                }
                if (this.mp3GainNode) {
                    this.mp3GainNode.gain.setValueAtTime(mp3Vol, this.audioContext.currentTime);
                }
            }
            
            this.updateBlendLabels();
        }
        
        updateBlendLabels() {
            const synthLabel = this.element.querySelector('.sbn-blend-synth');
            const musicLabel = this.element.querySelector('.sbn-blend-music');
            if (synthLabel) synthLabel.classList.toggle('is-active', this.blend < 50);
            if (musicLabel) musicLabel.classList.toggle('is-active', this.blend >= 50);
        }
        
        async loadMp3() {
            if (!this.mp3Url || this.mp3Loading || this.mp3Loaded) return;
            
            this.mp3Loading = true;
            this.element.classList.add('is-loading');
            
            try {
                this.initAudio();
                const response = await fetch(this.mp3Url);
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const arrayBuffer = await response.arrayBuffer();
                this.mp3Buffer = await this.audioContext.decodeAudioData(arrayBuffer);
                this.mp3Loaded = true;
                console.log('[Rhythm] MP3 loaded:', this.mp3Url, 'Duration:', this.mp3Buffer.duration.toFixed(2) + 's');
            } catch (error) {
                console.error('[Rhythm] Failed to load MP3:', error);
            } finally {
                this.mp3Loading = false;
                this.element.classList.remove('is-loading');
            }
        }
        
        setupResponsiveScaling() {
            const scaleRhythmGrid = () => {
                if (!this.grid) return;
                const container = this.element.closest('.sbn-content-body, .sbn-bar-panel-inner');
                if (!container) return;
                const containerWidth = container.offsetWidth - 40;
                const gridWidth = this.grid.scrollWidth;
                if (gridWidth > containerWidth) {
                    const scale = containerWidth / gridWidth;
                    this.element.style.transform = `scale(${scale})`;
                    this.element.style.transformOrigin = 'left center';
                } else {
                    this.element.style.transform = 'scale(1)';
                }
            };
            setTimeout(scaleRhythmGrid, 100);
            window.addEventListener('resize', () => {
                clearTimeout(this.resizeTimeout);
                this.resizeTimeout = setTimeout(scaleRhythmGrid, 200);
            });
        }

        initAudio() {
            if (!this.audioContext) {
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            }
            if (this.audioContext.state === 'suspended') {
                this.audioContext.resume();
            }
        }

        togglePlay() {
            if (this.isPlaying) {
                this.stop();
            } else {
                this.play();
            }
        }

        async play() {
            // Stop any other playing rhythm players
            rhythmPlayerRegistry.forEach(player => {
                if (player !== this && player.isPlaying) player.stop();
            });
            
            this.initAudio();

            // Resume / init percussion engine
            if (this.hasPerc && window.SbnPercussion) {
                SbnPercussion.resume();
                if (!SbnPercussion.ready && !SbnPercussion.loading && this.samplesUrl) {
                    SbnPercussion.init(this.samplesUrl);
                }
                SbnPercussion.setVolume(this.percVol);
            }

            if (this.hasMp3 && !this.mp3Loaded && !this.mp3Loading) {
                await this.loadMp3();
            }
            
            this.isPlaying = true;
            this.currentBeat = -1;
            
            if (this.playBtn) {
                this.playBtn.classList.add('playing');
            }
            
            // Calculate timing
            this.eighthNoteDuration = 60 / this.bpm / 2;
            const quarterNoteDuration = 60 / this.bpm;
            const countInDuration = quarterNoteDuration * 4; // 4 beat count-in
            
            // Create gain nodes
            this.synthGainNode = this.audioContext.createGain();
            this.synthGainNode.connect(this.audioContext.destination);
            
            if (this.hasMp3 && this.mp3Loaded) {
                this.mp3GainNode = this.audioContext.createGain();
                this.mp3GainNode.connect(this.audioContext.destination);
            }
            
            // Set blend levels
            this.setBlend(this.blend);
            
            // Play count-in
            this.playCountIn(quarterNoteDuration);
            
            // Schedule pattern start after count-in
            this.startTime = this.audioContext.currentTime + countInDuration;
            
            // Start MP3 (plays once - contains pre-baked loops)
            if (this.hasMp3 && this.mp3Loaded) {
                this.startMp3(this.startTime);
            }
            
            // Start synth loop
            this.startSynthLoop();
            
            // Start visual sync
            this.syncVisuals();
        }
        
        playCountIn(quarterNoteDuration) {
            for (let i = 0; i < 4; i++) {
                const time = this.audioContext.currentTime + (i * quarterNoteDuration);
                this.scheduleCountClick(time);
            }
        }
        
        scheduleCountClick(time) {
            const osc = this.audioContext.createOscillator();
            const gain = this.audioContext.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(1200, time);
            osc.frequency.exponentialRampToValueAtTime(800, time + 0.02);
            gain.gain.setValueAtTime(0.3, time);
            gain.gain.exponentialRampToValueAtTime(0.01, time + 0.08);
            osc.connect(gain);
            gain.connect(this.audioContext.destination);
            osc.start(time);
            osc.stop(time + 0.1);
        }
        
        startMp3(startTime) {
            this.mp3Source = this.audioContext.createBufferSource();
            this.mp3Source.buffer = this.mp3Buffer;
            this.mp3Source.loop = false; // Play once - MP3 has pre-baked loops
            this.mp3Source.connect(this.mp3GainNode);
            this.mp3Source.start(startTime);
            
            // When MP3 ends, stop everything
            this.mp3Source.onended = () => {
                if (this.isPlaying) {
                    this.stop();
                }
            };
        }
        
        startSynthLoop() {
            // Use setInterval for continuous synth playback
            // This is simple and works well for synth-only mode
            const intervalMs = this.eighthNoteDuration * 1000;
            let beatIndex = 0;
            
            // Wait until startTime to begin
            const waitMs = (this.startTime - this.audioContext.currentTime) * 1000;
            
            setTimeout(() => {
                if (!this.isPlaying) return;
                
                // Play first beat immediately
                this.playSynthBeat(beatIndex % this.beats);
                beatIndex++;
                
                // Then continue on interval
                this.synthIntervalId = setInterval(() => {
                    if (!this.isPlaying) return;
                    
                    // If MP3 is playing, it controls duration. If synth-only, loop forever.
                    this.playSynthBeat(beatIndex % this.beats);
                    beatIndex++;
                }, intervalMs);
                
            }, Math.max(0, waitMs));
        }
        
        playSynthBeat(index) {
            const cell        = this.rhythmPattern[index] || '.';
            const rhythmHit   = cell.toLowerCase() === 'x';
            const rhythmAccent= cell === 'X';
            const thumbCell   = this.thumbPattern[index] || '.';
            const thumbHit    = this.hasThumb && thumbCell.toLowerCase() === 'x';

            const now = this.audioContext.currentTime;

            // Synth sounds (kept for blend/fallback)
            if (this.soundType === 'clave') {
                if (rhythmHit) this.playClave(now, 'high');
                if (thumbHit)  this.playClave(now, 'low');
            } else if (this.soundType === 'guitar') {
                if (rhythmHit) this.playNote(now, 493.88, 0.2, 0.10);
                if (thumbHit)  this.playNote(now, 220, 0.3, 0.15);
            } else if (this.soundType === 'muted') {
                if (rhythmHit) this.playMuted(now, 'high');
                if (thumbHit)  this.playMuted(now, 'low');
            }

            if (this.hihatEnabled) this.playHiHat(now);

            // Percussion samples
            if (this.hasPerc && window.SbnPercussion && SbnPercussion.ready) {
                // Top row — main hits + ghost notes on empty cells
                if (this.percTop !== 'none') {
                    if (rhythmHit) {
                        // Normal or accented hit
                        const gain = rhythmAccent ? 1.0 : 0.78;
                        SbnPercussion.playHit(this.percTop, rhythmAccent, now, gain);
                    } else if (this.ghostDensity > 0) {
                        // Ghost note — always fires, gain scales with density
                        // Humanization (timing ±4ms, pitch ±2.5%) handled inside playHit
                        const ghostGain = this.ghostDensity * 0.18;
                        SbnPercussion.playHit(this.percTop, false, now, ghostGain);
                    }
                }

                // Bass row — main hits only, never ghosted
                if (thumbHit && this.percBass !== 'none') {
                    SbnPercussion.playHit(this.percBass, false, now, 0.85);
                }
            }
        }
        
        playClave(time, pitch) {
            const osc = this.audioContext.createOscillator();
            const gain = this.audioContext.createGain();
            const filter = this.audioContext.createBiquadFilter();
            
            osc.type = 'triangle';
            if (pitch === 'high') {
                osc.frequency.setValueAtTime(2500, time);
                osc.frequency.exponentialRampToValueAtTime(1800, time + 0.01);
                filter.frequency.setValueAtTime(3000, time);
                gain.gain.setValueAtTime(0.25, time);
            } else {
                osc.frequency.setValueAtTime(1800, time);
                osc.frequency.exponentialRampToValueAtTime(1200, time + 0.01);
                filter.frequency.setValueAtTime(2000, time);
                gain.gain.setValueAtTime(0.3, time);
            }
            gain.gain.exponentialRampToValueAtTime(0.01, time + 0.06);
            filter.type = 'bandpass';
            filter.Q.setValueAtTime(8, time);
            
            osc.connect(filter);
            filter.connect(gain);
            gain.connect(this.synthGainNode);
            osc.start(time);
            osc.stop(time + 0.08);
        }
        
        playNote(time, freq, dur, vol) {
            const osc = this.audioContext.createOscillator();
            const gain = this.audioContext.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, time);
            gain.gain.setValueAtTime(0, time);
            gain.gain.linearRampToValueAtTime(vol, time + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.01, time + dur);
            osc.connect(gain);
            gain.connect(this.synthGainNode);
            osc.start(time);
            osc.stop(time + dur);
        }
        
        playMuted(time, pitch) {
            const osc = this.audioContext.createOscillator();
            const gain = this.audioContext.createGain();
            const filter = this.audioContext.createBiquadFilter();
            osc.type = 'square';
            if (pitch === 'low') {
                osc.frequency.setValueAtTime(110, time);
                filter.frequency.setValueAtTime(300, time);
            } else {
                osc.frequency.setValueAtTime(330, time);
                filter.frequency.setValueAtTime(800, time);
            }
            gain.gain.setValueAtTime(0.2, time);
            gain.gain.exponentialRampToValueAtTime(0.01, time + 0.08);
            filter.type = 'lowpass';
            osc.connect(filter);
            filter.connect(gain);
            gain.connect(this.synthGainNode);
            osc.start(time);
            osc.stop(time + 0.1);
        }
        
        playHiHat(time) {
            const bufferSize = this.audioContext.sampleRate * 0.05;
            const buffer = this.audioContext.createBuffer(1, bufferSize, this.audioContext.sampleRate);
            const output = buffer.getChannelData(0);
            for (let i = 0; i < bufferSize; i++) output[i] = Math.random() * 2 - 1;
            
            const noise = this.audioContext.createBufferSource();
            noise.buffer = buffer;
            const bp = this.audioContext.createBiquadFilter();
            bp.type = 'bandpass';
            bp.frequency.setValueAtTime(8000, time);
            const hp = this.audioContext.createBiquadFilter();
            hp.type = 'highpass';
            hp.frequency.setValueAtTime(7000, time);
            const gain = this.audioContext.createGain();
            gain.gain.setValueAtTime(0.06, time);
            gain.gain.exponentialRampToValueAtTime(0.01, time + 0.04);
            
            noise.connect(bp);
            bp.connect(hp);
            hp.connect(gain);
            gain.connect(this.synthGainNode);
            noise.start(time);
            noise.stop(time + 0.05);
        }
        
        syncVisuals() {
            const update = () => {
                if (!this.isPlaying) return;
                
                const elapsed = this.audioContext.currentTime - this.startTime;
                
                if (elapsed < 0) {
                    this.animationFrameId = requestAnimationFrame(update);
                    return;
                }
                
                // Calculate current beat based on elapsed time
                const loopDuration = this.eighthNoteDuration * this.beats;
                const positionInLoop = elapsed % loopDuration;
                const beat = Math.floor(positionInLoop / this.eighthNoteDuration) % this.beats;
                
                if (beat !== this.currentBeat) {
                    this.currentBeat = beat;
                    this.highlightBeat(beat);
                }
                
                this.animationFrameId = requestAnimationFrame(update);
            };
            
            this.animationFrameId = requestAnimationFrame(update);
        }

        stop() {
            this.isPlaying = false;
            
            if (this.synthIntervalId) {
                clearInterval(this.synthIntervalId);
                this.synthIntervalId = null;
            }
            
            if (this.animationFrameId) {
                cancelAnimationFrame(this.animationFrameId);
                this.animationFrameId = null;
            }
            
            if (this.mp3Source) {
                try { this.mp3Source.stop(); } catch (e) {}
                this.mp3Source = null;
            }
            
            if (this.synthGainNode) {
                this.synthGainNode.disconnect();
                this.synthGainNode = null;
            }
            if (this.mp3GainNode) {
                this.mp3GainNode.disconnect();
                this.mp3GainNode = null;
            }
            
            if (this.playBtn) {
                this.playBtn.classList.remove('playing');
            }
            
            this.element.querySelectorAll('.sbn-rhythm-beat.is-playing').forEach(el => {
                el.classList.remove('is-playing');
            });
            this.element.querySelectorAll('.sbn-rhythm-beat-num.is-playing').forEach(el => {
                el.classList.remove('is-playing');
            });
            
            this.currentBeat = -1;
        }
        
        highlightBeat(index) {
            this.element.querySelectorAll('.sbn-rhythm-beat.is-playing').forEach(el => {
                el.classList.remove('is-playing');
            });
            this.element.querySelectorAll('.sbn-rhythm-beat-num.is-playing').forEach(el => {
                el.classList.remove('is-playing');
            });
            
            this.element.querySelectorAll(`.sbn-rhythm-beat[data-beat="${index}"]`).forEach(el => {
                el.classList.add('is-playing');
            });
            
            const beatNums = this.element.querySelectorAll('.sbn-rhythm-beat-num');
            if (beatNums[index]) beatNums[index].classList.add('is-playing');
        }
    }
    
    // Global function to stop all rhythm players
    function stopAllRhythmPlayers() {
        rhythmPlayerRegistry.forEach(player => {
            if (player.isPlaying) player.stop();
        });
    }
    
    window.stopAllRhythmPlayers = stopAllRhythmPlayers;

    // ========================================================================
    // TABLET & LAPTOP APP MODE - Sidebar Toggle
    // Applies to screens 769px - 1440px
    // ========================================================================
    
    function initTabletAppMode() {
        const isAppMode = window.matchMedia('(min-width: 769px) and (max-width: 1440px)').matches;
        if (!isAppMode) return;
        
        const player = document.querySelector('.sbn-course-player');
        const sidebar = document.querySelector('.sbn-sidebar');
        const mainContent = document.querySelector('.sbn-main');
        if (!player || !sidebar) return;
        
        // Prevent re-initialization
        if (player.dataset.appModeInitialized) return;
        player.dataset.appModeInitialized = 'true';
        
        // Click on sidebar toggle tab (::after pseudo element area)
        sidebar.addEventListener('click', (e) => {
            const rect = sidebar.getBoundingClientRect();
            const clickX = e.clientX - rect.left;
            
            // If clicked on the right edge (toggle area) when collapsed
            if (player.classList.contains('sidebar-collapsed') && clickX > rect.width - 10) {
                player.classList.remove('sidebar-collapsed');
            }
        });
        
        // Double-tap/click on main content to toggle sidebar
        let lastTap = 0;
        if (mainContent) {
            // Touch devices - double tap
            mainContent.addEventListener('touchend', (e) => {
                // Only handle double-tap for sidebar toggle
                const now = Date.now();
                if (now - lastTap < 300) {
                    player.classList.toggle('sidebar-collapsed');
                    e.preventDefault(); // Prevent accidental clicks after double-tap
                }
                lastTap = now;
            });
            
            // Mouse double-click for laptops
            mainContent.addEventListener('dblclick', (e) => {
                // Don't toggle if clicking on interactive elements
                if (e.target.closest('a, button, input, .sbn-bar-tab')) return;
                player.classList.toggle('sidebar-collapsed');
            });
        }
        
        // Swipe gestures for sidebar toggle
        let touchStartX = 0;
        let touchStartY = 0;
        
        // Handle swipe on main content area
        if (mainContent) {
            mainContent.addEventListener('touchstart', (e) => {
                touchStartX = e.touches[0].clientX;
                touchStartY = e.touches[0].clientY;
            }, { passive: true });
            
            mainContent.addEventListener('touchend', (e) => {
                const touchEndX = e.changedTouches[0].clientX;
                const touchEndY = e.changedTouches[0].clientY;
                const deltaX = touchEndX - touchStartX;
                const deltaY = Math.abs(touchEndY - touchStartY);
                
                // Only process horizontal swipes (more horizontal than vertical)
                if (Math.abs(deltaX) > 60 && deltaY < 80) {
                    const isCollapsed = player.classList.contains('sidebar-collapsed');
                    
                    // Swipe RIGHT - show sidebar (only when collapsed)
                    if (deltaX > 0 && isCollapsed) {
                        player.classList.remove('sidebar-collapsed');
                    }
                    // Swipe LEFT - hide sidebar (only when visible)
                    else if (deltaX < 0 && !isCollapsed) {
                        player.classList.add('sidebar-collapsed');
                    }
                }
            }, { passive: true });
        }
        
        // Also allow swipe on sidebar to close it
        sidebar.addEventListener('touchstart', (e) => {
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
        }, { passive: true });
        
        sidebar.addEventListener('touchend', (e) => {
            // Only if not scrolling in lesson nav
            if (e.target.closest('.sbn-lesson-nav')) return;
            
            const touchEndX = e.changedTouches[0].clientX;
            const touchEndY = e.changedTouches[0].clientY;
            const deltaX = touchEndX - touchStartX;
            const deltaY = Math.abs(touchEndY - touchStartY);
            
            // Swipe LEFT on sidebar - hide it
            if (deltaX < -60 && deltaY < 80 && !player.classList.contains('sidebar-collapsed')) {
                player.classList.add('sidebar-collapsed');
            }
        }, { passive: true });
        
        console.log('[SBN] App Mode initialized (769px - 1440px)');
    }

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    function initRhythmPlayers() {
        // Initialize rhythm players - support both new data-rhythm and legacy data-fingers
        document.querySelectorAll('.sbn-rhythm[data-rhythm], .sbn-rhythm[data-fingers]').forEach(rhythmElement => {
            // Skip if already initialized
            if (rhythmElement.dataset.initialized) return;
            
            try {
                new RhythmPlayer(rhythmElement);
                rhythmElement.dataset.initialized = 'true';
                console.log('[SBN] Initialized rhythm player');
            } catch (error) {
                console.error('[SBN] Failed to initialize rhythm player:', error);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.sbn-course-player').forEach(container => {
            new CoursePlayer(container);
        });
        
        // Initialize rhythm players immediately
        initRhythmPlayers();
        
        // Initialize Tablet App Mode
        initTabletAppMode();
        
        // Also initialize after a delay (in case content loads dynamically)
        setTimeout(initRhythmPlayers, 500);
        setTimeout(initRhythmPlayers, 1500);
    });
    
    // Re-check on resize
    window.addEventListener('resize', () => {
        initTabletAppMode();
    });
    
    // Export for external use (e.g., after AJAX content load)
    window.initSBNRhythmPlayers = initRhythmPlayers;

})();

// Initialize AlphaTab for [alphatex] shortcodes

// ============================================================================
// ALPHATEX PLAYER - Handled by sheet-player.js
// ============================================================================
// AlphaTex initialization is now handled by SBNTexSnippet class in sheet-player.js
// which is called via initSBNSheetPlayersInContainer()
