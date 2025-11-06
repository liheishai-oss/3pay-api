#!/bin/bash

# 机器人消息监控启动脚本

echo "🤖 启动机器人消息监控服务"
echo "========================"



# 创建日志目录
mkdir -p runtime/logs

echo "🚀 启动WebSocket服务器..."
echo "📡 监听地址: ws://0.0.0.0:8789"
echo "🔗 前端监控: http://localhost:3000/robot/monitor"
echo ""

# 启动WebSocket服务器
php start.php start
docker exec -it php82 bash -c "php ./fourth-party-payment/backend-api/start.php ${action}"
echo ""
echo "✅ 机器人消息监控服务已启动"
echo "💡 使用以下命令测试连接:"
echo "   php robot_monitor_client.php"
echo ""
echo "💡 访问前端监控页面:"
echo "   http://localhost:3000/robot/monitor"
echo ""
echo "🛑 停止服务请按 Ctrl+C"















