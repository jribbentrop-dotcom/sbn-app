<?php
/**
 * Template Name: Repertoire Roadmap Quiz
 */

get_header(); ?>

<style>
    #quiz-container { max-width: 850px; margin: 50px auto; padding: 30px; border-radius: 12px; background: #fff; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    .quiz-option-btn { padding: 20px; border: 2px solid #e0e0e0; background: #fff; border-radius: 8px; cursor: pointer; transition: all 0.2s; font-size: 1.1em; font-weight: 500; }
    .quiz-option-btn:hover { border-color: #2ecc71; background: #f0fff4; transform: translateY(-2px); }
    .roadmap-card { border-left: 5px solid #2ecc71; padding: 20px; margin-bottom: 20px; background: #f8f9fa; border-radius: 0 8px 8px 0; }
    .next-step-card { border-left: 5px solid #3498db; background: #ebf5fb; }
    .popularity-badge { background: #2ecc71; color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.8em; }
</style>

<div id="quiz-container">

    <?php if (!isset($_GET['style'])) : ?>
        <div id="quiz-ui">
            <h2 id="quiz-question" style="margin-bottom: 30px; color: #2c3e50;">Loading your journey...</h2>
            <div id="quiz-options" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;"></div>
            
            <div style="margin-top: 40px;">
                <p style="font-size: 0.9em; color: #7f8c8d;">Progress</p>
                <div style="height: 8px; background: #eee; border-radius: 4px;">
                    <div id="progress-bar" style="width: 0%; height: 100%; background: #2ecc71; border-radius: 4px; transition: 0.5s;"></div>
                </div>
            </div>
        </div>

        <script>
            const quizConfig = {
                step: 0,
                selections: {},
                questions: [
                    { key: 'style', q: "What's the vibe today?", opts: ['Jazz', 'Bossa Nova', 'Blues'] },
                    { key: 'diff', q: "How's your technique feeling?", opts: ['Basic', 'Intermediate', 'Advanced'] },
                    { key: 'format', q: "Playing solo or with others?", opts: ['Solo Guitar', 'Leadsheet'] }
                ]
            };

            function updateQuiz() {
                const current = quizConfig.questions[quizConfig.step];
                document.getElementById('quiz-question').innerText = current.q;
                const container = document.getElementById('quiz-options');
                container.innerHTML = '';
                
                current.opts.forEach(opt => {
                    const btn = document.createElement('button');
                    btn.className = 'quiz-option-btn';
                    btn.innerText = opt;
                    btn.onclick = () => {
                        quizConfig.selections[current.key] = opt;
                        if (quizConfig.step < quizConfig.questions.length - 1) {
                            quizConfig.step++;
                            updateQuiz();
                        } else {
                            const query = new URLSearchParams(quizConfig.selections).toString();
                            window.location.search = query;
                        }
                    };
                    container.appendChild(btn);
                });
                document.getElementById('progress-bar').style.width = ((quizConfig.step + 1) / 3 * 100) + '%';
            }
            updateQuiz();
        </script>

    <?php else : ?>
        <div id="results-ui">
            <h2 style="color: #2c3e50;">Your Learning Path</h2>
            <p style="margin-bottom: 30px;"><a href="<?php the_permalink(); ?>" style="text-decoration: none; color: #2ecc71;">← Change my answers</a></p>

            <?php
            $style = sanitize_text_field($_GET['style']);
            $diff  = sanitize_text_field($_GET['diff']);
            $format = sanitize_text_field($_GET['format']);

            // 1. Fetch current songs sorted by Popularity
            $current_songs = new WP_Query(array(
                'post_type'  => 'repertoire',
                'posts_per_page' => 3,
                'meta_key'   => '_rep_popularity',
                'orderby'    => 'meta_value_num',
                'order'      => 'DESC',
                'meta_query' => array(
                    'relation' => 'AND',
                    array('key' => '_rep_style', 'value' => $style),
                    array('key' => '_rep_difficulty', 'value' => $diff),
                    array('key' => '_rep_format', 'value' => $format),
                ),
            ));

            if ($current_songs->have_posts()) :
                while ($current_songs->have_posts()) : $current_songs->the_post(); 
                    $pop = get_post_meta(get_the_ID(), '_rep_popularity', true);
                    $tech = get_post_meta(get_the_ID(), '_rep_technique', true);
                    ?>
                    <div class="roadmap-card">
                        <span class="popularity-badge">Essential #<?php echo esc_html($pop); ?></span>
                        <h3><?php the_title(); ?></h3>
                        <p><strong>Practice Focus:</strong> <?php echo esc_html($tech); ?></p>
                        <a href="<?php the_permalink(); ?>" style="color: #2ecc71; font-weight: bold;">Get Started →</a>
                    </div>
                <?php endwhile; wp_reset_postdata();

                // 2. Logic for "The Next Milestone" (Difficulty +1)
                $next_diff = ($diff == 'Basic') ? 'Intermediate' : 'Advanced';
                if ($diff !== 'Advanced') :
                    $next_goal = new WP_Query(array(
                        'post_type' => 'repertoire',
                        'posts_per_page' => 1,
                        'meta_key' => '_rep_popularity',
                        'orderby' => 'meta_value_num',
                        'meta_query' => array(
                            'relation' => 'AND',
                            array('key' => '_rep_style', 'value' => $style),
                            array('key' => '_rep_difficulty', 'value' => $next_diff),
                        )
                    ));

                    if ($next_goal->have_posts()) : $next_goal->the_post(); ?>
                        <div class="roadmap-card next-step-card">
                            <span class="popularity-badge" style="background: #3498db;">Next Milestone</span>
                            <h3><?php the_title(); ?></h3>
                            <p>Once you master the songs above, this <strong><?php echo $next_diff; ?></strong> piece is your next target.</p>
                        </div>
                    <?php wp_reset_postdata(); endif;
                endif;

            else : ?>
                <p>No exact matches. Our database is growing! Try selecting "Leadsheet" or a lower difficulty level.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<?php get_footer(); ?>