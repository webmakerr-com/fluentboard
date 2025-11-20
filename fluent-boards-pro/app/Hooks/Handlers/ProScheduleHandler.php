<?php

namespace FluentBoardsPro\App\Hooks\Handlers;

use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\Framework\Support\Arr;

class ProScheduleHandler
{
    public function fiveMinutesScheduler()
    {
        $this->processTaskReminders();

    }
    public function hourlyScheduler()
    {
        $this->scheduleDailyTaskReminder();
    }
    public function dailyScheduler()
    {
        $this->maybeRemoveOldScheuledActionLogs();
    }

    public function recurringTaskScheduler()
    {
        $modules = fluent_boards_get_pref_settings(false);
        if($modules['recurring_task']['enabled'] == 'yes' && !as_next_scheduled_action('fluent_boards/repeat_task_scheduler')) {
            as_schedule_recurring_action(time() + MINUTE_IN_SECONDS * 5, MINUTE_IN_SECONDS * 5, 'fluent_boards/repeat_task_scheduler', [], 'fluent-boards');
        }
    }

    public function scheduleDailyTaskReminder()
    {
        $this->schedule();
    }
    public function schedule()
    {
        $remind_at = Arr::get(fluent_boards_get_option('general_settings'), 'daily_remind_at', '08:00'); // Default time is 8:00 AM
        $is_reminder_enabled = Arr::get(fluent_boards_get_option('general_settings'), 'daily_reminder_enabled') == 'true' ? true : false;

        if ( ! as_next_scheduled_action('fluent_boards/daily_task_reminder') && $is_reminder_enabled) {

            $currentTime = current_time('timestamp');
            $localTimeZone = new \DateTimeZone(wp_timezone_string());
            $localDateTime = new \DateTime('now', $localTimeZone);
            $utcOffsetInSeconds = $localDateTime->getOffset();

            // Convert $remind_at to a timestamp for the current day
            $timeAtReminderLocal = strtotime(gmdate('Y-m-d', $currentTime) . ' ' . $remind_at);
            $timeAtReminderUTC = $timeAtReminderLocal - $utcOffsetInSeconds;

            if ($currentTime >= $timeAtReminderLocal) {
                // If current time is greater than or equal to the reminder time, schedule it for the next day at the reminder time
                $nextRunDateTimeLocal = strtotime('tomorrow ' . $remind_at, $currentTime);
                $nextRunDateTimeUTC = $nextRunDateTimeLocal - $utcOffsetInSeconds;
            } else {
                // Schedule it for today at the reminder time
                $nextRunDateTimeUTC = $timeAtReminderUTC;
            }

            // Schedule the single action
            as_schedule_single_action($nextRunDateTimeUTC, 'fluent_boards/daily_task_reminder', [], 'fluent-boards');

        }
    }

    /**
     * Clear all the scheduled actions for Fluent Boards on deactivation
     * @return void
     */
    public function clear_All_FBS_Scheduler()
    {
        $this->clearDailyTaskReminderScheduler();
        $this->clearFiveMinutesScheduler();
        $this->clearHourlyScheduler();
        $this->clearRepeatTaskScheduler();
    }

    /**
     * Clear the daily task reminder scheduler
     * @return void
     */
    public function clearDailyTaskReminderScheduler()
    {
        if(as_next_scheduled_action('fluent_boards/daily_task_reminder')) {
            as_unschedule_action('fluent_boards/daily_task_reminder');
        }
    }

    /**
     * clear the hourly scheduler
     * @return void
     */
    public function clearFiveMinutesScheduler()
    {
        if(as_next_scheduled_action('fluent_boards/five_minutes_scheduler')) {
            as_unschedule_action('fluent_boards/five_minutes_scheduler');
        }
    }
    public function clearHourlyScheduler()
    {
        if(as_next_scheduled_action('fluent_boards/hourly_scheduler')) {
            as_unschedule_action('fluent_boards/hourly_scheduler');
        }
    }
    public function clearDailyScheduler()
    {
        if(as_next_scheduled_action('fluent_boards/daily_scheduler')) {
            as_unschedule_action('fluent_boards/daily_scheduler');
        }
    }

