<?php

namespace FluentBoardsPro\App\Http\Controllers;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;

use FluentBoards\App\Services\Libs\FileSystem;
use FluentBoards\App\Services\UploadService;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\Framework\Support\Arr;
use FluentBoardsPro\App\Services\AttachmentService;

class AttachmentController extends Controller
{

    protected $attachmentService;
    public function __construct(AttachmentService $attachmentService)
    {
        parent::__construct();
        $this->attachmentService = $attachmentService;
    }

    public function deleteTaskAttachment($task_id, $attachment_id)
    {
        try {
            return $this->sendSuccess([
                'message'     => __('Task attachment has been deleted', 'fluent-boards-pro'),
                'attachments' => $this->attachmentService->deleteTaskAttachment($task_id, $attachment_id),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function updateTaskAttachment($task_id, $attachment_id, Request $request)
    {
        $attachMentData = $this->taskAttachmentSanitizeAndValidate($request->all(), [
            'title' => 'required|string',
        ]);
        try {
            return $this->sendSuccess([
                'message'    => __('Task attachment has been updated', 'fluent-boards-pro'),
                'attachment' => $this->attachmentService->updateTaskAttachment($attachment_id, $attachMentData['title']),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function getAttachments($board_id, $task_id)
    {
        try {
            $task = Task::find($task_id);
            return $this->sendSuccess([
                'attachments' => $task->attachments
            ]);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function addTaskAttachment(Request $request, $task_id)
    {
        $attachmentData = $this->taskAttachmentSanitizeAndValidate($request->all(), [
            'title' => 'nullable|string',
            'url'   => 'required|url',
        ]);
        try {
            $attachmentData['type'] = 'url';
            return $this->sendSuccess([
                'message'    => __('Attachment has been added to task', 'fluent-boards-pro'),
                'attachment' => $this->attachmentService->handleAttachment($task_id, $attachmentData),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function addTaskAttachmentFile(Request $request, $board_id, $task_id)
    {
        try {
            $filesToBeProcessed = $request->fileCollection();

            $files = Arr::get($filesToBeProcessed, 'file', []);
            $attachments = [];
            foreach ($files as $file) {
//                (new UploadService())->validateFile($file->toArray());
                $uploadInfo = UploadService::handleFileUpload($file, $board_id, $task_id);
                $fileData = $uploadInfo[0];

                // Call the unified attachment handler
                $attachments[] = $this->attachmentService->handleAttachment($task_id, $fileData, $file);
            }

            return $this->sendSuccess([
                'message'    => __('Task attachment has been added', 'fluent-boards-pro'),
                'attachments' => $attachments
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }


    }

    private function taskAttachmentSanitizeAndValidate($data, array $rules = [])
    {
        $data = Helper::sanitizeTask($data);
        return $this->validate($data, $rules);
    }

}
