### 安装项目依赖组件
composer install --optimize-autoloader

### 数据库初始化并安装
### 默认使用 Sqlite 数据库，若使用其他数据库请修改配置后再执行
php think migrate:run

### 开启PHP内置WEB服务
### 默认后台登录账号及密码都是 admin
php think run --host 127.0.0.1
```

## 数据库安装

1. 创建空的数据库，其中 **Sqlite** 不需要创建；
2. 将数据库配置到 **config/database.php** 文件；

注意：数据库参数修改，除了要修改连接参数，还需要切换 **default** 默认连接名称，如下面的 **mysql**、**sqlite** 等。

```php
return [
     // 数据库类型
    'default' => 'sqlite',
    // 数据库连接参数
    'connections' => [
        'mysql'  => [ /* 具体参数省略 */ ], 
        'sqlite' => [ /* 具体参数省略 */ ],
    ]       
]
```

当前版本是 **ThinkAdmin v6.1** ，不需要导入数据库 `SQL` 脚本，修改数据库配置后执行 `php think migrate:run` 即可；

## 技术支持

开发前请认真阅读 ThinkPHP 官方文档，会对您有帮助哦！

本地开发请使用 `php think run` 运行服务，访问 `http://127.0.0.1:8000` 即可进入项目。

官方地址及开发指南：https://doc.thinkadmin.top ，如果实在无法解决问题，可以加入官方群免费交流。

**1.官方QQ交流群：** 513350915

**2.官方QQ交流群：** 866345568

**3.官方微信交流群**

<img alt="" src="https://doc.thinkadmin.top/static/img/wx.png" width="250">

## 注解权限

注解权限是指通过方法注释来实现后台 **RBAC** 授权管理，用注解来管理功能节点。

开发人员只需要写好注释，会自动生成功能的节点，只需要配置角色及用户就可以使用 **RBAC** 权限。

* 此版本的权限使用注解实现
* 注释必须是标准的块注释，案例如下展示
* 其中 `@auth true` 表示访问需要权限验证
* 其中 `@menu true` 菜单编辑显示可选节点
* 其中 `@login true` 需要强制登录才可访问

```php
/**
 * 操作的名称
 * @auth true  # 表示访问需要权限验证
 * @menu true  # 菜单编辑显示可选节点
 * @login true # 需要强制登录才可访问 
 */
public function index(){
   // @todo
}
```

## 代码仓库

主仓库放置于 **Gitee**, **Github** 为镜像仓库。

部分代码来自互联网，若有异议可以联系作者进行删除。

* 在线体验地址：https://v6.thinkadmin.top （账号和密码都是 admin ）
* Gitee 仓库地址：https://gitee.com/zoujingli/ThinkAdmin
* Github 仓库地址：https://github.com/zoujingli/ThinkAdmin

## 框架指令

* 执行 `php think run` 启用本地开发环境，访问 `http://127.0.0.1:8000`
* 执行 `php think xadmin:package` 将现有 `MySQL` 数据库打包为 `Phinx` 数据库脚本
* 执行 `php think xadmin:sysmenu` 重写系统菜单并生成新编号，同时会清理已禁用的菜单数据
* 执行 `php think xadmin:fansall` 同步微信粉丝数据，依赖于 `ThinkPlugsWechat` 应用插件
* 执行 `php think xadmin:replace` 可以批量替换数据库指定字符字段内容，通常用于文件地址替换
* 执行 `php think xadmin:database` 对数据库的所有表 `repair|optimize` 操作，优化并整理数据库碎片
* 执行 `php think xadmin:publish` 可自动安装现在模块或已安装应用插件，增加 `--migrate` 参数执行数据库脚本

#### 1. 任务进程管理（可自建定时任务去守护监听主进程）

* 执行 `php think xadmin:queue listen` [监听]启动异步任务监听服务
* 执行 `php think xadmin:queue start`  [控制]检查创建任务监听服务（建议定时任务执行）
* 执行 `php think xadmin:queue query`  [控制]查询当前任务相关的进程
* 执行 `php think xadmin:queue status`  [控制]查看异步任务监听状态
* 执行 `php think xadmin:queue stop`   [控制]平滑停止所有任务进程

#### 2. 本地调试管理（可自建定时任务去守护监听主进程）

