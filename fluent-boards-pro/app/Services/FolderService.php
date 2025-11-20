<?php

namespace FluentBoardsPro\App\Services;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Relation;
use FluentBoardsPro\App\Services\Constant;
use FluentBoards\App\Services\PermissionManager;
use FluentBoardsPro\App\Models\Folder;

class FolderService
{
    public function create(array $data)
    {
        $background = [
            'id'       => null,
            'is_image'  => false,
            'image_url' => null,
            'color'     => $data['color'] ?? null,
        ];

        $folderData = [
            'title'      => $data['title'],
            'background' => $background,
        ];

        if (!empty($data['parent_id'])) {
            $folderData['parent_id'] = (int) $data['parent_id'];
        }

        return Folder::create($folderData);
    }

    public function getFolders($userId = null)
    {
        if (!$userId) {
            $userId = \get_current_user_id();
        }

        $boardIds = PermissionManager::getBoardIdsForUser($userId);
        $boardIds = array_unique($boardIds);
        $folderIds = Relation::where('object_type', Constant::OBJECT_TYPE_FOLDER_BOARD)
            ->whereIn('foreign_id', $boardIds)
            ->pluck('object_id')->unique()->toArray();

        return Folder::whereNull('parent_id')
            ->where(function ($query) use ($folderIds, $userId) {
                $query->whereIn('id', $folderIds)
                      ->orWhere('created_by', $userId);
            })
            ->with(['subFolders' => function ($query) {
                $query->orderBy('created_at', 'DESC');
            }])
            ->with(['boards' => function ($query) use ($boardIds) {
                $query->whereIn('fbs_boards.id', $boardIds)
                      ->orderBy('created_at', 'DESC');
            }])
            ->orderBy('created_at', 'DESC')
            ->distinct()
            ->get();
    }

    public function getFolderById($folderId, $data = [])
    {
        $order = $data['order'] ?? 'created_at';
        $orderBy = $data['orderBy'] ?? 'DESC';
        $searchInput = $data['searchInput'] ?? null;
        $option = $data['option'] ?? null;
        $boardIds = PermissionManager::getBoardIdsForUser();

        return Folder::where('id', $folderId)
            ->with(['subFolders' => function ($query) {
                $query->orderBy('created_at', 'DESC');
            }])
            ->with(['boards' => function ($query) use ($boardIds, $option, $order, $orderBy, $searchInput) {
                $query->whereIn('fbs_boards.id', $boardIds);
                if ($option) {
                    if ($option == 'archived') {
                        $query->whereNotNull('archived_at');
                    } else {
                        $query->whereNull('archived_at');
                    }
                }
                if ($searchInput) {
                    $query->where(function ($q) use ($searchInput) {
                        $q->where('title', 'like', "%{$searchInput}%");
                    });
                }


                $query->withCount('completedTasks')->orderBy($order, $orderBy);
            }])
            ->orderBy('title', 'ASC')
            ->first();
    }

    public function addBoardToFolder($folderId, $boardIds)
    {
        $folder = Folder::findOrFail($folderId);

        // Prepare sync data with object_type for each board
        $syncData = [];
        foreach ($boardIds as $boardId) {
            $syncData[$boardId] = ['object_type' => Constant::OBJECT_TYPE_FOLDER_BOARD];
            $this->removeBoardFromPreviousFolder($boardId);
        }

        $folder->boards()->syncWithoutDetaching($syncData);
    }

    private function removeBoardFromPreviousFolder($boardId)
    {
        $relation = Relation::where('object_type', Constant::OBJECT_TYPE_FOLDER_BOARD)
            ->where('foreign_id', $boardId)
            ->first();

        if (!$relation) {
            return;
        }
        $relation->delete();
    }

    public function updateFolder($folderId, $title)
    {
        $folder = Folder::findOrFail($folderId);
        $folder->title = $title;
        $folder->save();
        return $folder;
    }

    public function deleteFolder($folderId)
    {
        $folder = Folder::findOrFail($folderId);
        
        // Remove all boards from the folder before deleting
        $folder->boards()->detach();
        
        // Delete the folder
        $folder->delete();
    }
}
