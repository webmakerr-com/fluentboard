<?php

namespace FluentBoardsPro\App\Services;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Services\Constant;


class SubtaskService
{
    public function createSubtaskGroup($task_id, $subtaskData)
    {
        $parentTask = Task::findOrFail($task_id);

        // Add subtask group
        $group = $parentTask->taskMeta()->create([
            'key' => Constant::SUBTASK_GROUP_NAME,
            'value' => $subtaskData['title']
        ]);

        do_action('fluent_boards/subtask_group_created', $task_id, $group);

        return $group;
    }

    public function updateSubtaskGroup($subtaskGroupData)
    {
        $taskMeta = TaskMeta::findOrFail($subtaskGroupData['group_id']);
        $oldTitle = $taskMeta->value;
        $taskMeta->value = $subtaskGroupData['title'];
        $taskMeta->save();
        do_action('fluent_boards/subtask_group_title_updated', $oldTitle, $taskMeta);
        return $taskMeta;
    }

    public function deleteSubtaskGroup($group_id)
    {
        $group = TaskMeta::findOrFail($group_id);
        $subTasksId = $group->subtasks()->pluck('task_id');
        $group->subtasks()->detach();
        $deleted = $group->delete();
        if ($deleted) {
            Task::whereIn('id', $subTasksId)->delete();
            do_action('fluent_boards/subtask_group_deleted_activity', $group->task_id, $group);
        }
    }


    public function createSubtask($task_id, $subtaskData)
    {
        $parentTask = Task::findOrFail($task_id);
        $addToTop = isset($subtaskData['add_to_top']) ? filter_var($subtaskData['add_to_top'], FILTER_VALIDATE_BOOLEAN) : false;

        if ($addToTop) {
            $position = 1; //First position
        } else {
            $position = $this->getLastSubtaskPosition($task_id) + 1; //Last position
        }

        $data = [
            'parent_id' => $parentTask->id,
            'title' => $subtaskData['title'],
            'board_id' => $parentTask->board_id,
            'status' => 'open',
            'priority' => 'low',
            'due_at' => null,
            'position' => $position  
        ];

        $subtask = Task::create($data);
        
        if ($addToTop) {
            $subtask->moveToNewPosition(1); // Move to first position
        }

        $group = TaskMeta::findOrFail($subtaskData['group_id']);

        TaskMeta::create([
            'task_id' => $subtask['id'],
            'key' => Constant::SUBTASK_GROUP_CHILD,
            'value' => $group->id
        ]);

        do_action('fluent_boards/subtask_added', $parentTask, $subtask);

        return $subtask;
    }

    public function deleteSubtask($task)
    {
        $parentTaskId = $task->parent_id;
        do_action('fluent_boards/task_deleted', $task);
        $deleted = $task->delete();

        if ($deleted) {
            $task->watchers()->detach();
            $this->detachFromGroup($task);
        }

        Task::adjustSubtaskCount($parentTaskId);
    }

    public function detachFromGroup($task)
    {
        TaskMeta::where('task_id', $task->id)->where('key', Constant::SUBTASK_GROUP_CHILD)->delete();
    }

