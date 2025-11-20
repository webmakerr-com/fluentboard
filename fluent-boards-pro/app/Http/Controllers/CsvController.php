<?php

namespace FluentBoardsPro\App\Http\Controllers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Services\Libs\FileSystem;
use FluentBoards\App\Services\StageService;
use FluentBoards\Framework\Support\Str;
use FluentBoardsPro\App\Services\Helper;
use FluentBoards\App\Services\Constant;
use FluentBoards\Framework\Http\Request\Request;

class CsvController extends Controller
{
    public function upload(Request $request)
    {
        if (is_multisite()) {
            add_filter('upload_mimes', function ($types) {
                if (empty($types['csv'])) {
                    $types['csv'] = 'text/csv';
                }
                return $types;
            });
        }

        $files = $this->validate($this->request->files(), [
            'file' => 'mimetypes:' . implode(',', fluentboardsCsvMimes())
        ], [
            'file.mimetypes' => __('The file must be a valid CSV.', 'fluent-crm')
        ]);

        $delimeter = $request->get('delimiter', 'comma');

        if ($delimeter == 'comma') {
            $delimeter = ',';
        } else {
            $delimeter = ';';
        }

        $uploadedFiles = FileSystem::put($files);

        try {
            $csv = $this->getCsvReader(FileSystem::get($uploadedFiles[0]['file']));
            $csv->setDelimiter($delimeter);
            $headers = $csv->fetchOne();
        } catch (\Exception $exception) {
            return $this->sendError([
                'message' => $exception->getMessage()
            ]);
        }

        if (count($headers) != count(array_unique($headers))) {
            return $this->sendError([
                'message' => __('Looks like your csv has same name header multiple times. Please fix your csv first and remove any duplicate header column', 'fluent-crm')
            ]);
        }

        $mappables = Task::mappables();

        $headerItems = array_values(array_filter($headers));
        $taskColumns = array_keys($mappables);

        $maps = [];

        foreach ($headerItems as $headerItem) {
            $tableMap = (in_array($headerItem, $taskColumns)) ? $headerItem : null;

            if (!$tableMap) {
                $santizedItem = str_replace(' ', '_', strtolower($headerItem));
                if (in_array($santizedItem, $taskColumns)) {
                    $tableMap = $santizedItem;
                }
            }

            $maps[] = [
                'csv'   => $headerItem,
                'table' => $tableMap
            ];
        }

        $columns = apply_filters(
            'fluent_boards/task_table_columns', $taskColumns
        );

        return $this->send([
            'file'    => $uploadedFiles[0]['file'],
            'headers' => $headerItems,
            'fields'  => $mappables,
            'columns' => $columns,
            'map'     => $maps
        ]);
    }