* 执行 `php think xadmin:queue webstop` [调试]停止本地调试服务
* 执行 `php think xadmin:queue webstart` [调试]开启本地调试服务（建议定时任务执行）
* 执行 `php think xadmin:queue webstatus` [调试]查看本地调试状态

## 问题修复

* 增加 **CORS** 跨域规则配置，配置参数置放于 `config/app.php`，需要更新 `ThinkLibrary`。
* 修复 `layui.table` 导致基于 `ThinkPHP` 模板输出自动转义 `XSS` 过滤机制失效，需要更新 `ThinkLibrary`。
* 修复在模板中使用 `{:input(NAME)}` 取值而产生的 `XSS` 问题，模板取值更换为 `{$get.NAME|default=''}`。
* 修复 `CKEDITOR` 配置文件，禁用所有标签的 `on` 事件，阻止 `xss` 脚本注入，需要更新 `ckeditor/config.js`。
* 修复文件上传入口的后缀验证，读取真实文件后缀与配置对比，阻止不合法的文件上传并存储到本地服务器。
* 修改 `JsonRpc` 接口异常处理机制，当服务端绑定 `Exception` 时，客户端将能收到 `error` 消息及异常数据。
* 修改 `location.hash` 访问机制，禁止直接访问外部 `URL` 资源链接，防止外部 `XSS` 攻击读取本地缓存数据。
* 增加后台主题样式配置，支持全局默认+用户个性配置，需要更新 `admin`, `static`, `ThinkLibrary` 组件及模块。
* 后台行政区域数据更新，由原来的腾讯地图数据切换为百度地图最新数据，需要更新 `static`，数据库版需另行更新。

## 版权信息

[**ThinkAdmin**](https://thinkadmin.top) 遵循 [**MIT**](license) 开源协议发布，并免费提供使用。

本项目包含的第三方源码和二进制文件的版权信息另行标注。

版权所有 Copyright © 2014-2023 by ThinkAdmin (https://thinkadmin.top) All rights reserved。

更多细节参阅 [`LISENSE`](license) 文件

## 历史版本

以下系统的体验账号及密码都是 admin

### ThinkAdmin v6 基于 ThinkPHP 6.0 开发（后台权限基于注解实现）

* 在线体验地址：https://v6.thinkadmin.top (运行中)
* Gitee 代码地址：https://gitee.com/zoujingli/ThinkAdmin/tree/v6
* Github 代码地址：https://github.com/zoujingli/ThinkAdmin/tree/v6

### ThinkAdmin v5 基于 ThinkPHP 5.1 开发（后台权限基于注解实现）

* 在线体验地址：https://v5.thinkadmin.top (已停用)
* Gitee 代码地址：https://gitee.com/zoujingli/ThinkAdmin/tree/v5
* Github 代码地址：https://github.com/zoujingli/ThinkAdmin/tree/v5

### ThinkAdmin v4 基于 ThinkPHP 5.1 开发（不建议继续使用）

* 在线体验地址：https://v4.thinkadmin.top (已停用)
* Gitee 代码地址：https://gitee.com/zoujingli/ThinkAdmin/tree/v4
* Github 代码地址：https://github.com/zoujingli/ThinkAdmin/tree/v4

### ThinkAdmin v3 基于 ThinkPHP 5.1 开发（不建议继续使用）

* 在线体验地址：https://v3.thinkadmin.top (已停用)
* Gitee 代码地址：https://gitee.com/zoujingli/ThinkAdmin/tree/v3
* Github 代码地址：https://github.com/zoujingli/ThinkAdmin/tree/v3

### ThinkAdmin v2 基于 ThinkPHP 5.0 开发（不建议继续使用）

* 在线体验地址：https://v2.thinkadmin.top (已停用)
* Gitee 代码地址：https://gitee.com/zoujingli/ThinkAdmin/tree/v2
* Github 代码地址：https://github.com/zoujingli/ThinkAdmin/tree/v2

### ThinkAdmin v1 基于 ThinkPHP 5.0 开发（不建议继续使用）

* 在线体验地址：https://v1.thinkadmin.top (已停用)
* Gitee 代码地址：https://gitee.com/zoujingli/ThinkAdmin/tree/v1
* Github 代码地址：https://github.com/zoujingli/ThinkAdmin/tree/v1
