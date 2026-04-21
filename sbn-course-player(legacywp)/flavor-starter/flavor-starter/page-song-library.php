<?php
/**
 * Template Name: Song Library
 * 
 * Browse and explore bossa nova, samba, and jazz standards
 * with interactive leadsheets and chord references.
 * 
 * @package SoulBossaNova
 */

get_header(); ?>

<div id="primary" class="content-area">
    <main id="main" class="site-main sbn-song-library-main">
        
        <div class="sbn-song-library">
            
            <!-- HEADER SECTION -->
            <header class="sbn-library-header">
                <h1 class="sbn-library-title">Song Library</h1>
                <p class="sbn-library-subtitle">Explore bossa nova, samba, and jazz standards</p>
                
                <!-- UNIFIED SEARCH BOX -->
                <div class="sbn-search-container">
                    <div class="sbn-search-box">
                        <svg class="sbn-search-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2"/>
                            <path d="M13 13L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <input 
                            type="text" 
                            id="sbn-song-search" 
                            class="sbn-search-input"
                            placeholder="Search songs, artists, chords..." 
                            autocomplete="off"
                        >
                        <button type="button" id="sbn-search-clear" class="sbn-clear-btn" style="display:none;">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M4 4L12 12M12 4L4 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                    
                    <!-- Search examples -->
                    <div class="sbn-search-examples">
                        <span class="sbn-search-try">Try: </span>
                        <button type="button" class="sbn-example-btn" data-example="Wave">Wave</button>
                        <button type="button" class="sbn-example-btn" data-example="Jobim">Jobim</button>
                        <button type="button" class="sbn-example-btn" data-example="Dm7">Dm7</button>
                        <button type="button" class="sbn-example-btn" data-example="bossa nova">bossa nova</button>
                    </div>
                </div>
            </header>
            
            <!-- SEARCH STATUS -->
            <div id="sbn-search-status" class="sbn-search-status"></div>
            
            <!-- SIDEBAR + GRID LAYOUT -->
            <div class="sbn-content-wrapper">
                
                <!-- FILTER SIDEBAR -->
                <aside id="sbn-filter-sidebar" class="sbn-filter-sidebar">
                    <div class="sbn-sidebar-header">
                        <h3>Filters</h3>
                    </div>
                    
                    <!-- Genre Filter -->
                    <div class="sbn-sidebar-section">
                        <h4 class="sbn-sidebar-label">Genre</h4>
                        <div class="sbn-sidebar-options" id="sbn-filter-genre-options">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                    
                    <!-- Key Filter -->
                    <div class="sbn-sidebar-section">
                        <h4 class="sbn-sidebar-label">Key</h4>
                        <div class="sbn-sidebar-options" id="sbn-filter-key-options">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                    
                    <!-- Rhythm Filter -->
                    <div class="sbn-sidebar-section">
                        <h4 class="sbn-sidebar-label">Rhythm</h4>
                        <div class="sbn-sidebar-options" id="sbn-filter-rhythm-options">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                    
                    <!-- Difficulty Filter -->
                    <div class="sbn-sidebar-section">
                        <h4 class="sbn-sidebar-label">Difficulty</h4>
                        <div class="sbn-sidebar-options" id="sbn-filter-difficulty-options">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                    
                    <!-- Popularity Filter -->
                    <div class="sbn-sidebar-section">
                        <h4 class="sbn-sidebar-label">Popularity</h4>
                        <div class="sbn-sidebar-options" id="sbn-filter-popularity-options">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                    
                    <!-- Tempo Filter -->
                    <div class="sbn-sidebar-section">
                        <h4 class="sbn-sidebar-label">Tempo</h4>
                        <div class="sbn-tempo-filter">
                            <button type="button" class="sbn-sidebar-option sbn-tempo-option" data-tempo="slow">Slow (< 100)</button>
                            <button type="button" class="sbn-sidebar-option sbn-tempo-option" data-tempo="medium">Medium (100-140)</button>
                            <button type="button" class="sbn-sidebar-option sbn-tempo-option" data-tempo="fast">Fast (> 140)</button>
                        </div>
                    </div>
                    
                    <button type="button" id="sbn-clear-filters" class="sbn-clear-filters-btn">Clear All Filters</button>
                </aside>
                
                <!-- RESULTS CONTAINER -->
                <div id="sbn-results-container" class="sbn-results-container">
                    
                    <!-- Loading State -->
                    <div id="sbn-loading" class="sbn-loading" style="display:none;">
                        <div class="sbn-loading-spinner"></div>
                        <p>Loading songs...</p>
                    </div>
                    
                    <!-- Song Cards Grid -->
                    <div id="sbn-songs-grid" class="sbn-songs-grid">
                        <!-- Populated by JS -->
                    </div>
                    
                    <!-- No Results Message -->
                    <div id="sbn-no-results" class="sbn-no-results" style="display:none;">
                        <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                            <circle cx="20" cy="20" r="12" stroke="currentColor" stroke-width="2"/>
                            <path d="M30 30L42 42" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <path d="M16 20H24M20 16V24" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <h3>No songs found</h3>
                        <p>Try adjusting your search or filters</p>
                    </div>
                    
                </div>
                
            </div><!-- .sbn-content-wrapper -->
            
            <!-- SONG INFO MODAL (animated overlay — mirrors chord library) -->
            <div id="sbn-song-modal-overlay" class="sbn-song-modal-overlay" style="display:none;">
                <div id="sbn-song-modal" class="sbn-song-modal">
                    <!-- Populated by JS -->
                </div>
            </div>
            
            <!-- LEADSHEET MODAL -->
            <div id="sbn-leadsheet-modal" class="sbn-leadsheet-modal" style="display:none;">
                <div class="sbn-leadsheet-modal-box">
                    <button type="button" id="sbn-close-leadsheet" class="sbn-leadsheet-close" title="Close">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                            <path d="M6 6L18 18M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
                        </svg>
                    </button>
                    <div id="sbn-leadsheet-modal-inner" class="sbn-leadsheet-modal-inner"></div>
                </div>
            </div>
            
        </div>
    </main>
</div>

<?php get_footer(); ?>
