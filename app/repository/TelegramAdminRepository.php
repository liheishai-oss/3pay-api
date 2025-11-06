<?php

namespace app\repository;

use app\model\TelegramAdmin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class TelegramAdminRepository
{
    /**
     * 根据ID查找管理员
     */
    public function findById(int $id): ?TelegramAdmin
    {
        return TelegramAdmin::find($id);
    }

    /**
     * 根据Telegram ID查找管理员
     */
    public function findByTelegramId(int $telegramId): ?TelegramAdmin
    {
        return TelegramAdmin::where('telegram_id', $telegramId)->first();
    }

    /**
     * 根据用户名查找管理员
     */
    public function findByUsername(string $username): ?TelegramAdmin
    {
        return TelegramAdmin::where('username', $username)->first();
    }

    /**
     * 检查Telegram ID是否存在（排除指定ID）
     */
    public function existsByTelegramId(int $telegramId, ?int $excludeId = null): bool
    {
        $query = TelegramAdmin::where('telegram_id', $telegramId);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        return $query->exists();
    }

    /**
     * 检查用户名是否存在（排除指定ID）
     */
    public function existsByUsername(string $username, ?int $excludeId = null): bool
    {
        $query = TelegramAdmin::where('username', $username);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        return $query->exists();
    }

    /**
     * 创建管理员
     */
    public function create(array $data): TelegramAdmin
    {
        return TelegramAdmin::create($data);
    }

    /**
     * 更新管理员
     */
    public function update(int $id, array $data): bool
    {
        $admin = $this->findById($id);
        if (!$admin) {
            return false;
        }
        return $admin->update($data);
    }

    /**
     * 删除管理员
     */
    public function delete($id): bool
    {
        return TelegramAdmin::whereIn('id', $id)->delete();
    }

    /**
     * 批量删除
     */
    public function batchDelete(array $ids): int
    {
        return TelegramAdmin::whereIn('id', $ids)->delete();
    }

    /**
     * 批量更新状态
     */
    public function batchUpdateStatus(array $ids, int $status): int
    {
        return TelegramAdmin::whereIn('id', $ids)->update(['status' => $status]);
    }

    /**
     * 获取分页列表
     */
    public function getPaginatedList(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = TelegramAdmin::query();

        // 处理搜索参数
        if (!empty($filters['search'])) {
            $search = json_decode($filters['search'], true);
            if (is_array($search)) {
                // 处理嵌套的search对象
                if (isset($search['search']) && is_array($search['search'])) {
                    $search = $search['search'];
                }
                
                // 昵称搜索
                if (!empty($search['nickname'])) {
                    $query->where('nickname', 'like', '%' . trim($search['nickname']) . '%');
                }
                
                // 用户名搜索
                if (!empty($search['username'])) {
                    $query->where('username', 'like', '%' . trim($search['username']) . '%');
                }
                
                // 状态筛选
                if (isset($search['status']) && $search['status'] !== '') {
                    $query->where('status', $search['status']);
                }
            }
        }

        // 兼容旧的搜索方式
        // 关键词搜索
        if (!empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('nickname', 'like', "%{$keyword}%")
                  ->orWhere('username', 'like', "%{$keyword}%")
                  ->orWhere('remark', 'like', "%{$keyword}%");
            });
        }

        // 状态筛选
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }


        // 排序
        $query->orderBy('created_at', 'desc');

        return $query->paginate($perPage);
    }

    /**
     * 获取所有管理员（不分页）
     */
    public function getAll(array $filters = []): Collection
    {
        $query = TelegramAdmin::query();

        // 应用筛选条件
        if (!empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('nickname', 'like', "%{$keyword}%")
                  ->orWhere('username', 'like', "%{$keyword}%")
                  ->orWhere('remark', 'like', "%{$keyword}%");
            });
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * 获取统计信息
     */
    public function getStatistics(): array
    {
        return [
            'total' => TelegramAdmin::count(),
            'enabled' => TelegramAdmin::enabled()->count(),
            'disabled' => TelegramAdmin::where('status', TelegramAdmin::STATUS_DISABLED)->count(),
        ];
    }

    /**
     * 根据状态获取管理员数量
     */
    public function countByStatus(int $status): int
    {
        return TelegramAdmin::where('status', $status)->count();
    }

}