    public function clearRepeatTaskScheduler()
    {
        if(as_next_scheduled_action('fluent_boards/repeat_task_scheduler')) {
            as_unschedule_action('fluent_boards/repeat_task_scheduler');
        };
    }

    /**
     * @throws \Exception
     */
    public function dailyTaskSummaryMail()
    {
        if ( ! function_exists('fluentBoards')) {
            return;
        }

        $is_reminder_enabled = Arr::get(fluent_boards_get_option('general_settings'), 'daily_reminder_enabled') == 'true' ? true : false;
        if (! $is_reminder_enabled) {
            return;
        }

        // check if it ran today. If yes, then do not run again
        $lastRunDateTime = fluent_boards_get_option('_last_daily_summary_run_at');
        if ($lastRunDateTime) {
            if (gmdate('Ymd') == gmdate('Ymd', strtotime($lastRunDateTime))) {
                return false; // We don't want to run this at the same date
            }
        }

        // Get all the tasks which are due today and not completed yet
        $startTime = gmdate('Y-m-d 00:00:00', current_time('timestamp'));
        $endTime   = gmdate('Y-m-d 23:59:59', current_time('timestamp'));

        $tasks = Task::whereBetween('due_at', [$startTime, $endTime])
                     ->where('last_completed_at', null)
                     ->get();

        // if no tasks are due today, then do not send mail
        if ( ! $tasks) {
            fluent_boards_update_option('_last_daily_summary_userid', 0);
            fluent_boards_update_option('_last_daily_summary_run_at', gmdate('Y-m-d H:i:s'));
            return;
        }

        $allDueTaskIds = $tasks->pluck('id')->toArray();

        $tasksAndWatchersQuery = Relation::where('object_type', Constant::OBJECT_TYPE_USER_TASK_WATCH)
                                             ->whereIn('object_id', $allDueTaskIds)
                                             ->orderBy('foreign_id', 'ASC');

        $lastSentId = fluent_boards_get_option('_last_daily_summary_userid');
        if ($lastSentId) {
            //  get the tasks and watchers which are greater than the last sent id (user id) to send the mail to the next user
            $tasksAndWatchersQuery = $tasksAndWatchersQuery->where('foreign_id', '>', $lastSentId);
        }

        // get all the people who are watching those tasks
        $userIds = $tasksAndWatchersQuery->pluck('foreign_id')->toArray();
        // make userId unique
        $userIds = array_unique($userIds);

        if ( ! $userIds) {
            // Completed for this day as no user is watching any task for this day
            // set the last id as 0
            fluent_boards_update_option('_last_daily_summary_userid', 0);
            // set the last run as today
            fluent_boards_update_option('_last_daily_summary_run_at', gmdate('Y-m-d H:i:s'));
            return;
        }

        $processingStartTime = time();
        $hasMoreUsers        = false;

        foreach ($userIds as $userId) {
            // get all the tasks which are watched by this user
            $taskIds = PermissionManager::getTaskIdsWatchByUser($userId);
            // filter the tasks which are due today and not completed yet
            $perUserTasks = $tasks->filter(function ($task) use ($taskIds) {
                return in_array($task->id, $taskIds);
            });

            $this->sendDailyTaskSummaryToUser($userId, $perUserTasks);

            if (time() - $processingStartTime > 40) { // if it takes more than 40 seconds, then break the loop
                // set the last id as this user id
                $hasMoreUsers = true;
                fluent_boards_update_option('_last_daily_summary_userid', $userId);
                // schedule a one time action scheduler to run this function again
                as_schedule_single_action(time() + 20, 'fluent_boards/daily_task_reminder', [], 'fluent-boards'); // run this function again after 10 seconds
                break;
            }
        }

        if ( ! $hasMoreUsers) {
            // Completed for this day as no user left to send the mail
            // set the last id as 0
            fluent_boards_update_option('_last_daily_summary_userid', 0);
            // set the last run as today
            fluent_boards_update_option('_last_daily_summary_run_at', gmdate('Y-m-d H:i:s'));
        }
    }