    public function importBoard()
    {
        $inputs = $this->request->only([
            'map', 'file'
        ]);

        $delimeter = $this->request->get('delimiter', 'comma');

        $boardId = $this->request->get('board_id', null);

        if ($delimeter == 'comma') {
            $delimeter = ',';
        } else {
            $delimeter = ';';
        }

        try {
            $reader = $this->getCsvReader(FileSystem::get($inputs['file']));
            $reader->setDelimiter($delimeter);

            if (method_exists($reader, 'getRecords')) {

                $aHeaders = $reader->fetchOne(0);

                $allRecords = $reader->getRecords($aHeaders);

                if (!is_array($allRecords)) {
                    $allRecords = iterator_to_array($allRecords, true);
                }

                unset($allRecords[0]);
                $allRecords = array_values($allRecords);

            } else {
                $aHeaders = $reader->fetchOne(0);

                $allRecords = $reader->fetchAssoc($aHeaders);

                if (!is_array($allRecords)) {
                    $allRecords = iterator_to_array($allRecords, true);
                }

                unset($allRecords[0]);

                $allRecords = array_values($allRecords);

            }
        } catch (\Exception $exception) {
            return $this->sendError([
                'message' => $exception->getMessage()
            ]);
        }

        $page = $this->request->get('importing_page', 1);
        $processPerRequest = 100;
        $offset = ($page - 1) * $processPerRequest;
        $records = array_slice($allRecords, $offset, $processPerRequest);

        $allTasksToImport = [];

        foreach ($records as $record) {
            if (!array_filter($record)) {
                continue;
            }

            $task = [];
            foreach ($inputs['map'] as $map) {
                if (!$map['table']) {
                    continue;
                }
                if (isset($map['csv'], $map['table'])) {
                    $task[$map['table']] = trim($record[$map['csv']]);
                }
            }

            if (!empty($task)) {
                $allTasksToImport[] = $task;
            }
        }

        // Guard: no valid rows to import
        if (empty($allTasksToImport)) {
            FileSystem::delete($inputs['file']);
            if (!$boardId) {
                return $this->sendError([
                    'message' => __('No valid rows found to import. Please check your csv and try again.', 'fluent-boards-pro')
                ]);
            }
            return $this->sendSuccess([
                'board_id' => $boardId,
                'message' => __('Board has been imported successfully!', 'fluent-boards-pro')
            ]);
        }

        if ($boardId) {
            $board = Board::findOrFail($boardId);
        } else {
            //creating board
            $board = $this->createBoard($allTasksToImport[0]);
        }

        //creating stages
        $stageIds = [];
        // Check if any task contains the `stage` key
        $hasStageColumn = false;
        foreach ($allTasksToImport as $task) {
            if (is_array($task) && array_key_exists('stage', $task)) {
                $hasStageColumn = true;
                break;
            }
        }
        if (!$hasStageColumn) {
            $stageIds = $this->createDefaultStages($board);
        }
        $stageMapper = $this->createStages($board, $allTasksToImport);

        //creating tasks
        $this->createTasks($board, $allTasksToImport, $stageMapper, $stageIds);

        $completed = $offset + count($allTasksToImport);
        $totalCount = count($allRecords);
        $hasMore = $completed < $totalCount;
        if (!$hasMore) {
            FileSystem::delete($inputs['file']);
        }

        $message = __('Board has been imported successfully', 'fluent-boards-pro');

        return $this->sendSuccess([
            'total'      => $totalCount,
            'completed'  => count($allTasksToImport),
            'total_page' => ceil($totalCount / $processPerRequest),
            'has_more'   => $hasMore,
            'last_page'  => $page,
            'offset'     => $offset,
            'board_id'   => $board->id,
            'message'    => $message
        ]);
    }

    private function createBoard($task)
    {
        if (array_key_exists('board_title', $task) && $task['board_title']) {
            $boardName = $task['board_title'] . ' (imported)';
        } else {
            $boardName = 'Imported - Board name ' . gmdate("Y-m-d");
        }
        $boardData = [
            'title' => $boardName,
            'user_id' => get_current_user_id()
        ];

        $board = Board::create($boardData);

        if ($board) {
            //set user's default preferences
            Helper::setCurrentUserPreferencesOnBoardCreate($board);
            //set default Labels of Board
            Helper::createDefaultLabels($board->id);
            do_action('fluent_boards/board_created', $board);
        }

        return $board;
    }

    private function createDefaultStages($board)
    {
        $stageIds = [];
        $stageService = new StageService();
        $defaultStages = $stageService->defaultStages($board);
        foreach ($defaultStages as $stage) {
            $newStage = Stage::create($stage);
            $stageIds[] = $newStage->id;
        }
        return $stageIds;
    }

    private function createStages($board, $allTasks)
    {
        // Extract unique values for the 'name' key
        $stages = array_unique(array_column($allTasks, 'stage'));

        $stageService = new StageService();
        $latestStage = $stageService->getLastPositionOfStagesOfBoard($board->id);
        if ($latestStage) {
            $stagePosition = $latestStage->position + 1;
        } else {
            $stagePosition = 1;
        }
        $stageMapper = [];

        foreach ($stages as $stage) {
            if(strlen($stage)) {
                $existingStage = $this->checkIfStageAlreadyCreated($stage, $board);
                if ($existingStage) {
                    $stageMapper[$stage] = $existingStage->id;
                    continue;
                }
                $newStage = $board->stages()->create([
                    'title' => $stage,
                    'slug' => str::slug($stage),
                    'type' => 'stage',
                    'position' => $stagePosition++
                ]);

                $stageMapper[$stage] = $newStage->id;
            }
        }

        return $stageMapper;
    }

