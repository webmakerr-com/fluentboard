<?php

namespace FluentBoardsPro\App\Hooks\Handlers;


use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Services\TaskService;
use FluentBoards\Framework\Support\DateTime;
use FluentBoardsPro\App\Modules\TimeTracking\Model\TimeTrack;
use FluentBoardsPro\App\Services\Constant;

class ProTaskHandler
{
    public function repeatTask($task_id)
    {
        $task = Task::find($task_id);
        if (!$task) {
            return null;
        }
        
        // Skip if original task is archived
        if ($task->archived_at !== null) {
            return null;
        }
        
        $meta = Meta::where('object_id', $task_id)->where('object_type', Constant::REPEAT_TASK_META)->first();
        if(!$meta) {
            return null;
        }
        $repeatTaskStage = $meta['value']['selected_stage'] ?? $task['stage_id'];
        
        // Check if target stage exists and is not archived
        $stage = \FluentBoards\App\Models\Stage::find($repeatTaskStage);
        if (!$stage || $stage->archived_at !== null) {
            return null;
        }
        
        // Validate stage and clean up meta if invalid
        if (!$this->validateRecurringTaskStage($task_id, $repeatTaskStage, $task['board_id'])) {
            return null;
        }
        $nextRepeatDate = $this->taskNextRepeatDate($task, $meta);
        $due_at = $this->setDueDate($task, $meta, $nextRepeatDate);
        if ($meta['value']['create_new'] == 1) {
            if ($meta['value']['repeat_when_complete'] == 1) {
                // Create a new task when the current task is completed
                if ($task['status'] == 'closed') {
                    $this->createNewTask($task, $repeatTaskStage, $meta['value']['next_repeat_date'], $due_at);
                    $this->updateTaskRepeatMeta($meta, $nextRepeatDate);
                }
            } else {
                // Create a new task regardless of the current task's status
                $this->createNewTask($task, $repeatTaskStage, $meta['value']['next_repeat_date'], $due_at);
                $this->updateTaskRepeatMeta($meta, $nextRepeatDate);
            }
        } else {
            if($meta['value']['repeat_when_complete'] == 1) {
                // Update the due_at date when the current task is completed
                if ($task['status'] == 'closed') {
                    $this->updateTask($task, $repeatTaskStage, $meta['value']['next_repeat_date'], $due_at);
                    $this->updateTaskRepeatMeta($meta, $nextRepeatDate);
                }
            } else {
                // Update the due_at date regardless of the current task's status
                $this->updateTask($task, $repeatTaskStage, $meta['value']['next_repeat_date'], $due_at);
                $this->updateTaskRepeatMeta($meta, $nextRepeatDate);
            }
        }
    }
    private function taskNextRepeatDate($task, $meta)
    {
        
        $nextRepeat = new DateTime();
        if (!empty($meta['value']['next_repeat_date'])) {
            $nextRepeat = new DateTime($meta['value']['next_repeat_date']);
            if (!empty($meta['value']['time_zone'])) {
                $timeZone = new \DateTimeZone($meta['value']['time_zone']);
                $nextRepeat->setTimezone($timeZone);
            }
        } else {
            if (!empty($task['due_at']) || !empty($task['started_at'])) {
                $dateString = !empty($task['due_at']) ? $task['due_at'] : $task['started_at'];
                $date = new DateTime($dateString);
                $nextRepeat->setDate($date->format('Y'), $date->format('m'), $date->format('d'));
            }
        }
        $repeatIn = !empty($meta['value']['repeat_in']) && $meta['value']['repeat_in'] > 0 ? intval($meta['value']['repeat_in']) : 1;
        $repeatType = $meta['value']['repeat_type'];

        if ($repeatType === 'daily') {
            if ($repeatIn > 365) {
                $repeatIn = 365;
            }
            $nextRepeat->modify("+$repeatIn days");
        } elseif ($repeatType === 'weekly') {
            if ($repeatIn > 99) {
                $repeatIn = 99;
            }
            if (!empty($meta['value']['selected_repeat_week_days'])) {
                $weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                $selected_repeat_week_days = $meta['value']['selected_repeat_week_days'];
                sort($selected_repeat_week_days);
                $currentDayIndex = $nextRepeat->format('w');
                $nextDayIndex = null;
                foreach ($selected_repeat_week_days as $day) {
                    if (array_search($day, $weekDays) > $currentDayIndex) {
                        $nextDayIndex = array_search($day, $weekDays);
                        break;
                    }
                }
                if ($nextDayIndex !== null) {
                    $nextRepeat->modify('+' . ($nextDayIndex - $currentDayIndex) . ' days');
                } else {
                    $nextRepeat->modify('+' . (7 - $currentDayIndex + array_search($selected_repeat_week_days[0], $weekDays)) . ' days');
                }
            } else {
                $nextRepeat->modify("+$repeatIn weeks");
            }
        } elseif ($repeatType === 'monthly') {
            if ($repeatIn > 12) {
                $repeatIn = 12;
            }
            if (!empty($meta['value']['selected_month_days'])) {
                $selected_month_days = $meta['value']['selected_month_days'];
                sort($selected_month_days);
                $currentDay = $nextRepeat->format('d');
                $nextDay = null;
                foreach ($selected_month_days as $day) {
                    if ($day > $currentDay) {
                        $nextDay = $day;
                        break;
                    }
                }
                if ($nextDay !== null) {
                    $nextRepeat->setDate($nextRepeat->format('Y'), $nextRepeat->format('m'), $nextDay);
                } else {
                    $nextRepeat->setDate($nextRepeat->format('Y'), $nextRepeat->format('m') + $repeatIn, $selected_month_days[0]);
                }
            } elseif ($meta['value']['repeat_in_month_type'] === 'firstDay') {
                $nextRepeat->setDate($nextRepeat->format('Y'), $nextRepeat->format('m'), 1);
                $nextRepeat->modify('+1 month');
            } elseif ($meta['value']['repeat_in_month_type'] === 'lastDay') {
                $nextRepeat->modify('first day of next month');
                $nextRepeat->modify('-1 day');
            } else {
                $nextRepeat->modify('+1 month');
            }
        } elseif ($repeatType === 'yearly') {
            if (!empty($meta['value']['selected_month']) && !empty($meta['value']['selected_month_days'])) {
                $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                $monthIndex = array_search($meta['value']['selected_month'], $months);
                $nextRepeat->setDate($nextRepeat->format('Y'), $monthIndex + 1, 1);
                $selected_month_days = $meta['value']['selected_month_days'];
                sort($selected_month_days);
                $currentDay = $nextRepeat->format('d');
                $nextDay = null;
                foreach ($selected_month_days as $day) {
                    if ($day > $currentDay) {
                        $nextDay = $day;
                        break;
                    }
                }
                if ($nextDay !== null) {
                    $nextRepeat->setDate($nextRepeat->format('Y'), $monthIndex + 1, $nextDay);
                } else {
                    $nextRepeat->setDate($nextRepeat->format('Y') + $repeatIn, $monthIndex + 1, $selected_month_days[0]);
                }
            } elseif ($meta['value']['repeat_in_month_type'] === 'firstDay') {
                $nextRepeat->setDate($nextRepeat->format('Y'), 1, 1);
            } elseif ($meta['value']['repeat_in_month_type'] === 'lastDay') {
                $nextRepeat->setDate($nextRepeat->format('Y'), 12, 31);
            } else {
                $nextRepeat->modify("+$repeatIn years");
            }
        }

        if (!empty($meta['value']['time'])) {
            $time = $meta['value']['time'];
            $hours = intval(substr($time, 0, 2));
            $minutes = intval(substr($time, 3, 2));
            $seconds = intval(substr($time, 6, 2));

            $nextRepeat->setTime($hours, $minutes, $seconds);
        }

        $next_repeat_date = $nextRepeat->format('Y-m-d H:i:s');
        $serverTimeZone = new \DateTimeZone(date_default_timezone_get());
        $nextRepeat->setTimezone($serverTimeZone);
        $next_repeat_date_server = $nextRepeat->format('Y-m-d H:i:s');
        
        return [
            'next_repeat_date' => $next_repeat_date,
            'next_repeat_date_server' => $next_repeat_date_server
        ];


    }

