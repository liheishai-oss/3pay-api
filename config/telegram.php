<?php
/**
 * Telegram机器人配置
 */

return [
    // Telegram Bot Token
    // 从 @BotFather 获取
    'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
    
    // 默认聊天ID
    // 从 @userinfobot 获取你的Chat ID
    'chat_id' => env('TELEGRAM_CHAT_ID', ''),
    
    // API基础URL（可选，默认使用官方API）
    'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
    
    // 超时设置（秒）
    'timeout' => 10,
    
    // 是否启用机器人推送
    'enabled' => env('TELEGRAM_ENABLED', true),
];
