</main><!-- #main-content -->

<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-copyright">
            <p>&copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?>. Alle Rechte vorbehalten.</p>
        </div>
        
        <?php if (has_nav_menu('footer')): ?>
        <nav class="footer-navigation" aria-label="<?php esc_attr_e('Footer Menu', 'flavor-starter'); ?>">
            <?php
            wp_nav_menu(array(
                'theme_location' => 'footer',
                'container'      => false,
                'menu_class'     => 'footer-menu',
                'depth'          => 1,
            ));
            ?>
        </nav>
        <?php endif; ?>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
