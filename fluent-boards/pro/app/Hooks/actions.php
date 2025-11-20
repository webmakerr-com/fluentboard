<?php

/**
 * @var $app FluentBoards\Framework\Foundation\Application
 */

use FluentBoardsPro\App\Hooks\Handlers\DataExporter;
use FluentBoardsPro\App\Hooks\Handlers\InvitationHandler;
use FluentBoardsPro\App\Hooks\Handlers\ProScheduleHandler;

(new \FluentBoardsPro\App\Hooks\Handlers\FrontendRenderer())->register();
(new \FluentBoardsPro\App\Hooks\Handlers\SingleBoardShortCodeHandler())->register();

$app->addAction('admin_post_myform', 'InvitationHandler@processInvitation', 10, 0);
$app->addAction('admin_post_nopriv_myform', 'InvitationHandler@processInvitation', 10, 0);
$app->addAction('wp_ajax_fluent_boards_export_timesheet', 'FluentBoardsPro\App\Hooks\Handlers\DataExporter@exportTimeSheet', 10, 0);

/* 
 * Begin Webhook Related Actions
 */

(new \FluentBoardsPro\App\Hooks\Handlers\OutWebhookHandler())->ListenWebhookActions();

$app->addAction('fluent_boards/async_task_created_webhook', 'OutWebhookHandler@handleWebhookRequest', 10, 3);
$app->addAction('fluent_boards/async_task_completed_webhook', 'OutWebhookHandler@handleWebhookRequest', 10, 3);
$app->addAction('fluent_boards/async_task_stage_updated_webhook', 'OutWebhookHandler@handleWebhookRequest', 10, 3);
$app->addAction('fluent_boards/async_task_date_changed_webhook', 'OutWebhookHandler@handleWebhookRequest', 10, 3);
$app->addAction('fluent_boards/async_task_priority_changed_webhook', 'OutWebhookHandler@handleWebhookRequest', 10, 3);
$app->addAction('fluent_boards/async_task_label_added_webhook', 'OutWebhookHandler@handleWebhookRequest', 10, 3);
$app->addAction('fluent_boards/async_task_label_removed_webhook', 'OutWebhookHandler@handleWebhookRequest', 10, 3);
$app->addAction('fluent_boards/async_stage_added_webhook', 'OutWebhookHandler@handleWebhookRequest', 10, 3);
$app->addAction('fluent_boards/async_comment_created_webhook', 'OutWebhookHandler@handleWebhookRequest', 10, 3);
$app->addAction('fluent_boards/async_task_assignee_added_webhook', 'OutWebhookHandler@handleWebhookRequest', 10, 3);
$app->addAction('fluent_boards/async_task_archived_webhook', 'OutWebhookHandler@handleWebhookRequest', 10, 3);

/*
 *  End Webhook Related Actions
 */

$app->addAction('fluent_boards/five_minutes_scheduler', [ProScheduleHandler::class, 'fiveMinutesScheduler'], 10, 0);
$app->addAction('fluent_boards/hourly_scheduler', 'ProScheduleHandler@hourlyScheduler', 10, 0);
$app->addAction('fluent_boards/daily_scheduler', 'ProScheduleHandler@dailyScheduler', 10, 0);
$app->addAction('fluent_boards/daily_task_reminder', 'ProScheduleHandler@dailyTaskSummaryMail', 10, 0);
$app->addAction('fluent_boards/install_plugin', 'FluentBoardsPro\App\Hooks\Handlers\InstallationHandler@installPlugin', 10, 2);
$app->addFilter('fluent_boards/repeat_task', 'FluentBoardsPro\App\Hooks\Handlers\ProTaskHandler@repeatTask', 10, 1);
$app->addAction('fluent_boards/repeat_task_scheduler', 'FluentBoardsPro\App\Hooks\Handlers\ProScheduleHandler@repeatTasks', 10, 0);
$app->addAction('fluent_boards/recurring_task_disabled', 'FluentBoardsPro\App\Hooks\Handlers\ProScheduleHandler@clearRepeatTaskScheduler', 10, 0);

$app->addAction('fluent_boards/task_reminder_scheduler_for_rest', [ProScheduleHandler::class, 'processTaskReminders'], 10, 0);

$app->addAction('wp_ajax_fluent_boards_export_csv', 'FluentBoardsPro\App\Hooks\Handlers\DataExporter@exportBoardInCsv', 10, 0);
$app->addAction('wp_ajax_fluent_boards_export_csv_file_download', 'FluentBoardsPro\App\Hooks\Handlers\DataExporter@downloadBoardCsvFile', 10, 0);
$app->addAction('wp_ajax_fluent_boards_export_csv_status', [DataExporter::class, 'exportCsvStatus']);
$app->addAction('fluent_boards_prepare_csv_export_file', [DataExporter::class, 'prepareCsvExportFile'], 10, 4);

$app->addAction('fluent_boards/send_invitation', [InvitationHandler::class, 'sendInvitationViaEmail'], 10, 3);


$app->addAction('wp_ajax_fluent_boards_export_json', 'FluentBoardsPro\App\Hooks\Handlers\DataExporter@exportBoardInJson', 10, 0);
$app->addAction('wp_ajax_fluent_boards_export_json_file_download', 'FluentBoardsPro\App\Hooks\Handlers\DataExporter@downloadBoardJsonFile', 10, 0);
$app->addAction('wp_ajax_fluent_boards_export_json_status', [DataExporter::class, 'exportJsonStatus']);
$app->addAction('fluent_boards/prepare_json_export_file', [DataExporter::class, 'prepareJsonExportFile'], 10, 4);

$app->addAction('fluent_boards/task_moved_update_time_tracking', 'FluentBoardsPro\App\Hooks\Handlers\ProTaskHandler@handleTaskBoardMoveAndUpdateTimeTracking', 10, 1);

/*
 * IMPORTANT
 * External Pages Handler
 * Each Request must have fbs=1 as a query parameter, then the plugin will handle the request.
 */

if(isset($_GET['fbs']) && $_GET['fbs'] == 1) {

    // For viewing attachment
    if(isset($_GET['fbs_attachment'])) {
        add_action('init', function() {
            (new \FluentBoardsPro\App\Hooks\Handlers\ExternalPages())->view_attachment();
        });
    }

    // Form page for invited user to join the board
    if(isset($_GET['invitation']) && $_GET['invitation'] == 'board') {
        add_action('init', function () {
            (new \FluentBoardsPro\App\Hooks\Handlers\ExternalPages())->boardMemberInvitation();
        });
    }
}

