<?php
/**
 * Main Template
 */

get_header(); ?>

<div class="content-area">
    <?php
    if (have_posts()):
        while (have_posts()):
            the_post();
            ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <?php if (!is_page()): // Only show title for blog posts, not pages ?>
                <header class="entry-header">
                    <?php the_title('<h1 class="entry-title">', '</h1>'); ?>
                </header>
                <?php endif; ?>
                <div class="entry-content">
                    <?php the_content(); ?>
                </div>
            </article>
            <?php
        endwhile;
    else:
        ?>
        <p>Keine Inhalte gefunden.</p>
        <?php
    endif;
    ?>
</div>

<?php get_footer();
