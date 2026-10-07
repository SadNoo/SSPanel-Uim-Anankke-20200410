# 数据库引用清单（基于本仓库代码）

> 本清单基于本仓库（提交历史 2018-12 ～ 2020-04）的代码整理，用来和**你服务器上实际部署的版本**做对比。
> 实际部署的版本和数据库结构，请用 `tool/db-audit/collect.sh` 在服务器上收集后再对照（见文末）。

## 1. 数据库连接入口

| 位置 | 作用 | 说明 |
| --- | --- | --- |
| `app/Services/Boot.php` | 主连接（Eloquent / Capsule） | 读取 `Config::getDbConfig()`；开启 Radius 时再加一个名为 `radius` 的连接 |
| `app/Services/Config.php` | `getDbConfig()`、`getRadiusDbConfig()` | 只有 driver / host / database / username / password / charset / collation / prefix，**没有端口和 SSL 配置** |
| `app/Utils/DatatablesHelper.php` | 后台表格（Datatables）专用连接 | 另建一个 Capsule 连接，执行手写 SQL |
| `app/Command/Update.php:137` | `mysqli_connect` 直连 | 升级时执行 `ALTER TABLE user ADD discord ...`，不经过 Eloquent |
| `config/.config.php` | 配置项 | `db_driver`、`db_host`、`db_database`、`db_username`、`db_password`、`db_charset`、`db_collation`、`db_prefix`；Radius 用 `radius_db_*` |

迁移到远程数据库时，以上 4 个代码入口都要支持端口和 SSL。

## 2. 数据表 ↔ 模型 ↔ 引用位置

路径相对于 `app/`。

### 用户与账号

| 表 | 模型 | 引用位置 |
| --- | --- | --- |
| `user` | `User` | 几乎所有控制器、`Command/*`、支付网关、`Services/Auth/*`、`Services/Token/*`、`Utils/Radius.php`、`Utils/Telegram*` 等（约 50 个文件） |
| `user_token` | `Token` | `Models/User.php`、`Services/Token/DB.php` |
| `ss_password_reset` | `PasswordReset` | `Controllers/PasswordController.php`、`Models/User.php`、`Services/Password.php` |
| `email_verify` | `EmailVerify` | `Command/Job.php`、`Controllers/AuthController.php` |
| `ss_invite_code` | `InviteCode` | `Controllers/AuthController.php`、`HomeController.php`、`UserController.php`、`VueController.php`、`Models/User.php` |
| `login_ip` | `LoginIp` | `Command/Job.php`、`Controllers/AuthController.php`、`UserController.php`、`Models/User.php` |
| `telegram_session` | `TelegramSession` | `Command/Job.php`、`Models/User.php`、`Utils/TelegramSessionManager.php` |
| `link` | `Link` | `Controllers/LinkController.php`（订阅链接）、`Models/User.php` |
| `ss_checkin_log` | `CheckInLog` | 代码中没有引用，**SQL 文件里也没有建表** |
| `user_role` | `Role` | 代码中没有引用，**SQL 文件里也没有建表** |

### 节点与后端通信

