<?php

namespace FluentBoardsPro\App\Services;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\NotificationUser;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\TaskService;
use FluentBoardsPro\App\Models\TaskAttachment;
use FluentBoardsPro\App\Modules\TimeTracking\Model\TimeTrack;

class ProTaskService
{
    public function getDefaultBoardImages()
    {
        $url = Constant::BOARD_DEFAULT_IMAGE_URL;

        /**
         * Image URL names after the static URL
         * 'https://fluentboards.com/shared-files/5036/?image_1.jpg'
         * https://fluentboards.com/shared-files/ is the static part
         * $remoteImages will be ['5036/?image_1.jpg']
         */

        $remoteImages = [
            '5027/?image_1.jpg',
            '5029/?image_2.jpg',
            '5036/?image_3.jpg',
        ];
        $existingImages = Meta::where('object_type', Constant::BOARD_DEFAULT_IMAGE)->get();

        $data = [];

        foreach ($remoteImages as $index => $remoteImage) {
            $downloaded = false;

            foreach ($existingImages as $key => $image) {
                if ($image->key == $remoteImage) {
                    $downloaded = true;
                    $data[] = [
                        'id' => $image->object_id,
                        'downloadable' => false,
                        'value' => $image->value,
                    ];
                    // Remove the image from the collection
                    unset($existingImages[$key]);
                    break;
                }
            }

            if (!$downloaded) {
                $data[] = [
                    'id' => $remoteImage,
                    'downloadable' => true,
                    'value' => $url . $remoteImage,
                ];
            }
        }

        return $data;
    }

    public function downloadDefaultBoardImages($imageData)
    {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attachment_id = media_sideload_image(Constant::BOARD_DEFAULT_IMAGE_URL . $imageData['id'], 0, $imageData['id'], 'id');
        $media = wp_prepare_attachment_for_js($attachment_id);
        $downloadedImage = (new Meta());
        $downloadedImage['object_id'] = $attachment_id;
        $downloadedImage['object_type'] = Constant::BOARD_DEFAULT_IMAGE;
        $downloadedImage['key'] = $imageData['id'];
        $downloadedImage['value'] = $media['url'];
        $downloadedImage->save();
        return [
            'id' => $downloadedImage->object_id,
            'downloadable' => false,
            'value' => $downloadedImage->value,
        ];
    }
  
    public function getTemplateTasks()
    {
        $userId = get_current_user_id();
        $currentUser = User::find($userId);

        $relatedBoardsQuery = Board::query();

        if (!PermissionManager::isAdmin($userId)) {
            $relatedBoardsQuery->whereIn('id', $currentUser->whichBoards->pluck('id'));
        }

        $templateTaskIds = TaskMeta::where('key', 'is_template')->where('value', 'yes')->pluck('task_id');
        return Task::whereIn('id', $templateTaskIds)
            ->where('archived_at', null)
            ->whereIn('board_id', $relatedBoardsQuery->pluck('id'))
            ->with('assignees', 'labels')
            ->get();

    }

