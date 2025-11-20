<?php

namespace FluentBoardsPro\App\Hooks\Handlers;

use FluentBoards\App\Models\Attachment;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Webhook;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Libs\FileSystem;
use FluentBoards\Framework\Support\Arr;
use FluentBoardsPro\App\Models\TaskAttachment;
use FluentBoardsPro\App\Modules\CloudStorage\CloudStorageModule;


class ExternalPages
{
    protected $request;
    
    /*
     * Render board member invitation form for new user
     */
    public function boardMemberInvitation()
    {
        $boardId  = isset($_GET['bid']) ? sanitize_text_field($_GET['bid']) : '';
        $hashCode = isset($_GET['hash']) ? sanitize_text_field($_GET['hash']) : '';

        if (empty($boardId) || empty($hashCode)) {
            status_header(400);
            die('Invalid invite link');
        }

        (new InvitationHandler())->showInviteeRegistrationForm($boardId, $hashCode);
        exit();

    }

    public function view_attachment()
    {
        $attachmentHash = sanitize_text_field($_REQUEST['fbs_attachment']);

        if (empty($attachmentHash)) {
            die('Invalid Attachment Hash');
        }

        $attachment = $this->getAttachmentByHash($attachmentHash);

        if (in_array($attachment->object_type, [Constant::TASK_ATTACHMENT])) {
            $attachment->load('task');
        }

        if (!$attachment) {
            die('Invalid Attachment Hash');
        }

        // check signature hash
        if (!$this->validateAttachmentSignature($attachment)) {
            $dieMessage = __('Sorry, Your secure sign is invalid, Please reload the previous page and get new signed url', 'fluent-support');
            die($dieMessage);
        }

        if ('cloudflare_r2' == $attachment->driver) {
            if(!empty($attachment->file_path)){
                $this->serveRemoteAttachment2($attachment);
            }else{
                die('File could not be found');
            }
        }

        if ('amazon_s3' == $attachment->driver) {
            if(!empty($attachment->file_path)){
//                $this->redirectToExternalAttachment($attachment->full_url);
                $this->serveRemoteAttachment2($attachment);
            }else{
                die('File could not be found');
            }
        }

        if ('blackblaze_b2' == $attachment->driver) {
            if(!empty($attachment->file_path)){
                $this->serveRemoteAttachment2($attachment);
            }else{
                die('File could not be found');
            }
        }

        if ('digital_ocean' == $attachment->driver) {
            if(!empty($attachment->file_path)){
                $this->serveRemoteAttachment2($attachment);
            }else{
                die('File could not be found');
            }
        }

        //Handle Local file ( local driver)
        if (in_array($attachment->object_type, [Constant::TASK_ATTACHMENT])) {
            $fileName = $attachment->file_path;
            $boardId = $attachment->task->board_id;
        }

        $filePath = $fileName;
        if(!file_exists($fileName)){
            $filePath = FileSystem::setSubDir('board_' . $boardId)->getDir() . DIRECTORY_SEPARATOR . $fileName;
        }

        if (!file_exists($filePath)) {
            die('File could not be found.');
        }

        $this->serveLocalAttachment($attachment, $filePath);
    }

    private function getAttachmentByHash($attachmentHash)
    {
        return TaskAttachment::where('file_hash', $attachmentHash)->first();
    }

    private function validateAttachmentSignature($attachment)
    {
        $sign = md5($attachment->id . gmdate('YmdH'));
        return $sign === $_REQUEST['secure_sign'];
    }

    /*
     * Showing local attachment
    */
    private function serveLocalAttachment($attachment, $filePath)
    {
        ob_get_clean();
        header("Content-Type: {$attachment->attachment_type}");
        
        // Use attachment disposition for Microsoft Office files to prevent corruption errors
        $officeFileTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
        $disposition = in_array($attachment->attachment_type, $officeFileTypes) ? 'attachment' : 'inline';
        
        header("Content-Disposition: {$disposition}; filename=\"{$attachment->title}\"");
        header("Content-Length: " . filesize($filePath));
        header("Cache-Control: public");
        
        // Use readfile() correctly - it outputs the file content directly
        readfile($filePath);
        die();
    }

