# Docker 执行规范记忆

- 项目：AlkaidSYS 后端（ThinkPHP + Swoole，Docker 本地开发环境）。
- 约定：所有 `php think` 相关命令在本地开发环境必须通过 Docker 容器 `alkaid-backend` 执行，形式统一为：
  - `docker exec -it alkaid-backend php think <command>`（本地交互环境）
  - `docker exec alkaid-backend php think <command>`（CI / 非交互环境，可省略 `-it`）。
- 典型场景：单元/特性测试（`php think test`）、数据库迁移（`php think migrate:*`）、seed（`php think seed:run`）、缓存清理（`php think cache:clear`）、一次性维护脚本、Composer 安装等。
- 禁止：在宿主机直接运行 `php think test` / `php think migrate:run` / 任意 `php think ...` 命令。