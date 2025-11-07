#!/bin/bash
# 检查是否传入参数
if [ -z "$1" ]; then
  echo "请提供参数: start, stop"
  exit 1
fi

# 获取传入的命令参数
action=$1

# 获取当前脚本文件所在目录的名字
script_dir=$(dirname "$(realpath "$0")")  # 获取当前脚本的完整目录路径
dir_name=$(basename "$script_dir")        # 仅获取目录的名字

# 输出当前目录名称
echo "当前脚本所在目录名称: $dir_name"

# 获取当前工作目录
current_dir=$(pwd)

# 设定 www 目录的关键字
target_dir="www"
# 一直向上查找 www 目录
while [[ "$current_dir" != "/" && ! "$current_dir" =~ "/$target_dir" ]]; do
  current_dir=$(dirname "$current_dir")  # 进入父目录
done

# 检查是否找到了 www 目录
if [[ "$current_dir" != "/" && "$current_dir" =~ "/$target_dir" ]]; then
  # 获取 www 后面的路径
  cmd="php ./api/${dir_name#*$target_dir/}/start.php ${action}"
  echo "www 目录后的路径是: $cmd"
  docker exec -it php82 bash -c "php ./3pay/3pay-api/start.php ${action}"
else
  echo "未找到www目录"
fi