    public function sendDailyTaskSummaryToUser($userId, $tasks)
    {
        $headers  = ['Content-Type: text/html; charset=UTF-8'];
        $user     = get_user_by('ID', $userId);
        $subject  = __('Your Daily Task Summary - Stay on Track',
            'fluent-boards');
        $page_url = fluent_boards_page_url();
        $to       = $user->user_email;
        $name     = $user->display_name;

        $data = [
            'tasks'       => $tasks,
            'name'        => $name,
            'page_url'    => $page_url,
            'pre_header'  => __('Daily Digest Mail', 'fluent-boards'),
            'show_footer' => true,
            'site_url'    => site_url(),
            'site_title'  => get_bloginfo('name'),
            'site_logo'   => fluent_boards_site_logo(),
        ];

        $message = Helper::loadView('emails.daily',
            $data); // view is loaded from fluent-boards plugin file

        return \wp_mail($to, $subject, $message, $headers);

    }

    /**
     * Repeat tasks scheduler
     */
    public function repeatTasks()
    {
        $dateTime= new \DateTime('now', new \DateTimeZone(date_default_timezone_get()));
        $formattedTime = $dateTime->format('Y-m-d H:i:s');

        // Fetch repeat tasks meta using UTC time
        $repeatTasksMeta = Meta::where('object_type', \FluentBoardsPro\App\Services\Constant::REPEAT_TASK_META)
            ->where('key', '<=', $formattedTime)
            ->get();

        foreach ($repeatTasksMeta as $repeatTaskMeta) {
            do_action('fluent_boards/repeat_task', $repeatTaskMeta->object_id);
        }

    }

    /**
     * Process task reminders - called by the 5-minute scheduler
     * It will be called only once every 5 minutes
     * Check for tasks with reminder set and send mails if the reminder time has passed
     */
    public function processTaskReminders()
    {
        $currentTime = current_time('mysql');

        // Get all tasks with remind_at less than or equal to current time and reminder_type is not null
        // first 200 tasks order by remind_at ascending and task/subtask id ascending
        $tasksIdsWithReminders = Task::where('remind_at', '<=', $currentTime)
             ->whereNotNull('remind_at')
             ->whereNotNull('reminder_type')
             ->with(['assignees', 'board'])
             ->orderBy('remind_at', 'ASC')
             ->orderBy('id', 'ASC')
             ->limit(200)
             ->pluck('id')
             ->toArray();


        if (empty($tasksIdsWithReminders)) {
            return;
        }

        $chunkSize = 20;

        // let's take first 20 tasks to process in this run
        $tasksIdsToProcess = array_slice($tasksIdsWithReminders, 0, $chunkSize); // first 20 tasks to process
        $taskIdsLeftToProcess = array_slice($tasksIdsWithReminders, $chunkSize); // remaining tasks to process in next run

        // sending reminder emails for the 20 tasks, assuming each task has 3 assignees max, so total 60 emails per run
        foreach ($tasksIdsToProcess as $taskId) {
            $this->sendTaskReminderEmails($taskId);
        }

        // send rest tasks to the next queue
        $this->processNextTaskRemindersQueue($taskIdsLeftToProcess);
        
    }

