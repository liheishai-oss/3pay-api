<?php

use PHPUnit\Framework\TestCase;
use app\model\TelegramAdmin;

class TelegramAdminModelTest extends TestCase
{
    protected function tearDown(): void
    {
        // 清理测试数据
        TelegramAdmin::where('nickname', 'like', '测试%')->delete();
        parent::tearDown();
    }

    /**
     * 测试模型常量
     */
    public function testConstants()
    {
        $this->assertEquals(0, TelegramAdmin::STATUS_DISABLED);
        $this->assertEquals(1, TelegramAdmin::STATUS_ENABLED);
    }

    /**
     * 测试模型属性
     */
    public function testFillableAttributes()
    {
        $fillable = [
            'telegram_id',
            'nickname',
            'username',
            'status',
            'remark'
        ];

        $this->assertEquals($fillable, (new TelegramAdmin())->getFillable());
    }

    /**
     * 测试模型类型转换
     */
    public function testCasts()
    {
        $admin = new TelegramAdmin();
        $casts = $admin->getCasts();

        $this->assertEquals('integer', $casts['telegram_id']);
        $this->assertEquals('integer', $casts['status']);
        $this->assertEquals('datetime', $casts['created_at']);
        $this->assertEquals('datetime', $casts['updated_at']);
    }

    /**
     * 测试创建管理员
     */
    public function testCreateAdmin()
    {
        $data = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => '测试模型管理员_' . time(),
            'username' => 'test_model_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];

        $admin = TelegramAdmin::create($data);

        $this->assertInstanceOf(TelegramAdmin::class, $admin);
        $this->assertEquals($data['telegram_id'], $admin->telegram_id);
        $this->assertEquals($data['nickname'], $admin->nickname);
        $this->assertEquals($data['username'], $admin->username);
        $this->assertEquals($data['status'], $admin->status);
        $this->assertEquals($data['remark'], $admin->remark);
        $this->assertNotNull($admin->id);
        $this->assertNotNull($admin->created_at);
        $this->assertNotNull($admin->updated_at);
    }

    /**
     * 测试状态文本属性
     */
    public function testStatusTextAttribute()
    {
        $admin = $this->createTestAdmin(['status' => 1]);
        $this->assertEquals('启用', $admin->status_text);

        $admin = $this->createTestAdmin(['status' => 0]);
        $this->assertEquals('禁用', $admin->status_text);
    }

    /**
     * 测试启用状态查询作用域
     */
    public function testEnabledScope()
    {
        $this->createTestAdmin(['status' => 1]);
        $this->createTestAdmin(['status' => 0]);

        $enabledAdmins = TelegramAdmin::enabled()->get();
        $this->assertGreaterThan(0, $enabledAdmins->count());

        foreach ($enabledAdmins as $admin) {
            $this->assertEquals(1, $admin->status);
        }
    }

    /**
     * 测试更新管理员
     */
    public function testUpdateAdmin()
    {
        $admin = $this->createTestAdmin();

        $updateData = [
            'nickname' => '已更新的模型管理员_' . time(),
            'remark' => '已更新的备注',
            'status' => 0
        ];

        $admin->update($updateData);

        $this->assertEquals($updateData['nickname'], $admin->nickname);
        $this->assertEquals($updateData['remark'], $admin->remark);
        $this->assertEquals($updateData['status'], $admin->status);
    }

    /**
     * 测试删除管理员
     */
    public function testDeleteAdmin()
    {
        $admin = $this->createTestAdmin();
        $adminId = $admin->id;

        $result = $admin->delete();

        $this->assertTrue($result);
        $this->assertNull(TelegramAdmin::find($adminId));
    }

    /**
     * 测试批量操作
     */
    public function testBatchOperations()
    {
        // 创建多个测试数据
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $admin = $this->createTestAdmin('批量模型测试_' . $i . '_' . time());
            $ids[] = $admin->id;
        }

        // 测试批量更新
        $updateResult = TelegramAdmin::whereIn('id', $ids)->update(['status' => 0]);
        $this->assertEquals(3, $updateResult);

        // 验证更新结果
        foreach ($ids as $id) {
            $admin = TelegramAdmin::find($id);
            $this->assertEquals(0, $admin->status);
        }

        // 测试批量删除
        $deleteResult = TelegramAdmin::whereIn('id', $ids)->delete();
        $this->assertEquals(3, $deleteResult);

        // 验证删除结果
        foreach ($ids as $id) {
            $this->assertNull(TelegramAdmin::find($id));
        }
    }

    /**
     * 测试查询条件
     */
    public function testQueryConditions()
    {
        $telegramId = rand(100000000, 999999999);
        $username = 'test_query_' . time();
        
        $admin = $this->createTestAdmin([
            'telegram_id' => $telegramId,
            'username' => $username,
            'status' => 1
        ]);

        // 测试根据Telegram ID查询
        $foundByTelegramId = TelegramAdmin::where('telegram_id', $telegramId)->first();
        $this->assertInstanceOf(TelegramAdmin::class, $foundByTelegramId);
        $this->assertEquals($admin->id, $foundByTelegramId->id);

        // 测试根据用户名查询
        $foundByUsername = TelegramAdmin::where('username', $username)->first();
        $this->assertInstanceOf(TelegramAdmin::class, $foundByUsername);
        $this->assertEquals($admin->id, $foundByUsername->id);

        // 测试复合条件查询
        $foundByConditions = TelegramAdmin::where('status', 1)
            ->where('telegram_id', $telegramId)
            ->first();
        $this->assertInstanceOf(TelegramAdmin::class, $foundByConditions);
        $this->assertEquals($admin->id, $foundByConditions->id);
    }

    /**
     * 测试排序
     */
    public function testOrdering()
    {
        // 创建多个测试数据
        for ($i = 0; $i < 3; $i++) {
            $this->createTestAdmin('排序测试_' . $i . '_' . time());
            sleep(1); // 确保创建时间不同
        }

        // 测试按创建时间降序排列
        $admins = TelegramAdmin::where('nickname', 'like', '排序测试_%')
            ->orderBy('created_at', 'desc')
            ->get();

        $this->assertGreaterThan(1, $admins->count());
        
        // 验证排序
        for ($i = 0; $i < $admins->count() - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $admins[$i + 1]->created_at,
                $admins[$i]->created_at
            );
        }
    }

    /**
     * 测试分页
     */
    public function testPagination()
    {
        // 创建多个测试数据
        for ($i = 0; $i < 5; $i++) {
            $this->createTestAdmin('分页测试_' . $i . '_' . time());
        }

        $paginated = TelegramAdmin::where('nickname', 'like', '分页测试_%')
            ->paginate(2);

        $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $paginated);
        $this->assertEquals(2, $paginated->perPage());
        $this->assertGreaterThan(0, $paginated->total());
    }

    /**
     * 测试唯一约束
     */
    public function testUniqueConstraints()
    {
        $telegramId = rand(100000000, 999999999);
        $username = 'test_unique_' . time();

        // 创建第一个管理员
        $admin1 = $this->createTestAdmin([
            'telegram_id' => $telegramId,
            'username' => $username
        ]);

        // 尝试创建相同Telegram ID的管理员应该失败
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->createTestAdmin([
            'telegram_id' => $telegramId,
            'username' => 'different_username'
        ]);
    }

    /**
     * 创建测试管理员
     */
    private function createTestAdmin(array $data = []): TelegramAdmin
    {
        $defaultData = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => '测试模型管理员_' . time(),
            'username' => 'test_model_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];

        $data = array_merge($defaultData, $data);

        return TelegramAdmin::create($data);
    }
}