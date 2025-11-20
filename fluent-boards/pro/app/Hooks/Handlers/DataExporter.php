<?php

namespace FluentBoardsPro\App\Hooks\Handlers;

use FluentBoards\App\Models\Attachment;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Notification;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\Framework\Support\Str;
use FluentBoardsPro\App\Modules\TimeTracking\Model\TimeTrack;
use FluentBoardsPro\App\Services\ProHelper;
use FluentBoards\App\Services\Libs\FileSystem;
use FluentBoards\App\Models\Activity;
use FluentBoardsPro\App\Services\Constant as ProConstant;

class DataExporter
{
    private $request;

    public function exportTimeSheet()
    {
        $this->verifyRequest();
        $boardId = $this->request->get('board_id');

        $dateRange = ProHelper::getValidatedDateRange($this->request->get('date_range', []));

        $tracks = TimeTrack::when($this->request->get('board_id'), function ($q) use ($boardId) {
            $q->where('board_id', $boardId);
        })
            ->orderBy('updated_at', 'DESC')
            ->whereBetween('completed_at', $dateRange)
            ->with(['user', 'board', 'task' => function ($q) {
                $q->select('id', 'title', 'slug');
            }])
            ->whereHas('task')
            ->get();

        $writer = $this->getCsvWriter();
        $writer->insertOne([
            'Board',
            'Task',
            'Member',
            'Log Date',
            'Billable Hours',
            'Notes'
        ]);

        $rows = [];
        foreach ($tracks as $track) {
            $rows[] = [
                $this->sanitizeForCSV($track->board->title),
                $this->sanitizeForCSV($track->task->title),
                $this->sanitizeForCSV($track->user->display_name),
                $this->formatTime($track->completed_at, 'Y-m-d'),
                $this->minutesToHours($track->billable_minutes),
                $this->sanitizeForCSV($track->message)
            ];
        }

        $writer->insertAll($rows);
        $writer->output('time-sheet-' . gmdate('Y-m-d_H-i') . '.csv');
        die();
    }

    

    private function prepareSubtasksToExport($task)
    {
        $subtasks = $task->subtasks;
        $subtaskTitles = [];
        foreach ($subtasks as $subtask) {
            $subtaskTitles[] = $this->sanitizeForCSV($subtask->title);
        }
        return implode(', ', $subtaskTitles);
    }

    private function verifyRequest()
    {
        $this->request = FluentBoards('request');
        $boardId = $this->request->get('board_id');
        if (PermissionManager::isBoardManager($boardId)) {
            return true;
        }

        die('You do not have permission');
    }

    private function getCsvWriter()
    {
        if (!class_exists('\League\Csv\Writer')) {
            include FLUENT_BOARDS_PLUGIN_PATH . 'app/Services/Libs/csv/autoload.php';
        }

        return \League\Csv\Writer::createFromFileObject(new \SplTempFileObject());
    }

    private function sanitizeForCSV($content)
    {
        $formulas = ['=', '-', '+', '@', "\t", "\r"];

        if (Str::startsWith($content ?? '', $formulas)) {
            $content = "'" . $content;
        }

        return $content;
    }

    /*
     * Convert minutes to hours 30 mins as .5 hours
     */
    private function minutesToHours($minutes)
    {
        return round($minutes / 60, 2);
    }

    private function formatTime($time, $format = 'Y-m-d H:i:s')
    {
        return gmdate($format, strtotime($time));
    }