    /**
     * Warn: This method Runs a loop with 5 sec delay to process the next tasks in the queue
     * This method process task reminders for the tasks ids stored in the option _tasks_ids_with_reminders
     */
    public function processTaskRemindersForRest()
    {
        $tasksIdsWithReminders = fluent_boards_get_option('_tasks_ids_with_reminders');
        if (empty($tasksIdsWithReminders)) {
            return;
        }

        $tasksIdsWithReminders = \maybe_unserialize($tasksIdsWithReminders);
        if (!is_array($tasksIdsWithReminders) || empty($tasksIdsWithReminders)) {
            return;
        }

        $chunkSize = 20;

        // let's take first 10 tasks to process in this run
        $tasksIdsToProcess = array_slice($tasksIdsWithReminders, 0, $chunkSize); // first 20 tasks to process
        $taskIdsLeftToProcess = array_slice($tasksIdsWithReminders, $chunkSize); // remaining tasks to process in next run

        // sending reminder emails for the 10 tasks, assuming
        foreach ($tasksIdsToProcess as $taskId) {
            $this->sendTaskReminderEmails($taskId);
        }

        $this->processNextTaskRemindersQueue($taskIdsLeftToProcess);

    }

    private function processNextTaskRemindersQueue($taskIdsLeftToProcess)
    {
        if (!empty($taskIdsLeftToProcess)) {
            fluent_boards_update_option('_tasks_ids_with_reminders', \maybe_serialize($taskIdsLeftToProcess));
            // schedule the next run after 5 soconds to process remaining tasks
            if (!as_next_scheduled_action('fluent_boards/task_reminder_scheduler_for_rest')) {
                as_schedule_single_action(time() + 5, 'fluent_boards/task_reminder_scheduler_for_rest', [], 'fluent-boards');
            }
        } else {
            // No more tasks left to process, clear the option
            fluent_boards_update_option('_tasks_ids_with_reminders', '');
        }
    }


    /**
     * This method process only one task and sends reminder emails to its assignees
     * @param Task $task The task object for which to send reminder emails
     * Update the task to clear the reminder fields after sending emails
     */
    public function sendTaskReminderEmails($taskId)
    {
        $task = Task::with(['assignees', 'board'])
                    ->whereNotNull('remind_at')
                    ->whereNotNull('reminder_type')
                    ->find($taskId);

        // check if there are assignees
        if (!$task->assignees || $task->assignees->isEmpty()) {
            return;
        }

        foreach ($task->assignees as $assignee) {
            $this->sendReminderEmail($task->id, $assignee->ID);
        }

        // Clear the remind_at and reminder_type fields for not sending again
        $task->update([
            'remind_at' => null,
//            'reminder_type' => null
        ]);
    }

    public function sendReminderEmail($taskId, $userId)
    {

        try {
            $task = Task::find($taskId);
            if (!$task) {
                return;
            }

            $user = get_user_by('ID', $userId);
            if (!$user) {
                return;
            }
            
            $board = $task->board;
            $page_url = fluent_boards_page_url();

            $taskType = '';
            $stage = '';

            if ($task->parent_id) {
                // This is a subtask
                $parentTask = Task::find($task->parent_id);
                $taskUrl = $page_url . 'boards/' . $board->id . '/tasks/' . $parentTask->id . '-' . substr($parentTask->title, 0, 10);
                $taskType = 'subtask';
                $stage = $parentTask->stage->title ?? '';
                $bodyText = sprintf(
                    __('Reminder: Your subtask %1$s is due soon.','fluent-boards'),
                    $task->title
                );
            } else {
                // This is a regular task
                $taskUrl = $page_url . 'boards/' . $board->id . '/tasks/' . $task->id . '-' . substr($task->title, 0, 10);
                $taskType = 'task';
                $stage = $task->stage->title ?? '';
                $parentTask = $task;
                $bodyText = sprintf(
                    __('Reminder: Your task %1$s is due soon.','fluent-boards'),
                    $task->title
                );
            }

            $data = [
                'body' => $bodyText,
                'pre_header' => ucfirst($taskType) . ' Reminder',
                'show_footer' => true,
                'task' => $task,
                'stage' => $stage,
                'parent_task' => $parentTask,
                'board' => $board,
                'task_url' => $taskUrl,
                'site_url' => site_url(),
                'site_title' => get_bloginfo('name'),
                'site_logo' => fluent_boards_site_logo(),
            ];

            $subject =  $this->taskReminderSubjects($task, $user, $taskType);

            $message = Helper::loadView('emails.subtask-reminder', $data);

            $headers = ['Content-Type: text/html; charset=UTF-8'];

            return \wp_mail($user->user_email, $subject, $message, $headers);

        } catch (\Exception $e) {
            error_log('Task reminder email error: ' . $e->getMessage());
            return false;
        }
    }

