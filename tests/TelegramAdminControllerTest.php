<?php

use PHPUnit\Framework\TestCase;
use app\admin\controller\v1\telegram\admin\IndexController;
use app\admin\controller\v1\telegram\admin\StoreController;
use app\admin\controller\v1\telegram\admin\DetailController;
use app\admin\controller\v1\telegram\admin\EditAdminController;
use app\admin\controller\v1\telegram\admin\DestroyController;
use app\admin\controller\v1\telegram\admin\StatusSwitchController;
use app\service\TelegramAdminService;
use app\admin\controller\v1\telegram\admin\validator\TelegramAdminValidator;
use app\model\TelegramAdmin;
use support\Request;

class TelegramAdminControllerTest extends TestCase
{
    private TelegramAdminService $service;
    private TelegramAdminValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TelegramAdminService(new \app\repository\TelegramAdminRepository());
        $this->validator = new TelegramAdminValidator();
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        TelegramAdmin::where('nickname', 'like', '测试%')->delete();
        parent::tearDown();
    }

    /**
     * 测试IndexController的index方法
     */
    public function testIndexControllerIndex()
    {
        // 创建测试数据
        $this->createTestAdmin();

        $controller = new IndexController($this->service);
        $request = $this->createMockRequest(['per_page' => 10]);

        $response = $controller->index($request);

        $this->assertInstanceOf(\support\Response::class, $response);
        $data = json_decode($response->rawBody(), true);
        $this->assertEquals(200, $data['code']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('list', $data['data']);
    }

    /**
     * 测试IndexController的statistics方法
     */
    public function testIndexControllerStatistics()
    {
        $controller = new IndexController($this->service);
        $response = $controller->statistics();

        $this->assertInstanceOf(\support\Response::class, $response);
        $data = json_decode($response->rawBody(), true);
        $this->assertEquals(200, $data['code']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('total', $data['data']);
    }

    /**
     * 测试StoreController的store方法
     */
    public function testStoreControllerStore()
    {
        $controller = new StoreController($this->validator, $this->service);
        $data = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => '测试创建控制器管理员_' . time(),
            'username' => 'test_controller_create_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];
        $request = $this->createMockRequest($data);

        $response = $controller->store($request);

        $this->assertInstanceOf(\support\Response::class, $response);
        $responseData = json_decode($response->rawBody(), true);
        $this->assertEquals(200, $responseData['code']);
        $this->assertArrayHasKey('data', $responseData);
    }

    /**
     * 测试DetailController的show方法
     */
    public function testDetailControllerShow()
    {
        // 创建测试数据
        $admin = $this->createTestAdmin();

        $controller = new DetailController($this->service);
        $request = $this->createMockRequest([]);

        $response = $controller->show($request, $admin->id);

        $this->assertInstanceOf(\support\Response::class, $response);
        $data = json_decode($response->rawBody(), true);
        $this->assertEquals(200, $data['code']);
        $this->assertArrayHasKey('data', $data);
    }

    /**
     * 测试DetailController的show方法 - ID为空
     */
    public function testDetailControllerShowEmptyId()
    {
        $controller = new DetailController($this->service);
        $request = $this->createMockRequest([]);

        $response = $controller->show($request, 0);

        $this->assertInstanceOf(\support\Response::class, $response);
        $data = json_decode($response->rawBody(), true);
        $this->assertEquals(400, $data['code']);
        $this->assertEquals('ID不能为空', $data['msg']);
    }

    /**
     * 测试EditAdminController的update方法
     */
    public function testEditAdminControllerUpdate()
    {
        // 创建测试数据
        $admin = $this->createTestAdmin();

        $controller = new EditAdminController($this->validator, $this->service);
        $data = [
            'id' => $admin->id,
            'nickname' => '已更新的控制器管理员_' . time(),
            'remark' => '已更新的备注',
            'status' => 0
        ];
        $request = $this->createMockRequest($data);

        $response = $controller->update($request);

        $this->assertInstanceOf(\support\Response::class, $response);
        $responseData = json_decode($response->rawBody(), true);
        $this->assertEquals(200, $responseData['code']);
        $this->assertArrayHasKey('data', $responseData);
    }

    /**
     * 测试EditAdminController的update方法 - ID为空
     */
    public function testEditAdminControllerUpdateEmptyId()
    {
        $controller = new EditAdminController($this->validator, $this->service);
        $request = $this->createMockRequest([]);

        $response = $controller->update($request);

        $this->assertInstanceOf(\support\Response::class, $response);
        $data = json_decode($response->rawBody(), true);
        $this->assertEquals(400, $data['code']);
        $this->assertEquals('ID不能为空', $data['msg']);
    }

    /**
     * 测试EditAdminController的batchUpdateStatus方法
     */
    public function testEditAdminControllerBatchUpdateStatus()
    {
        // 创建多个测试数据
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $admin = $this->createTestAdmin('批量状态测试_' . $i . '_' . time());
            $ids[] = $admin->id;
        }

        $controller = new EditAdminController($this->validator, $this->service);
        $data = [
            'ids' => $ids,
            'status' => 0
        ];
        $request = $this->createMockRequest($data);

        $response = $controller->batchUpdateStatus($request);

        $this->assertInstanceOf(\support\Response::class, $response);
        $responseData = json_decode($response->rawBody(), true);
        $this->assertEquals(200, $responseData['code']);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertEquals(3, $responseData['data']['count']);
    }

    /**
     * 测试DestroyController的destroy方法
     */
    public function testDestroyControllerDestroy()
    {
        // 创建测试数据
        $admin = $this->createTestAdmin();

        $controller = new DestroyController($this->validator, $this->service);
        $request = $this->createMockRequest(['id' => $admin->id]);

        $response = $controller->destroy($request);

        $this->assertInstanceOf(\support\Response::class, $response);
        $data = json_decode($response->rawBody(), true);
        $this->assertEquals(200, $data['code']);
        $this->assertEquals('删除管理员成功', $data['msg']);
    }

    /**
     * 测试DestroyController的destroy方法 - ID为空
     */
    public function testDestroyControllerDestroyEmptyId()
    {
        $controller = new DestroyController($this->validator, $this->service);
        $request = $this->createMockRequest([]);

        $response = $controller->destroy($request);

        $this->assertInstanceOf(\support\Response::class, $response);
        $data = json_decode($response->rawBody(), true);
        $this->assertEquals(400, $data['code']);
        $this->assertEquals('ID不能为空', $data['msg']);
    }

    /**
     * 测试DestroyController的batchDestroy方法
     */
    public function testDestroyControllerBatchDestroy()
    {
        // 创建多个测试数据
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $admin = $this->createTestAdmin('批量删除测试_' . $i . '_' . time());
            $ids[] = $admin->id;
        }

        $controller = new DestroyController($this->validator, $this->service);
        $data = ['ids' => $ids];
        $request = $this->createMockRequest($data);

        $response = $controller->batchDestroy($request);

        $this->assertInstanceOf(\support\Response::class, $response);
        $responseData = json_decode($response->rawBody(), true);
        $this->assertEquals(200, $responseData['code']);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertEquals(3, $responseData['data']['count']);
    }

    /**
     * 测试StatusSwitchController的toggle方法
     */
    public function testStatusSwitchControllerToggle()
    {
        // 创建测试数据
        $admin = $this->createTestAdmin();

        $controller = new StatusSwitchController($this->service);
        $request = $this->createMockRequest(['id' => $admin->id]);

        $response = $controller->toggle($request);

        $this->assertInstanceOf(\support\Response::class, $response);
        $data = json_decode($response->rawBody(), true);
        $this->assertEquals(200, $data['code']);
        $this->assertArrayHasKey('data', $data);
    }

    /**
     * 测试StatusSwitchController的toggle方法 - ID为空
     */
    public function testStatusSwitchControllerToggleEmptyId()
    {
        $controller = new StatusSwitchController($this->service);
        $request = $this->createMockRequest([]);

        $response = $controller->toggle($request);

        $this->assertInstanceOf(\support\Response::class, $response);
        $data = json_decode($response->rawBody(), true);
        $this->assertEquals(400, $data['code']);
        $this->assertEquals('ID不能为空', $data['msg']);
    }

    /**
     * 创建测试管理员
     */
    private function createTestAdmin(string $nickname = null): TelegramAdmin
    {
        $data = [
            'telegram_id' => rand(100000000, 999999999),
            'nickname' => $nickname ?: '测试控制器管理员_' . time(),
            'username' => 'test_controller_' . time(),
            'status' => 1,
            'remark' => '测试备注'
        ];

        return TelegramAdmin::create($data);
    }

    /**
     * 创建模拟Request对象
     */
    private function createMockRequest(array $data): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('all')->willReturn($data);
        $request->method('input')->willReturnCallback(function ($key, $default = null) use ($data) {
            return $data[$key] ?? $default;
        });

        return $request;
    }

    /**
     * 创建模拟Request对象（带路由参数）
     */
    private function createMockRequestWithRoute(array $data): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('all')->willReturn($data);
        $request->method('input')->willReturnCallback(function ($key, $default = null) use ($data) {
            return $data[$key] ?? $default;
        });
        $request->method('route')->willReturnCallback(function ($key, $default = null) use ($data) {
            return $data[$key] ?? $default;
        });

        return $request;
    }
}