    public function exportBoardInCsv()
    {
        $this->verifyRequest();
        $boardId = $this->request->get('board_id');
        $userId = get_current_user_id(); // Get the current user ID

        if (!$boardId) {
            wp_die(__('Board ID is missing.', 'fluent-boards'));
        }
        
        $board = Board::findOrFail($boardId);
        $board->load(['stages', 'labels', 'users', 'customFields']);

        $existingMeta = $board->getMetaByKey(Constant::BOARD_CSV_EXPORT . '_' . $userId);
        if($existingMeta) {
            $metaData = \maybe_unserialize($existingMeta);

            // Clean up old file and meta record
            if (isset($metaData['file_path']) && file_exists($metaData['file_path'])) {
                unlink($metaData['file_path']);
            }
            Meta::where('object_id', $board->id)
                ->where('object_type', Constant::OBJECT_TYPE_BOARD)
                ->where('key', Constant::BOARD_CSV_EXPORT . '_' . $userId)
                ->delete();
        }
        

        $totalTasks = Task::where('board_id', $boardId)->whereNull('parent_id')->count();


        // generate file name based on board title, user id and timestamp
        $fileName = preg_replace('/\s+/', '_', $board->title) . '_user_id_' . $userId . '_' . time() . '.csv';
        $file_path = FileSystem::setSubDir('board_' . $boardId)->getDir() . DIRECTORY_SEPARATOR . $fileName;

        $boardMeta = [
            'status' => 'preparing',
            'progress' => 0,
            'user' => $userId,
            'file_path' => $file_path,
            'total_tasks' => $totalTasks
        ];

        $meta =  $board->updateMeta(Constant::BOARD_CSV_EXPORT . '_' . $userId, maybe_serialize($boardMeta));

        $boardMeta = \maybe_unserialize($meta->value);

        // If tasks are small, generate CSV immediately
        if ($totalTasks <= 400) {
            $this->prepareCsvExportFile($boardId, $meta->id, 0,  400);
            return wp_send_json_success([
                'status' => 'succeed',
                'progress' => $totalTasks,
                'totalTasks' => $totalTasks,
            ]);
        }

        $chunkSize = 200;

        // // Otherwise, process the first 200 tasks instantly
        $processed = $this->prepareSingleChunk($board, $meta, 0, $chunkSize);

        // Schedule the rest with action scheduler
        if ($totalTasks > $chunkSize) {
            as_enqueue_async_action('fluent_boards_prepare_csv_export_file', [
                $boardId,
                $meta->id,
                $processed,
                $chunkSize
            ]);
        }

        wp_send_json_success([
            'status' => 'file preparing',
            'progress' => $chunkSize,
            'message' => __('Processing initial batch, remaining tasks are scheduled.', 'fluent-boards'),
            'totalTasks' => $totalTasks
        ]);
        exit();
    }