    public function createFromTemplate($taskId, $data)
    {
        // Input validation
        if (!is_numeric($taskId) || $taskId <= 0) {
            throw new \Exception('Invalid task id', 400);
        }
        
        if (!isset($data['board_id']) || !is_numeric($data['board_id']) || $data['board_id'] <= 0 || !is_int($data['board_id'] + 0) || $data['board_id'] != (int)$data['board_id']) {
            throw new \Exception('Invalid board id - must be a positive integer', 400);
        }
        
        $templateTask = Task::find($taskId);
        if (!$templateTask) {
            throw new \Exception('Template task not found', 404);
        }
        
        // Additional validation: ensure template task exists and is accessible
        if (!$templateTask->id || $templateTask->id != $taskId) {
            throw new \Exception('Template task not found', 404);
        }
        
        $taskService = new TaskService();
        $task = new Task();

        $task->fill($templateTask->toArray());

        $task->title = sanitize_text_field($data['title']);
        $task->board_id = (int) $data['board_id'];
        $task->stage_id = isset($data['stage_id']) ? (int) $data['stage_id'] : null;
        $task->created_by = get_current_user_id();
        $task->comments_count = 0;
        
        // Remove task cover if creating in a DIFFERENT board
        if ($templateTask->board_id !== $task->board_id) {
            $this->removeTaskCoverImage($task);
        }
        
        $task->moveToNewPosition(1);
        $task->save();

        // Check if creating in the same board or different board
        $isSameBoard = (int)$templateTask->board_id === (int)$task->board_id;

        if ($isSameBoard) {
            // SAME BOARD: Copy everything as before
            if (isset($data['assignee']) && $data['assignee'] == 'true') {
                $templateTask->load('assignees');
                foreach ($templateTask->assignees as $assignee) {
                    $taskService->updateAssignee($assignee->ID, $task);
                }
            }
            if (isset($data['label']) && $data['label'] == 'true') {
                $templateTask->load('labels');
                foreach ($templateTask->labels as $label) {
                    $task->labels()->syncWithoutDetaching([$label->id => ['object_type' => Constant::OBJECT_TYPE_TASK_LABEL]]);
                }
            }
            if (isset($data['watcher']) && $data['watcher'] == 'true') {
                $templateTask->load('watchers');
                foreach ($templateTask->watchers as $watcher) {
                    $task->watchers()->syncWithoutDetaching([$watcher->ID => ['object_type' => Constant::OBJECT_TYPE_USER_TASK_WATCH]]);
                }
            }
            if (isset($data['attachment']) && $data['attachment'] == 'true') {
                $templateTask->load('attachments');
                foreach ($templateTask->attachments as $attachment) {
                    $newAttachment = new TaskAttachment();
                    $newAttachment->object_id = $task->id;
                    $newAttachment->object_type = $attachment->object_type;
                    $newAttachment->attachment_type = $attachment->attachment_type;
                    $newAttachment->title = $attachment->title;
                    $newAttachment->file_path = $attachment->file_path;
                    $newAttachment->full_url = $attachment->full_url;
                    $newAttachment->file_size = $attachment->file_size;
                    $newAttachment->settings = $attachment->settings;
                    $newAttachment->driver = $attachment->driver;
                    $newAttachment->save();
                }
            }
            if (isset($data['comment']) && $data['comment'] == 'true') {
                $this->copyCommentsAndReplies($templateTask->id, $task->id);
            }
            if (isset($data['time_tracking']) && $data['time_tracking'] == 'true') {
                $this->copyTimeTrackingRecords($templateTask->id, $task->id);
            }
            if (isset($data['custom_field']) && $data['custom_field'] == 'true') {
                $this->copyCustomFieldAssociations($templateTask->id, $task->id);
            }
            if (isset($data['recurring']) && $data['recurring'] == 'true') {
                $this->copyRecurringTaskSettings($templateTask->id, $task->id);
            }
        }
        // DIFFERENT BOARD: Only copy safe data (subtasks, recurring tasks)
        // SECURITY: Don't copy user-specific or file data

        // KEEP: Non-user-specific data only (works for both same and different boards)
        if (isset($data['subtask']) && $data['subtask'] == 'true') {
            $subtaskGroupMap = [];
            $subtaskGroupMap = (new TaskService())->copySubtaskGroup($templateTask, $task, $subtaskGroupMap);
            $subtasks = Task::where('parent_id', $taskId)->get();
            foreach ($subtasks as $subtask) {
                $newSubtask = new Task();
                $newSubtask->fill($subtask->toArray());
                $newSubtask['parent_id'] = $task->id;
                $newSubtask['board_id'] = $data['board_id'];
                $newSubtask->save();

                //adding to subtasktask group
                $groupRelationOfTask = TaskMeta::where('key', Constant::SUBTASK_GROUP_CHILD)
                    ->where('task_id', $subtask->id)
                    ->first();
                if ($groupRelationOfTask && $subtaskGroupMap[$groupRelationOfTask->value]) {
                    TaskMeta::create([
                        'task_id' => $newSubtask->id,
                        'key' => Constant::SUBTASK_GROUP_CHILD,
                        'value' => $subtaskGroupMap[$groupRelationOfTask->value]
                    ]);
                }

                //adding assignees - SECURITY: Only if same board
                if ($isSameBoard) {
                    $subtask->load('assignees');
                    foreach ($subtask->assignees as $assignee) {
                        $taskService->updateAssignee($assignee->ID, $newSubtask);
                    }
                }
            }
        }
        
        // Handle recurring tasks for both same and different boards
        if (isset($data['recurring']) && $data['recurring'] == 'true') {
            if ($isSameBoard) {
                // SAME BOARD: Copy recurring task settings
                $this->copyRecurringTaskSettings($templateTask->id, $task->id);
            } else {
                // DIFFERENT BOARD: Remove recurring task settings for security
                $this->removeRecurringTaskSettings($task->id);
            }
        }
        
        // Apply stage default assignees ONLY if no template assignees were copied
        // This prevents conflicts with template assignees
        if (!isset($data['assignee']) || $data['assignee'] != 'true') {
            $taskService->manageDefaultAssignees($task, $task['stage_id']);
        }
        
        $task->load(['board', 'assignees', 'labels', 'watchers', 'attachments']);

        return $task;
    }


