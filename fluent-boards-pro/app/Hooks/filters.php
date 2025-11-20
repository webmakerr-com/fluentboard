<?php

/**
 * @var $app FluentBoards\Framework\Foundation\Application
 */


use FluentBoardsPro\App\Modules\TimeTracking\TimeTrackingHelper;

$app->addFilter('fluent_boards/accepted_plugins', 'FluentBoardsPro\App\Hooks\Handlers\InstallationHandler@acceptedPlugins',10, 1);
$app->addFilter('fluent_boards/addons_settings', 'FluentBoardsPro\App\Hooks\Handlers\InstallationHandler@addOnSettings', 10, 1);



// loading folder model for board
add_filter('fluent_boards/board_find', function ($board) {
    $board->folder = \FluentBoardsPro\App\Services\ProHelper::getFolderByBoard($board->id);
    return $board;
});

