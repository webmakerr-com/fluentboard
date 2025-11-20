<?php
namespace FluentBoardsPro\App\Hooks\Handlers;

use FluentBoards\App\App;
use FluentBoards\App\Hooks\Handlers\AdminMenuHandler;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Services\PermissionManager;

class SingleBoardShortCodeHandler
{
    public function register()
    {
        add_shortcode('fluent_board', [$this, 'loadShortCode']);
    }

    public function loadShortCode($atts)
    {
        $atts = shortcode_atts(array(
            'id' => '', // Default ID value if not provided
        ), $atts, 'fluent_board');

        if (empty($atts['id'])) {
            return 'Please provide a valid board id';
        }

        $boardId = $atts['id'];

        $board = Board::find($boardId);

        if (!$board) {
            return 'Please provide a valid board id';
        }

        wp_enqueue_style('fluent-boards-pro-frontend', FLUENT_BOARDS_PRO_URL . 'dist/css/frontend.css', [], FLUENT_BOARDS_PRO_VERSION);

        if (!get_current_user_id()) {
            return (new FrontendRenderer())->getAuthContent();
        }

        if (!PermissionManager::userHasAnyBoardAccess(get_current_user_id())) {
            return '<div class="fbs_no_permission">' . apply_filters('fluent_boards/no_permission_message', __('You do not have permission to view the projects', 'fluent-boards-pro')) . '</div>';
        }
        $this->enqueueAssets();
        ob_start();

        echo "<div data-board_id='" . $board->id . "' class='fluent_boards_frontend fluent_board_single_board_app_wrapper' id='frontend-fluent-board-" . $board->id . "'>";

        (new AdminMenuHandler())->singleBoardRender();

        echo '</div>';

        return ob_get_clean();
    }

    protected function enqueueAssets()
    {
        if (!PermissionManager::hasAppAccess()) {
            return;
        }

        if (function_exists('wp_enqueue_media')) {
            // Editor default styles.
            add_filter('user_can_richedit', '__return_true');
            if (is_admin()) {
                wp_tinymce_inline_scripts();
            }
            wp_enqueue_editor();
            wp_enqueue_script('thickbox');
            wp_enqueue_script('editor');
        }
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        $app = App::getInstance();

        $assets = $app['url.assets'];

        $slug = $app->config->get('app.slug');

        $isRtl = is_rtl();
        $adminAppCss = 'admin/admin.css';
        if($isRtl) {
            $adminAppCss = 'admin/admin-rtl.css';
        }
        wp_enqueue_style(
            $slug . '_admin_app',
            $assets . $adminAppCss,
            [],
            FLUENT_BOARDS_PLUGIN_VERSION
        );

        do_action($slug . '_loading_app');

        wp_enqueue_script(
            $slug . '_single_board_app',
            $assets . 'admin/single_board.min.js',
            ['jquery'],
            FLUENT_BOARDS_PLUGIN_VERSION,
            true
        );

        wp_enqueue_script(
            $slug . '_global_admin',
            $assets . 'admin/global_admin.js',
            [],
            FLUENT_BOARDS_PLUGIN_VERSION,
            true
        );
        /*
        * This script only for resolve the conflict of lodash and underscore js
        * Resolved the issue of media uploader specially for image upload
        */
        wp_add_inline_script($slug . '_global_admin', (new AdminMenuHandler())->getInlineScript(), 'after');

        wp_localize_script($slug . '_single_board_app', 'fluentAddonVars', (new AdminMenuHandler())->getAddonVars($app));

        do_action('fluent_boards/after_enqueue_assets', $app);
    }
}