<?php

use PHPUnit\Framework\TestCase;
use app\service\TelegramAdminService;
use app\repository\TelegramAdminRepository;
use app\model\TelegramAdmin;
use app\exception\MyBusinessException;

class TelegramAdminServiceTest extends TestCase
{
    private TelegramAdminService $service;
    private TelegramAdminRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new TelegramAdminRepository();
        $this->service = new TelegramAdminService($this->repository);
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        TelegramAdmin::where('nickname', 'like', '测试%')
            ->orWhere('username', 'like', 'test_%')
            ->orWhere('username', 'like', 'batch_%')
            ->delete();
        parent::tearDown();
    }

    /**
     * 测试获取管理员列表
     */
    public function testGetList()
    {
        // 创建测试数据
        $testData = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => '测试列表管理员_' . time(),
            'username' => 'test_list_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];
        TelegramAdmin::create($testData);

        $params = ['per_page' => 10];
        $result = $this->service->getList($params);

        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $result);
        $this->assertGreaterThan(0, $result->total());
    }

    /**
     * 测试获取统计信息
     */
    public function testGetStatistics()
    {
        $statistics = $this->service->getStatistics();

        $this->assertIsArray($statistics);
        $this->assertArrayHasKey('total', $statistics);
        $this->assertArrayHasKey('enabled', $statistics);
        $this->assertArrayHasKey('disabled', $statistics);
    }

    /**
     * 测试创建管理员
     */
    public function testCreate()
    {
        $data = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => '测试创建管理员_' . time(),
            'username' => 'test_create_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];

        $admin = $this->service->create($data);

        $this->assertInstanceOf(TelegramAdmin::class, $admin);
        $this->assertEquals($data['telegram_id'], $admin->telegram_id);
        $this->assertEquals($data['nickname'], $admin->nickname);
    }

    /**
     * 测试创建重复Telegram ID的管理员
     */
    public function testCreateWithDuplicateTelegramId()
    {
        $data = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => '测试重复ID管理员_' . time(),
            'username' => 'test_duplicate_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];

        // 第一次创建应该成功
        $admin1 = $this->service->create($data);
        $this->assertInstanceOf(TelegramAdmin::class, $admin1);

        // 第二次创建相同Telegram ID应该失败
        $this->expectException(MyBusinessException::class);
        $this->expectExceptionMessage('该 Telegram ID 已存在');
        $this->service->create($data);
    }

    /**
     * 测试获取管理员详情
     */
    public function testGetDetail()
    {
        // 创建测试数据
        $data = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => '测试详情管理员_' . time(),
            'username' => 'test_detail_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];
        $admin = TelegramAdmin::create($data);

        $result = $this->service->getDetail($admin->id);

        $this->assertInstanceOf(TelegramAdmin::class, $result);
        $this->assertEquals($admin->id, $result->id);
    }

    /**
     * 测试获取不存在的管理员详情
     */
    public function testGetDetailNotFound()
    {
        $this->expectException(MyBusinessException::class);
        $this->expectExceptionMessage('管理员不存在');
        $this->service->getDetail(99999);
    }

    /**
     * 测试更新管理员
     */
    public function testUpdate()
    {
        // 创建测试数据
        $data = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => '测试更新管理员_' . time(),
            'username' => 'test_update_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];
        $admin = TelegramAdmin::create($data);

        $updateData = [
            'nickname' => '已更新的昵称_' . time(),
            'remark' => '已更新的备注',
            'status' => 0
        ];

        $result = $this->service->update($admin->id, $updateData);

        $this->assertInstanceOf(TelegramAdmin::class, $result);
        $this->assertEquals($updateData['nickname'], $result->nickname);
        $this->assertEquals($updateData['status'], $result->status);
    }

    /**
     * 测试删除管理员
     */
    public function testDelete()
    {
        // 创建测试数据
        $data = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => '测试删除管理员_' . time(),
            'username' => 'test_delete_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];
        $admin = TelegramAdmin::create($data);

        $result = $this->service->delete($admin->id);

        $this->assertTrue($result);
        $this->assertNull(TelegramAdmin::find($admin->id));
    }

    /**
     * 测试状态切换
     */
    public function testToggleStatus()
    {
        // 创建测试数据
        $data = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => '测试状态切换管理员_' . time(),
            'username' => 'test_switch_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];
        $admin = TelegramAdmin::create($data);

        $result = $this->service->toggleStatus($admin->id);

        $this->assertInstanceOf(TelegramAdmin::class, $result);
        $this->assertEquals(0, $result->status); // 从1切换到0

        // 再次切换
        $result2 = $this->service->toggleStatus($admin->id);
        $this->assertEquals(1, $result2->status); // 从0切换到1
    }

    /**
     * 测试批量删除
     */
    public function testBatchDelete()
    {
        // 创建多个测试数据
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $data = [
                'telegram_id' => rand(100000000, 999999999),
                'nickname' => '批量测试管理员_' . $i . '_' . time(),
                'username' => 'batch_test_' . $i . '_' . time(),
                'status' => 1,
                'remark' => '测试备注'
            ];
            $admin = TelegramAdmin::create($data);
            $ids[] = $admin->id;
        }

        $result = $this->service->batchDelete($ids);

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
            $data = [
                'telegram_id' => rand(100000000, 999999999),
                'nickname' => '批量状态测试管理员_' . $i . '_' . time(),
                'username' => 'batch_status_test_' . $i . '_' . time(),
                'status' => 1,
                'remark' => '测试备注'
            ];
            $admin = TelegramAdmin::create($data);
            $ids[] = $admin->id;
        }

        $result = $this->service->batchUpdateStatus($ids, 0);

        $this->assertEquals(3, $result);
        foreach ($ids as $id) {
            $admin = TelegramAdmin::find($id);
            $this->assertEquals(0, $admin->status);
        }
    }

    /**
     * 测试根据Telegram ID查找
     */
    public function testFindByTelegramId()
    {
        $telegramId = rand(100000000, 999999999);
        $data = [
            'telegram_id' => $telegramId,
            'nickname' => '测试查找管理员_' . time(),
            'username' => 'test_find_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];
        TelegramAdmin::create($data);

        $result = $this->service->findByTelegramId($telegramId);

        $this->assertInstanceOf(TelegramAdmin::class, $result);
        $this->assertEquals($telegramId, $result->telegram_id);
    }

    /**
     * 测试根据用户名查找
     */
    public function testFindByUsername()
    {
        $username = 'test_username_' . time();
        $data = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => '测试用户名查找管理员_' . time(),
            'username' => $username,
            'status' => 1,
            'remark' => '测试备注'
        ];
        TelegramAdmin::create($data);

        $result = $this->service->findByUsername($username);

        $this->assertInstanceOf(TelegramAdmin::class, $result);
        $this->assertEquals($username, $result->username);
    }
}