# UCenter PHP SDK

UCenter 统一用户中心官方 PHP 客户端。

- Packagist：[`yangxiaozhan/fc-ucenter-sdk`](https://packagist.org/packages/yangxiaozhan/fc-ucenter-sdk)
- 源码：https://github.com/yangxiaozhan/fc_ucenter_php_sdk

## 安装

```bash
composer require yangxiaozhan/fc-ucenter-sdk
```

PHP >= 8.1，依赖 `guzzlehttp/guzzle`。

## 使用

```php
<?php

use FC\UCenter\UCenterClient;

$ucenter = new UCenterClient(
    baseUrl: 'https://fcu.cdfcz.com',
    appKey: getenv('UCENTER_APP_KEY'),
    appSecret: getenv('UCENTER_APP_SECRET'),
);

// 从 Session 恢复 Token
$ucenter->setTokens($_SESSION['access_token'] ?? null, $_SESSION['refresh_token'] ?? null);

$ucenter->sendSms('13800138000');
$result = $ucenter->loginWithPhoneSms('13800138000', '123456');

$_SESSION['access_token'] = $ucenter->getAccessToken();
$_SESSION['refresh_token'] = $ucenter->getRefreshToken();

$profile = $ucenter->getProfile();
```

## OAuth H5

```php
// 微信登录：PC 扫码 / 手机微信内直接授权
$oauth = $ucenter->getWechatLoginUrl('https://your-app.com/oauth/callback');
header('Location: ' . $oauth['url']);

// 手动指定模式
$oauth = $ucenter->getOAuthUrl('wechat', 'https://your-app.com/oauth/callback', ['mode' => 'qr']);
```

## 常用方法

| 方法 | 说明 |
|------|------|
| `sendSms($phone, $scene = 'login')` | 发码，`scene`: login / bind / merge / cancel |
| `sendNotifySms($phone, $templateId, $params = [])` | 业务模板短信，`template_id` 须在应用白名单内 |
| `login($method, $params)` | 统一登录 |
| `loginWithPhoneSms` / `loginWithWechat` / `loginWithWechatPhone` | 便捷登录 |
| `loginWithAlipay` / `loginWithAlipayPhone` / `loginWithWeibo` / `loginWithQq` | 便捷登录 |
| `loginWithDouyin` / `loginWithDouyinPhone` | 便捷登录 |
| `getOAuthUrl` / `getWechatLoginUrl` | OAuth 授权 URL |
| `getProfile` / `updateProfile` / `uploadAvatar($filePath)` | 资料与头像 |
| `bindPhone` / `bindIdentity` | 绑定 |
| `getCancelChecklist` / `cancelAccount` | 注销账号 |
| `submitFeedback` / `listFeedbacks` | 用户反馈 |
| `exchangeAuthCode` / `buildPortalLoginUrl` | Portal |

## 配置

从管理后台创建应用后取得 `appKey` / `appSecret`。生产 API 默认地址：

```text
https://fcu.cdfcz.com
```
