# WPKJ Patterns Library

**Version:** 0.7.1  
**Requires at least:** WordPress 5.8  
**Tested up to:** WordPress 6.7  
**Requires PHP:** 7.4  
**License:** GPLv2 or later  
**Author:** WPKJ Team

## 📖 Description

WPKJ Patterns Library 是一个客户端插件，用于通过 REST API 从 WPKJ Patterns Manager（管理端）发现和导入区块模板（Block Patterns）。插件提供了一个直观的模态界面，集成到 WordPress 古腾堡编辑器工具栏中。

### 核心功能

- 🎨 **模板浏览**：在编辑器中直接浏览远程模板库
- 🔍 **智能搜索**：支持关键词搜索和分类/类型筛选
- ⚡ **快速导入**：一键导入模板到当前文章/页面
- ⭐ **收藏功能**：本地收藏常用模板，支持云端同步
- 🔌 **依赖管理**：自动检测并安装所需插件
- 💾 **智能缓存**：支持 Redis/Memcached 对象缓存，提升性能
- 🌏 **多语言**：支持简体中文和繁体中文

## 🚀 Installation

### 自动安装
1. 联系 WPKJ Team 获取插件安装包
2. 在 WordPress 后台：插件 → 安装插件 → 上传插件
3. 选择 `wpkj-patterns-library.zip` 并安装
4. 激活插件

### 手动安装
1. 将插件文件夹上传到 `/wp-content/plugins/` 目录
2. 在 WordPress 后台激活插件
3. 进入 设置 → WPKJ Patterns Library 配置 API 地址

## ⚙️ Configuration

### 基础配置

激活插件后，访问 **设置 → WPKJ Patterns Library** 进行配置：

- **API Base URL**：远程模板服务器地址（默认：`https://mb.wpkz.cn/wp-json/wpkj/v1`）
- **Cache TTL**：缓存过期时间（秒），默认 900 秒（15 分钟）
- **JWT Token**：可选，用于服务端认证

### 首次使用

1. 插件激活时会自动设置默认配置
2. 打开任意文章/页面编辑器
3. 点击工具栏中的 "Patterns" 按钮
4. 开始浏览和导入模板

## 💡 Usage

### 在编辑器中使用

1. **打开模板库**
   - 点击古腾堡编辑器顶部工具栏的 "Patterns" 按钮
   - 或使用快捷键（如果配置）

2. **浏览模板**
   - 使用左侧筛选器按分类/类型过滤
   - 使用搜索框查找特定模板
   - 支持按日期/标题/热度排序

3. **导入模板**
   - 悬停在模板卡片上查看操作按钮
   - 点击 "Preview" 在新窗口预览
   - 点击 "Import" 导入到当前位置
   - 点击 ⭐ 收藏/取消收藏

4. **加载更多**
   - 滚动到底部或点击 "Load more" 按钮
   - 新内容会平滑滚动到可视区域

### 管理收藏

- **本地收藏**：存储在浏览器 localStorage
- **云端同步**：登录用户的收藏会同步到服务器
- **最近导入**：自动记录最近 10 个导入的模板

## 🏗️ Architecture

### 文件结构

```
wpkj-patterns-library/
├── Admin/                  # 后台设置页面
│   ├── AdminActions.php   # 后台操作处理
│   └── Settings.php        # 设置页面
├── Api/                    # REST API 控制器
│   ├── ApiClient.php       # API 客户端（与远程服务器通信）
│   ├── DepsController.php  # 依赖管理端点
│   ├── FavoritesController.php # 收藏管理端点
│   └── ManagerProxyController.php # 代理端点
├── Includes/               # 核心功能
│   ├── Activator.php       # 插件激活逻辑
│   ├── Assets.php          # 资源加载
│   ├── Cache.php           # 缓存管理
│   ├── Core.php            # 核心启动类
│   ├── Deactivator.php     # 插件停用逻辑
│   ├── Dependencies.php    # 依赖检测
│   ├── I18n.php            # 国际化
│   ├── Scheduler.php       # 计划任务
│   └── Sync.php            # 缓存预热
├── assets/                 # 前端资源
│   ├── css/
│   │   └── editor.css      # 编辑器样式
│   └── js/
│       └── editor.js       # 编辑器脚本
└── languages/              # 翻译文件
```

