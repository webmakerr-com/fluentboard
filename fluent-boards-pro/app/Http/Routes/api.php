<?php
// exit if file is called directly
if (!defined('ABSPATH')) exit;

/**
 * @var $router \FluentBoards\Framework\Http\Router
 */

use FluentBoards\App\Http\Policies\AdminPolicy;
use FluentBoards\App\Http\Policies\AuthPolicy;
use FluentBoardsPro\App\Http\Controllers\BoardUserController;
use FluentBoardsPro\App\Http\Controllers\FolderController;
use FluentBoardsPro\App\Http\Policies\UserPolicy;
use FluentBoards\App\Http\Policies\SingleBoardPolicy;
use FluentBoards\App\Http\Policies\BoardUserPolicy;


/*
 * All the routes must be defined inside the group otherwise multiple router do not work
 */
$router->withPolicy(BoardUserPolicy::class)->group(function ($router) {

    $router->delete('tasks/{task_id}/attachment-delete/{attachment_id}', 'AttachmentController@deleteTaskAttachment')->int('task_id')->int('attachment_id');
    $router->post('tasks/{task_id}/add-attachment', 'AttachmentController@addTaskAttachment')->int('task_id');
    $router->put('tasks/{task_id}/attachment-update/{attachment_id}', 'AttachmentController@updateTaskAttachment')->int('task_id')->int('attachment_id');
    $router->get('projects/template-stages', 'ProBoardController@getTemplateStages');
    $router->get('projects/get-template-tasks/', 'ProBoardController@getTemplateTasks');



    # Route prefix: /boards
    $router->prefix('projects/{board_id}')->withPolicy(SingleBoardPolicy::class)->group(function ($router) {
        $router->get('/get-default-board-images', 'ProBoardController@getDefaultBoardImages')->int('board_id');
        $router->post('/download-default-board-images', 'ProBoardController@downloadDefaultBoardImages');

        $router->post('/send-invitation', 'ProBoardController@sendInvitationToBoard');
        $router->get('/all-invitations', 'ProBoardController@getInvitations');
        $router->delete('/invitation/{invitation_id}', 'ProBoardController@deleteInvitation');
        $router->put('/stage/{stage_id}/update-stage-template', 'ProBoardController@updateStageTemplate')->int('board_id')->int('stage_id');

        $router->post('/user/{user_id}/make-manager', 'BoardUserController@makeManager')->int('board_id')->int('user_id');
        $router->post('/user/{user_id}/remove-manager', 'BoardUserController@removeManager')->int('board_id')->int('user_id');
        $router->post('/user/{user_id}/make-viewer', 'BoardUserController@makeViewer')->int('board_id')->int('user_id');
        $router->post('/user/{user_id}/make-member', 'BoardUserController@makeMember')->int('board_id')->int('user_id');
        
        $router->get('/custom-fields', 'CustomFieldController@getCustomFields');
        $router->post('/custom-field', 'CustomFieldController@createCustomField');
        $router->put('/custom-field/{custom_field_id}', 'CustomFieldController@updateCustomField')->int('custom_field_id');
        $router->put('/custom-field/{custom_field_id}/update-position', 'CustomFieldController@updateCustomFieldPosition')->int('custom_field_id');
        $router->delete('/custom-field/{custom_field_id}', 'CustomFieldController@deleteCustomField')->int('board_id')->int('custom_field_id');

        $router->put('/stage/{stage_id}/default-assignees', 'ProBoardController@setDefaultAssignees')->int('board_id')->int('stage_id');


        # Route prefix: /boards/{id}/tasks
        $router->prefix('/tasks')->group(function ($router) {
            $router->put('/update-subtask-position/{subtask_id}', 'SubtaskController@updateSubtaskPosition')->int('board_id')->int('subtask_id');

            # Route prefix: /boards/{id}/tasks/{task_id}
            $router->prefix('/{task_id}')->group(function ($router) {
                $router->get('/subtasks', 'SubtaskController@getSubtasks')->int('board_id')->int('task_id');
                $router->put('/move-to-board', 'SubtaskController@moveToBoard')->int('board_id')->int('task_id');
                $router->delete('/delete-subtask', 'SubtaskController@deleteSubtasks')->int('board_id')->int('task_id');
                $router->post('/subtask-group', 'SubtaskController@createSubtaskGroup')->int('board_id')->int('task_id');
                $router->put('/subtask-group', 'SubtaskController@updateSubtaskGroup')->int('board_id')->int('task_id');
                $router->delete('/subtask-group', 'SubtaskController@deleteSubtaskGroup')->int('board_id')->int('task_id');
                $router->post('/subtasks', 'SubtaskController@createSubtask')->int('board_id')->int('task_id');
                $router->post('/move-subtask', 'SubtaskController@moveSubtask')->int('board_id')->int('task_id');
                $router->get('/attachment', 'AttachmentController@getAttachments')->int('board_id')->int('task_id');
                $router->post('/add-task-attachment-file', 'AttachmentController@addTaskAttachmentFile')->int('board_id')->int('task_id');

                $router->post('/task-create-from-template', 'ProBoardController@createFromTemplate')->int('board_id')->int('task_id');

                // Time Tracking Endpoints
                $router->get('/time-tracks', 'TimeTrackController@getTracks')->int('board_id')->int('task_id');
                $router->post('/time-tracks/start', 'TimeTrackController@startTrack')->int('board_id')->int('task_id');
                $router->post('/time-tracks/pause', 'TimeTrackController@pauseTrack')->int('board_id')->int('task_id');
                $router->post('/time-tracks/stop', 'TimeTrackController@stopTrack')->int('board_id')->int('task_id');
                $router->post('/time-tracks/commit', 'TimeTrackController@commitTrack')->int('board_id')->int('task_id');
                $router->put('/time-tracks/commit/{track_id}', 'TimeTrackController@updateCommitTrack')->int('board_id')->int('task_id')->int('track_id');
                $router->post('/time-tracks/commit-manually', 'TimeTrackController@manualCommitTrack')->int('board_id')->int('task_id')->int('track_id');

                $router->put('/convert-to-subtask', 'SubtaskController@ConvertTaskToSubtask')->int('board_id')->int('task_id');

                $router->get('/custom-fields', 'CustomFieldController@getCustomFieldsByTask')->int('board_id')->int('task_id');;
                $router->post('/custom-fields', 'CustomFieldController@saveCustomFieldDataOfTask')->int('board_id')->int('task_id');;
                $router->post('/create-repeat-task', 'ProTaskController@createOrUpdateTaskRepeatMeta')->int('board_id')->int('task_id');;
                $router->delete('/remove-repeat-task', 'ProTaskController@removeRepeatTaskMeta')->int('board_id')->int('task_id');;

                $router->post('/clone-subtask', 'SubtaskController@cloneSubtask')->int('board_id')->int('task_id');

            }); // end of task individual
        }); // end of task group
    }); // end of board group

    $router->withPolicy(\FluentBoards\App\Http\Policies\AdminPolicy::class)->post('import-file', 'FluentBoardsPro\App\Http\Controllers\ImportController@importFile');
    $router->withPolicy(\FluentBoards\App\Http\Policies\AdminPolicy::class)->post('csv-upload', 'FluentBoardsPro\App\Http\Controllers\CsvController@upload');
    $router->withPolicy(\FluentBoards\App\Http\Policies\AdminPolicy::class)->post('import-csv', 'FluentBoardsPro\App\Http\Controllers\CsvController@importBoard');

});

