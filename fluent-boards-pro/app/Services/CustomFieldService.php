<?php

namespace FluentBoardsPro\App\Services;

use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Relation;
use FluentBoards\Framework\Support\DateTime;
use FluentBoardsPro\App\Models\CustomField;
use FluentBoardsPro\App\Services\Constant;

class CustomFieldService
{
    public function getCustomFields($boardId)
    {
        $customFields = CustomField::where('board_id', $boardId)->get();
        return $customFields;
    }

    public function createCustomField($boardId, $customFieldData)
    {
        if($this->duplicateCustomFieldcheck($boardId, $customFieldData)){
            return false;
        }
        $customField = new CustomField();
        $customField->board_id = $boardId;
        $customField->title = $customFieldData['title'];
        $customField->slug = str_replace(' ', '-', strtolower($customFieldData['title']));

        $preferenceData = [];
        $preferenceData['custom_field_type'] = $customFieldData['type'];
        if ( key_exists('options', $customFieldData) )
        {
            $preferenceData['select_options'] = $customFieldData['options'];
        }
        $customField->settings = $preferenceData;
        $position = $this->getHighestCustomFieldPosition($boardId);
        $customField->position = $position + 1;

        $customField->save();

        return $customField;
    }

    public function updateCustomField($customFieldId, $customFieldData)
    {
        $customField = CustomField::findOrFail($customFieldId);

        if($this->duplicateCustomFieldcheck($customField->board_id, $customFieldData, $customField->id)){
            return false;
        }
        $customField->title = $customFieldData['title'];

        $preferenceData = [];
        if ( key_exists('options', $customFieldData) )
        {
            $preferenceData['select_options'] = $customFieldData['options'];
        }
        $customField->settings = $preferenceData;
        $customField->save();

        return $customField;
    }

    private function duplicateCustomFieldcheck($boardId, $customFieldData, $customFieldId = null)
    {
        $isDuplicate = false;
        $customFields = CustomField::where('type', 'custom-field')
            ->where('board_id', $boardId)
            ->where('title', $customFieldData['title'])
            ->get();

        foreach ($customFields as $customField)
        {
            if ( ($customFieldId != $customField->id) && $customField->settings['custom_field_type'] == $customFieldData['type'])
            {
                $isDuplicate = true;
                break;
            }
        }

        return $isDuplicate;
    }

    public function deleteCustomField($customFieldId)
    {
        $customField = CustomField::findOrFail($customFieldId);
        if($customField)
        {
            $deleted = $customField->delete();
            if($deleted)
            {
                $customField->tasks()->detach();
            }
        }
    }

    public function getCustomFieldsByTask($taskId)
    {
        return Relation::where('object_id', $taskId)
            ->where('object_type', Constant::TASK_CUSTOM_FIELD)
            ->get();
    }

    public function getCustomFieldById($id)
    {
        return customField::findOrFail($id);
    }

    public function saveCustomFieldDataOfTask($taskId, $customFieldId, $value)
    {
        $customField = $this->getCustomFieldById($customFieldId);
        $customFieldOfTask = Relation::where('object_id', $taskId)
            ->where('object_type', Constant::TASK_CUSTOM_FIELD)
            ->where('foreign_id', $customFieldId)
            ->first();

        // Store old value for activity logging
        $oldValue = null;
        $isNewCustomField = false;
        
        if ($customFieldOfTask) {
            // Get the old value for comparison
            $oldSettings = $customFieldOfTask->settings;
            $oldValue = isset($oldSettings['value']) ? $oldSettings['value'] : null;
        } else {
            $isNewCustomField = true;
        }

        // Process the new value based on custom field type
        $processedValue = $value;
        if ($customField->settings['custom_field_type'] == 'checkbox') {
            $processedValue = $value == 'true' ? true : false;
        } else if ($customField->settings['custom_field_type'] == 'date') {
            $processedValue = $this->formatDate($value);
        }

        if ($customFieldOfTask) {
            // Update existing custom field value
            $customFieldOfTask->settings = ['value' => $processedValue];
            $customFieldOfTask->save();
        } else {
            // Create new custom field value
            $customFieldOfTask = new Relation();
            $customFieldOfTask->object_id = $taskId;
            $customFieldOfTask->object_type = Constant::TASK_CUSTOM_FIELD;
            $customFieldOfTask->foreign_id = $customFieldId;
            $customFieldOfTask->settings = ['value' => $processedValue];
            $customFieldOfTask->save();
        }

        // Fire hook for activity logging
        do_action('fluent_boards/task_custom_field_changed', $taskId, $customField, $oldValue, $processedValue, $isNewCustomField);

        return $customField;
    }

    private function formatDate($value)
    {
        if(!$value) {
            return null;
        }
        // Remove the timezone name, it was creating formating issue
        $dateStringWithoutTimezoneName = preg_replace('/ \(.*\)$/', '', $value);
        $date = DateTime::createFromFormat('D M d Y H:i:s \G\M\TO', $dateStringWithoutTimezoneName);
        return $date->format('Y-m-d H:i:s');
    }

    public function updateCustomFieldPosition($customFieldId, $newIndex)
    {
        $customField = CustomField::findOrFail($customFieldId);
        $newIndex = (int) $newIndex;
        if ($newIndex < 1) {
            $newIndex = 1;
        }

        $customFieldQuery = CustomField::where('board_id', $customField->board_id);

        if ($newIndex == 1) {
            $firstItem = $customFieldQuery->where('id', '!=', $customField->id)
                                         ->orderBy('position', 'asc')
                                         ->first();

            if ($firstItem) {
                if ($firstItem->position <= 0.02) {
                    $this->reIndexCustomFieldPositions($customField->board_id);
                    return $this->updateCustomFieldPosition($customFieldId, $newIndex);
                }
                $index = round($firstItem->position / 2, 2);
            } else {
                $index = 1;
            }

            $customField->position = $index;
            $customField->save();

            return $customField;
        }

        $prevCustomField = $customFieldQuery
            ->offset($newIndex - 2)
            ->where('id', '!=', $customField->id)
            ->orderBy('position', 'asc')
            ->first();


        if (!$prevCustomField) {
            return $this->updateCustomFieldPosition($customFieldId, 1);
        }
        $nextItem = $customFieldQuery
            ->offset($newIndex - 1)
            ->where('id', '!=', $customField->id)
            ->orderBy('position', 'asc')
            ->first();

        if (!$nextItem) {
            $customField->position = $prevCustomField->position + 1;
            $customField->save();

            return $customField;
        }

        $newPosition = ($prevCustomField->position + $nextItem->position) / 2;

        // check if new position is already taken
        $exist = $customFieldQuery
            ->where('position', $newPosition)
            ->where('id', '!=', $customField->id)
            ->first();

        if ($exist) {
            $this->reIndexCustomFieldPositions($customField->board_id);
            return $this->updateCustomFieldPosition($customFieldId, $newIndex);
        }

        $customField->position = $newPosition;
        $customField->save();

        return $customField;
    }

    public function reIndexCustomFieldPositions($boardId)
    {
        $customFieldsQuery = CustomField::where('board_id', $boardId);
        $allCustomFields = $customFieldsQuery->orderBy('position', 'asc')->get();

        foreach ($allCustomFields as $index => $customField) {
            $customField->position = $index + 1;
            $customField->save();
        }
    }

    private function getHighestCustomFieldPosition($boardId)
    {
        $customField = CustomField::where('board_id', $boardId)->orderBy('position', 'desc')->first();
        return $customField ? (int) $customField->position : 0;
    }
}