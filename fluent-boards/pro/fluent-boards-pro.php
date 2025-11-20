<?php defined('ABSPATH') or exit;

// Pro bootstrap stub for the unified Fluent Boards plugin.
if (!defined('FLUENT_BOARDS_PRO')) {
    define('FLUENT_BOARDS_PRO', 'fluent-boards-pro');
    define('FLUENT_BOARDS_PRO_VERSION', '1.90');
    define('FLUENT_BOARDS_PRO_DIR_FILE', defined('FLUENT_BOARDS_DIR_FILE') ? FLUENT_BOARDS_DIR_FILE : __FILE__);
    define('FLUENT_BOARDS_PRO_URL', defined('FLUENT_BOARDS_PLUGIN_URL') ? FLUENT_BOARDS_PLUGIN_URL . 'pro/' : plugin_dir_url(__FILE__));
    define('FLUENT_BOARDS_PRO_LIVE', true);
    define('FLUENT_BOARDS_CORE_MIN_VERSION', '1.90');
}

if (!defined('FLUENT_BOARDS_PRO_BOOTSTRAPPED')) {
    define('FLUENT_BOARDS_PRO_BOOTSTRAPPED', true);

    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require __DIR__ . '/vendor/autoload.php';
    }

    if (class_exists('FluentBoardsPro\\App\\Services\\HelperInstaller')) {
        (new \FluentBoardsPro\App\Services\HelperInstaller())->register();
    }

    if (file_exists(__DIR__ . '/boot/app.php')) {
        call_user_func(function ($bootstrap) {
            $bootstrap(defined('FLUENT_BOARDS_DIR_FILE') ? FLUENT_BOARDS_DIR_FILE : __FILE__);
        }, require __DIR__ . '/boot/app.php');
    }
}
