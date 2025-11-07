<?php
namespace app\middleware;

use app\common;
use app\model\Admin;
use app\model\AdminLog;
use app\model\RoleGroup;
use ReflectionClass;
use support\Log;
use support\Redis;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use app\exception\MyBusinessException;
class Auth implements MiddlewareInterface
{

    public function process(Request $request, callable $handler) : Response
    {
        // 当路由未命中或尚未解析出控制器/方法时，直接放行，避免反射空值导致500
        if (empty($request->controller) || empty($request->action)) {
            return $handler($request);
        }

        $controller = new ReflectionClass($request->controller);
        $noNeedLogin = $controller->getDefaultProperties()['noNeedLogin'] ?? [];

        // 如果noNeedLogin包含*，则跳过认证
        if (in_array('*', $noNeedLogin)) {
            return $handler($request);
        }

        // 如果动作不在noNeedLogin中，则需要认证
        if (!in_array($request->action, $noNeedLogin)) {

            $token = request()->header('Authorization') ?? false;
            if (!$token) {
                throw new MyBusinessException('请登录后操作1',401);
            }

            if (str_starts_with(strtolower($token), 'bearer ')) {
                $token = trim(substr($token, 7)); // 截取 'Bearer ' 后面的内容
            }
            try{
                $userData = Redis::hGetAll(common::LOGIN_TOKEN_PREFIX."admin:{$token}") ?: throw new MyBusinessException('请登录后操作2',401);
                $userData['group_id'] = json_decode($userData['group_id'],true);
                // 将 Redis 中的字符串转换为布尔值
                $userData['is_merchant_admin'] = ($userData['is_merchant_admin'] ?? '0') === '1';
                $userData['is_agent'] = ($userData['is_agent'] ?? '0') === '1';
                
                // 转换 agent_id 为整数或 null
                // 如果是代理商但 agent_id 为空，尝试从数据库查询
                $isAgent = ($userData['is_agent'] ?? '0') === '1';
                echo "代理检测";
                print_r($userData);
                if ($isAgent && empty($userData['agent_id'])) {
                    $agent = \app\model\Agent::where('admin_id', $userData['admin_id'])->first();
                    if ($agent) {
                        $userData['agent_id'] = $agent->id;
                    } else {
                        $userData['agent_id'] = null;
                    }
                } else {
                    $userData['agent_id'] = !empty($userData['agent_id']) ? (int)$userData['agent_id'] : null;
                }
            } catch (\Exception $e) {

                if($e->getCode() == 401){
                    throw $e;
                }
                Log::error('redis异常',[
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString(),
                ]);
                $userinfo = Admin::where(['token'=>$token])->first();
                if (!$userinfo) {
                    throw new MyBusinessException('请登录后操作3',401);
                }

                $allGroupIds = $this->getAllSubGroupIds($userinfo->group_id);
                $allGroupIds[] = $userinfo->group_id;

                // 商户功能已移除
                $isMerchantAdmin = false;
                
                // 代理商判断（代理商管理组 group_id = 3）
                $isAgent = $userinfo->group_id == 3;
                $agentId = null;
                
                // 如果是代理商，查询对应的 agent_id
                if ($isAgent) {
                    $agent = \app\model\Agent::where('admin_id', $userinfo->id)->first();
                    if ($agent) {
                        $agentId = $agent->id;
                    } else {
                        // 记录警告日志
                        Log::warning('代理商账号未找到对应的代理商记录', [
                            'admin_id' => $userinfo->id,
                            'username' => $userinfo->username,
                            'group_id' => $userinfo->group_id
                        ]);
                    }
                }
                
                $userData = [
                    'admin_id' => $userinfo->id,
                    'nickname' => $userinfo->nickname,
                    'username' => $userinfo->username,
                    'user_group_id' => $userinfo->group_id,
                    'group_id' => $allGroupIds,
                    'status' => $userinfo->status,
                    'is_merchant_admin' => $isMerchantAdmin,
                    'is_agent' => $isAgent,
                    'agent_id' => $agentId,
                ];

            }
            $request->userData = $userData;
        }

        // 执行控制器
        $response = $handler($request);

        // 记录操作日志
        if (isset($request->userData)) {
            $this->logUserOperation($request);
        }
        return $response;
    }
    private function logUserOperation(Request $request)
    {
        $user = $request->userData;
        AdminLog::insert([
            'admin_id' => $user['admin_id'],
            'username' => $user['username'],
            'route' => $request->path(),
            'method' => $request->method(),
            'params' => json_encode($request->all(), JSON_UNESCAPED_UNICODE),
            'ip' => $request->getRealIp(true),
            'user_agent' => $request->header('user-agent', ''),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    private function getAllSubGroupIds($groupId): array
    {
        $groupIds = [];
        $this->getSubGroupIdsRecursive($groupId, $groupIds);
        return $groupIds;
    }

    private function getSubGroupIdsRecursive($parentGroupId, &$groupIds)
    {
        // 获取当前父分组的子分组
        $subGroups = RoleGroup::where('parent_id', $parentGroupId)->get();

        foreach ($subGroups as $subGroup) {
            // 将当前分组ID添加到结果数组中
            $groupIds[] = $subGroup->id;

            // 递归查询子分组
            $this->getSubGroupIdsRecursive($subGroup->id, $groupIds);
        }
    }
}