    public function prepareCsvExportFile($boardId, $metaId, $offset = 0, $limit = 200)
    {

        $board = Board::findOrFail($boardId);

        if (!$board) {
            $dieMessage = __('Board Not Found!', 'fluent-boards');
            die($dieMessage);
        }

        $currentOffset = $offset;

        $meta = Meta::findOrFail($metaId);

        $boardMeta = \maybe_unserialize($meta->value);
        if (!$boardMeta) {
            return;
        }

        $totalTasks = $boardMeta['total_tasks'];
        $file_path = $boardMeta['file_path'];
        $startTime = time();
        $maxExecutionTime = ini_get('max_execution_time') - 5; // Leave some buffer time
        while (time() - $startTime < $maxExecutionTime && $currentOffset < $totalTasks) {
            $this->prepareSingleChunk($board, $meta, $currentOffset, $limit);
            $currentOffset += $limit;
        }

        // Schedule next chunk if there are remaining tasks
        if ($currentOffset < $totalTasks) {
            as_enqueue_async_action('fluent_boards_prepare_csv_export_file', [
                $boardId,
                $metaId,
                $currentOffset,
                $limit
                ]);
        } else {
            $boardMeta['status'] = 'succeed';
            $boardMeta['progress'] = $totalTasks;
            $meta->value = maybe_serialize($boardMeta);
            $meta->save();

            // Send notification to the user

            // create attachment then send notification and activity

            $fileUrl = ProHelper::getFullUrlByPath($file_path);

//            Attachment::create([
//                'status'    => 'active',
//                'object_id' => $boardId,
//                'object_type' => Constant::BOARD_ATTACHMENT,
//                'attachment_type' => 'application/csv',
//                'title' =>  "Exported CSV",
//                'file_path' => $file_path,
//                'full_url'  => $fileUrl,
//                'settings'  => '',
//                'driver'    => 'local'
//            ]);
//            $notification = Notification::create([
//                'object_id' => $boardId,
//                'object_type' => Constant::OBJECT_TYPE_BOARD_NOTIFICATION,
//                'activity_by' => $userId,
//                'action' => 'success',
//                'description' => 'Please click : <a href="' . $fileUrl . '">Download CSV</a>'
//            ]);
//
//            $notification->users()->attach($userId);

            

            // Activity::create([
            //     'object_id' => $boardId,
            //     'object_type' => Constant::ACTIVITY_BOARD,
            //     'activity_by' => $userId,
            //     'action' => 'exported_csv',
            //     'description' => "<a href='" . admin_url('admin-ajax.php?action=fluent_boards_export_csv_file_download&board_id=' . $boardId) . "'>Download CSV</a>",
            // ]);

        
        }

    }
    public function prepareSingleChunk($board, $meta, $offset = 0, $limit = 200)
    {

        if (!$board) {
            $dieMessage = __('Board Not Found!', 'fluent-boards');
            die($dieMessage);
        }

        if(!$meta) {
            $dieMessage = __('Meta Not Found!', 'fluent-boards');
            die($dieMessage);
        }
        $boardMeta = \maybe_unserialize($meta->value);

        $boardId = $board->id;

        $customFields = $board->customFields ?? [];

        // Define task properties
        $taskProperties = [
            'board_title', 'task_title', 'slug', 'type', 'status', 'stage', 'source', 'priority', 'description',
            'position', 'started_at', 'due_at', 'archived_at', 'subtasks'
        ];

        foreach ($customFields as $customField) {
            $taskProperties[] = $customField->slug;
        }

        $header = $taskProperties;

        $file_path = $boardMeta['file_path'];

        // Open the file in append mode
        if (!file_exists(dirname($file_path))) {
            wp_mkdir_p(dirname($file_path), 0755, true);
        }

        $file = fopen($file_path, 'a');

        if ($file === false) {
            return new \WP_Error('file_open_error', __('Failed to open file for writing.'));
        }

        // Write the header row to CSV if it's the first chunk
        if ($offset === 0) {
            fputcsv($file, $header);
        }

        $totalProcessed = $offset;

        $tasks = Task::where('board_id', $boardId)
            ->whereNull('parent_id')
            ->with(['stage', 'customFields'])
            ->skip($offset)
            ->take($limit)
            ->get();

        if ($tasks->isEmpty()) {
            return;
        }

        foreach ($tasks as $task) {
            foreach ($task->customFields as $customField) {
                $pivotSettings = maybe_unserialize($customField->pivot->settings);
                $task->{$customField->slug} = $pivotSettings['value'];
            }

            $row = [];
            foreach ($taskProperties as $property) {
                if ($property == 'stage') {
                    $row[] = $this->sanitizeForCSV($task->stage->title ?? '');
                } elseif ($property == 'board_title') {
                    $row[] = $this->sanitizeForCSV($task->board->title ?? '');
                } elseif ($property == 'task_title') {
                    $row[] = $this->sanitizeForCSV($task->title);
                } elseif ($property == 'subtasks') {
                    $row[] = $this->prepareSubtasksToExport($task);
                } else {
                    $row[] = $this->sanitizeForCSV($task->{$property});
                }
            }

            fputcsv($file, $row);
        }

        $offset += $limit;
        $totalProcessed += $tasks->count();

        $boardMeta['progress'] = $totalProcessed;

        // Update the meta with the current progress
        $meta->value = maybe_serialize($boardMeta);
        $meta->save();

        fclose($file);

        return $totalProcessed;
    }