    private function taskReminderSubjects($task, $user, $taskType)
    {
        $taskTitle = strlen($task->title) > 50 ? substr($task->title, 0, 47) . '...' : $task->title;
        $reminderType = $task->reminder_type;
        $subjectsByType = [
            '30_minutes_before' => [
                sprintf(__('🚨 URGENT: %s "%s" due in 30 minutes!', 'fluent-boards'), ucfirst($taskType), $taskTitle),
                sprintf(__('⏰ Final call %s! "%s" due in 30 minutes', 'fluent-boards'), $user->display_name, $taskTitle),
                sprintf(__('⚡ Quick action needed %s — "%s" due very soon', 'fluent-boards'), $user->display_name, $taskTitle),
                sprintf(__('🚀 30-minute warning: "%s" deadline approaching fast', 'fluent-boards'), $taskTitle),
                sprintf(__('🔔 Immediate attention: "%s" due in half an hour', 'fluent-boards'), $taskTitle),
            ],
            
            '1_hour_before' => [
                sprintf(__('⏰ 1 hour left %s! "%s" needs your focus', 'fluent-boards'), $user->display_name, $taskTitle),
                sprintf(__('🎯 Focus time: %s "%s" due in 1 hour', 'fluent-boards'), $taskType, $taskTitle),
                sprintf(__('🔥 One hour countdown: "%s" requires action', 'fluent-boards'), $taskTitle),
                sprintf(__('⏳ 60 minutes to go: %s "%s" deadline approaching', 'fluent-boards'), $taskType, $taskTitle),
                sprintf(__('🚀 Final hour %s! Complete "%s" now', 'fluent-boards'), $user->display_name, $taskTitle),
                sprintf(__('⚠️ 1-hour warning: "%s" needs completion', 'fluent-boards'), $taskTitle),
                sprintf(__('💪 Crunch time: %s "%s" due in 60 minutes', 'fluent-boards'), ucfirst($taskType), $taskTitle),
            ],
            
            '2_hours_before' => [
                sprintf(__('⏰ 2 hours remaining %s — "%s" needs attention', 'fluent-boards'), $user->display_name, $taskTitle),
                sprintf(__('🎯 Time check: %s "%s" due in 2 hours', 'fluent-boards'), $taskType, $taskTitle),
                sprintf(__('⚡ Good timing %s! "%s" deadline in 2 hours', 'fluent-boards'), $user->display_name, $taskTitle),
                sprintf(__('📋 2-hour heads up: "%s" requires completion', 'fluent-boards'), $taskTitle),
                sprintf(__('⏳ 120 minutes left: %s "%s" needs progress', 'fluent-boards'), $taskType, $taskTitle),
                sprintf(__('💡 Perfect timing: 2 hours to finish "%s"', 'fluent-boards'), $taskTitle),
                sprintf(__('⚠️ 2-hour notice: %s "%s" approaching deadline', 'fluent-boards'), ucfirst($taskType),$taskTitle),
            ],
            
            '1_day_before' => [
                sprintf(__('📅 Tomorrow\'s deadline %s: "%s" needs completion', 'fluent-boards'), $user->display_name, $taskTitle),
                sprintf(__('⏰ 24-hour notice: %s "%s" due tomorrow', 'fluent-boards'), $taskType, $taskTitle),
                sprintf(__('🗓️ One day left %s — plan to finish "%s"', 'fluent-boards'), $user->display_name, $taskTitle),
                sprintf(__('📋 Daily reminder: "%s" deadline is tomorrow', 'fluent-boards'), $taskTitle),
                sprintf(__('⏳ 1 day remaining: %s "%s" needs attention', 'fluent-boards'), $taskType, $taskTitle),
                sprintf(__('🔔 Tomorrow\'s task %s: don\'t forget "%s"', 'fluent-boards'), $user->display_name, $taskTitle),
                sprintf(__('💡 Plan ahead: "%s" due in 24 hours', 'fluent-boards'), $taskTitle),
                sprintf(__('⚠️ Day-ahead warning: %s "%s" due tomorrow', 'fluent-boards'), ucfirst($taskType), $taskTitle),
            ],
            
            '2_days_before' => [
                sprintf(__('📅 2 days ahead %s: "%s" coming up soon', 'fluent-boards'), $user->display_name, $taskTitle),
                sprintf(__('⏰ Early reminder: %s "%s" due in 2 days', 'fluent-boards'), $taskType, $taskTitle),
                sprintf(__('📋 2-day notice: "%s" needs planning', 'fluent-boards'), $taskTitle),
                sprintf(__('⏳ 48 hours out: %s "%s" approaching', 'fluent-boards'), $taskType, $taskTitle),
                sprintf(__('🔔 Advance notice %s: "%s" due in 2 days', 'fluent-boards'), $user->display_name,$taskTitle),
                sprintf(__('💡 Early bird: "%s" deadline in 48 hours', 'fluent-boards'), $taskTitle),
                sprintf(__('⚠️ 2-day heads up: %s "%s" needs preparation', 'fluent-boards'), ucfirst($taskType), $taskTitle),
            ],
            
            '1_week_before' => [
                sprintf(__('📅 Week ahead %s: "%s" due next week', 'fluent-boards'), $user->display_name, $taskTitle),
                sprintf(__('⏰ Weekly reminder: %s "%s" due in 7 days', 'fluent-boards'), $taskType, $taskTitle),
                sprintf(__('🗓️ Next week\'s deadline %s: "%s" needs planning', 'fluent-boards'), $user->display_name, $taskTitle),
                sprintf(__('📋 7-day notice: "%s" coming up', 'fluent-boards'), $taskTitle),
                sprintf(__('⏳ One week out: %s "%s" approaching', 'fluent-boards'), $taskType, $taskTitle),
                sprintf(__('🔔 Weekly heads-up %s: "%s" due next week', 'fluent-boards'), $user->display_name, $taskTitle),
                sprintf(__('💡 Plan your week: "%s" deadline ahead', 'fluent-boards'), $taskTitle),
                sprintf(__('⚠️ 7-day advance: %s "%s" needs scheduling', 'fluent-boards'), ucfirst($taskType), $taskTitle),
            ],
        ];
        $subjects = $subjectsByType[$reminderType];
        
        return $subjects[array_rand($subjects)];
    }

    private function maybeRemoveOldScheuledActionLogs($group_slug = 'fluent-boards', $days_old = 7)
    {
        global $wpdb;

        // Get the timestamp for 7 days ago
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$days_old} days"));

        // Get the group ID
        $group_id = $wpdb->get_var($wpdb->prepare(
            "SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = %s",
            $group_slug
        ));

        if (!$group_id) {
            return false; // Group not found
        }

        // Delete old actions and their associated logs
        $wpdb->query($wpdb->prepare("
        DELETE a, l
        FROM {$wpdb->prefix}actionscheduler_actions a
        LEFT JOIN {$wpdb->prefix}actionscheduler_logs l ON a.action_id = l.action_id
        WHERE a.group_id = %d
        AND a.status IN ('complete', 'failed')
        AND a.scheduled_date_gmt < %s", $group_id, $cutoff_date));

        // Clean up orphaned claims
        $wpdb->query("
        DELETE c
        FROM {$wpdb->prefix}actionscheduler_claims c
        LEFT JOIN {$wpdb->prefix}actionscheduler_actions a ON c.claim_id = a.claim_id
        WHERE a.action_id IS NULL");
    }
}