| 表 | 模型 | 引用位置 |
| --- | --- | --- |
| `ss_node` | `Node` | 后台、用户中心、`Mod_Mu/*`、`Mu/*`、`Middleware/Mod_Mu.php`、`Middleware/Mu.php`、`Command/Job.php`、`Utils/URL.php` 等（约 30 个文件） |
| `ss_node_info` | `NodeInfoLog` | `Command/Job.php`、`Mod_Mu/NodeController.php`、`Mu/NodeController.php`、`UserController.php`、`Models/Node.php` |
| `ss_node_online_log` | `NodeOnlineLog` | `Command/Job.php`、`Mod_Mu/UserController.php`、`Mu/NodeController.php`、`UserController.php`、`Models/Node.php` |
| `user_traffic_log` | `TrafficLog` | `Command/Job.php`、`Command/SyncRadius.php`、`Mod_Mu/UserController.php`、`Mu/UserController.php`、`UserController.php`、`Models/Node.php`、`Models/User.php` |
| `alive_ip` | `Ip` | `Command/Job.php`、`Admin/UserController.php`、`Mod_Mu/UserController.php`、`UserController.php`、`Models/User.php` |
| `blockip` | `BlockIp` | `Command/Job.php`、`Admin/IpController.php`、`Mod_Mu/FuncController.php`、`UserController.php` |
| `unblockip` | `UnblockIp` | `Command/Job.php`、`Admin/IpController.php`、`Mod_Mu/FuncController.php`、`UserController.php`、`Models/User.php` |
| `disconnect_ip` | `Disconnect` | `Command/Job.php`、`Models/User.php` |
| `detect_list` | `DetectRule` | `Admin/DetectController.php`、`Mod_Mu/FuncController.php`、`UserController.php`、`Models/DetectLog.php` |
| `detect_log` | `DetectLog` | `Command/Job.php`、`Mod_Mu/UserController.php`、`UserController.php`、`Models/User.php` |
| `relay` | `Relay` | `Command/XCat.php`、`Admin/RelayController.php`、`Admin/UserController.php`、`Mod_Mu/FuncController.php`、`RelayController.php`、`UserController.php`、`VueController.php`、`Utils/Tools.php`、`Utils/URL.php` |
| `speedtest` | `Speedtest` | `Command/Job.php`、`Mod_Mu/FuncController.php`、`UserController.php`、`Models/Node.php` |
| `auto` | `Auto` | `Admin/AutoController.php`、`Mod_Mu/FuncController.php` |

### 商店、支付与充值

| 表 | 模型 | 引用位置 |
| --- | --- | --- |
| `shop` | `Shop` | `Command/Job.php`、`Admin/ShopController.php`、`Admin/UserController.php`、`UserController.php`、`VueController.php`、`Models/Bought.php` |
| `bought` | `Bought` | `Command/Job.php`、`Admin/ShopController.php`、`Admin/UserController.php`、`UserController.php`、`Models/Payback.php`、`Models/User.php` |
| `coupon` | `Coupon` | `AdminController.php`、`UserController.php` |
| `code` | `Code` | `Admin/CodeController.php`、`UserController.php`、`VueController.php`、`Models/User.php`、`Services/Gateway/AbstractPayment.php`、`PaymentWall.php`、`Utils/DoiAMPay.php`；`Command/FinanceMail.php`（手写 SQL） |
| `paylist` | `Paylist` | `Services/Gateway/*`（AbstractPayment、AopF2F、BitPay、ChenPay、Codepay、DoiAMPay、PAYJS、SPay、TrimePay）、`Utils/DoiAMPay.php` |
| `payback` | `Payback` | `UserController.php`、`VueController.php`、`Services/Gateway/AbstractPayment.php`、`PaymentWall.php`、`Utils/DoiAMPay.php` |
| `yft_order_info` | 无模型 | 只在 `Command/FinanceMail.php` 的手写 SQL 中出现，**SQL 文件里没有建表**（先查表是否存在才使用） |

### 系统与其他

| 表 | 模型 | 引用位置 |
| --- | --- | --- |
| `config` | `Config`（模型） | `Services/Gateway/ChenPay.php`（注意：大量 `Config::get()` 指的是 `Services/Config`，读配置文件，不是这张表） |
| `announcement` | `Ann` | `Command/DailyMail.php`、`Admin/AnnController.php`、`Client/ClientApiController.php`、`UserController.php`、`VueController.php` |
| `ticket` | `Ticket` | `Admin/TicketController.php`、`UserController.php`、`Models/TelegramSession.php` |

### Radius（只在 `enable_radius = true` 时使用，走 `radius` 连接）

| 表 | 模型 | 引用位置 |
| --- | --- | --- |
| `radius_ban` | `RadiusBan` | `Command/Job.php`、`Models/User.php`、`Utils/Radius.php`（主库） |
| `nas` | `RadiusNas` | `Command/SyncRadius.php`、`Utils/Radius.php` |
| `radacct` | `RadiusRadAcct` | `Command/SyncRadius.php` |
| `radcheck` | `RadiusRadCheck` | `Utils/Radius.php` |
| `radpostauth` | `RadiusRadPostauth` | `Command/SyncRadius.php` |
| `radusergroup` | `RadiusRadUserGroup` | `Utils/Radius.php` |