    /**
     * Retrieves subtasks that have been updated within the last minute for a given parent task ID.
     * @param int $parent_id The ID of the parent task for which subtasks are to be retrieved.
     * @return \FluentBoards\Framework\Database\Orm\Builder[]|\FluentBoards\Framework\Database\Orm\Collection
     * Returns a collection of subtasks that meet the specified criteria.
     */
    public function getLastMinuteUpdatedSubtasks($parent_id)
    {
        try {
            return Task::query()
                ->where('parent_id', (int)$parent_id)
                ->where('updated_at', '>=', gmdate('Y-m-d H:i:s', current_time('timestamp') - 60))
                ->with(['assignees'])
                ->get();
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }
    public function moveToBoard($subtask, $subtaskData)
    {
        $parentTaskId = $subtask->parent_id;
        $subtask->parent_id = null;
        $subtask->stage_id = $subtaskData['stage_id'];
        $saved = $subtask->save();
        if ($saved) {
            $subtask->moveToNewPosition(1);
            Task::adjustSubtaskCount($parentTaskId);
            $this->detachFromGroup($subtask);
        }

        return $subtask;
    }

    /**
     * Moves a subtask or multiple subtasks to a different group
     * @param array $subtaskData Array containing subtask_id (int|array) and group_id (int)
     * @return Task|Task[] The updated subtask(s) with assignees loaded
     */
    public function moveSubtaskToGroup($subtaskData)
    {
        $subtaskId = $subtaskData['subtask_id'];
        if (is_array($subtaskId)) {
            $updated = TaskMeta::whereIn('task_id', $subtaskId)
                ->where('key', Constant::SUBTASK_GROUP_CHILD)
                ->update(['value' => $subtaskData['group_id']]);

            if ($updated) {
                $subtasks = Task::whereIn('id', $subtaskId)->get();
                $position = $this->getLastSubtaskPosition($subtasks[0]->parent_id) + 1;
                foreach ($subtasks as $subtask) {
                    $subtask->position = $position++;
                    $subtask->save();
                    $subtask->load('assignees');
                }
                return $subtasks;
            }
        } else {
            $updated = TaskMeta::where('task_id', $subtaskId)
                ->where('key', Constant::SUBTASK_GROUP_CHILD)
                ->update(['value' => $subtaskData['group_id']]);

            if ($updated) {
                $subtask = Task::findOrFail($subtaskId);
                $position = $this->getLastSubtaskPosition($subtask->parent_id) + 1;
                $subtask->position = $position;
                $subtask->save();
                $subtask->load('assignees');
                return $subtask;
            }
        }
    }

    /**
     * Summary of getLastSubtaskPosition it will return last position of subtask in a task. if there is no subtask than it will return 0
     * @param mixed $task_id
     * @return mixed
     */

    public function getLastSubtaskPosition($task_id)
    {
        $subtask = Task::where('parent_id', $task_id)->orderBy('position', 'desc')->first();

        return isset($subtask->position) ? $subtask->position : 0;
    }

    public function updateSubtaskPosition($subtask_id, $subtaskData)
    {
        $subtask = Task::findOrFail($subtask_id);
        // If newSubtasksGroupId is not present, get it from subtask meta
        if (empty($subtaskData['newSubtasksGroupId'])) {
            $subTaskMeta = TaskMeta::where('task_id', $subtask_id)
                ->where('key', Constant::SUBTASK_GROUP_CHILD)
                ->first();
            if ($subTaskMeta) {
                $subtaskData['newSubtasksGroupId'] = $subTaskMeta->value;
            } else {
                // Optionally handle the case where meta is missing
                throw new \Exception('Subtask group ID not found in meta.');
            }
        }

        if($subtaskData['newSubtasksGroupId'] && ($subtask['meta']['subtask_group_id'] != $subtaskData['newSubtasksGroupId'])){
            $subTaskMeta = TaskMeta::where('task_id', $subtask_id)->where('key', Constant::SUBTASK_GROUP_CHILD)->first();
            $subTaskMeta->value = $subtaskData['newSubtasksGroupId'];
            $subTaskMeta->save();
        }

        $subtasksIds = TaskMeta::where('value', $subtaskData['newSubtasksGroupId'])
            ->where('key', Constant::SUBTASK_GROUP_CHILD)
            ->pluck('task_id')
            ->toArray();


        $newIndex = (int) $subtaskData['newPosition'];
        if ($newIndex < 1) {
            $newIndex = 1;
        }

        // Declaring query for subtask or task
        $subtasksQuery = Task::whereIn('id', $subtasksIds)
            ->whereNull('archived_at')
            ->orderBy('position', 'asc')
            ->where('id', '!=', $subtask_id);

        if ($newIndex == 1) {
            $firstItem = $subtasksQuery->first();

            if ($firstItem) {
                if ($firstItem->position < 0.02) {
                    $this->reIndexSubtasksPositions($subtaskData['newSubtasksGroupId']);
                    return $this->updateSubtaskPosition($subtask_id, $subtaskData);
                }
                $index = round($firstItem->position / 2, 2);
            } else {
                $index = 1;
            }

            $subtask->position = $index;
            $subtask->save();

            return $subtask;
        }

        $prevTask = (clone $subtasksQuery)
            ->offset($newIndex - 2)
            ->first();

        if (! $prevTask) {
            $subtaskData['newPosition'] = 1;
            return $this->updateSubtaskPosition($subtask_id, $subtaskData);
        }

        $nextItem = (clone $subtasksQuery)
            ->offset($newIndex - 1)
            ->first();

        if (! $nextItem) {
            $subtask->position = $prevTask->position + 1;
            $subtask->save();
            return $subtask;
        }

        $newPosition = ($prevTask->position + $nextItem->position) / 2;

        // check if new position is already taken
        $exist = $subtasksQuery
            ->where('position', $newPosition)
            ->where('id', '!=', $subtask_id)
            ->first();

        if ($exist) {
            $this->reIndexSubtasksPositions($subtaskData['newSubtasksGroupId']);
            $subtaskData['newPosition'] = $newIndex;
            return $this->updateSubtaskPosition($subtask_id, $subtaskData);
        }

        $subtask->position = $newPosition;
        $subtask->save();

        return $subtask;
    }
    
    private function reIndexSubtasksPositions($subtask_group_id) 
    {
        $subtasksIds = TaskMeta::where('value', $subtask_group_id)->where('key', Constant::SUBTASK_GROUP_CHILD)->pluck('task_id')->toArray();
        $allSubtasks = Task::whereIn('id', $subtasksIds)
            ->whereNull('archived_at')
            ->orderBy('position', 'asc')
            ->get();

        foreach ($allSubtasks as $index => $subtask) {
            $subtask->position = $index + 1;
            $subtask->save();
        }
    }

    public function cloneSubtask(Task $subtask)
    {
        $clonedSubtask = $subtask->replicate();

        $clonedSubtask->title = $subtask->title . ' (cloned)';

        $clonedSubtask->started_at = $subtask->started_at;
        $clonedSubtask->due_at = $subtask->due_at;

        $clonedSubtask->save();

        $subtaskGroupMeta = TaskMeta::where('task_id', $subtask->id)
            ->where('key', Constant::SUBTASK_GROUP_CHILD)
            ->first();

        if ($subtaskGroupMeta) {
            TaskMeta::create([
                'task_id' => $clonedSubtask->id,
                'key' => Constant::SUBTASK_GROUP_CHILD,
                'value' => $subtaskGroupMeta->value
            ]);
        }

        if ($subtask->assignees) {
            $clonedSubtask->assignees()->sync($subtask->assignees->pluck('id'));
        }

        $watchers = Relation::where('object_id', $subtask->id)
            ->whereIn('object_type', ['task_user_watch', 'task_assignee'])
            ->get();
        
        foreach ($watchers as $watcher) {
            Relation::create([
                'object_id' => $clonedSubtask->id,
                'object_type' => $watcher->object_type,
                'foreign_id' => $watcher->foreign_id
            ]);
        }

        $originalPosition = $subtask->position;
        $subtasksIds = TaskMeta::where('value', $subtaskGroupMeta->value)
            ->where('key', Constant::SUBTASK_GROUP_CHILD)
            ->pluck('task_id')
            ->toArray();

        // Find the next subtask after the original
        $nextSubtask = Task::whereIn('id', $subtasksIds)
            ->where('position', '>', $originalPosition)
            ->orderBy('position', 'asc')
            ->first();

        if ($nextSubtask) {
            // Place clone between original and next
            $newPosition = ($originalPosition + $nextSubtask->position) / 2;
        } else {
            // Place clone at the end
            $newPosition = $originalPosition + 1;
        }

        $clonedSubtask->position = $newPosition;
        $clonedSubtask->save();

        $clonedSubtask->load('assignees');
        $parentTask = Task::findOrFail($subtask->parent_id);

        do_action('fluent_boards/subtask_cloned', $parentTask, $clonedSubtask);

        return $clonedSubtask;
    }

}