$router->prefix('managers')->withPolicy(\FluentBoards\App\Http\Policies\AdminPolicy::class)->group(function ($router) {
    $router->get('/', [\FluentBoardsPro\App\Http\Controllers\BoardUserController::class, 'users']);
    $router->post('/', [\FluentBoardsPro\App\Http\Controllers\BoardUserController::class, 'addUserToBoards']);
    $router->post('/add-admins', [\FluentBoardsPro\App\Http\Controllers\BoardUserController::class, 'addAsAdmin']);
    $router->post('/add-users-to-boards', [\FluentBoardsPro\App\Http\Controllers\BoardUserController::class, 'addUsersToBoards']);
    $router->post('/remove-admins', [\FluentBoardsPro\App\Http\Controllers\BoardUserController::class, 'removeAdmins']);

    $router->post('/roles/{user_id}', [\FluentBoardsPro\App\Http\Controllers\BoardUserController::class, 'syncBoardRoles'])->int('user_id');
    $router->delete('/roles/{user_id}', [\FluentBoardsPro\App\Http\Controllers\BoardUserController::class, 'removeUserFromAllBoards'])->int('user_id');
});

$router->prefix('admin')->withPolicy(\FluentBoards\App\Http\Policies\AdminPolicy::class)->group(function ($router) {
    $router->get('/storage-settings', [\FluentBoardsPro\App\Http\Controllers\ProAdminController::class, 'getStorageSettings']);
    $router->post('/storage-settings', [\FluentBoardsPro\App\Http\Controllers\ProAdminController::class, 'updateStorageSettings']);

    $router->prefix('folders')->group(function ($router) {
        $router->get('/', [FolderController::class, 'getFolders']);
        $router->post('/', [FolderController::class, 'createFolder']);
        $router->prefix('/{folder_id}')->group(function ($router) {
            $router->post('/add-board', [FolderController::class, 'addBoardToFolder'])->int('folder_id');
            $router->post('/remove-board', [FolderController::class, 'removeBoardFromFolder'])->int('folder_id');
            $router->put('/', [FolderController::class, 'updateFolder'])->int('folder_id');
            $router->delete('/', [FolderController::class, 'deleteFolder'])->int('folder_id');
        });
    });

});

$router->prefix('license')->withPolicy(\FluentBoards\App\Http\Policies\AdminPolicy::class)->group(function ($router) {
    $router->get('/', [\FluentBoardsPro\App\Http\Controllers\LicenseController::class, 'getStatus']);
    $router->post('/', [\FluentBoardsPro\App\Http\Controllers\LicenseController::class, 'saveLicense']);
    $router->delete('/', [\FluentBoardsPro\App\Http\Controllers\LicenseController::class, 'deactivateLicense']);
});