    /**
     * Remove task cover image for security reasons
     * Keeps background colors but removes image references
     */
    private function removeTaskCoverImage($task)
    {
        $settings = $task->settings;
        if (empty($settings) || !is_array($settings)) {
            return;
        }
        
        if (isset($settings['cover']) && is_array($settings['cover'])) {
            $cover = $settings['cover'];
            
            // Remove image references
            unset($cover['imageId']);
            unset($cover['backgroundImage']);
            
            // Keep only background color if it exists
            if (isset($cover['backgroundColor'])) {
                $settings['cover'] = array('backgroundColor' => $cover['backgroundColor']);
            } else {
                unset($settings['cover']);
            }
            
            $task->settings = $settings;
        }
    }

    /**
     * Copy comments and replies from template task to new task
     * Only used when creating in same board for security
     */
    private function copyCommentsAndReplies($templateTaskId, $newTaskId)
    {
        if (!is_numeric($templateTaskId) || $templateTaskId <= 0 || !is_numeric($newTaskId) || $newTaskId <= 0) {
            return;
        }

        $comments = Comment::where('task_id', (int) $templateTaskId)->get();
        
        foreach ($comments as $comment) {
            $newComment = new Comment();
            $newComment->fill($comment->toArray());
            $newComment->task_id = (int) $newTaskId;
            $newComment->id = null; // Let database assign new ID
            $newComment->save();
        }
    }

    /**
     * Copy time tracking records from template task to new task
     * Only used when creating in same board for security
     */
    private function copyTimeTrackingRecords($templateTaskId, $newTaskId)
    {
        if (!is_numeric($templateTaskId) || $templateTaskId <= 0 || !is_numeric($newTaskId) || $newTaskId <= 0) {
            return;
        }

        if (class_exists('FluentBoardsPro\App\Modules\TimeTracking\Model\TimeTrack')) {
            $timeRecords = \FluentBoardsPro\App\Modules\TimeTracking\Model\TimeTrack::where('task_id', (int) $templateTaskId)->get();
            
            foreach ($timeRecords as $timeRecord) {
                $newTimeRecord = new \FluentBoardsPro\App\Modules\TimeTracking\Model\TimeTrack();
                $newTimeRecord->fill($timeRecord->toArray());
                $newTimeRecord->task_id = (int) $newTaskId;
                $newTimeRecord->id = null; // Let database assign new ID
                $newTimeRecord->save();
            }
        }
    }

