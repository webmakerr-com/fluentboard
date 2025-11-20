<?php

namespace FluentBoardsPro\App\Hooks\Handlers;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\User;

class OutWebhookHandler
{


    /**
     * Register webhook trigger actions here
     *
     * @return void
     */
    public function ListenWebhookActions()
    {
        add_action('fluent_boards/task_created', [$this, 'queueTaskCreatedWebhook'], 10, 1);
        add_action('fluent_boards/task_completed_activity', [$this, 'queueTaskCompletedWebhook'], 10, 2);   
        add_action('fluent_boards/task_stage_updated', [$this, 'queueTaskStageUpdatedWebhook'], 10, 2);
        add_action('fluent_boards/task_date_changed', [$this, 'queueTaskDateChangedWebhook'], 10, 2);
        add_action('fluent_boards/task_priority_changed', [$this, 'queueTaskPriorityChangedWebhook'], 10, 2);
        add_action('fluent_boards/task_label', [$this, 'queueTaskLabelWebhook'], 10, 3);
        add_action('fluent_boards/board_stage_added', [$this, 'queueStageAddedWebhook'], 10, 2);
        add_action('fluent_boards/comment_created', [$this, 'queueCommentCreatedWebhook'], 10, 1);
        add_action('fluent_boards/task_assignee_added', [$this, 'queueTaskAssigneeAddedWebhook'], 10, 2);
        add_action('fluent_boards/task_archived', [$this, 'queueTaskArchivedWebhook'], 10, 1);
    }

    private function defaultHeaders($format = 'JSON')
    {
        if (strtoupper($format) === 'FORM') {
            return [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ];
        }
        
        return [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json'
        ];
    }

    private function defaultArgs($method, $timeout = 15, $headers = [], $format = 'JSON')
    {
        $defaultHeaders = $this->defaultHeaders($format);
        return [
            'method'  => $method,
            'timeout' => $timeout,
            'headers' => array_merge($defaultHeaders, $headers)
        ];
    }