    private function createNewTask($task, $repeatTaskStage, $started_at, $due_at)
    {
        $newTask = Task::withoutTaskCreatedEvent(function() use ($task, $repeatTaskStage, $started_at, $due_at) {
            $newTask = $task->replicate()->fill([
                'started_at' => $started_at,
                'due_at' => $due_at,
                'status' => 'open',
                'archived_at' => null,
                'last_completed_at' => null,
                'stage_id' => $repeatTaskStage
            ]);

            $newTask->save();
            $newTask->moveToNewPosition(1);
            return $newTask;
        });

        do_action('fluent_boards/repeat_task_created', $newTask, $task);
        do_action('fluent_boards/task_due_date_changed', $newTask, $started_at);

        $this->addAssigneesToNewTask($newTask, $task);
        $this->addWatcherToNewTask($newTask, $task);
        $this->addLabelsToNewTask($newTask, $task);
        
        // Clone subtask groups first to get the group mapping
        $subtaskGroupMap = $this->cloneSubtaskGroups($task, $newTask);
        $this->createNewSubtasks($newTask, $task, $started_at, $due_at, $subtaskGroupMap);
    }
    private function updateTaskRepeatMeta($meta, $nextRepeatDate)
    {
        $value = (array) $meta->value;
        $value['next_repeat_date'] = $nextRepeatDate['next_repeat_date'];
        $meta->update([
            'value' => $value,
            'key' => $nextRepeatDate['next_repeat_date_server']
        ]);
    }
    private function updateTask($task, $repeatTaskStage, $startedAt, $dueAt)
    {
        $task->update([
            'status' => 'open',
            'started_at' => $startedAt,
            'due_at' => $dueAt,
            'stage_id' => $repeatTaskStage
        ]);
        // update subtasks
        $subTasks = $task->subTasks;
        foreach ($subTasks as $subTask) {
            $subTask->update([
                'status' => 'open',
                'started_at' => $startedAt,
                'due_at' => $dueAt,
                'stage_id' => $repeatTaskStage
            ]);
        }
        
    }

