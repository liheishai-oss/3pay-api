<?php

namespace app\service;

use app\exception\MyBusinessException;
use app\model\TelegramAdmin;
use app\repository\TelegramAdminRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class TelegramAdminService
{
    public function __construct(private readonly TelegramAdminRepository $repository)
    {
    }

    /**
     * 获取管理员列表（分页）
     */
    public function getList(array $params): array
    {
        $perPage = $params['per_page'] ?? 10;
        return $this->repository->getPaginatedList($params, $perPage)->toArray();
    }

    /**
     * 获取管理员详情
     */
    public function getDetail(int $id): TelegramAdmin
    {
        $admin = $this->repository->findById($id);
        if (!$admin) {
            throw new MyBusinessException('管理员不存在');
        }
        return $admin;
    }

    /**
     * 创建管理员
     */
    public function create(array $data): TelegramAdmin
    {
        // 检查 Telegram ID 是否已存在
        if ($this->repository->existsByTelegramId($data['telegram_id'])) {
            throw new MyBusinessException('该 Telegram ID 已存在');
        }

        // 检查用户名是否已存在（如果提供了用户名）
        if (!empty($data['username']) && $this->repository->existsByUsername($data['username'])) {
            throw new MyBusinessException('该用户名已存在');
        }

        return $this->repository->create($data);
    }

    /**
     * 更新管理员
     */
    public function update(int $id, array $data): TelegramAdmin
    {
        $admin = $this->getDetail($id);

        // 检查 Telegram ID 是否被其他记录使用
        if (isset($data['telegram_id']) && $this->repository->existsByTelegramId($data['telegram_id'], $id)) {
            throw new MyBusinessException('该 Telegram ID 已被其他管理员使用');
        }

        // 检查用户名是否被其他记录使用
        if (!empty($data['username']) && $this->repository->existsByUsername($data['username'], $id)) {
            throw new MyBusinessException('该用户名已被其他管理员使用');
        }

        $this->repository->update($id, $data);
        return $this->getDetail($id);
    }

    /**
     * 删除管理员
     */
    public function delete($id): bool
    {
        return $this->repository->delete($id);
    }

    /**
     * 切换状态
     */
    public function toggleStatus(int $id): TelegramAdmin
    {
        $admin = $this->getDetail($id);
        $newStatus = $admin->status === TelegramAdmin::STATUS_ENABLED 
            ? TelegramAdmin::STATUS_DISABLED 
            : TelegramAdmin::STATUS_ENABLED;
        
        $this->repository->update($id, ['status' => $newStatus]);
        return $this->getDetail($id);
    }

    /**
     * 批量删除
     */
    public function batchDelete(array $ids): int
    {
        return $this->repository->batchDelete($ids);
    }

    /**
     * 批量更新状态
     */
    public function batchUpdateStatus(array $ids, int $status): int
    {
        return $this->repository->batchUpdateStatus($ids, $status);
    }

    /**
     * 获取统计信息
     */
    public function getStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    /**
     * 获取所有管理员（不分页）
     */
    public function getAll(array $filters = []): Collection
    {
        return $this->repository->getAll($filters);
    }

    /**
     * 根据Telegram ID查找管理员
     */
    public function findByTelegramId(int $telegramId): ?TelegramAdmin
    {
        return $this->repository->findByTelegramId($telegramId);
    }

    /**
     * 根据用户名查找管理员
     */
    public function findByUsername(string $username): ?TelegramAdmin
    {
        return $this->repository->findByUsername($username);
    }
}