    private function flattenForForm($data, $prefix = '')
    {
        $result = [];
        foreach ($data as $key => $value) {
            $newKey = $prefix ? $prefix . '[' . $key . ']' : $key;
            if (is_array($value) || is_object($value)) {
                $result = array_merge($result, $this->flattenForForm($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }
        return $result;
    }

    private function isValidUrl($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

     /**
     * Shared method to queue a webhook event for async processing
     *
     * @param int $boardId The board ID
     * @param string $eventSlug The event slug (e.g., 'task_created')
     * @param mixed $model The primary model (e.g., Task or Comment instance)
     * @param string $asyncAction The async action name (e.g., 'fluent_boards/async_task_created_webhook')
     * @param string $group The async group (standardized to 'fluent-boards')
     * @return void
     */
    private function queueWebhookEvent($boardId, $eventSlug, $model, $asyncAction, $group = 'fluent-boards')
    {
        $webhookByBoard = Relation::where('object_type', 'outgoing_webhook_board')
                             ->where('foreign_id', $boardId)
                             ->get();
    
        if (empty($webhookByBoard)) {
            return;
        }
    
        $processableWebhookIds = [];
    
        foreach ($webhookByBoard as $relation) {
            $settings = \maybe_unserialize($relation->settings);
            if (is_array($settings) && in_array($eventSlug, $settings)) {
                $processableWebhookIds[] = $relation->object_id;
            }
        }
    
        if (empty($processableWebhookIds)) {
            return;
        }
    
        as_enqueue_async_action($asyncAction, [$model, $processableWebhookIds, $eventSlug], $group);
    }

    public function queueTaskCreatedWebhook($task)
    {
        $taskDataToSend = clone $task;
        $creatorId = is_array($taskDataToSend) ? $taskDataToSend['created_by'] : $taskDataToSend->created_by;
        if (is_null($creatorId)) {
            $creatorName = '';
        } else {
            $user = User::find($creatorId);
            $creatorName = $user ? $user->display_name : '';
        }
        if (is_array($taskDataToSend)) {
            $taskDataToSend['event_description'] = sprintf(__('\'%s\'has created task \'%s\'', 'fluent-boards'), $creatorName, $taskDataToSend['title']);
        } elseif (is_object($taskDataToSend)) {
            $taskDataToSend->event_description = sprintf(__('\'%s\' has created task \'%s\'', 'fluent-boards'), $creatorName, $taskDataToSend->title);
        }
        $this->queueWebhookEvent($taskDataToSend['board_id'], 'task_created', $taskDataToSend, 'fluent_boards/async_task_created_webhook');
    }

     public function queueTaskCompletedWebhook($task, $value)
     {
         if ($value != 'closed') {
             return;
         }
         $taskDataToSend = clone $task;
         if (is_array($taskDataToSend) && isset($taskDataToSend['board']['meta'])) {
             unset($taskDataToSend['board']['meta']);
         } elseif (is_object($taskDataToSend) && isset($taskDataToSend->board->meta)) {
             unset($taskDataToSend->board->meta);
         }

         if (is_array($taskDataToSend)) {
             $taskDataToSend['event_description'] = sprintf(__('Task \'%s\' has been completed', 'fluent-boards'), $taskDataToSend['title']);
         } elseif (is_object($taskDataToSend)) {
             $taskDataToSend->event_description = sprintf(__('Task \'%s\' has been completed', 'fluent-boards'), $taskDataToSend->title);
         }
         
         $this->queueWebhookEvent($taskDataToSend['board_id'], 'task_closed', $taskDataToSend, 'fluent_boards/async_task_completed_webhook', 'fluent-boards');
     }

    public function queueTaskStageUpdatedWebhook($task, $oldStageId)
    {
        $taskDataToSend = clone $task;
        if (is_array($taskDataToSend) && isset($taskDataToSend['board']['meta'])) {
             unset($taskDataToSend['board']['meta']);
        } elseif (is_object($taskDataToSend) && isset($taskDataToSend->board->meta)) {
             unset($taskDataToSend->board->meta);
        }

        $oldStage = Stage::find($oldStageId);
        $oldStageName = $oldStage->title;
        if ($oldStage) {
            if (is_array($taskDataToSend)) {
                $taskDataToSend['old_stage'] = $oldStage->toArray();
            } elseif (is_object($taskDataToSend)) {
                $taskDataToSend->old_stage = $oldStage;
            }
        }

        if (is_array($taskDataToSend)) {
            $currentStage = isset($taskDataToSend['stage']['title']) ? $taskDataToSend['stage']['title'] : __('Unknown', 'fluent-boards-pro');
            $taskDataToSend['event_description'] = sprintf(__('Task \'%s\' has been moved from \'%s\' to \'%s\'', 'fluent-boards'), $taskDataToSend['title'], $oldStageName, $currentStage);
        } elseif (is_object($taskDataToSend)) {
            $currentStage = isset($taskDataToSend->stage->title) ? $taskDataToSend->stage->title : __('Unknown', 'fluent-boards-pro');
            $taskDataToSend->event_description = sprintf(__('Task \'%s\' has been moved from \'%s\' to \'%s\'', 'fluent-boards'), $taskDataToSend->title, $oldStageName, $currentStage);
        }
        
        $this->queueWebhookEvent($taskDataToSend['board_id'], 'task_stage_changed', $taskDataToSend, 'fluent_boards/async_task_stage_updated_webhook', 'fluent-boards');
    }

    public function queueTaskDateChangedWebhook($task, $oldDates)
    {
        $taskDataToSend = clone $task;
        
        // Remove board data completely
        if (is_array($taskDataToSend) && isset($taskDataToSend['board'])) {
             unset($taskDataToSend['board']);
        } elseif (is_object($taskDataToSend) && isset($taskDataToSend->board)) {
             unset($taskDataToSend->board);
        }

        // Add old dates information
        if (is_array($taskDataToSend)) {
            $taskDataToSend['old_dates'] = $oldDates;
        } elseif (is_object($taskDataToSend)) {
            $taskDataToSend->old_dates = $oldDates;
        }

        // Create event description based on what changed
        $eventDescription = '';
        $taskTitle = is_array($taskDataToSend) ? $taskDataToSend['title'] : $taskDataToSend->title;
        
        if (isset($taskDataToSend['due_at']) || isset($taskDataToSend['started_at'])) {
            if (isset($oldDates['due_at']) && isset($oldDates['started_at']) && ($oldDates['due_at'] != $taskDataToSend['due_at'] && $oldDates['started_at'] != $taskDataToSend['started_at'])) {
                $eventDescription = sprintf(__('Task \'%s\' dates have been updated', 'fluent-boards'), $taskTitle);
            }  elseif (isset($taskDataToSend['started_at']) && $oldDates['started_at'] != $taskDataToSend['started_at']) {
                $eventDescription = sprintf(__('Task \'%s\' start date has been updated', 'fluent-boards'), $taskTitle);
            } elseif (isset($taskDataToSend['due_at']) && $oldDates['due_at'] != $taskDataToSend['due_at']) {
                $eventDescription = sprintf(__('Task \'%s\' due date has been updated', 'fluent-boards'), $taskTitle);
            }
        } else {
            $eventDescription = sprintf(__('Task \'%s\' dates have been removed', 'fluent-boards'), $taskTitle);
        }

        if (is_array($taskDataToSend)) {
            $taskDataToSend['event_description'] = $eventDescription;
        } elseif (is_object($taskDataToSend)) {
            $taskDataToSend->event_description = $eventDescription;
        }
        
        $this->queueWebhookEvent($task->board_id, 'task_date_changed', $taskDataToSend, 'fluent_boards/async_task_date_changed_webhook', 'fluent-boards');
    }

    public function queueTaskPriorityChangedWebhook($task, $oldPriority)
    {
        $taskDataToSend = clone $task;
        
        // Remove board data completely
        if (is_array($taskDataToSend) && isset($taskDataToSend['board'])) {
             unset($taskDataToSend['board']);
        } elseif (is_object($taskDataToSend) && isset($taskDataToSend->board)) {
             unset($taskDataToSend->board);
        }

        // Add old priority information
        if (is_array($taskDataToSend)) {
            $taskDataToSend['old_priority'] = $oldPriority;
        } elseif (is_object($taskDataToSend)) {
            $taskDataToSend->old_priority = $oldPriority;
        }

        // Create event description
        $taskTitle = is_array($taskDataToSend) ? $taskDataToSend['title'] : $taskDataToSend->title;
        $currentPriority = is_array($taskDataToSend) ? $taskDataToSend['priority'] : $taskDataToSend->priority;
        
        $priorityLabels = [
            'low' => __('Low', 'fluent-boards'),
            'medium' => __('Medium', 'fluent-boards'),
            'high' => __('High', 'fluent-boards'),
            'urgent' => __('Urgent', 'fluent-boards')
        ];
        
        $oldPriorityLabel = isset($priorityLabels[$oldPriority]) ? $priorityLabels[$oldPriority] : ucfirst($oldPriority);
        $currentPriorityLabel = isset($priorityLabels[$currentPriority]) ? $priorityLabels[$currentPriority] : ucfirst($currentPriority);
        
        $eventDescription = sprintf(__('Task \'%s\' priority has been changed from \'%s\' to \'%s\'', 'fluent-boards'), $taskTitle, $oldPriorityLabel, $currentPriorityLabel);

        if (is_array($taskDataToSend)) {
            $taskDataToSend['event_description'] = $eventDescription;
        } elseif (is_object($taskDataToSend)) {
            $taskDataToSend->event_description = $eventDescription;
        }
        
        $this->queueWebhookEvent($task->board_id, 'task_priority_changed', $taskDataToSend, 'fluent_boards/async_task_priority_changed_webhook', 'fluent-boards');
    }

    public function queueTaskLabelWebhook($task, $label, $action)
    {
        $taskDataToSend = clone $task;
        
        // Remove board data completely
        if (is_array($taskDataToSend) && isset($taskDataToSend['board'])) {
             unset($taskDataToSend['board']);
        } elseif (is_object($taskDataToSend) && isset($taskDataToSend->board)) {
             unset($taskDataToSend->board);
        }

        // Add label information
        if (is_array($taskDataToSend)) {
            $taskDataToSend['label'] = $label;
            $taskDataToSend['label_action'] = $action;
        } elseif (is_object($taskDataToSend)) {
            $taskDataToSend->label = $label;
            $taskDataToSend->label_action = $action;
        }

        // Create event description based on action
        $taskTitle = is_array($taskDataToSend) ? $taskDataToSend['title'] : $taskDataToSend->title;
        $labelTitle = is_array($label) ? $label['title'] : $label->title;
        
        $eventType = '';
        $eventDescription = '';
        
        if ($action === 'added') {
            $eventType = 'task_label_added';
            // Handle labels without text/title
            if (empty($labelTitle)) {
                $eventDescription = sprintf(__('Label has been added to task \'%s\'', 'fluent-boards'), $taskTitle);
            } else {
                $eventDescription = sprintf(__('Label \'%s\' has been added to task \'%s\'', 'fluent-boards'), $labelTitle, $taskTitle);
            }
        } elseif ($action === 'removed') {
            $eventType = 'task_label_removed';
            // Handle labels without text/title
            if (empty($labelTitle)) {
                $eventDescription = sprintf(__('Label has been removed from task \'%s\'', 'fluent-boards'), $taskTitle);
            } else {
                $eventDescription = sprintf(__('Label \'%s\' has been removed from task \'%s\'', 'fluent-boards'), $labelTitle, $taskTitle);
            }
        }

        if (is_array($taskDataToSend)) {
            $taskDataToSend['event_description'] = $eventDescription;
        } elseif (is_object($taskDataToSend)) {
            $taskDataToSend->event_description = $eventDescription;
        }
        
        $asyncAction = 'fluent_boards/async_' . $eventType . '_webhook';
        $this->queueWebhookEvent($task->board_id, $eventType, $taskDataToSend, $asyncAction, 'fluent-boards');
    }


    public function queueStageAddedWebhook($stage, $board)
    {
        $stageDataToSend = clone $stage;
        
        // Remove any unnecessary data
        if (is_array($stageDataToSend) && isset($stageDataToSend['board'])) {
             unset($stageDataToSend['board']);
        } elseif (is_object($stageDataToSend) && isset($stageDataToSend->board)) {
             unset($stageDataToSend->board);
        }

        // Add board information
        if (is_array($stageDataToSend)) {
            $stageDataToSend['board_info'] = [
                'id' => $board->id,
                'title' => $board->title
            ];
        } elseif (is_object($stageDataToSend)) {
            $stageDataToSend->board_info = (object)[
                'id' => $board->id,
                'title' => $board->title
            ];
        }

        // Create event description
        $stageTitle = is_array($stageDataToSend) ? $stageDataToSend['title'] : $stageDataToSend->title;
        $boardTitle = $board->title;
        $eventDescription = sprintf(__('Stage \'%s\' has been added to board \'%s\'', 'fluent-boards'), $stageTitle, $boardTitle);

        if (is_array($stageDataToSend)) {
            $stageDataToSend['event_description'] = $eventDescription;
        } elseif (is_object($stageDataToSend)) {
            $stageDataToSend->event_description = $eventDescription;
        }
        
        $this->queueWebhookEvent($board->id, 'stage_added', $stageDataToSend, 'fluent_boards/async_stage_added_webhook', 'fluent-boards');
    }

    public function queueCommentCreatedWebhook($comment)
    {
        $commentedById = is_array($comment) ? $comment['created_by'] : $comment->created_by;
        $commentedByName = User::find($commentedById)->display_name;
        $commentTextFull = is_array($comment) ? $comment['settings']['raw_description'] : $comment->settings['raw_description'];
        $commentText = strlen($commentTextFull) > 50 ? substr($commentTextFull, 0, 47) . '...' : $commentTextFull;
        $taskTitle = is_array($comment) ? $comment['task']['title'] : $comment->task['title'];
        if (is_array($comment)) {
            $comment['event_description'] = sprintf(__('\'%s\' has added comment \'%s\' to task \'%s\'', 'fluent-boards'), $commentedByName, $commentText, $taskTitle);
        } elseif (is_object($comment)) {
            $comment->event_description = sprintf(__('\'%s\' has added comment \'%s\' to task \'%s\'', 'fluent-boards'), $commentedByName, $commentText, $taskTitle);
        }
        
        $this->queueWebhookEvent($comment['board_id'], 'comment_created', $comment, 'fluent_boards/async_comment_created_webhook', 'fluent-boards');
    }

    public function queueTaskAssigneeAddedWebhook($task, $assignee)
    {
         $taskDataToSend = clone $task;
         if (is_array($taskDataToSend) && isset($taskDataToSend['board']['meta'])) {
             unset($taskDataToSend['board']['meta']);
         } elseif (is_object($taskDataToSend) && isset($taskDataToSend->board->meta)) {
             unset($taskDataToSend->board->meta);
         }

         $assigneeObject = User::find($assignee);

         if (is_array($taskDataToSend)) {
             $taskDataToSend['added_assignee'] = $assigneeObject;
         } elseif (is_object($taskDataToSend)) {
             $taskDataToSend->added_assignee = $assigneeObject;
         }
         $assigneeName = $assigneeObject->display_name ;
         if (is_array($taskDataToSend)) {
             $taskDataToSend['event_description'] = sprintf(__('\'%s\' has been assigned to task \'%s\'', 'fluent-boards'), $assigneeName, $taskDataToSend['title']);
         } elseif (is_object($taskDataToSend)) {
             $taskDataToSend->event_description = sprintf(__('\'%s\' has been assigned to task \'%s\'', 'fluent-boards'), $assigneeName, $taskDataToSend->title);
         }
         
        $this->queueWebhookEvent($taskDataToSend['board_id'], 'assignee_added', $taskDataToSend, 'fluent_boards/async_task_assignee_added_webhook', 'fluent-boards');
    }

    public function queueTaskArchivedWebhook($task)
    {
         $taskDataToSend = clone $task;
         if (is_array($taskDataToSend) && isset($taskDataToSend['board']['meta'])) {
             unset($taskDataToSend['board']['meta']);
         } elseif (is_object($taskDataToSend) && isset($taskDataToSend->board->meta)) {
             unset($taskDataToSend->board->meta);
         }
         
         if (is_array($taskDataToSend)) {
             $taskDataToSend['event_description'] = sprintf(__('Task \'%s\' has been archived', 'fluent-boards'), $taskDataToSend['title']);
         } elseif (is_object($taskDataToSend)) {
             $taskDataToSend->event_description = sprintf(__('Task \'%s\' has been archived', 'fluent-boards'), $taskDataToSend->title);
         }

        $this->queueWebhookEvent($taskDataToSend['board_id'], 'task_archived', $taskDataToSend, 'fluent_boards/async_task_archived_webhook', 'fluent-boards');
    }

    public function handleWebhookRequest($task, $processableWebhookIds, $eventSlug)
    {
        $outgoingWebhooks = Meta::where('object_type', 'outgoing_webhook')
            ->whereIn('id', $processableWebhookIds)
            ->pluck('value')
            ->toArray();

        if (empty($outgoingWebhooks)) {
            return;
        }

        foreach ($outgoingWebhooks as $webhook) {
            if (empty($webhook['url']) || !filter_var($webhook['url'], FILTER_VALIDATE_URL)) {
                continue;
            }

            if (isset($webhook['status']) && $webhook['status'] !== 'active') {
                continue;
            }


            $message = '';
            $taskDataCopy = $task;
            
            if (is_array($taskDataCopy) && isset($taskDataCopy['event_description'])) {
                $message = $taskDataCopy['event_description'];
                unset($taskDataCopy['event_description']);
            } elseif (is_object($taskDataCopy) && isset($taskDataCopy->event_description)) {
                $message = $taskDataCopy->event_description;
                unset($taskDataCopy->event_description);
            }

            $taskData = [
                'event' => $eventSlug,
                'message' => $message,
                'data' => $taskDataCopy,
            ];

            $url = $webhook['url'];
            $method = isset($webhook['method']) ? strtoupper($webhook['method']) : 'POST';
            $format = isset($webhook['format']) ? strtoupper($webhook['format']) : 'JSON';
            $headers = [];
            if (!empty($webhook['headers']) && is_array($webhook['headers'])) {
                foreach ($webhook['headers'] as $header) {
                    if (isset($header['name']) && isset($header['value'])) {
                        $headers[$header['name']] = $header['value'];
                    }
                }
            }

            if ($method === 'GET') {
                $getTaskData = [
                    'event' => $taskData['event'],
                    'task_id' => $task['id'],
                    'timestamp' => time(),
                ];
                $this->sendGet($url, $getTaskData, $headers, $format);
            } else {
                $this->sendPost($url, $taskData, $headers, $format);
            }
        }
    }

    /**
     * Send a GET request to the specified webhook URL
     *
     * @param string $url The webhook URL to send the request to
     * @param array $params Optional query parameters to append to the URL
     * @param array $headers Optional headers to send with the request
     * @param int $timeout Request timeout in seconds
     * @return array Response array containing 'success' boolean and 'data'/'error' message
     */
    public function sendGet($url, $params = [], $headers = [], $format = 'JSON', $timeout = 15)
    {
        try {
            // Add query parameters to URL if provided
            if (!empty($params)) {
                if (strtoupper($format) === 'FORM') {
                    // For form format, flatten the data and don't JSON encode

                    $params = $this->flattenForForm($params);
                } else {
                    // Convert complex data structures to JSON strings
                    foreach ($params as $key => $value) {
                        if (is_array($value) || is_object($value)) {
                            $params[$key] = json_encode($value);
                        }
                    }
                }
                $url = add_query_arg($params, $url);
            }

            // Validate URL
            if (!$this->isValidUrl($url)) {
                return [
                    'success' => false,
                    'error'   => 'Invalid URL provided.'
                ];
            }

            $args = $this->defaultArgs('GET', $timeout, $headers, $format);
            $response = wp_remote_get($url, $args);
    
            if (is_wp_error($response)) {
                return [
                    'success' => false,
                    'error'   => $response->get_error_message()
                ];
            }
    
            $body = wp_remote_retrieve_body($response);
            $code = wp_remote_retrieve_response_code($response);
    
            if ($code >= 200 && $code < 300) {
                return [
                    'success' => true,
                    'data'    => json_decode($body, true) ?: $body
                ];
            }

            return [
                'success' => false,
                'error'   => "Request failed with status code: {$code}",
                'body'    => $body
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }

    /**
     * Send a POST request to the specified webhook URL
     *
     * @param string $url The webhook URL to send the request to
     * @param array|string $data The data to send in the request body
     * @param array $headers Optional headers to send with the request
     * @param int $timeout Request timeout in seconds
     * @return array Response array containing 'success' boolean and 'data'/'error' message
     */
    public function sendPost($url, $data, $headers = [], $format = 'JSON', $timeout = 15)
    {
        try {
            // Validate URL
            if (!$this->isValidUrl($url)) {
                return [
                    'success' => false,
                    'error'   => 'Invalid URL provided.'
                ];
            }

            $args = $this->defaultArgs('POST', $timeout, $headers, $format);
            
            if (strtoupper($format) === 'FORM') {
                $args['body'] = http_build_query($this->flattenForForm($data));
            } else {
                $args['body'] = is_string($data) ? $data : json_encode($data);
            }
            
            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                return [
                    'success' => false,
                    'error'   => $response->get_error_message()
                ];
            }

            $body = wp_remote_retrieve_body($response);
            $code = wp_remote_retrieve_response_code($response);

            if ($code >= 200 && $code < 300) {
                return [
                    'success' => true,
                    'data'    => json_decode($body, true) ?: $body
                ];
            }

            return [
                'success' => false,
                'error'   => "Request failed with status code: {$code}",
                'body'    => $body
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }
}
