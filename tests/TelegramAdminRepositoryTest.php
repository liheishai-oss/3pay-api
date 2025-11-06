<?php

use PHPUnit\Framework\TestCase;
use app\repository\TelegramAdminRepository;
use app\model\TelegramAdmin;

class TelegramAdminRepositoryTest extends TestCase
{
    private TelegramAdminRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new TelegramAdminRepository();
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        TelegramAdmin::where('nickname', 'like', '测试%')->delete();
        parent::tearDown();
    }

    /**
     * 测试根据ID查找管理员
     */
    public function testFindById()
    {
        $admin = $this->createTestAdmin();
        
        $result = $this->repository->findById($admin->id);
        
        $this->assertInstanceOf(TelegramAdmin::class, $result);
        $this->assertEquals($admin->id, $result->id);
    }

    /**
     * 测试根据不存在的ID查找管理员
     */
    public function testFindByIdNotFound()
    {
        $result = $this->repository->findById(99999);
        
        $this->assertNull($result);
    }

    /**
     * 测试根据Telegram ID查找管理员
     */
    public function testFindByTelegramId()
    {
        $telegramId = rand(100000000, 999999999);
        $admin = $this->createTestAdmin(['telegram_id' => $telegramId]);
        
        $result = $this->repository->findByTelegramId($telegramId);
        
        $this->assertInstanceOf(TelegramAdmin::class, $result);
        $this->assertEquals($telegramId, $result->telegram_id);
    }

    /**
     * 测试根据用户名查找管理员
     */
    public function testFindByUsername()
    {
        $username = 'test_username_' . time();
        $admin = $this->createTestAdmin(['username' => $username]);
        
        $result = $this->repository->findByUsername($username);
        
        $this->assertInstanceOf(TelegramAdmin::class, $result);
        $this->assertEquals($username, $result->username);
    }

    /**
     * 测试检查Telegram ID是否存在
     */
    public function testExistsByTelegramId()
    {
        $telegramId = rand(100000000, 999999999);
        $admin = $this->createTestAdmin(['telegram_id' => $telegramId]);
        
        // 测试存在的Telegram ID
        $this->assertTrue($this->repository->existsByTelegramId($telegramId));
        
        // 测试不存在的Telegram ID
        $this->assertFalse($this->repository->existsByTelegramId(999999999));
        
        // 测试排除指定ID
        $this->assertFalse($this->repository->existsByTelegramId($telegramId, $admin->id));
    }

    /**
     * 测试检查用户名是否存在
     */
    public function testExistsByUsername()
    {
        $username = 'test_username_' . time();
        $admin = $this->createTestAdmin(['username' => $username]);
        
        // 测试存在的用户名
        $this->assertTrue($this->repository->existsByUsername($username));
        
        // 测试不存在的用户名
        $this->assertFalse($this->repository->existsByUsername('nonexistent_username'));
        
        // 测试排除指定ID
        $this->assertFalse($this->repository->existsByUsername($username, $admin->id));
    }

    /**
     * 测试创建管理员
     */
    public function testCreate()
    {
        $data = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => '测试创建仓库管理员_' . time(),
            'username' => 'test_repo_create_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];
        
        $admin = $this->repository->create($data);
        
        $this->assertInstanceOf(TelegramAdmin::class, $admin);
        $this->assertEquals($data['telegram_id'], $admin->telegram_id);
        $this->assertEquals($data['nickname'], $admin->nickname);
        $this->assertNotNull($admin->id);
    }

    /**
     * 测试更新管理员
     */
    public function testUpdate()
    {
        $admin = $this->createTestAdmin();
        
        $updateData = [
            'nickname' => '已更新的仓库管理员_' . time(),
            'remark' => '已更新的备注',
            'status' => 0
        ];
        
        $result = $this->repository->update($admin->id, $updateData);
        
        $this->assertTrue($result);
        
        $admin->refresh();
        $this->assertEquals($updateData['nickname'], $admin->nickname);
        $this->assertEquals($updateData['status'], $admin->status);
    }

    /**
     * 测试更新不存在的管理员
     */
    public function testUpdateNotFound()
    {
        $updateData = [
            'nickname' => '不存在的管理员',
            'status' => 0
        ];
        
        $result = $this->repository->update(99999, $updateData);
        
        $this->assertFalse($result);
    }

    /**
     * 测试删除管理员
     */
    public function testDelete()
    {
        $admin = $this->createTestAdmin();
        $adminId = $admin->id;
        
        $result = $this->repository->delete($adminId);
        
        $this->assertTrue($result);
        $this->assertNull(TelegramAdmin::find($adminId));
    }

    /**
     * 测试删除不存在的管理员
     */
    public function testDeleteNotFound()
    {
        $result = $this->repository->delete(99999);
        
        $this->assertFalse($result);
    }

    /**
     * 测试批量删除
     */
    public function testBatchDelete()
    {
        // 创建多个测试数据
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $admin = $this->createTestAdmin('批量删除仓库测试_' . $i . '_' . time());
            $ids[] = $admin->id;
        }
        
        $result = $this->repository->batchDelete($ids);
        
        $this->assertEquals(3, $result);
        foreach ($ids as $id) {
            $this->assertNull(TelegramAdmin::find($id));
        }
    }

    /**
     * 测试批量更新状态
     */
    public function testBatchUpdateStatus()
    {
        // 创建多个测试数据
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $admin = $this->createTestAdmin('批量状态仓库测试_' . $i . '_' . time());
            $ids[] = $admin->id;
        }
        
        $result = $this->repository->batchUpdateStatus($ids, 0);
        
        $this->assertEquals(3, $result);
        foreach ($ids as $id) {
            $admin = TelegramAdmin::find($id);
            $this->assertEquals(0, $admin->status);
        }
    }

    /**
     * 测试获取分页列表
     */
    public function testGetPaginatedList()
    {
        // 创建测试数据
        $this->createTestAdmin();
        
        $result = $this->repository->getPaginatedList([], 10);
        
        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $result);
        $this->assertGreaterThan(0, $result->total());
    }

    /**
     * 测试获取分页列表 - 带筛选条件
     */
    public function testGetPaginatedListWithFilters()
    {
        // 创建测试数据
        $admin = $this->createTestAdmin();
        
        $filters = [
            'keyword' => $admin->nickname,
            'status' => 1
        ];
        
        $result = $this->repository->getPaginatedList($filters, 10);
        
        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $result);
        $this->assertGreaterThan(0, $result->total());
    }

    /**
     * 测试获取所有管理员
     */
    public function testGetAll()
    {
        // 创建测试数据
        $this->createTestAdmin();
        
        $result = $this->repository->getAll();
        
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $result);
        $this->assertGreaterThan(0, $result->count());
    }

    /**
     * 测试获取统计信息
     */
    public function testGetStatistics()
    {
        // 创建测试数据
        $this->createTestAdmin();
        
        $statistics = $this->repository->getStatistics();
        
        $this->assertIsArray($statistics);
        $this->assertArrayHasKey('total', $statistics);
        $this->assertArrayHasKey('enabled', $statistics);
        $this->assertArrayHasKey('disabled', $statistics);
        $this->assertGreaterThan(0, $statistics['total']);
    }

    /**
     * 测试根据状态获取管理员数量
     */
    public function testCountByStatus()
    {
        // 创建测试数据
        $this->createTestAdmin(['status' => 1]);
        $this->createTestAdmin(['status' => 0]);
        
        $enabledCount = $this->repository->countByStatus(1);
        $disabledCount = $this->repository->countByStatus(0);
        
        $this->assertGreaterThan(0, $enabledCount);
        $this->assertGreaterThan(0, $disabledCount);
    }

    /**
     * 创建测试管理员
     */
    private function createTestAdmin(array $data = []): TelegramAdmin
    {
        $defaultData = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => '测试仓库管理员_' . time(),
            'username' => 'test_repo_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];
        
        $data = array_merge($defaultData, $data);
        
        return TelegramAdmin::create($data);
    }
}