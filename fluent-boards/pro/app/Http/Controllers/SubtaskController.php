<?php

namespace FluentBoardsPro\App\Http\Controllers;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\TaskService;
use FluentBoards\Framework\Support\Arr;
use FluentBoardsPro\App\Services\ProTaskService;
use FluentBoardsPro\App\Services\SubtaskService;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\Constant;

class SubtaskController extends Controller
{
    private SubtaskService $subtaskService;

    public function __construct(SubtaskService $subtaskService)
    {
        parent::__construct();
        $this->subtaskService = $subtaskService;
    }

    public function getSubtasks($board_id, $task_id)
    {
        $parentTask = Task::findOrFail($task_id);

        $subTasks = Task::where('parent_id', $parentTask->id)
            ->with(['assignees'])
            ->orderBy('position', 'asc')
            ->get();

        $groups = TaskMeta::where('task_id', $parentTask->id)
            ->select('id', 'value')
            ->where('key', Constant::SUBTASK_GROUP_NAME)
            ->get()
            ->keyBy('id')
            ->toArray();

        $formattedSubTasks = [];

        foreach ($groups as $group) {
            $formattedSubTasks[$group['id']] = [
                'id' => $group['id'],
                'task_id' => $parentTask->id,
                'subtasks' => [],
                'value' => $group['value'] ?? __('Uncategorized', 'fluent-boards-pro'),
            ];
        }

        foreach ($subTasks as $subTask) {
            $groupId = (string) Arr::get($subTask->meta, 'subtask_group_id', 'uncategorized');
            if(!isset($formattedSubTasks[$groupId])) {
                $formattedSubTasks[$groupId] = [
                    'id' => $groupId,
                    'subtasks' => [],
                    'task_id' => $parentTask->id,
                    'value' => $groups[$groupId]['value'] ?? __('Subtask Group 1', 'fluent-boards-pro'),
                ];
            }
            $formattedSubTasks[$groupId]['subtasks'][] = $subTask;
        }

        if(!empty($formattedSubTasks['uncategorized'])) {
            $uncategorizedSubTasks = $formattedSubTasks['uncategorized']['subtasks'];
            $newGroup = [
                'value' => __('Subtask Group 1', 'fluent-boards-pro'),
                'task_id' => $parentTask->id,
                'key' => Constant::SUBTASK_GROUP_NAME,
            ];
            $createdGroupId = TaskMeta::query()->insertGetId($newGroup);
            $formattedSubTasks['uncategorized']['id'] = $createdGroupId;
            foreach ($uncategorizedSubTasks as $subTask) {
                TaskMeta::create([
                    'task_id' => $subTask->id,
                    'key' => Constant::SUBTASK_GROUP_CHILD,
                    'value' => $createdGroupId,
                ]);
            }
        }

        $formattedSubTasks = array_values($formattedSubTasks);

        return [
            'subtaskGroups' => $formattedSubTasks
        ];
    }

