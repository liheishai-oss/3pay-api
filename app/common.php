<?php

namespace app;

use app\common\constants\SystemConstants;

class common
{
    const LOGIN_TOKEN_PREFIX = SystemConstants::CACHE_PREFIX.'login:token:';
    const TENANT_GROUP_ID = 3;      // 租户分组id
    const ADMIN_USER_ID   = 5;      // 超级系统管理员id

    const REDIS_KEY_MENU_PREFIX = SystemConstants::CACHE_PREFIX.'admin_menu:'; // Redis菜单缓存前缀
}