    private function checkIfStageAlreadyCreated($stageTitle, $board)
    {
        $currentStages = Stage::where('board_id', $board->id)->orderBy('position', 'asc')->get();

        foreach ($currentStages as $stage) {
            if ($stage->title == $stageTitle) {
                return $stage;
            }
        }

        return false;
    }

    private function createTasks($board, $tasks, $stageMapper = null, $stageIds = null)
    {
        foreach ($tasks as $index => $task) {
            if(empty($task['task_title'])) {
                continue;
            }
            $taskData = [
                'title' => $task['task_title'],
                'description' => !empty($task['description']) ? $task['description'] : null,
                'parent_id' => null,
                'source' => 'csv',
                'last_completed_at' => null,
                'due_at' => null,
                'started_at' => null,
                'position' => !empty($task['position']) ? $task['position'] : $index + 1,
                'archived_at' => !empty($task['archived_at']) ? $task['archived_at'] : null,
                'stage_id' => (!empty($task['stage']) && isset($stageMapper[$task['stage']]))
                    ? $stageMapper[$task['stage']]
                    : $stageIds[0] // Fallback to first stage ID
            ];


            if (!isset($task['status'])) {
                $taskData['staus'] = 'open';
            } else if ($task['status'] == 1) {
                $taskData['status'] = 'closed';
            } else if ($task['status'] == 'open' || $task['status'] == 'closed') {
                $taskData['status'] = $task['status'];
            } else {
                $taskData['status'] = 'open';
            }

            if (array_key_exists('due_at', $task) && $task['due_at']) {
                if (strpos($task['due_at'], '→') !== false)
                {
                    $dateAfterExplode = explode('→', $task['due_at']);
                    $taskData['started_at'] = gmdate('Y-m-d H:i:s', strtotime($dateAfterExplode[0]));
                    $taskData['due_at'] = gmdate('Y-m-d H:i:s', strtotime($dateAfterExplode[1]));
                } else {
                    $taskData['due_at'] = gmdate('Y-m-d H:i:s', strtotime($task['due_at']));
                }
            }

            if (array_key_exists('started_at', $task) && $task['started_at']) {
                if (strpos($task['started_at'], '→') !== false) {
                    $dateAfterExplode = explode('→', $task['started_at']);
                    $taskData['started_at'] = gmdate('Y-m-d H:i:s', strtotime($dateAfterExplode[0]));
                    $taskData['due_at'] = gmdate('Y-m-d H:i:s', strtotime($dateAfterExplode[1]));
                } else {
                    $taskData['started_at'] = gmdate('Y-m-d H:i:s', strtotime($task['started_at']));
                }
            }

            $createdTask = $board->tasks()->create($taskData);

            if (array_key_exists('subtasks', $task) && $task['subtasks']) {
                $subtasks = explode(',', $task['subtasks']);
                $defaultSubtaskGroup = $this->createDefaultSubtaskGroup($createdTask);
                foreach ($subtasks as $subtask) {
                    $subtaskData = [
                        'title' => trim($subtask),
                        'parent_id' => $createdTask->id,
                        'source' => 'csv',
                        'archived_at' => null
                    ];
                    $newSubtask = $board->tasks()->create($subtaskData);

                    TaskMeta::create([
                        'task_id' => $newSubtask->id,
                        'key' => Constant::SUBTASK_GROUP_CHILD,
                        'value' => $defaultSubtaskGroup->id
                    ]);
                }
            }
        }
    }

    private function getCsvReader($file)
    {
        if (!class_exists(' \League\Csv\Reader')) {
            include FLUENT_BOARDS_PLUGIN_PATH . 'app/Services/Libs/csv/autoload.php';
        }

        return \League\Csv\Reader::createFromString($file);
    }

    /**
     * @param $task
     * @return mixed
     * this function creating a default subtask group if it doesn't exist
     */
    private function createDefaultSubtaskGroup($task)
    {
        $group = TaskMeta::where('task_id', $task->id)
            ->where('key', Constant::SUBTASK_GROUP_NAME)
            ->first();

        if (!$group) {
            return TaskMeta::create([
                'task_id' => $task->id,
                'key' => Constant::SUBTASK_GROUP_NAME,
                'value' => 'Default Subtask Group'
            ]);
        }

        return $group;
    }
}
