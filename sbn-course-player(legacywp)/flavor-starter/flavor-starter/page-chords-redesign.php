<?php
/**
 * Template Name: Chord Library (Redesigned)
 * 
 * Modern, streamlined chord search with unified interface.
 * Single intelligent search handles both chord names and text filtering.
 * 
 * @package SoulBossaNova
 */

get_header(); ?>

<div id="primary" class="content-area">
    <main id="main" class="site-main sbn-chord-library-main">
        
        <?php
        global $wpdb;
        $table_name = $wpdb->prefix . 'sbn_chord_diagrams';
        
        // Fetch ALL chords — archetypes first (grouped by family), then the rest by popularity
        $chords = $wpdb->get_results(
            "SELECT * FROM $table_name 
             ORDER BY 
                CASE WHEN voicing_category = 'archetype' THEN 0 ELSE 1 END,
                shape_family ASC,
                sort_order ASC,
                popularity DESC, 
                quality, voicing_category, root_string DESC"
        );
        
        // Separate archetypes from other chords, group archetypes by family
        $archetype_families = array();
        $other_chords = array();
        foreach ($chords as $chord) {
            if ($chord->voicing_category === 'archetype' && !empty($chord->shape_family)) {
                $archetype_families[$chord->shape_family][] = $chord;
            } else {
                $other_chords[] = $chord;
            }
        }
        
        // Pretty labels for shape families
        $family_labels = array(
            'archetype-e'  => 'E Shape',
            'archetype-em' => 'Em Shape',
            'archetype-a'  => 'A Shape',
            'archetype-am' => 'Am Shape',
            'archetype-d'  => 'D Shape',
            'archetype-dm' => 'Dm Shape',
            'archetype-c'  => 'C Shape',
            'archetype-g'  => 'G Shape',
        );
        
        // Get readable labels
        $cat_labels = SBN_Chord_Diagrams::get_voicing_categories();
        $string_labels = SBN_Chord_Diagrams::ROOT_STRINGS;
        
        // Get unique values for filters
        $all_qualities   = array();
        $all_extensions  = array();
        $all_inversions  = array();
        $has_popularity  = false;
        $has_difficulty  = false;
        foreach ($chords as $chord) {
            if (!empty($chord->quality))   $all_qualities[$chord->quality]     = $chord->quality;
            if (!empty($chord->extensions)) $all_extensions[$chord->extensions] = $chord->extensions;
            if (!empty($chord->inversion) && $chord->inversion !== 'root')
                $all_inversions[$chord->inversion] = $chord->inversion;
            if (!empty($chord->popularity)) $has_popularity = true;
            if (!empty($chord->difficulty)) $has_difficulty = true;
        }
        sort($all_qualities);
        sort($all_extensions);
        
        $popularity_tiers = [
            'occasional' => 'Rare',
            'common'     => 'Common',
            'essential'  => 'Core',
            'iconic'     => 'Iconic',
        ];
        $difficulty_options = [
            1 => 'Beginner (★)',
            2 => 'Easy (★★)',
            3 => 'Intermediate (★★★)',
            4 => 'Advanced (★★★★)',
            5 => 'Expert (★★★★★)',
        ];
        
        // Inversion labels
        $inv_labels = ['inv1' => '1st Inversion', 'inv2' => '2nd Inversion', 'inv3' => '3rd Inversion'];
        ?>
        
        <div class="sbn-chord-library-redesign">
            
            <!-- HEADER SECTION -->
            <header class="sbn-library-header">
                <h1 class="sbn-library-title">Chord Dictionary</h1>
                <p class="sbn-library-subtitle">Search by chord name or browse by category</p>
                
                <!-- UNIFIED SEARCH BOX -->
                <div class="sbn-search-container">
                    <div class="sbn-search-box">
                        <svg class="sbn-search-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2"/>
                            <path d="M13 13L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <input 
                            type="text" 
                            id="sbn-unified-search" 
                            class="sbn-search-input"
                            placeholder="Try: Dm7, F#maj7, or 'drop 2'..." 
                            autocomplete="off"
                        >
                        <button type="button" id="sbn-search-clear" class="sbn-clear-btn" style="display:none;">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M4 4L12 12M12 4L4 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                </div>
                
            </header>
            
            
            
            <!-- EDUCATIONAL CONTEXT PANEL (fixed height, content changes) -->
            <div id="sbn-edu-panel" class="sbn-edu-panel">
                <div class="sbn-edu-content" id="sbn-edu-content">
                    <!-- Content is rendered by JavaScript based on filterState -->
                </div>
            </div>
            <!-- SEARCH STATUS / RESULTS HEADER -->
            <div id="sbn-search-status" class="sbn-search-status"></div>
            
            <!-- SIDEBAR + GRID LAYOUT WRAPPER -->
            <div class="sbn-content-wrapper">
                
                <!-- FILTER SIDEBAR -->
                <aside id="sbn-filter-sidebar" class="sbn-filter-sidebar">
                    <div class="sbn-sidebar-header">
                        <h3>Filters</h3>
                    </div>
                    
                    <!-- Quality -->
                    <div class="sbn-sidebar-section">
                        <h4 class="sbn-sidebar-label">Chord Quality</h4>
                        <div class="sbn-sidebar-options" id="sbn-filter-quality-options">
                            <?php foreach ($all_qualities as $q): ?>
                                <button type="button" class="sbn-sidebar-option" data-filter="quality" data-value="<?php echo esc_attr($q); ?>">
                                    <?php echo esc_html($q); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Voicing Type -->
                    <div class="sbn-sidebar-section">
                        <h4 class="sbn-sidebar-label">Voicing Type</h4>
                        <div class="sbn-sidebar-options" id="sbn-filter-voicing-options">
                            <?php foreach ($cat_labels as $key => $label): ?>
                                <button type="button" class="sbn-sidebar-option" data-filter="voicing" data-value="<?php echo esc_attr($key); ?>">
                                    <?php echo esc_html($label); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Popularity -->
                    <?php if ($has_popularity): ?>
                    <div class="sbn-sidebar-section">
                        <h4 class="sbn-sidebar-label">Popularity</h4>
                        <div class="sbn-sidebar-options" id="sbn-filter-popularity-options">
                            <?php foreach ($popularity_tiers as $tier => $label): ?>
                                <button type="button" class="sbn-sidebar-option" data-filter="popularity" data-value="<?php echo esc_attr($tier); ?>">
                                    <?php echo esc_html($label); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Difficulty -->
                    <?php if ($has_difficulty): ?>
                    <div class="sbn-sidebar-section">
                        <h4 class="sbn-sidebar-label">Difficulty</h4>
                        <div class="sbn-sidebar-options" id="sbn-filter-difficulty-options">
                            <?php foreach ($difficulty_options as $val => $label): ?>
                                <button type="button" class="sbn-sidebar-option" data-filter="difficulty" data-value="<?php echo esc_attr($val); ?>">
                                    <?php echo esc_html($label); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Inversion -->
                    <?php if (!empty($all_inversions)): ?>
                    <div class="sbn-sidebar-section">
                        <h4 class="sbn-sidebar-label">Inversion</h4>
                        <div class="sbn-sidebar-options" id="sbn-filter-inversion-options">
                            <button type="button" class="sbn-sidebar-option" data-filter="inversion" data-value="root">Root Position</button>
                            <?php foreach ($all_inversions as $inv): ?>
                                <button type="button" class="sbn-sidebar-option" data-filter="inversion" data-value="<?php echo esc_attr($inv); ?>">
                                    <?php echo esc_html($inv_labels[$inv] ?? $inv); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Extensions -->
                    <?php if (!empty($all_extensions)): ?>
                    <div class="sbn-sidebar-section">
                        <h4 class="sbn-sidebar-label">Extensions</h4>
                        <div class="sbn-sidebar-options" id="sbn-filter-extension-options">
                            <?php foreach ($all_extensions as $ext): ?>
                                <button type="button" class="sbn-sidebar-option" data-filter="extension" data-value="<?php echo esc_attr($ext); ?>">
                                    <?php echo esc_html($ext); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <button type="button" id="sbn-clear-filters" class="sbn-clear-filters-btn">Clear All Filters</button>
                </aside>
            
            <!-- RESULTS CONTAINER -->
            <div id="sbn-results-container" class="sbn-results-container">
                
                <?php if (empty($chords)): ?>
                    <div class="sbn-empty-state">
                        <svg width="64" height="64" viewBox="0 0 64 64" fill="none">
                            <rect x="12" y="8" width="40" height="48" rx="4" stroke="currentColor" stroke-width="2"/>
                            <line x1="20" y1="16" x2="44" y2="16" stroke="currentColor" stroke-width="2"/>
                            <line x1="20" y1="24" x2="44" y2="24" stroke="currentColor" stroke-width="2"/>
                            <line x1="20" y1="32" x2="36" y2="32" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        <h2>No Chords Found</h2>
                        <p>Add chord diagrams to get started</p>
                    </div>
                <?php else: 
                    // Helper: render a single chord card
                    // (used for both archetype and non-archetype cards)
                    $render_card = function($chord) use ($cat_labels, $string_labels) {
                        $cat_label = $cat_labels[$chord->voicing_category] ?? 'General';
                        $root_label = $string_labels[$chord->root_string] ?? 'Custom';
                        $root_label_short = str_replace('String ', '', $root_label);
                        $search_text = strtolower(
                            $chord->quality . ' ' . 
                            ($chord->extensions ?: '') . ' ' . 
                            $cat_label . ' ' . 
                            $root_label . ' ' .
                            ($chord->shape_family ?? '')
                        );
                        $pop       = intval($chord->popularity ?? 0);
                        $diff      = intval($chord->difficulty ?? 0);
                        $pop_tier  = '';
                        $pop_label = '';
                        if      ($pop >= 11) { $pop_tier = 'iconic';     $pop_label = 'Iconic'; }
                        elseif  ($pop >= 6)  { $pop_tier = 'essential';  $pop_label = 'Core'; }
                        elseif  ($pop >= 3)  { $pop_tier = 'common';     $pop_label = 'Common'; }
                        elseif  ($pop >= 1)  { $pop_tier = 'occasional'; $pop_label = 'Rare'; }
                        $diff_labels = [1=>'Beginner',2=>'Easy',3=>'Intermediate',4=>'Advanced',5=>'Expert'];
                        ?>
                        <div class="sbn-chord-card" 
                             data-diagram-id="<?php echo esc_attr($chord->id); ?>"
                             data-slug="<?php echo esc_attr($chord->slug); ?>"
                             data-root-note=""
                             data-search="<?php echo esc_attr($search_text); ?>"
                             data-root="<?php echo esc_attr($chord->root_string); ?>"
                             data-quality="<?php echo esc_attr($chord->quality); ?>"
                             data-extensions="<?php echo esc_attr($chord->extensions ?? ''); ?>"
                             data-inversion="<?php echo esc_attr($chord->inversion ?? 'root'); ?>"
                             data-popularity="<?php echo esc_attr($pop); ?>"
                             data-difficulty="<?php echo esc_attr($diff); ?>"
                             data-voicing="<?php echo esc_attr($chord->voicing_category); ?>"
                             data-shape-family="<?php echo esc_attr($chord->shape_family ?? ''); ?>"
                             data-fingering="<?php echo esc_attr($chord->fingering ?? ''); ?>"
                             data-notes="<?php echo esc_attr($chord->notes ?? ''); ?>"
                             data-functions="<?php echo esc_attr($chord->functions ?? ''); ?>">
                            
                            <div class="sbn-card-chord-name">
                                <?php 
                                $chord_name = $chord->quality . ($chord->extensions ?: '');
                                echo sbn_format_chord_name($chord_name);
                                ?>
                            </div>
                            
                            <div class="sbn-card-inversion"></div>
                            
                            <div class="sbn-card-diagram">
                                <div class="sbn-chord-fretboard" 
                                     data-diagram='<?php echo esc_attr($chord->diagram_data); ?>' 
                                     data-start-fret="<?php echo esc_attr($chord->start_fret); ?>"
                                     data-intervals="<?php echo esc_attr($chord->interval_labels); ?>"
                                     data-notes=""
                                     data-has-root="false">
                                </div>
                            </div>
                            
                            <div class="sbn-card-footer">
                                <div class="sbn-card-footer-left">
                                    <?php if ($pop_label): ?>
                                        <span class="sbn-card-pop sbn-pop-<?php echo esc_attr($pop_tier); ?>"
                                              title="<?php echo esc_attr($pop . ($pop === 1 ? ' song' : ' songs')); ?>">
                                            <?php echo esc_html($pop_label); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="sbn-card-footer-right">
                                    <?php if ($diff > 0): ?>
                                        <span class="sbn-card-diff" title="<?php echo esc_attr($diff_labels[$diff] ?? ''); ?>">
                                            <?php for ($si = 1; $si <= 5; $si++): ?>
                                                <span class="sbn-diff-star<?php echo $si <= $diff ? ' filled' : ''; ?>">★</span>
                                            <?php endfor; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="sbn-card-hover-controls">
                                <button class="sbn-play-btn" title="Play chord" aria-label="Play chord sound">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                                </button>
                            </div>
                        </div>
                    <?php }; ?>
                    
                    <!-- Chord Cards Grid -->
                    <div class="sbn-chords-grid">
                        
                        <?php if (!empty($archetype_families)): ?>
                            <!-- Archetype section with family grouping -->
                            <div class="sbn-archetype-section" data-voicing-section="archetype">
                                <h2 class="sbn-section-title">Archetypes</h2>
                                <p class="sbn-section-subtitle">The fundamental open-position guitar shapes — transposable as barré chords</p>
                                
                                <div class="sbn-family-groups">
                                    <?php foreach ($archetype_families as $family_key => $family_chords): 
                                        $family_label = $family_labels[$family_key] ?? ucwords(str_replace(array('archetype-', '-'), array('', ' '), $family_key));
                                    ?>
                                        <div class="sbn-family-group" data-family="<?php echo esc_attr($family_key); ?>">
                                            <h3 class="sbn-family-title"><?php echo esc_html($family_label); ?></h3>
                                            <div class="sbn-family-cards">
                                                <?php foreach ($family_chords as $chord) { $render_card($chord); } ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php // Render all non-archetype chords
                        foreach ($other_chords as $chord) { $render_card($chord); } ?>
                        
                    </div>
                    
                    <!-- No Results Message -->
                    <div id="sbn-no-results" class="sbn-no-results" style="display:none;">
                        <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                            <circle cx="20" cy="20" r="12" stroke="currentColor" stroke-width="2"/>
                            <path d="M30 30L42 42" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <path d="M16 20H24M20 16V24" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <h3>No chords match your search</h3>
                        <p>Try a different chord name or adjust your filters</p>
                    </div>
                    
                <?php endif; ?>
            </div>
            
            </div><!-- .sbn-content-wrapper -->
            
        </div>
    </main>
</div>

<?php get_footer(); ?>