    private function setDueDate($task, $meta, $nextRepeatDate)
    {
        $startedAt = new DateTime($meta['value']['next_repeat_date']);
        $taskDueAt = new DateTime($task->due_at);
        $taskStartAt = new DateTime($task->started_at);
        if($taskStartAt) {
            $interval = $taskStartAt->diff($taskDueAt) ;
            $startedAt->add($interval);
        }
        return $startedAt->modify('-5 minutes');
    }

    private function addAssigneesToNewTask($newTask, $oldTask)
    {
        $oldTaskAssignees = $oldTask->assignees;
        foreach ($oldTaskAssignees as $assignee) {
            (new TaskService())->updateAssignee($assignee->ID, $newTask);
        }
    }

    private function addLabelsToNewTask($newTask, $oldTask)
    {
        $oldTaskLabels = $oldTask->labels;
        foreach ($oldTaskLabels as $label) {
            $newRelation = new Relation();
            $newRelation['object_id'] = $newTask->id;
            $newRelation['object_type'] = \FluentBoards\App\Services\Constant::OBJECT_TYPE_TASK_LABEL;
            $newRelation['foreign_id'] = $label->id;
            $newRelation->save();
        }
    }
    private function createNewSubtasks($newTask, $oldTask, $started_at, $due_at, $subtaskGroupMap = [])
    {
        $subTasks = $oldTask->subTasks;
        foreach ($subTasks as $subTask) {
            $newSubTask = $subTask->replicate()->fill([
                'parent_id' => $newTask->id,
                'board_id' => $newTask->board_id,
                'stage_id' => $newTask->stage_id,
                'started_at' => $started_at,
                'due_at' => $due_at,
                'status' => 'open',
                'archived_at' => null,
                'last_completed_at' => null
            ]);
            $newSubTask->save();

            $this->addAssigneesToNewTask($newSubTask, $subTask);
            
            // Copy subtask group relationship
            $groupRelation = TaskMeta::where('task_id', $subTask->id)
                ->where('key', \FluentBoards\App\Services\Constant::SUBTASK_GROUP_CHILD)
                ->first();

            if ($groupRelation && isset($subtaskGroupMap[$groupRelation->value])) {
                TaskMeta::create([
                    'task_id' => $newSubTask->id,
                    'key' => \FluentBoards\App\Services\Constant::SUBTASK_GROUP_CHILD,
                    'value' => $subtaskGroupMap[$groupRelation->value]
                ]);
            }
        }
    }