## 3. 手写 SQL / MySQL 专用写法

换数据库或升级 MySQL 版本时，这些地方最需要测试。

| 位置 | 内容 | 风险 |
| --- | --- | --- |
| `Command/FinanceMail.php`（5 处） | `TO_DAYS(NOW())`、`DATEDIFF`、`yearweek(date_format(...))`、查询 `INFORMATION_SCHEMA` | MySQL 专用函数 |
| `Command/Job.php:503`、`:946` | `whereRaw('UNIX_TIMESTAMP()-\`node_heartbeat\`<300')` | MySQL 专用函数 |
| `Models/Node.php:57`、`:67` | `whereRaw('\`log_time\`%1800<60')` | 反引号是 MySQL 语法 |
| `Controllers/Admin/UserController.php:440`、`:472` | `orderByRaw($order_field . ' ' . $order)` | 排序字段和方向直接取自请求参数，**没有白名单校验**，是 SQL 注入点（仅管理员可访问） |
| `Command/Update.php:137-138` | `mysqli_connect` + `ALTER TABLE` | 绕过主连接配置 |
| 后台表格（Datatables） | `Admin/TicketController.php`(1)、`Admin/IpController.php`(4)、`Admin/RelayController.php`(1)、`Admin/ShopController.php`(2)、`Admin/DetectController.php`(2)、`Admin/NodeController.php`(1)、`Admin/AnnController.php`(1)、`Admin/AutoController.php`(1)、`Admin/CodeController.php`(1)、`AdminController.php`(3) | 多表 JOIN 的手写 SQL，严格 `sql_mode` 下需测试 |

## 4. 节点后端如何访问数据库

| 方式 | 路由 | 控制器 | 读写的表 |
| --- | --- | --- | --- |
| WebAPI（`/mod_mu`） | `config/routes.php:314` | `Mod_Mu/NodeController.php`、`Mod_Mu/UserController.php`、`Mod_Mu/FuncController.php` | 读：`ss_node`、`user`、`relay`、`detect_list`、`blockip`、`unblockip`、`auto`；写：`ss_node_info`、`ss_node_online_log`、`user_traffic_log`、`user`（流量）、`alive_ip`、`detect_log`、`blockip`、`speedtest`、`auto` |
| 旧版 Mu API（`/mu`） | `config/routes.php` | `Mu/NodeController.php`、`Mu/UserController.php` | 读：`ss_node`、`user`；写：`ss_node_info`、`ss_node_online_log`、`user_traffic_log` |
| 节点直连数据库 | 无（节点端配置） | 无 | 节点端直接读写上述表 |

**数据库独立部署后，建议所有节点改用 WebAPI**，数据库只允许面板服务器连接。

## 5. 建表文件

| 文件 | 内容 |
| --- | --- |
| `sql/glzjin_all.sql` | 主建表脚本（30 张表）；字符集混用：17 张 `utf8`、2 张 `utf8mb4`、1 张 `latin1`（`ss_password_reset`） |
| `sql/config.sql` | `config` 表 |
| `sql/clean.sql` | 清理日志数据 |
| `sql/fix_unable_to_reg.sql` | 注册问题修复 |

## 6. 下一步：确认服务器实际版本

在**面板服务器**上运行 `tool/db-audit/collect.sh`（用法见脚本开头注释），它会收集：

- MySQL / MariaDB 版本和关键参数（字符集、`sql_mode`、认证插件）；
- 数据库**表结构**（不含数据）、每张表的行数和大小；
- 面板代码的版本信息：git 提交（如果有）、`composer.lock`、PHP 文件指纹、配置项**名称**（不含值）；
- PHP 版本和扩展。

脚本**不会导出用户数据和密码**。把生成的压缩包发给我，我会：

1. 用 `tool/db-audit/match_commit.py file-hashes.txt` 在本仓库历史里找文件指纹最接近的提交，确定你部署的是哪个版本；
2. 对比实际表结构和代码引用，列出多出来、缺少的表和字段；
3. 根据实际情况调整数据库迁移方案。