    /**
     * Copy custom field associations from template task to new task
     * Only used when creating in same board for security
     */
    private function copyCustomFieldAssociations($templateTaskId, $newTaskId)
    {
        if (!is_numeric($templateTaskId) || $templateTaskId <= 0 || !is_numeric($newTaskId) || $newTaskId <= 0) {
            return;
        }

        // Get all task metas for the template task (custom fields are stored as task metas)
        $customFields = TaskMeta::where('task_id', (int) $templateTaskId)->get();
        
        foreach ($customFields as $customField) {
            $newCustomField = new TaskMeta();
            $newCustomField->fill($customField->toArray());
            $newCustomField->task_id = (int) $newTaskId;
            $newCustomField->id = null; // Let database assign new ID
            $newCustomField->save();
        }
    }

    /**
     * Copy recurring task settings from template task to new task
     * Only used when creating in same board for security
     */
    private function copyRecurringTaskSettings($templateTaskId, $newTaskId)
    {
        if (!is_numeric($templateTaskId) || $templateTaskId <= 0 || !is_numeric($newTaskId) || $newTaskId <= 0) {
            return;
        }

        $recurringSettings = TaskMeta::where('task_id', (int) $templateTaskId)
            ->where('key', 'repeat_task_meta')
            ->first();
        
        if ($recurringSettings) {
            $newRecurringSetting = new TaskMeta();
            $newRecurringSetting->task_id = (int) $newTaskId;
            $newRecurringSetting->key = 'repeat_task_meta';
            $newRecurringSetting->value = $recurringSettings->value;
            $newRecurringSetting->object_type = $recurringSettings->object_type;
            $newRecurringSetting->save();
        }
    }

    /**
     * Remove recurring task settings for security reasons
     * Used when creating in different board
     */
    private function removeRecurringTaskSettings($taskId)
    {
        if (!is_numeric($taskId) || $taskId <= 0) {
            return;
        }

        TaskMeta::where('task_id', (int) $taskId)
            ->where('key', 'repeat_task_meta')
            ->delete();
    }

    public function convertTaskToSubtask($taskId, $parent_id)
    {
        $task = Task::findOrFail($taskId);
        $parentTask = Task::findOrFail($parent_id);
        
        // Prevent converting task to subtask if they have different board IDs
        if ($task->board_id != $parentTask->board_id) {
            throw new \Exception(__('Cannot convert task to subtask: Task and parent task must be on the same board', 'fluent-boards-pro'));
        }
        
        if($task)
        {
            $task->parent_id = $parent_id;
            $task->stage_id = null;
            $task->save();

            //Removing all task related notifications, because task can not be opened from notification
            $notificationIds = $task->notifications->pluck('id');
            $task->notifications()->delete();
            NotificationUser::whereIn('notification_id', $notificationIds)->delete();
        }

        do_action('fluent_boards/subtask_added', $parentTask, $task);

        return $parentTask;
    }

    public function addAssigneeToSubtask($taskId, $assigneeId){
        $task = Task::findOrFail($taskId);

        //task assignees watchers removed
        $task->watchers()->detach();
        $task->assignees()->detach();

        $taskService = new TaskService();
        $taskService->updateTaskProperty('assignees', $assigneeId, $task);
    }

    public function addToSubtaskGroup($taskId, $subtaskGroupId = null, $parentTaskId = null) {
        if (!$subtaskGroupId && $parentTaskId) {
            $newGroup = TaskMeta::create([
                'task_id' => $parentTaskId,
                'key'     => Constant::SUBTASK_GROUP_NAME,
                'value'   => 'Default Subtask Group'
            ]);
            if ($newGroup) {
                TaskMeta::create([
                    'task_id' => $taskId,
                    'key'     => Constant::SUBTASK_GROUP_CHILD,
                    'value'   => $newGroup->id
                ]);
            }
            return;
        }
        TaskMeta::create([
           'task_id' => $taskId,
           'key'     => Constant::SUBTASK_GROUP_CHILD,
           'value'   => $subtaskGroupId
        ]);
    }

    public function getTaskTimeTrack($boardId, $taskId)
    {
        return TimeTrack::where('board_id', $boardId)
            ->where('task_id', $taskId)
            ->orderBy('updated_at', 'DESC')
            ->with(['user'])
            ->get();
    }

}