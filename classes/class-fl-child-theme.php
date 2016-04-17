<?php

/**
 * Helper class for theme functions.
 *
 * @class FLChildTheme
 */
final class FLChildTheme {

    /**
     * @method styles
     */
    static public function stylesheet()
    {
        require_once(FL_CHILD_THEME_DIR . '/js/typekit.js');

        echo '<link rel="stylesheet" href="' . FL_CHILD_THEME_URL . '/style.css" />';
    }

}