    public function serveRemoteAttachment($attachment)
    {
        /*
         * Private Bucket File Accessible. But let's hide this code
         * because, as there are other storage providers like s3, r2, b2 etc. if someone migrates from one to another,
         * this file fetch won't work as configs are different for each storage provider are not stored in the database.
         * so for now we are only serving public bucket files only.
         * There is another issue for same provider public and private.
         *  date: 2024-12-17, 4:33 PM
         *
         */
        $driver = (new CloudStorageModule())->getDriver();
        $fileContent = $driver->getObject($attachment->full_url);

        if ($fileContent === false) {
            die('Failed to fetch remote file');
        }

        $fileName = basename($attachment->full_url);
        
        ob_clean();
        // Set content headers before any output
        header('Content-Type: ' . $attachment->attachment_type);
        header('Content-Disposition: inline; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($fileContent->body));
        header('Cache-Control: public');
        
        // Output file content directly
        echo $fileContent->body;
        echo '<title>' . $attachment->title . '</title>';
        exit();
    }

    private function serveRemoteAttachment2($attachment)
    {
        /*
         * Has Public URL/Path
         * Return as masked. User cannot see the actual path.
         */
        ob_clean();
        
        $context = stream_context_create([
            'http' => [
                'follow_location' => true
            ]
        ]);
        
        $fileContent = file_get_contents($attachment->full_url, false, $context);
        
        if ($fileContent === false) {
            die('Failed to fetch remote file');
        }
        
        // Use attachment disposition for Microsoft Office files to prevent corruption errors
        $officeFileTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
        $disposition = in_array($attachment->attachment_type, $officeFileTypes) ? 'attachment' : 'inline';
        
        header('Cache-Control: public');
        header("Content-Type: {$attachment->attachment_type}");
        header("Content-Disposition: {$disposition}; filename=\"{$attachment->title}\"");
        header('Content-Length: ' . strlen($fileContent));
        
        echo $fileContent;
        exit();
    }

    private function redirectToExternalAttachment($redirectUrl)
    {
        wp_redirect($redirectUrl, 307);
        exit();
    }

    public function handleTaskWebhook()
    {
        $this->request = FluentBoards('request');

        // check if it's a POST request
        if ($this->request->method() != 'POST') {
            wp_send_json_error([
                'message' => __('Webhook must need to be as POST Method', 'fluent-boards'),
                'type'    => 'invalid_request_method'
            ], 200);
        }

        if (empty($hash = $this->request->get('hash'))) {
            wp_send_json_error([
                'message' => __('Invalid Webhook URL', 'fluent-crm'),
                'type'    => 'invalid_webhook_url'
            ], 200);
        }

        $webhook = $this->getWebhookByHash($hash);
        if (!$webhook) {
            wp_send_json_error([
                'message' => __('Invalid Webhook Hash', 'fluent-boards'),
                'type'    => 'invalid_webhook_hash'
            ], 200);
        }

        $postData = $this->request->get();

        if(empty($postData['title'])){
            wp_send_json_error([
                'message' => __('Task Title is required', 'fluent-boards'),
                'type'    => 'task_title_required'
            ], 200);
        }

        $postData = apply_filters('fluent_boards/incoming_webhook_data', $postData, $webhook);

        // Set default values in the first place
        $boardId = Arr::get($webhook->value, 'board');
        $stageId = Arr::get($webhook->value, 'stage');

        // Check if stage is provided in postData
        if (!empty($postData['stage'])) {
            $stage = Stage::where(function ($query) use ($postData) {
                $query->where('title', $postData['stage'])
                      ->orWhere('slug', $postData['stage']);
            })
                          ->where('board_id', $boardId)
                          ->first();
            if ($stage) {
                $stageId = $stage->id;
            }
        }

//        // Check again for stage and board match
//        $stageExists = Stage::where('id', $stageId)->where('board_id', $boardId)->first();
//        if (!$stageExists) {
//            wp_send_json_error([
//                'message' => __('Stage or Board Mismatched', 'fluent-boards'),
//                'type'    => 'invalid_stage_or_board'
//            ], 200);
//        }

        $data = $postData;
        $data['board_id'] = $boardId;
        $data['stage_id'] = $stageId;

        $data = apply_filters('fluent_boards/webhook_task_data', $data, $webhook);

        $task = FluentBoardsApi('tasks')->create($data);

        if(!$task){
            wp_send_json_error([
                'message' => __('Task Creation Failed', 'fluent-boards'),
                'type'    => 'task_creation_failed'
            ], 400);
        }
        wp_send_json_success([
           'message' => __('Task Created Successfully', 'fluent-boards'),
            'task'    => $task
        ], 200);
    }

    public function getWebhookByHash($hash)
    {
        return Webhook::where('key', $hash)->first();
    }

}