/**
 * SBN Sheet Music Player
 * AlphaTab integration with YouTube sync
 * Version 1.0.0
 */

(function() {
    'use strict';

    // ========================================================================
    // BIG PLAYER CLASS
    // ========================================================================

    class SBNSheetPlayer {
        constructor(container) {
            this.container = container;
            this.config = JSON.parse(container.dataset.config || '{}');
            
            // Elements
            this.videoContainer = container.querySelector('.sbn-sheet-video');
            this.sheetContainer = container.querySelector('.sbn-sheet-music');
            this.playBtn = container.querySelector('.sbn-sheet-play-btn');
            this.progressBar = container.querySelector('.sbn-sheet-progress');
            this.progressFill = container.querySelector('.sbn-sheet-progress-fill');
            this.timeDisplay = container.querySelector('.sbn-sheet-time');
            this.tempoDisplay = container.querySelector('.sbn-sheet-tempo-value');
            
            // Sync mode elements (admin only)
            this.syncModeBtn = container.querySelector('.sbn-sync-mode-btn');
            this.settingsModeBtn = container.querySelector('.sbn-settings-mode-btn');
            this.syncPanel = container.querySelector('.sbn-sync-panel');
            
            // State
            this.isPlaying = false;
            this.duration = 0;
            this.currentTime = 0;
            this.api = null; // AlphaTab API
            this.player = null; // YouTube player
            this.syncPoints = this.parseSyncPoints(this.config.sync || '');
            
            // Sync mode state
            this.syncMode = false;
            this.currentBar = 1;
            this.totalBars = 0;
            
            this.init();
        }

        init() {
            this.initAlphaTab();
            
            if (this.config.youtube && this.videoContainer) {
                this.initYouTube();
            }
            
            this.bindEvents();
            this.initSyncMode();
            this.initSettingsMode();
        }

        // --------------------------------------------------------------------
        // ALPHATAB INITIALIZATION
        // --------------------------------------------------------------------

        initAlphaTab() {
            if (!this.sheetContainer) return;

            // Ensure container is visible before init
            const rect = this.sheetContainer.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) {
                console.log('[SBN Big] Container not visible yet, waiting...');
                setTimeout(() => this.initAlphaTab(), 100);
                return;
            }

            console.log('[SBN Big] Initializing AlphaTab with file:', this.config.file);
            
            // Get display options from config (with defaults)
            const staveProfile = this.config.staveProfile || 'tab';
            const rhythmMode = this.config.rhythmMode || 'showWithBars';
            const layoutMode = this.config.layoutMode || 'horizontal';
            const barsPerRow = this.config.barsPerRow || 4;

            // Settings based on original working version
            const settings = {
                file: this.config.file,
                core: {
                    engine: 'svg',
                    fontDirectory: 'https://cdn.jsdelivr.net/npm/@coderline/alphatab@latest/dist/font/',
                    logLevel: 0,
                    useWorkers: false,
                    tracks: [0] // Use first track
                },
                display: {
                    scale: 1.0,
                    layoutMode: layoutMode,
                    staveProfile: staveProfile,
                    barsPerRow: barsPerRow,
                    stretchForce: 0.8,
                    justifyLastSystem: true, // Justify last system to fill the width
                    resources: {
                        barNumberColor: 'rgba(0, 0, 0, 0)'
                    }
                },
                notation: {
                    elements: {
                        scoreTitle: false,
                        scoreSubTitle: false,
                        scoreArtist: false,
                        scoreAlbum: false,
                        scoreWords: false,
                        scoreMusic: false,
                        scoreWordsAndMusic: false,
                        scoreCopyright: false,
                        guitarTuning: false,
                        trackNames: false
                    },
                    notationMode: 'GuitarPro',
                    rhythmMode: rhythmMode
                },
                player: {
                    enablePlayer: true,
                    enableCursor: true,
                    enableUserInteraction: true,
                    soundFont: 'https://cdn.jsdelivr.net/npm/@coderline/alphatab@latest/dist/soundfont/sonivox.sf2',
                    scrollElement: this.sheetContainer,
                    scrollMode: layoutMode === 'horizontal' ? 'continuous' : 'off'
                }
            };
            
            console.log('[SBN Big] Using staveProfile:', staveProfile, 'rhythmMode:', rhythmMode, 'layout:', layoutMode, 'barsPerRow:', barsPerRow);
            
            try {
                this.api = new alphaTab.AlphaTabApi(this.sheetContainer, settings);
                console.log('[SBN Big] AlphaTab API created successfully');
                
                // Set classical guitar sound (MIDI program 25) after score loads
                this.api.scoreLoaded.on((score) => {
                    if (score.tracks && score.tracks.length > 0) {
                        score.tracks[0].playbackInfo.program = 25; // Classical/nylon guitar
                        console.log('[SBN Big] Set to Classical Guitar (program 25)');
                    }
                    this.onScoreLoaded(score);
                });
            } catch (error) {
                console.error('[SBN Big] Failed to create AlphaTab API:', error);
                this.sheetContainer.innerHTML = '<div style="color: #e85d3b; padding: 20px;">Error loading sheet music: ' + error.message + '</div>';
                return;
            }

            // Event: Score loaded
            this.api.scoreLoaded.on((score) => {
                this.onScoreLoaded(score);
            });

            // Event: Player ready
            this.api.playerReady.on(() => {
                this.onPlayerReady();
            });

            // Event: Player state changed
            this.api.playerStateChanged.on((args) => {
                this.onPlayerStateChanged(args);
            });

            // Event: Player position changed
            this.api.playerPositionChanged.on((args) => {
                this.onPositionChanged(args);
            });

            // Event: Beat/bar clicked
            this.api.beatMouseDown.on((beat, originalEvent) => {
                this.onBeatClicked(beat, originalEvent);
            });
        }

        onScoreLoaded(score) {
            if (this.tempoDisplay && score.tempo) {
                this.tempoDisplay.textContent = Math.round(score.tempo);
            }
            
            // Get total bar count
            this.totalBars = score.masterBars.length;
            this.updateSyncPanelInfo();
        }

        onPlayerReady() {
            this.duration = this.api.player.playbackRange?.endTick || 0;
            this.updateTimeDisplay();
        }

        onPlayerStateChanged(args) {
            this.isPlaying = args.state === 1; // 1 = Playing
            this.updatePlayButton();
            
            // If no YouTube, AlphaTab handles everything
            if (!this.player) {
                return;
            }
            
            // Sync with YouTube
            if (this.isPlaying && this.player) {
                // Don't auto-play YouTube - user controls via main play button
            }
        }

        onPositionChanged(args) {
            // Track current bar for sync mode
            if (this.api?.score) {
                const ticksPerBar = this.api.score.masterBars[0]?.calculateDuration() || 3840;
                this.currentBar = Math.floor(args.currentTick / ticksPerBar) + 1;
                this.updateSyncPanelInfo();
            }
            
            if (!this.player) {
                // No YouTube - update progress from AlphaTab
                this.currentTime = args.currentTime;
                this.duration = args.endTime;
                this.updateProgress();
                this.updateTimeDisplay();
            }
        }

        onBeatClicked(beat, originalEvent) {
            originalEvent.preventDefault();
            
            // Get the tick position of this beat
            const tickPosition = beat.playbackStart;
            
            // Seek AlphaTab to this position
            this.api.tickPosition = tickPosition;
            
            // If YouTube is present, also seek video
            if (this.player && this.syncPoints.length >= 2) {
                const videoTime = this.tickToVideoTime(tickPosition);
                this.player.seekTo(videoTime, true);
            }
        }

        // --------------------------------------------------------------------
        // YOUTUBE INITIALIZATION
        // --------------------------------------------------------------------

        initYouTube() {
            // Wait for YouTube API to be ready
            if (typeof YT === 'undefined' || typeof YT.Player === 'undefined') {
                window.onYouTubeIframeAPIReady = () => this.createYouTubePlayer();
                
                // Load API if not already loading
                if (!document.querySelector('script[src*="youtube.com/iframe_api"]')) {
                    const tag = document.createElement('script');
                    tag.src = 'https://www.youtube.com/iframe_api';
                    document.head.appendChild(tag);
                }
            } else {
                this.createYouTubePlayer();
            }
        }

        createYouTubePlayer() {
            const videoId = this.config.youtube;
            const startTime = parseInt(this.config.start) || 0;
            
            // Create placeholder div for YouTube
            const placeholder = document.createElement('div');
            placeholder.id = 'yt-' + Math.random().toString(36).substr(2, 9);
            this.videoContainer.innerHTML = '';
            this.videoContainer.appendChild(placeholder);

            this.player = new YT.Player(placeholder.id, {
                videoId: videoId,
                playerVars: {
                    start: startTime,
                    controls: 0,
                    modestbranding: 1,
                    rel: 0,
                    fs: 0
                },
                events: {
                    onReady: (e) => this.onYouTubeReady(e),
                    onStateChange: (e) => this.onYouTubeStateChange(e)
                }
            });
        }

        onYouTubeReady(event) {
            this.duration = this.player.getDuration();
            this.updateTimeDisplay();
            
            // Start sync loop
            this.startSyncLoop();
        }

        onYouTubeStateChange(event) {
            const state = event.data;
            
            if (state === YT.PlayerState.PLAYING) {
                this.isPlaying = true;
                this.updatePlayButton();
            } else if (state === YT.PlayerState.PAUSED || state === YT.PlayerState.ENDED) {
                this.isPlaying = false;
                this.updatePlayButton();
            }
        }

        startSyncLoop() {
            const sync = () => {
                if (this.player && typeof this.player.getCurrentTime === 'function') {
                    this.currentTime = this.player.getCurrentTime();
                    this.updateProgress();
                    this.updateTimeDisplay();
                    
                    // Sync AlphaTab cursor to video position
                    if (this.isPlaying && this.syncPoints.length >= 2) {
                        const tickPosition = this.videoTimeToTick(this.currentTime);
                        // Only update if AlphaTab isn't playing its own audio
                        if (this.api && !this.api.player.isPlaying) {
                            this.api.tickPosition = tickPosition;
                        }
                    }
                }
                requestAnimationFrame(sync);
            };
            requestAnimationFrame(sync);
        }

        // --------------------------------------------------------------------
        // SYNC POINT LOGIC
        // --------------------------------------------------------------------

        parseSyncPoints(syncString) {
            // Format: "0:00=1,0:45=17,1:30=33" (time=bar)
            if (!syncString) return [];
            
            const points = [];
            const pairs = syncString.split(',');
            
            pairs.forEach(pair => {
                const [timeStr, barStr] = pair.split('=');
                if (timeStr && barStr) {
                    const time = this.parseTime(timeStr);
                    const bar = parseInt(barStr);
                    if (!isNaN(time) && !isNaN(bar)) {
                        points.push({ time, bar });
                    }
                }
            });
            
            // Sort by time
            points.sort((a, b) => a.time - b.time);
            
            return points;
        }

        parseTime(timeStr) {
            // Parse "0:45" or "1:30" format
            const parts = timeStr.trim().split(':');
            if (parts.length === 2) {
                const minutes = parseInt(parts[0]);
                const seconds = parseInt(parts[1]);
                return minutes * 60 + seconds;
            }
            return parseFloat(timeStr);
        }

        videoTimeToTick(videoTime) {
            // Interpolate between sync points to find tick position
            if (this.syncPoints.length < 2 || !this.api?.score) return 0;
            
            const score = this.api.score;
            const ticksPerBar = score.masterBars[0]?.calculateDuration() || 3840;
            
            // Find surrounding sync points
            let prevPoint = this.syncPoints[0];
            let nextPoint = this.syncPoints[this.syncPoints.length - 1];
            
            for (let i = 0; i < this.syncPoints.length - 1; i++) {
                if (videoTime >= this.syncPoints[i].time && videoTime < this.syncPoints[i + 1].time) {
                    prevPoint = this.syncPoints[i];
                    nextPoint = this.syncPoints[i + 1];
                    break;
                }
            }
            
            // Interpolate
            const timeDelta = nextPoint.time - prevPoint.time;
            const barDelta = nextPoint.bar - prevPoint.bar;
            
            if (timeDelta === 0) return (prevPoint.bar - 1) * ticksPerBar;
            
            const progress = (videoTime - prevPoint.time) / timeDelta;
            const currentBar = prevPoint.bar + (barDelta * progress);
            
            return Math.round((currentBar - 1) * ticksPerBar);
        }

        tickToVideoTime(tick) {
            // Convert tick position back to video time
            if (this.syncPoints.length < 2 || !this.api?.score) return 0;
            
            const score = this.api.score;
            const ticksPerBar = score.masterBars[0]?.calculateDuration() || 3840;
            const currentBar = (tick / ticksPerBar) + 1;
            
            // Find surrounding sync points
            let prevPoint = this.syncPoints[0];
            let nextPoint = this.syncPoints[this.syncPoints.length - 1];
            
            for (let i = 0; i < this.syncPoints.length - 1; i++) {
                if (currentBar >= this.syncPoints[i].bar && currentBar < this.syncPoints[i + 1].bar) {
                    prevPoint = this.syncPoints[i];
                    nextPoint = this.syncPoints[i + 1];
                    break;
                }
            }
            
            // Interpolate
            const barDelta = nextPoint.bar - prevPoint.bar;
            const timeDelta = nextPoint.time - prevPoint.time;
            
            if (barDelta === 0) return prevPoint.time;
            
            const progress = (currentBar - prevPoint.bar) / barDelta;
            return prevPoint.time + (timeDelta * progress);
        }

        // --------------------------------------------------------------------
        // UI UPDATES
        // --------------------------------------------------------------------

        bindEvents() {
            // Play button
            if (this.playBtn) {
                this.playBtn.addEventListener('click', () => this.togglePlay());
            }
            
            // Progress bar click
            if (this.progressBar) {
                this.progressBar.addEventListener('click', (e) => this.onProgressClick(e));
            }
        }

        togglePlay() {
            if (this.isPlaying) {
                this.pause();
            } else {
                this.play();
            }
        }

        play() {
            if (this.player) {
                // YouTube present - play video, sync sheet
                this.player.playVideo();
            } else if (this.api) {
                // No YouTube - play AlphaTab audio
                this.api.play();
            }
            this.isPlaying = true;
            this.updatePlayButton();
        }

        pause() {
            if (this.player) {
                this.player.pauseVideo();
            }
            if (this.api) {
                this.api.pause();
            }
            this.isPlaying = false;
            this.updatePlayButton();
        }

        onProgressClick(e) {
            const rect = this.progressBar.getBoundingClientRect();
            const percent = (e.clientX - rect.left) / rect.width;
            const seekTime = this.duration * percent;
            
            if (this.player) {
                this.player.seekTo(seekTime, true);
            } else if (this.api) {
                // Calculate tick position
                const tickPosition = (this.api.player.playbackRange?.endTick || 0) * percent;
                this.api.tickPosition = tickPosition;
            }
        }

        updatePlayButton() {
            if (!this.playBtn) return;
            
            if (this.isPlaying) {
                this.playBtn.innerHTML = '⏸';
                this.playBtn.classList.add('playing');
            } else {
                this.playBtn.innerHTML = '▶';
                this.playBtn.classList.remove('playing');
            }
        }

        updateProgress() {
            if (!this.progressFill || !this.duration) return;
            
            const percent = (this.currentTime / this.duration) * 100;
            this.progressFill.style.width = percent + '%';
        }

        updateTimeDisplay() {
            if (!this.timeDisplay) return;
            
            const current = this.formatTime(this.currentTime);
            const total = this.formatTime(this.duration);
            this.timeDisplay.textContent = `${current} / ${total}`;
        }

        formatTime(seconds) {
            if (!seconds || isNaN(seconds)) return '0:00';
            
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        }

        // --------------------------------------------------------------------
        // SYNC MODE (Admin Only - Tap to Sync)
        // --------------------------------------------------------------------

        initSyncMode() {
            // Only init if sync mode elements exist (admin only)
            if (!this.syncModeBtn || !this.syncPanel) return;
            
            // Sync mode button
            this.syncModeBtn.addEventListener('click', () => this.toggleSyncMode());
            
            // Keyboard handler for tap-to-sync
            this.keyHandler = (e) => this.onSyncKey(e);
            
            // Clear all button
            const clearBtn = this.syncPanel.querySelector('.sbn-sync-clear-btn');
            if (clearBtn) {
                clearBtn.addEventListener('click', () => this.clearSyncPoints());
            }
            
            // Save button
            const saveBtn = this.syncPanel.querySelector('.sbn-sync-save-btn');
            if (saveBtn) {
                saveBtn.addEventListener('click', () => this.saveSyncPoints());
            }
            
            // Undo button
            const undoBtn = this.syncPanel.querySelector('.sbn-sync-undo-btn');
            if (undoBtn) {
                undoBtn.addEventListener('click', () => this.undoLastSyncPoint());
            }
            
            // Render existing sync points
            this.renderSyncPointsList();
        }

        toggleSyncMode() {
            this.syncMode = !this.syncMode;
            
            if (this.syncMode) {
                this.syncModeBtn.classList.add('active');
                this.syncPanel.classList.add('visible');
                document.addEventListener('keydown', this.keyHandler);
                this.container.classList.add('sync-mode-active');
            } else {
                this.syncModeBtn.classList.remove('active');
                this.syncPanel.classList.remove('visible');
                document.removeEventListener('keydown', this.keyHandler);
                this.container.classList.remove('sync-mode-active');
            }
        }

        onSyncKey(e) {
            // Spacebar or 'S' key to add sync point
            if (e.code === 'Space' || e.code === 'KeyS') {
                e.preventDefault();
                this.addSyncPointAtCurrentPosition();
            }
            // 'Z' to undo last
            if (e.code === 'KeyZ' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                this.undoLastSyncPoint();
            }
        }

        addSyncPointAtCurrentPosition() {
            if (!this.isPlaying && !this.currentTime) {
                this.showSyncMessage('Start playback first, then tap to sync', 'warning');
                return;
            }
            
            const videoTime = this.player ? this.player.getCurrentTime() : this.currentTime;
            const bar = this.currentBar;
            
            // Check if bar already has a sync point
            const existingIndex = this.syncPoints.findIndex(p => p.bar === bar);
            if (existingIndex >= 0) {
                // Update existing
                this.syncPoints[existingIndex].time = videoTime;
                this.showSyncMessage(`Updated Bar ${bar} → ${this.formatTime(videoTime)}`, 'update');
            } else {
                // Add new
                this.syncPoints.push({ time: videoTime, bar: bar });
                this.syncPoints.sort((a, b) => a.time - b.time);
                this.showSyncMessage(`Added Bar ${bar} → ${this.formatTime(videoTime)}`, 'success');
            }
            
            this.renderSyncPointsList();
            this.updateSyncDataOutput();
        }

        undoLastSyncPoint() {
            if (this.syncPoints.length === 0) return;
            
            const removed = this.syncPoints.pop();
            this.showSyncMessage(`Removed Bar ${removed.bar}`, 'undo');
            this.renderSyncPointsList();
            this.updateSyncDataOutput();
        }

        clearSyncPoints() {
            if (this.syncPoints.length === 0) return;
            
            if (confirm('Clear all sync points?')) {
                this.syncPoints = [];
                this.renderSyncPointsList();
                this.updateSyncDataOutput();
                this.showSyncMessage('All sync points cleared', 'warning');
            }
        }

        renderSyncPointsList() {
            const list = this.syncPanel?.querySelector('.sbn-sync-points-list');
            if (!list) return;
            
            if (this.syncPoints.length === 0) {
                list.innerHTML = '<div class="sbn-sync-empty">No sync points yet.<br>Play video and press <kbd>Space</kbd> or <kbd>S</kbd> to sync.</div>';
                return;
            }
            
            list.innerHTML = this.syncPoints.map((point, index) => `
                <div class="sbn-sync-point-item" data-index="${index}">
                    <span class="sbn-sync-point-bar">Bar ${point.bar}</span>
                    <span class="sbn-sync-point-arrow">→</span>
                    <span class="sbn-sync-point-time">${this.formatTime(point.time)}</span>
                    <button type="button" class="sbn-sync-point-remove" data-index="${index}">×</button>
                </div>
            `).join('');
            
            // Bind remove buttons
            list.querySelectorAll('.sbn-sync-point-remove').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const index = parseInt(e.target.dataset.index);
                    this.syncPoints.splice(index, 1);
                    this.renderSyncPointsList();
                    this.updateSyncDataOutput();
                });
            });
        }

        updateSyncPanelInfo() {
            const barInfo = this.syncPanel?.querySelector('.sbn-sync-current-bar');
            if (barInfo) {
                barInfo.textContent = `Bar ${this.currentBar} / ${this.totalBars}`;
            }
        }

        updateSyncDataOutput() {
            const output = this.syncPanel?.querySelector('.sbn-sync-data-output');
            if (output) {
                const syncString = this.syncPoints.map(p => `${this.formatTime(p.time)}=${p.bar}`).join(',');
                output.value = syncString;
            }
        }

        showSyncMessage(text, type = 'info') {
            const msgEl = this.syncPanel?.querySelector('.sbn-sync-message');
            if (!msgEl) return;
            
            msgEl.textContent = text;
            msgEl.className = 'sbn-sync-message ' + type;
            msgEl.classList.add('visible');
            
            setTimeout(() => {
                msgEl.classList.remove('visible');
            }, 2000);
        }

        async saveSyncPoints() {
            if (!this.config.lessonId) {
                this.showSyncMessage('Cannot save: No lesson ID', 'error');
                return;
            }
            
            const syncString = this.syncPoints.map(p => `${this.formatTime(p.time)}=${p.bar}`).join(',');
            
            try {
                const response = await fetch(window.sbnSheetPlayer?.ajaxUrl || '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'sbn_save_sync_data',
                        lesson_id: this.config.lessonId,
                        sync_data: syncString,
                        nonce: window.sbnSheetPlayer?.nonce || ''
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    this.showSyncMessage('Sync points saved!', 'success');
                } else {
                    this.showSyncMessage('Save failed: ' + (result.data || 'Unknown error'), 'error');
                }
            } catch (error) {
                this.showSyncMessage('Save failed: ' + error.message, 'error');
            }
        }

        // --------------------------------------------------------------------
        // SETTINGS MODE (Admin)
        // --------------------------------------------------------------------

        initSettingsMode() {
            // Only init if settings button exists (admin only)
            if (!this.settingsModeBtn || !this.syncPanel) return;
            
            // Settings button toggles panel
            this.settingsModeBtn.addEventListener('click', () => this.toggleSettingsPanel());
            
            // Apply settings button
            const applyBtn = this.syncPanel.querySelector('.sbn-apply-settings-btn');
            if (applyBtn) {
                applyBtn.addEventListener('click', () => this.applySettings());
            }
            
            // Save settings button
            const saveSettingsBtn = this.syncPanel.querySelector('.sbn-save-settings-btn');
            if (saveSettingsBtn) {
                saveSettingsBtn.addEventListener('click', () => this.saveSettings());
            }
        }

        toggleSettingsPanel() {
            const isVisible = this.syncPanel.classList.contains('visible');
            
            if (isVisible) {
                this.syncPanel.classList.remove('visible');
            } else {
                this.syncPanel.classList.add('visible');
            }
        }

        getSettingsFromPanel() {
            const showTab = this.syncPanel.querySelector('.sbn-setting-show-tab')?.checked ?? true;
            const showNotation = this.syncPanel.querySelector('.sbn-setting-show-notation')?.checked ?? false;
            const rhythmMode = this.syncPanel.querySelector('.sbn-setting-rhythm')?.value || 'showWithBars';
            const layoutMode = this.syncPanel.querySelector('.sbn-setting-layout')?.value || 'horizontal';
            const barsPerRow = parseInt(this.syncPanel.querySelector('.sbn-setting-bars')?.value) || 4;
            
            // Determine stave profile
            let staveProfile = 'tab';
            if (showTab && showNotation) {
                staveProfile = 'scoreTab';
            } else if (showNotation) {
                staveProfile = 'score';
            }
            
            return { showTab, showNotation, staveProfile, rhythmMode, layoutMode, barsPerRow };
        }

        applySettings() {
            const settings = this.getSettingsFromPanel();
            
            console.log('[SBN Big] Applying new settings:', settings);
            
            // Update config
            this.config.staveProfile = settings.staveProfile;
            this.config.rhythmMode = settings.rhythmMode;
            this.config.layoutMode = settings.layoutMode;
            this.config.barsPerRow = settings.barsPerRow;
            this.config.showTab = settings.showTab;
            this.config.showNotation = settings.showNotation;
            
            // Destroy existing AlphaTab and re-init
            if (this.api) {
                try {
                    this.api.destroy();
                } catch (e) {
                    console.warn('[SBN Big] Error destroying API:', e);
                }
                this.api = null;
            }
            
            // Clear the container
            this.sheetContainer.innerHTML = '';
            
            // Re-initialize
            this.initAlphaTab();
            
            this.showSyncMessage('Settings applied! Re-rendering...', 'success');
        }

        async saveSettings() {
            if (!this.config.lessonId) {
                this.showSyncMessage('Cannot save: No lesson ID', 'error');
                return;
            }
            
            const settings = this.getSettingsFromPanel();
            
            try {
                const response = await fetch(window.sbnSheetPlayer?.ajaxUrl || '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'sbn_save_display_settings',
                        lesson_id: this.config.lessonId,
                        show_tab: settings.showTab ? '1' : '0',
                        show_notation: settings.showNotation ? '1' : '0',
                        rhythm_mode: settings.rhythmMode,
                        layout_mode: settings.layoutMode,
                        bars_per_row: settings.barsPerRow,
                        nonce: window.sbnSheetPlayer?.nonce || ''
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    this.showSyncMessage('Settings saved!', 'success');
                } else {
                    this.showSyncMessage('Save failed: ' + (result.data || 'Unknown error'), 'error');
                }
            } catch (error) {
                this.showSyncMessage('Save failed: ' + error.message, 'error');
            }
        }
    }

    // ========================================================================
    // MINI PLAYER CLASS
    // ========================================================================

    class SBNSheetMini {
        constructor(container) {
            this.container = container;
            this.config = JSON.parse(container.dataset.config || '{}');
            
            console.log('[SBN Mini] Initializing with config:', this.config);
            
            this.playBtn = container.querySelector('.sbn-mini-play-btn');
            this.toggleBtn = container.querySelector('.sbn-mini-toggle');
            this.sheetContainer = container.querySelector('.sbn-mini-sheet');
            
            this.api = null;
            this.isPlaying = false;
            this.showingTabs = this.config.showTab !== false; // Default to tabs
            
            this.init();
        }

        init() {
            this.initAlphaTab();
            this.bindEvents();
            this.updateToggleState();
        }

        updateToggleState() {
            if (!this.toggleBtn) return;
            
            if (this.showingTabs) {
                this.toggleBtn.classList.remove('active');
            } else {
                this.toggleBtn.classList.add('active');
            }
        }

        toggleStaveProfile() {
            if (!this.api || !this.sheetContainer) return;
            
            this.showingTabs = !this.showingTabs;
            
            // Determine new stave profile
            let newProfile;
            if (this.showingTabs && this.config.showNotation) {
                newProfile = 'scoreTab'; // Show both
            } else if (this.showingTabs) {
                newProfile = 'tab'; // Tabs only
            } else {
                newProfile = 'score'; // Notation only
            }
            
            console.log('[SBN Mini] Switching to stave profile:', newProfile);
            
            // Store current state
            const wasPlaying = this.isPlaying;
            const currentFile = this.config.file;
            
            // Stop playback if playing
            if (wasPlaying) {
                this.api.stop();
            }
            
            // Destroy current API instance
            if (this.api) {
                try {
                    this.api.destroy();
                } catch (e) {
                    console.log('[SBN Mini] API destroy not available, continuing...');
                }
                this.api = null;
            }
            
            // Clear container
            this.sheetContainer.innerHTML = '';
            
            // Update config with new profile
            this.config.staveProfile = newProfile;
            
            // Re-initialize with new settings
            setTimeout(() => {
                this.initAlphaTab();
                this.updateToggleState();
            }, 100);
        }

        initAlphaTab() {
            if (!this.sheetContainer) {
                console.error('[SBN Mini] Sheet container not found');
                return;
            }
            
            if (typeof alphaTab === 'undefined') {
                console.error('[SBN Mini] AlphaTab library not loaded');
                this.showError('Sheet music library not loaded');
                return;
            }
            
            if (!this.config.file) {
                console.error('[SBN Mini] No file specified');
                this.showError('No file specified');
                return;
            }
            
            // Ensure container is visible before init
            const rect = this.sheetContainer.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) {
                console.log('[SBN Mini] Container not visible yet, waiting...');
                setTimeout(() => this.initAlphaTab(), 100);
                return;
            }
            
            console.log('[SBN Mini] Loading file:', this.config.file);
            
            // Get display options from config (with defaults)
            const staveProfile = this.config.staveProfile || 'tab';
            const rhythmMode = this.config.rhythmMode || 'showWithBars';
            
            // CRITICAL: Use container width instead of window width
            // Mini player sits inside course player with sidebar, so available space is much less
            const containerWidth = this.container.offsetWidth || window.innerWidth;
            let layoutMode = 'horizontal';
            let barsPerRow = 4;
            let scale = 0.9;
            
            console.log('[SBN Mini] Container width:', containerWidth, 'Window width:', window.innerWidth);
            
            // Adjusted breakpoints for container inside course player
            if (containerWidth <= 400) {
                layoutMode = 'page';  // Page mode for very narrow - stacks vertically
                barsPerRow = 1;       // 1 bar per line
                scale = 0.65;         // Smaller scale for very narrow
            } else if (containerWidth <= 550) {
                layoutMode = 'page';  // Page mode for narrow
                barsPerRow = 2;       // 2 bars per line
                scale = 0.75;
            } else if (containerWidth <= 750) {
                layoutMode = 'horizontal'; // Horizontal for medium
                barsPerRow = 2;
                scale = 0.8;
            } else if (containerWidth <= 950) {
                layoutMode = 'horizontal'; // Horizontal for medium-large
                barsPerRow = 3;
                scale = 0.85;
            } else {
                layoutMode = 'horizontal'; // Horizontal for wide
                barsPerRow = 4;
                scale = 0.9;
            }
            
            console.log('[SBN Mini] Container width:', containerWidth, 'layoutMode:', layoutMode, 'barsPerRow:', barsPerRow, 'scale:', scale);

            // Settings based on original working version
            const settings = {
                file: this.config.file,
                core: {
                    engine: 'svg',
                    fontDirectory: 'https://cdn.jsdelivr.net/npm/@coderline/alphatab@latest/dist/font/',
                    logLevel: 0,
                    useWorkers: false
                },
                display: {
                    scale: scale,
                    layoutMode: layoutMode,
                    barsPerRow: barsPerRow,
                    staveProfile: staveProfile,
                    stretchForce: 0.8,
                    justifyLastSystem: true, // Justify last system to fill the width
                    padding: [0, 0, 20, 0], // Added bottom padding for two-line layouts
                    resources: {
                        barNumberColor: 'rgba(0, 0, 0, 0)',
                        scoreTitleFont: '0px Arial',
                        scoreSubTitleFont: '0px Arial',
                        verticalPadding: 5, // Add some vertical spacing between systems
                        firstSystemMargin: 0,
                        systemsLayoutMargin: 5, // Add spacing between stave systems
                        staffLineThickness: 1
                    }
                },
                notation: {
                    elements: {
                        scoreTitle: false,
                        scoreSubTitle: false,
                        scoreArtist: false,
                        scoreAlbum: false,
                        scoreWords: false,
                        scoreMusic: false,
                        scoreWordsAndMusic: false,
                        scoreCopyright: false,
                        guitarTuning: false,
                        trackNames: false
                    },
                    notationMode: 'GuitarPro',
                    rhythmMode: rhythmMode,
                    notationStaffTop: 0 // Minimize top spacing for notation staff
                },
                player: {
                    enablePlayer: true,
                    enableCursor: true,
                    enableUserInteraction: false,
                    soundFont: 'https://cdn.jsdelivr.net/npm/@coderline/alphatab@latest/dist/soundfont/sonivox.sf2',
                    scrollElement: this.sheetContainer,
                    scrollMode: 'off'
                }
            };
            
            console.log('[SBN Mini] Using staveProfile:', staveProfile, 'rhythmMode:', rhythmMode);

            try {
                this.api = new alphaTab.AlphaTabApi(this.sheetContainer, settings);
                console.log('[SBN Mini] AlphaTab API created successfully');
                
                // Error event
                this.api.error.on((error) => {
                    console.error('[SBN Mini] AlphaTab error:', error);
                    this.showError('Error loading file: ' + (error.message || error));
                });
                
                // Score loaded event - set classical guitar
                this.api.scoreLoaded.on((score) => {
                    console.log('[SBN Mini] Score loaded:', score.title, '- Bars:', score.masterBars.length);
                    if (score.tracks && score.tracks.length > 0) {
                        score.tracks[0].playbackInfo.program = 25; // Classical/nylon guitar
                        console.log('[SBN Mini] Set to Classical Guitar (program 25)');
                    }
                });
                
                // Render started
                this.api.renderStarted.on(() => {
                    console.log('[SBN Mini] Render started');
                });
                
                // Render finished
                this.api.renderFinished.on(() => {
                    console.log('[SBN Mini] Render finished');
                    
                    // CRITICAL FIX: Adjust container height to match actual rendered content
                    // Use requestAnimationFrame to avoid flicker
                    requestAnimationFrame(() => {
                        try {
                            // Find the actual rendered SVG
                            const svg = this.sheetContainer.querySelector('svg');
                            if (svg) {
                                const svgHeight = svg.getBoundingClientRect().height;
                                const currentHeight = this.sheetContainer.offsetHeight;
                                
                                console.log('[SBN Mini] SVG height:', svgHeight, 'Container height:', currentHeight);
                                
                                // If SVG is taller than container, adjust container
                                if (svgHeight > currentHeight + 5) { // +5px tolerance
                                    const newHeight = Math.ceil(svgHeight) + 10; // Add 10px buffer
                                    this.sheetContainer.style.minHeight = newHeight + 'px';
                                    console.log('[SBN Mini] Adjusted container min-height to:', newHeight);
                                }
                            }
                        } catch (error) {
                            console.warn('[SBN Mini] Could not adjust height:', error);
                        }
                    });
                });
                
                // Player ready
                this.api.playerReady.on(() => {
                    console.log('[SBN Mini] Player ready');
                });

                // Player state changed
                this.api.playerStateChanged.on((args) => {
                    console.log('[SBN Mini] Player state:', args.state);
                    this.isPlaying = args.state === 1;
                    this.updatePlayButton();
                    
                    if (args.state === 0) {
                        this.api.tickPosition = 0;
                    }
                });
                
            } catch (error) {
                console.error('[SBN Mini] Failed to initialize AlphaTab:', error);
                this.showError('Failed to initialize: ' + error.message);
            }
        }
        
        showError(message) {
            if (this.sheetContainer) {
                this.sheetContainer.innerHTML = '<div style="color: #e85d3b; font-size: 12px; padding: 10px;">' + message + '</div>';
            }
        }

        bindEvents() {
            if (this.playBtn) {
                this.playBtn.addEventListener('click', () => this.togglePlay());
            }
            
            if (this.toggleBtn) {
                this.toggleBtn.addEventListener('click', () => this.toggleStaveProfile());
                this.toggleBtn.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        this.toggleStaveProfile();
                    }
                });
            }
            
            // Add ResizeObserver to handle container size changes
            // This is critical for responsive behavior inside course player with collapsible sidebar
            if ('ResizeObserver' in window) {
                this.isInitialRender = true; // Flag to ignore first resize
                
                this.resizeObserver = new ResizeObserver((entries) => {
                    for (const entry of entries) {
                        const newWidth = entry.contentRect.width;
                        
                        // Ignore initial render
                        if (this.isInitialRender) {
                            this.lastWidth = newWidth;
                            this.isInitialRender = false;
                            console.log('[SBN Mini] Initial render, width:', newWidth);
                            return;
                        }
                        
                        // Only re-render if width changed significantly (> 50px)
                        if (Math.abs(newWidth - (this.lastWidth || 0)) > 50) {
                            console.log('[SBN Mini] Container resized to:', newWidth);
                            this.lastWidth = newWidth;
                            
                            // Debounce the re-render
                            clearTimeout(this.resizeTimeout);
                            this.resizeTimeout = setTimeout(() => {
                                this.handleResize();
                            }, 300);
                        }
                    }
                });
                
                this.resizeObserver.observe(this.container);
            }
        }
        
        handleResize() {
            console.log('[SBN Mini] Handling resize - re-rendering sheet');
            
            // Store current state
            const wasPlaying = this.isPlaying;
            const currentTickPosition = this.api?.tickPosition || 0;
            
            // Stop playback if playing
            if (wasPlaying && this.api) {
                this.api.stop();
            }
            
            // Destroy and re-initialize
            if (this.api) {
                try {
                    this.api.destroy();
                } catch (e) {
                    console.log('[SBN Mini] API destroy during resize not available');
                }
                this.api = null;
            }
            
            this.sheetContainer.innerHTML = '';
            
            // Re-initialize with new container width
            setTimeout(() => {
                this.initAlphaTab();
                
                // Restore playback state if needed
                if (wasPlaying && this.api) {
                    setTimeout(() => {
                        this.api.tickPosition = currentTickPosition;
                        this.api.play();
                    }, 500);
                }
            }, 100);
        }

        togglePlay() {
            if (this.isPlaying) {
                this.stop();
            } else {
                this.play();
            }
        }

        play() {
            if (this.api) {
                this.api.tickPosition = 0; // Start from beginning
                this.api.play();
            }
        }

        stop() {
            if (this.api) {
                this.api.stop();
                this.api.tickPosition = 0;
            }
            this.isPlaying = false;
            this.updatePlayButton();
        }

        updatePlayButton() {
            if (!this.playBtn) return;
            
            if (this.isPlaying) {
                this.playBtn.classList.add('playing');
                this.playBtn.setAttribute('aria-label', 'Stop');
            } else {
                this.playBtn.classList.remove('playing');
                this.playBtn.setAttribute('aria-label', 'Play');
            }
        }
    }

    // ========================================================================
    // TEXT SNIPPET CLASS (AlphaTex Shortcodes)
    // ========================================================================

    class SBNTexSnippet {
        constructor(wrapper) {
            // wrapper is the .sbn-alphatex-container element
            this.wrapper = wrapper;
            this.container = wrapper.querySelector('.sbn-alphatex-content') || wrapper;
            this.playBtn = wrapper.querySelector('.sbn-alphatex-play-btn');
            this.api = null;
            this.isPlaying = false;
            this.init();
        }

        init() {
            try {
                // Get raw text and clean it
                let texContent = this.container.innerText || this.container.textContent;
                
                // Strip HTML tags (like <br> or </div>)
                texContent = texContent.replace(/<\/?[^>]+(>|$)/g, ""); 
                
                // Fix quotes
                texContent = texContent.replace(/&quot;/g, '"').replace(/&#039;/g, "'");
                
                // Trim whitespace
                texContent = texContent.trim();

                // Get display option from data attribute (default: tab)
                const display = this.wrapper.dataset.display || 'tab';
                let staveProfile = 'tab';
                if (display === 'stave' || display === 'notation' || display === 'standard') {
                    staveProfile = 'score';
                } else if (display === 'both') {
                    staveProfile = 'scoreTab';
                }

                // Check if player is enabled (has play button)
                const enablePlayer = !!this.playBtn;

                // Initialize AlphaTab
                this.api = new alphaTab.AlphaTabApi(this.container, {
                    core: {
                        tex: texContent,
                        engine: 'svg',
                        logLevel: 0,
                        useWorkers: false
                    },
                    display: {
                        layoutMode: 'horizontal',
                        staveProfile: staveProfile,
                        resources: {
                            barNumberColor: 'rgba(0, 0, 0, 0)'
                        }
                    },
                    notation: {
                        elements: {
                            scoreTitle: false,
                            scoreSubTitle: false,
                            scoreArtist: false,
                            scoreAlbum: false,
                            scoreWords: false,
                            scoreMusic: false,
                            scoreWordsAndMusic: false,
                            scoreCopyright: false,
                            guitarTuning: false,
                            trackNames: false,
                            effectDynamics: false
                        },
                        notationMode: 'GuitarPro',
                        rhythmMode: 'ShowWithBars'
                    },
                    player: {
                        enablePlayer: enablePlayer,
                        enableCursor: enablePlayer,
                        enableUserInteraction: enablePlayer,
                        soundFont: 'https://cdn.jsdelivr.net/npm/@coderline/alphatab@latest/dist/soundfont/sonivox.sf2',
                        scrollElement: this.container,
                        scrollMode: 'continuous'
                    }
                });

                // Setup play button if present
                if (this.playBtn) {
                    this.playBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        this.api.playPause();
                    });

                    this.api.playerStateChanged.on((args) => {
                        this.isPlaying = (args.state === 1);
                        this.playBtn.classList.toggle('playing', this.isPlaying);
                        this.playBtn.setAttribute('aria-label', this.isPlaying ? 'Pause' : 'Play');
                    });
                }

                // Mark initialized
                this.wrapper.dataset.initialized = 'true';

            } catch (e) {
                console.error('[SBN Snippet] Error:', e);
                this.container.innerHTML = '<div style="color: #e85d3b; padding: 10px;">Error loading tab: ' + e.message + '</div>';
            }
        }
    }

    // ========================================================================
    // INITIALIZATION
    // ========================================================================

    let initAttempts = 0;
    const maxAttempts = 50; // 5 seconds max

    function initSheetPlayers() {
        initAttempts++;
        
        // Unified safety check - wait for AlphaTab
        if (typeof alphaTab === 'undefined' || typeof alphaTab.AlphaTabApi === 'undefined') {
            if (initAttempts < maxAttempts) {
                setTimeout(initSheetPlayers, 100);
                return;
            } else {
                console.error('[SBN] AlphaTab failed to load after 5 seconds');
                document.querySelectorAll('.sbn-sheet-player .sbn-sheet-music, .sbn-sheet-mini .sbn-mini-sheet, .sbn-alphatex-container').forEach(el => {
                    if (!el.dataset.initialized) {
                        el.innerHTML = '<div style="color: #e85d3b; padding: 20px; text-align: center;">Sheet music library failed to load. Please refresh the page.</div>';
                    }
                });
                return;
            }
        }
        
        // Initialize everything in document scope
        initAllInScope(document);
    }

    // Helper to init all player types in a specific scope (for both initial load and Ajax)
    function initAllInScope(scope) {
        // A. Big Players
        scope.querySelectorAll('.sbn-sheet-player').forEach((el, index) => {
            if (!el.dataset.initialized) {
                try {
                    new SBNSheetPlayer(el);
                    el.dataset.initialized = 'true';
                    console.log('[SBN] Initialized big player', index);
                } catch (e) {
                    console.error('[SBN] Failed to init big player', index, e);
                }
            }
        });

        // B. Mini Players
        scope.querySelectorAll('.sbn-sheet-mini').forEach((el, index) => {
            if (!el.dataset.initialized) {
                try {
                    new SBNSheetMini(el);
                    el.dataset.initialized = 'true';
                } catch (e) {
                    console.error('[SBN] Failed to init mini player', index, e);
                }
            }
        });

        // C. Text Snippets (AlphaTex shortcodes)
        scope.querySelectorAll('.sbn-alphatex-container').forEach((el, index) => {
            if (!el.dataset.initialized) {
                try {
                    new SBNTexSnippet(el);
                } catch (e) {
                    console.error('[SBN] Failed to init text snippet', index, e);
                }
            }
        });
    }

    // Wait for DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => setTimeout(initSheetPlayers, 100));
    } else {
        setTimeout(initSheetPlayers, 100);
    }
    
    // Initialize sheet players within a specific container (for lazy loading / Ajax)
    function initSheetPlayersInContainer(container) {
        if (!container) return;
        
        // Safety check for Ajax calls
        if (typeof alphaTab === 'undefined' || typeof alphaTab.AlphaTabApi === 'undefined') {
            setTimeout(() => initSheetPlayersInContainer(container), 100);
            return;
        }
        
        initAllInScope(container);
    }

    // Export for external use
    window.SBNSheetPlayer = SBNSheetPlayer;
    window.SBNSheetMini = SBNSheetMini;
    window.SBNTexSnippet = SBNTexSnippet;
    window.initSBNSheetPlayers = initSheetPlayers;
    window.initSBNSheetPlayersInContainer = initSheetPlayersInContainer;

})();