    private function addWatcherToNewTask($newTask, $oldTask)
    {
        $oldTaskWatchers = $oldTask->watchers;
        foreach ($oldTaskWatchers as $watcher) {
            $existingWatcher = Relation::where('object_id', $newTask->id)->where('object_type', \FluentBoards\App\Services\Constant::OBJECT_TYPE_USER_TASK_WATCH)->where('foreign_id', $watcher->ID)->first();
            if (!$existingWatcher) {
                $newRelation = new Relation();
                $newRelation['object_id'] = $newTask->id;
                $newRelation['object_type'] = \FluentBoards\App\Services\Constant::OBJECT_TYPE_USER_TASK_WATCH;
                $newRelation['foreign_id'] = $watcher->ID;
                $newRelation->save();
            }
        }
    }

    /**
     * Clone subtask groups from old task to new task for recurring tasks
     * @param Task $oldTask The original recurring task
     * @param Task $newTask The newly created recurring task
     * @return array Map of old group IDs to new group IDs
     */
    private function cloneSubtaskGroups($oldTask, $newTask)
    {
        $subtaskGroupMap = [];
        
        $subtaskGroups = TaskMeta::where('task_id', $oldTask->id)
            ->where('key', \FluentBoards\App\Services\Constant::SUBTASK_GROUP_NAME)
            ->get();
        
        foreach ($subtaskGroups as $group) {
            $newGroup = TaskMeta::create([
                'task_id' => $newTask->id,
                'key' => \FluentBoards\App\Services\Constant::SUBTASK_GROUP_NAME,
                'value' => $group->value
            ]);
            
            $subtaskGroupMap[$group->id] = $newGroup->id;
        }
        
        return $subtaskGroupMap;
    }

    public function handleTaskBoardMoveAndUpdateTimeTracking($task)
    {
        try {
            $timeTracks = TimeTrack::where('task_id', $task->id)->get();
            foreach ($timeTracks as $timeTrack) {
                $timeTrack->board_id = $task->board_id;
                $timeTrack->save();
            }
        } catch (\Exception $e) {
        }
    }
    
    /**
     * Validate recurring task stage and cleanup metadata if invalid
     * 
     * @param int $taskId The task ID
     * @param int $stageId The stage ID to validate
     * @param int $boardId The board ID to check against
     * @return bool True if stage is valid, false if invalid (and meta deleted)
     */
    private function validateRecurringTaskStage($taskId, $stageId, $boardId)
    {
        // Basic input validation - convert to int and check if valid
        $taskId = (int) $taskId;
        $stageId = (int) $stageId;
        $boardId = (int) $boardId;
        
        if ($taskId <= 0 || $stageId <= 0 || $boardId <= 0) {
            return false;
        }
        
        try {
            // Check if stage exists on the specified board
            $stageExists = \FluentBoards\App\Models\Stage::where('id', $stageId)
                ->where('board_id', $boardId)
                ->exists();
                
            if (!$stageExists) {
                // Stage doesn't exist - delete recurring metadata
                $deletedRows = Meta::where('object_id', $taskId)
                    ->where('object_type', Constant::REPEAT_TASK_META)
                    ->delete();
                    
                if ($deletedRows > 0) {
                    error_log("FluentBoards: Recurring task metadata deleted - original stage no longer exists on current board");
                }
                return false;
            }
            
            return true;
            
        } catch (\Exception $e) {
            error_log("FluentBoards: Error validating recurring task stage: " . $e->getMessage());
            return false;
        }
    }
}