### 技术栈

- **前端**：React (WordPress Components)
- **后端**：PHP 7.4+
- **缓存**：WordPress Transient API / Object Cache (Redis/Memcached)
- **API**：WordPress REST API
- **构建**：WordPress @wordpress/scripts

### 缓存策略

插件使用三层缓存架构：

1. **对象缓存**（Redis/Memcached）- 优先级最高
2. **浏览器缓存**（localStorage）- 用于收藏和历史记录
3. **定时预热**（每小时）- 预加载常用数据

## 🔧 Performance Optimization

### 缓存配置

**支持对象缓存**（自动检测）：
- Redis Object Cache
- Memcached
- 其他实现 `wp_using_ext_object_cache()` 的插件

**缓存清理**：
- 修改 API Base URL：自动清空所有缓存
- 修改 JWT Token：自动清空认证相关缓存
- 手动清理：设置页面提供清理按钮

### 性能指标

| 指标 | 无对象缓存 | 有对象缓存 (Redis) |
|------|-----------|-------------------|
| API 响应 | 5-20ms | 0.1-1ms |
| 并发处理 | ~100 req/s | ~10000 req/s |
| 缓存命中率 | ~80% | ~95% |

## 🔌 Filters & Actions

### Filters

```php
// 自定义默认 API 地址
add_filter('wpkj_patterns_library_default_api_base', function($url) {
    return 'https://your-server.com/wp-json/wpkj/v1';
});

// 自定义缓存 TTL
add_filter('wpkj_patterns_library_cache_ttl', function($ttl, $path, $params) {
    return 1800; // 30 分钟
}, 10, 3);

// 绕过缓存（用于调试）
add_filter('wpkj_patterns_library_bypass_cache', function($bypass, $path, $params) {
    return true; // 始终请求新数据
}, 10, 3);

// 自定义 API 请求头
add_filter('wpkj_patterns_library_api_headers', function($headers) {
    $headers['X-Custom-Header'] = 'value';
    return $headers;
});
```

### Actions

```php
// 插件激活后执行
add_action('wpkj_pl_sync_event', function() {
    // 自定义同步逻辑
});

// 依赖检查事件
add_action('wpkj_pl_deps_check_event', function() {
    // 自定义依赖检查
});
```

## 🐛 Troubleshooting

### 常见问题

**1. 提示"没有可用 Pattern"**

检查步骤：
- 确认 API Base URL 配置正确
- 测试能否访问远程服务器
- 查看浏览器控制台是否有错误
- 尝试清空缓存

**2. 模板无法导入**

可能原因：
- 缺少必需的插件依赖
- 权限不足（需要 `edit_posts` 权限）
- 内容格式不兼容

解决方案：
- 点击 "Install All" 安装依赖
- 检查用户角色权限
- 联系模板提供者

**3. 缓存不生效**

检查项：
- 对象缓存是否正确配置
- 查看 WP_DEBUG 日志
- 手动清空缓存后重试

**4. 骨架屏显示异常**

- 清除浏览器缓存
- 检查是否有 CSS 冲突
- 更新到最新版本

## 📝 Changelog

查看完整的版本更新历史：[CHANGELOG.md](CHANGELOG.md)

### 最新版本 0.7.1 (2026-02-06)

**🌍 国际化**
- 完善多语言支持系统
- 添加简体中文 (zh_CN) 完整翻译
- 添加繁体中文 (zh_TW) 完整翻译
- 生成 .mo 和 .json 语言包文件

**📝 文档**
- 将更新日志抽离至独立的 CHANGELOG.md 文件
- 优化 README.md 结构

[查看所有版本更新 →](CHANGELOG.md)

## 🤝 Contributing

本插件为私有项目，仅限 WPKJ Team 成员维护。

如有问题或建议：
1. 联系 WPKJ Team
2. 提交内部 Issue
3. 发送邮件至技术支持

## 📄 License

Copyright (c) 2024-2026 WPKJ Team  
Licensed under GPLv2 or later.

## 🔗 Links

- **管理端插件**：wpkj-patterns-manager
- **文档中心**：内部文档系统
- **技术支持**：WPKJ Team

---

**Made with ❤️ by WPKJ Team**