    public function createSubtaskGroup(Request $request, $board_id, $task_id)
    {
        $subtaskGroupData = $this->subtaskSanitizeAndValidate($request->all(), [
            'title' => 'required|string',
        ]);

        try {
            $subtaskGroup = $this->subtaskService->createSubtaskGroup($task_id, $subtaskGroupData);

            return $this->sendSuccess([
                'subtaskGroup' => $subtaskGroup,
                'message'      => __('New Subtask group has been added', 'fluent-boards-pro')
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function updateSubtaskGroup(Request $request, $board_id, $task_id)
    {
        $subtaskGroupData = $this->subtaskSanitizeAndValidate($request->all(), [
            'title'    => 'required|string',
            'group_id' => 'required'
        ]);

        try {
            $subtaskGroup = $this->subtaskService->updateSubtaskGroup($subtaskGroupData);

            return $this->sendSuccess([
                'subtaskGroup' => $subtaskGroup,
                'message'      => __('Subtask group title has been added', 'fluent-boards-pro')
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function deleteSubtaskGroup(Request $request, $board_id, $task_id)
    {
        $group_id = $request->getSafe('group_id');

        try {
            $this->subtaskService->deleteSubtaskGroup($group_id);

            return $this->sendSuccess([
                'message' => __('Subtask group has been deleted', 'fluent-boards-pro')
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function createSubtask(Request $request, $board_id, $task_id)
    {
        $subtaskData = $this->subtaskSanitizeAndValidate($request->all(), [
            'title'    => 'required|string',
            'group_id' => 'required'
        ]);

        try {
            $subtask = $this->subtaskService->createSubtask($task_id, $subtaskData);

            $subtask['assignees'] = $subtask->assignees;

            return $this->sendSuccess([
                'subtask' => $subtask,
                'message' => __('Subtask has been added', 'fluent-boards-pro')
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function deleteSubtasks($board_id, $task_id)
    {
        try {
            $task = Task::findOrFail($task_id);
            $task->subtask_group_id = TaskMeta::where('task_id', $task->id)
                ->where('key', Constant::SUBTASK_GROUP_CHILD)
                ->value('value');
            $deletedTask = clone $task;

            $options = null;
            //if we need to do something before a task is deleted
            do_action('fluent_boards/before_task_deleted', $task, $options);

            $this->subtaskService->deleteSubtask($task);

            do_action('fluent_boards/subtask_deleted_activity', $deletedTask->parent_id, $deletedTask->title);

            // therefore the task is subtask and we need to update other subtasks position
            return $this->sendSuccess([
                'deletedSubtask'  => $deletedTask,
                'changedSubtasks' => $this->subtaskService->getLastMinuteUpdatedSubtasks($deletedTask->parent_id),
                'message'         => __('Task has been deleted', 'fluent-boards-pro'),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    /*
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $board_id
     * @param  int  $subtask_id
     * Convert a subtask to a task and move it to the board where parent task was created
     * @return \Illuminate\Http\Response
     */
    public function moveToBoard(Request $request, $board_id, $task_id)
    {
        $subtaskId = $task_id;
        $subtaskData = $this->subtaskSanitizeAndValidate($request->all(), [
            'stage_id' => 'required',
        ]);
        $taskService = new TaskService();
        try {
            $subtask = Task::findOrFail($subtaskId);
            $subtask = $this->subtaskService->moveToBoard($subtask, $subtaskData);
            $changedSubtasks = $this->subtaskService->getLastMinuteUpdatedSubtasks($subtask->parent_id);

            return $this->sendSuccess([
                'moveSubtask'     => $subtask,
                'changedSubtasks' => $changedSubtasks,
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function moveSubtask(Request $request, $board_id, $task_id)
    {
        $subtaskData = $this->subtaskSanitizeAndValidate($request->all(), [
            'group_id'   => 'required',
            'subtask_id' => 'required',
        ]);

        try {
            $subtask = $this->subtaskService->moveSubtaskToGroup($subtaskData);

            return $this->sendSuccess([
                'subtask' => $subtask,
                'message' => __('Subtask has been moved', 'fluent-boards-pro'),
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }


    private function subtaskSanitizeAndValidate($data, array $rules = [])
    {
        $data = Helper::sanitizeSubtask($data);

        return $this->validate($data, $rules);
    }

    public function updateSubtaskPosition(Request $request, $board_id, $subtask_id)
    {
        $subtaskData = $this->subtaskSanitizeAndValidate($request->all(), [
            'newPosition'        => 'required|integer',
            'newSubtasksGroupId' => 'required',
        ]);
        try {
            $subtask = $this->subtaskService->updateSubtaskPosition($subtask_id, $subtaskData);

            $changedSubtasks = $this->subtaskService->getLastMinuteUpdatedSubtasks($subtask->parent_id);
            return $this->sendSuccess([
                'changedSubtasks' => $changedSubtasks
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function ConvertTaskToSubtask(Request $request, $board_id, $task_id)
    {
        $parentId = $request->getSafe('parent_id');
        $assigneeId = $request->getSafe('assigneeId');
        $subtaskGroupId = $request->getSafe('subtaskGroupId');
        $taskService = new ProTaskService();
        try {
            $parentTask = $taskService->convertTaskToSubtask($task_id, $parentId);

            if (!empty($subtaskGroupId)) {
                $taskService->addToSubtaskGroup($task_id, $subtaskGroupId, null);
            } else {
                $taskService->addToSubtaskGroup($task_id, null, $parentTask->id);
            }

            if (!empty($assigneeId)) {
                $taskService->addAssigneeToSubtask($task_id, $assigneeId);
            }

            return $this->sendSuccess([
                'message'    => __('Task has been converted to subtask', 'fluent-boards-pro'),
                'parentTask' => $parentTask
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function cloneSubtask(Request $request, $board_id, $subtask_id)
    {
        $subtask = Task::findOrFail($subtask_id);
        $cloneSubtask = $this->subtaskService->cloneSubtask($subtask);

        return $this->sendSuccess([
            'subtask' => $cloneSubtask,
            'message' => __('Subtask has been cloned successfully', 'fluent-boards-pro')
        ],200);
    }
}