    public function downloadBoardCsvFile()
    {
        $this->verifyRequest();
        $boardId = $this->request->get('board_id');
        $userId = get_current_user_id(); // Get the current user ID

        if (!$boardId) {
            wp_send_json([
                'success' => false,
                'message' => 'Board ID is missing.'
            ]);
        }

        $board = Board::findOrFail($boardId);

        $boardMeta = $board->getMetaByKey(Constant::BOARD_CSV_EXPORT . '_' . $userId);

        if($boardMeta) {
            $boardMeta = \maybe_unserialize($boardMeta);

            $status = $boardMeta['status'];
            $progress = $boardMeta['progress'];
            $totalTasks = $boardMeta['total_tasks'];
            $file_path = $boardMeta['file_path'];

            if ($status === 'succeed') {

                // Set headers for download
                header('Content-Description: File Transfer');
                header('Content-Type: application/csv');
                header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($file_path));

                // Read the file and send it to the output buffer
                readfile($file_path);

                // Delete the file after download
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
                // $boardMeta->delete();

                // Exit after the file is sent to prevent additional output
                exit();
            } else {
                wp_send_json([
                    'success' => false,
                    'message' => __('The export is not successful.', 'fluent-boards')
                ]);
            }

        } else {
            wp_send_json([
                'success' => false,
                'message' => __('Export not found.', 'fluent-boards')
            ]);
        }
        
    }

    public function exportCsvStatus()
    {
        $boardId = isset($_POST['board_id']) ? intval($_POST['board_id']) : null;

        if ($boardId === null) {
            wp_send_json_error([
                'message' => __('Board ID is missing.'),
                'status' => 400
            ]);
        }

        $meta = Meta::where('object_id', $boardId)
            ->where('key', Constant::BOARD_CSV_EXPORT . '_' . get_current_user_id())
            ->first();

        if ($meta) {
        
            $boardMeta = \maybe_unserialize($meta->value);

            $status = $boardMeta['status'];
            $progress = $boardMeta['progress'];
            $totalTasks = $boardMeta['total_tasks'];

            wp_send_json_success([
                'status' => $status,
                'progress' => $progress,
                'totalTasks' => $totalTasks
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Export status not found.'),
                'status' => 404
            ]);
        }
    }


    public function exportJsonStatus ()
    {
        $boardId = isset($_POST['board_id']) ? intval($_POST['board_id']) : null;

        if ($boardId === null) {
            wp_send_json_error([
                'message' => __('Board ID is missing.'),
                'status' => 400
            ]);
        }

        $meta = Meta::where('object_id', $boardId)
            ->where('key', Constant::BOARD_JSON_EXPORT . '_' . get_current_user_id())
            ->first();

        if ($meta) {
            $boardMeta = \maybe_unserialize($meta->value);

            $status = $boardMeta['status'];
            $progress = $boardMeta['progress'];
            $totalTasks = $boardMeta['total_tasks'];

            wp_send_json_success([
                'status' => $status,
                'progress' => $progress,
                'totalTasks' => $totalTasks
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Export status not found.'),
                'status' => 404
            ]);
        }
    }

    public function exportBoardInJson()
    {
        $this->verifyRequest();
        $boardId = $this->request->get('board_id');
        $userId = get_current_user_id(); // Get the current user ID

        if (!$boardId) {
            wp_die(__('Board ID is missing.', 'fluent-boards'));
        }

        $board = Board::findOrFail($boardId);
        $board->load(['stages', 'labels', 'users', 'customFields']);

        $existingMeta = $board->getMetaByKey(Constant::BOARD_JSON_EXPORT . '_' . $userId);
        if ($existingMeta) {
            $metaData = \maybe_unserialize($existingMeta);

            // Clean up old file and meta record
            if (isset($metaData['file_path']) && file_exists($metaData['file_path'])) {
                unlink($metaData['file_path']);
            }
            Meta::where('object_id', $board->id)
                ->where('object_type', Constant::OBJECT_TYPE_BOARD)
                ->where('key', Constant::BOARD_JSON_EXPORT . '_' . $userId)
                ->delete();
        }

        $totalTasks = Task::where('board_id', $boardId)->whereNull('parent_id')->count();

        // generate file name based on board title, user id and timestamp
        $fileName = preg_replace('/\s+/', '_', $board->title) . '_user_id_' . $userId . '_' . time() . '.json';
        $file_path = FileSystem::setSubDir('board_' . $boardId)->getDir() . DIRECTORY_SEPARATOR . $fileName;

        $boardMeta = [
            'status' => 'preparing',
            'progress' => 0,
            'user' => $userId,
            'file_path' => $file_path,
            'total_tasks' => $totalTasks
        ];

        $meta = $board->updateMeta(Constant::BOARD_JSON_EXPORT . '_' . $userId, maybe_serialize($boardMeta));

        // If tasks are small, generate JSON immediately
        if ($totalTasks <= 400) {
            $this->prepareJsonExportFile($boardId, $meta->id, 0, 400);
            return wp_send_json_success([
                'status' => 'succeed',
                'progress' => $totalTasks,
                'totalTasks' => $totalTasks,
            ]);
        }

        $chunkSize = 1000;

        // Otherwise, process the first chunk instantly
        $processed = $this->prepareSingleChunkForJsonExport($board, $meta, 0, $chunkSize);

        // Schedule the rest with action scheduler
        if ($totalTasks > $chunkSize) {
            as_enqueue_async_action('fluent_boards/prepare_json_export_file', [
                $boardId,
                $meta->id,
                $processed,
                $chunkSize
            ]);
        }

        wp_send_json_success([
            'status' => 'file preparing',
            'progress' => $chunkSize,
            'message' => __('Processing initial batch, remaining tasks are scheduled.', 'fluent-boards'),
            'totalTasks' => $totalTasks
        ]);
        exit();
    }

    public function downloadBoardJsonFile()
    {
        $this->verifyRequest();
        $boardId = $this->request->get('board_id');
        $userId = get_current_user_id();

        if (!$boardId) {
            wp_send_json([
                'success' => false,
                'message' => 'Board ID is missing.'
            ]);
        }

        $board = Board::findOrFail($boardId);
        $meta = $board->getMetaByKey(Constant::BOARD_JSON_EXPORT . '_' . $userId);

        if (!$meta) {
            wp_send_json([
                'success' => false,
                'message' => __('Export file not found. Please try exporting again.')
            ]);
        }

        $metaData = \maybe_unserialize($meta);
        $filePath = $metaData['file_path'];

        if (!file_exists($filePath)) {
            wp_send_json([
                'success' => false,
                'message' => __('Export file not found. Please try exporting again.')
            ]);
        }

        if ($metaData['status'] !== 'succeed') {
            wp_send_json([
                'success' => false,
                'message' => __('Export is still in progress. Please wait.')
            ]);
        }

        // Set headers for file download
        header('Content-Description: File Transfer');
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));

        // Output the file content
        readfile($filePath);

        // Clean up the file and meta after download
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        Meta::where('object_id', $board->id)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->where('key', Constant::BOARD_JSON_EXPORT . '_' . $userId)
            ->delete();

        exit();
    }

    public function prepareJsonExportFile($boardId, $metaId, $offset = 0, $limit = 1000)
    {
        $board = Board::findOrFail($boardId);
        $board->load(['stages', 'labels', 'users', 'customFields']);

        if (!$board) {
            $dieMessage = __('Board Not Found!', 'fluent-boards');
            die($dieMessage);
        }

        $currentOffset = $offset;
        $meta = Meta::findOrFail($metaId);
        $boardMeta = \maybe_unserialize($meta->value);
        
        if (!$boardMeta) {
            return;
        }

        $totalTasks = $boardMeta['total_tasks'];
        $file_path = $boardMeta['file_path'];

        $startTime = time();
        $maxExecutionTime = ini_get('max_execution_time') - 5; // Leave some buffer time

        // Process chunks within time limit
        while (time() - $startTime < $maxExecutionTime && $currentOffset < $totalTasks) {
            $this->prepareSingleChunkForJsonExport($board, $meta, $currentOffset, $limit);
            $currentOffset += $limit;
        }


        // Schedule next chunk if there are remaining tasks
        if ($currentOffset < $totalTasks) {
            as_enqueue_async_action('fluent_boards/prepare_json_export_file', [
                $boardId,
                $metaId,
                $currentOffset,
                $limit
            ]);
        } else {
            $boardMeta['status'] = 'succeed';
            $boardMeta['progress'] = $totalTasks;
            $meta->value = maybe_serialize($boardMeta);
            $meta->save();
        }
    }

    private function prepareSingleChunkForJsonExport($board, $meta, $offset = 0, $limit = 1000)
    {
        $boardMeta = \maybe_unserialize($meta->value);
        $file_path = $boardMeta['file_path'];

        // Ensure directory exists before writing file
        if (!file_exists(dirname($file_path))) {
            wp_mkdir_p(dirname($file_path));
        }

        // Load tasks with all relations for this chunk
        $tasks = Task::where('board_id', $board->id)
            ->whereNull('parent_id')
            ->with([
                'assignees',
                'watchers',
                'comments',
                'subtaskGroup.subtasks',
                'subtaskGroup.subtasks.assignees',
                'labels',
                'customFields'
            ])
            ->offset($offset)
            ->limit($limit)
            ->get();

        $processedCount = $tasks->count();

        if ($offset === 0) {
            // First chunk: load board with all relations except tasks, then add first chunk of tasks
            $board->load([
                'stages',
                'labels',
                'users',
                'customFields'
            ]);
            $boardArr = $board->toArray();
            $boardArr['tasks'] = $tasks->toArray();

            $data = [
                'key' => ProConstant::FLUENT_BOARDS_IMPORT,
                'site_url' => site_url('/'),
                'board' => $boardArr
            ];
        } else {
            // For subsequent chunks, read existing data and append tasks
            $existingContent = file_get_contents($file_path);
            $data = json_decode($existingContent, true);

            // Load the next chunk of tasks and append
            $existingTasks = $data['board']['tasks'] ?? [];
            $data['board']['tasks'] = array_merge($existingTasks, $tasks->toArray());
        }

        // Write JSON data to file
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT);
        file_put_contents($file_path, $jsonContent);

        // Update progress
        $boardMeta['progress'] = $offset + $processedCount;
        $meta->value = maybe_serialize($boardMeta);
        $meta->save();

        return $processedCount;
    }

}

