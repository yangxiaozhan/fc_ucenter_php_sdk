<?php

declare(strict_types=1);

namespace FC\UCenter;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class UCenterException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $errorCode = -1,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

class UCenterClient
{
    private readonly string $baseUrl;
    private Client $http;
    private ?string $accessToken = null;
    private ?string $refreshToken = null;

    public function __construct(
        string $baseUrl,
        private readonly string $appKey,
        private readonly string $appSecret,
        private readonly bool $autoRefresh = true,
        ?Client $http = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = $http ?? new Client(['timeout' => 15]);
    }

    public function setTokens(?string $accessToken, ?string $refreshToken = null): void
    {
        $this->accessToken = $accessToken;
        if ($refreshToken !== null) {
            $this->refreshToken = $refreshToken;
        }
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function isLoggedIn(): bool
    {
        return $this->accessToken !== null && $this->accessToken !== '';
    }

    /** @throws UCenterException */
    public function sendSms(string $phone, string $scene = 'login'): void
    {
        $this->request('POST', '/api/v1/auth/sms/send', ['phone' => $phone, 'scene' => $scene]);
    }

    /**
     * 发送业务模板短信（须在应用白名单内）
     *
     * @param list<string>|array<string, scalar> $params
     * @return array{message_id?: string, template_id?: string}
     */
    public function sendNotifySms(string $phone, string $templateId, array $params = []): array
    {
        return $this->request('POST', '/api/v1/sms/notify', [
            'phone' => $phone,
            'template_id' => $templateId,
            'params' => $params,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function login(string $method, array $params = []): array
    {
        $data = $this->request('POST', '/api/v1/auth/login', array_merge(['method' => $method], $params));
        $this->saveTokensFromLogin($data);
        return $data;
    }

    /** @return array<string, mixed> */
    public function loginWithPhoneSms(string $phone, string $code): array
    {
        return $this->login('phone_sms', ['phone' => $phone, 'code' => $code]);
    }

    /** @return array<string, mixed> */
    public function loginWithWechat(string $code, string $platform = 'mini_program', array $extra = []): array
    {
        return $this->login('wechat', array_merge(['code' => $code, 'platform' => $platform], $extra));
    }

    /** @return array<string, mixed> */
    public function loginWithWechatPhone(string $phoneCode, array $extra = []): array
    {
        return $this->login('wechat_phone', array_merge(['phone_code' => $phoneCode], $extra));
    }

    /** @return array<string, mixed> */
    public function loginWithAlipay(string $authCode): array
    {
        return $this->login('alipay', ['auth_code' => $authCode]);
    }

    /** @return array<string, mixed> */
    public function loginWithAlipayPhone(string $authCode, array $extra = []): array
    {
        return $this->login('alipay_phone', array_merge(['auth_code' => $authCode], $extra));
    }

    /** @return array<string, mixed> */
    public function loginWithWeibo(string $code, array $extra = []): array
    {
        return $this->login('weibo', array_merge(['code' => $code], $extra));
    }

    /** @return array<string, mixed> */
    public function loginWithQq(string $code, array $extra = []): array
    {
        return $this->login('qq', array_merge(['code' => $code], $extra));
    }

    /** @return array<string, mixed> */
    public function loginWithDouyin(string $code, array $extra = []): array
    {
        return $this->login('douyin', array_merge(['code' => $code], $extra));
    }

    /** @return array<string, mixed> */
    public function loginWithDouyinPhone(string $phoneCode, array $extra = []): array
    {
        return $this->login('douyin_phone', array_merge(['phone_code' => $phoneCode], $extra));
    }

    /**
     * @param array{redirect_uri?: string, scope?: string, mode?: string, user_agent?: string} $options
     * @return array<string, mixed>
     * @throws UCenterException
     */
    public function getOAuthUrl(string $provider, ?string $redirectUri = null, array $options = []): array
    {
        $body = ['provider' => $provider];
        if ($redirectUri !== null) {
            $body['redirect_uri'] = $redirectUri;
        }
        if (isset($options['scope'])) {
            $body['scope'] = $options['scope'];
        }
        if ($provider === 'wechat') {
            $body['mode'] = $options['mode'] ?? 'auto';
            $body['user_agent'] = $options['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        }
        return $this->request('POST', '/api/v1/auth/oauth/url', $body);
    }

    /**
     * 微信登录跳转：PC 扫码 / 手机微信内直接授权
     *
     * @return array<string, mixed>
     * @throws UCenterException
     */
    public function getWechatLoginUrl(?string $redirectUri = null, ?string $mode = 'auto', ?string $userAgent = null): array
    {
        return $this->getOAuthUrl('wechat', $redirectUri, [
            'mode' => $mode ?? 'auto',
            'user_agent' => $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
    }

    public static function detectWechatOAuthMode(?string $userAgent = null): string
    {
        $ua = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $isWeChat = stripos($ua, 'MicroMessenger') !== false;
        $isMobile = (bool) preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $ua);

        return ($isWeChat && $isMobile) ? 'h5' : 'qr';
    }

    /** @return array<string, mixed> */
    public function refreshToken(?string $refreshToken = null): array
    {
        $token = $refreshToken ?? $this->refreshToken;
        if (!$token) {
            throw new UCenterException('无 refresh_token');
        }
        $data = $this->request('POST', '/api/v1/auth/token/refresh', ['refresh_token' => $token]);
        $tokens = $data['tokens'] ?? [];
        $this->accessToken = $tokens['access_token'] ?? null;
        $this->refreshToken = $tokens['refresh_token'] ?? null;
        return $tokens;
    }

    public function logout(): void
    {
        try {
            $this->request('POST', '/api/v1/auth/logout', [
                'refresh_token' => $this->refreshToken ?? '',
            ], auth: 'user');
        } finally {
            $this->accessToken = null;
            $this->refreshToken = null;
        }
    }

    /** @return array<string, mixed> */
    public function getProfile(): array
    {
        return $this->request('GET', '/api/v1/user/profile', auth: 'user');
    }

    /**
     * @param array{nickname?: string, avatar?: string} $data
     * @return array<string, mixed>
     */
    public function updateProfile(array $data): array
    {
        return $this->request('PUT', '/api/v1/user/profile', $data, auth: 'user');
    }

    /**
     * 上传头像（multipart，字段名 file）
     *
     * @param string $filePath 本地文件路径
     * @return array<string, mixed>
     */
    public function uploadAvatar(string $filePath): array
    {
        if (!is_readable($filePath)) {
            throw new UCenterException('头像文件不可读');
        }

        return $this->request(
            'POST',
            '/api/v1/user/avatar',
            [],
            auth: 'user',
            multipart: [
                [
                    'name' => 'file',
                    'contents' => fopen($filePath, 'r'),
                    'filename' => basename($filePath),
                ],
            ]
        );
    }

    public function bindPhone(string $phone, string $code, bool $merge = false): void
    {
        $this->request('POST', '/api/v1/user/bind-phone', ['phone' => $phone, 'code' => $code, 'merge' => $merge], auth: 'user');
    }

    /** @return array<string, mixed> */
    public function bindIdentity(string $method, array $params = [], bool $merge = false): array
    {
        return $this->request('POST', '/api/v1/user/bind-identity', array_merge(['method' => $method, 'merge' => $merge], $params), auth: 'user');
    }

    /** @return array<string, mixed> */
    public function getCancelChecklist(): array
    {
        return $this->request('GET', '/api/v1/user/cancel-account', auth: 'user');
    }

    /**
     * @param array<string, bool> $confirmations
     */
    public function cancelAccount(array $confirmations, string $confirmText, string $smsCode = ''): void
    {
        try {
            $this->request('POST', '/api/v1/user/cancel-account', [
                'confirmations' => $confirmations,
                'confirm_text' => $confirmText,
                'sms_code' => $smsCode,
                'refresh_token' => $this->refreshToken ?? '',
            ], auth: 'user');
        } finally {
            $this->accessToken = null;
            $this->refreshToken = null;
        }
    }

    /** @return array<string, mixed> */
    public function mergeAccount(string $sourceUserId, string $phone, string $code): array
    {
        return $this->request('POST', '/api/v1/user/merge', [
            'source_user_id' => $sourceUserId,
            'phone' => $phone,
            'code' => $code,
        ], auth: 'user');
    }

    /**
     * @param 'suggestion'|'bug'|'other'|'general' $category
     */
    public function submitFeedback(string $content, string $category = 'general', string $contact = ''): void
    {
        $this->request('POST', '/api/v1/user/feedback', [
            'content' => $content,
            'category' => $category,
            'contact' => $contact,
        ], auth: 'user');
    }

    /** @return array<string, mixed> */
    public function listFeedbacks(): array
    {
        return $this->request('GET', '/api/v1/user/feedbacks', auth: 'user');
    }

    /** @return array<string, mixed> */
    public function exchangeAuthCode(string $code): array
    {
        $data = $this->request('POST', '/api/v1/auth/code/exchange', ['code' => $code]);
        $this->saveTokensFromLogin($data);
        return $data;
    }

    public function buildPortalLoginUrl(string $returnUrl, ?string $state = null, ?string $portalUrl = null): string
    {
        $base = rtrim($portalUrl ?? getenv('UCENTER_PORTAL_URL') ?: 'https://login.cdfcz.com', '/');
        $query = http_build_query(array_filter([
            'app_key' => $this->appKey,
            'return_url' => $returnUrl,
            'state' => $state,
        ]));
        return $base . '/?' . $query;
    }

    /** @return array<string, mixed> */
    public function getLoginMethods(?string $appKey = null): array
    {
        $key = $appKey ?? $this->appKey;
        return $this->request('GET', '/api/v1/apps/' . rawurlencode($key) . '/login-methods', auth: 'none');
    }

    /**
     * @param array<string, mixed> $body
     * @param list<array<string, mixed>>|null $multipart
     * @return array<string, mixed>
     * @throws UCenterException
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        string $auth = 'app',
        bool $retried = false,
        ?array $multipart = null,
    ): array {
        $headers = ['Accept' => 'application/json'];
        if ($multipart === null) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($auth === 'app' || $auth === 'user') {
            $headers['X-App-Key'] = $this->appKey;
            $headers['X-App-Secret'] = $this->appSecret;
        }
        if ($auth === 'user') {
            if (!$this->accessToken) {
                throw new UCenterException('未登录', httpStatus: 401);
            }
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }

        $options = ['headers' => $headers];
        if ($multipart !== null) {
            $options['multipart'] = $multipart;
        } elseif ($method !== 'GET' && $body !== []) {
            $options['json'] = $body;
        }

        try {
            $response = $this->http->request($method, $this->baseUrl . $path, $options);
        } catch (GuzzleException $e) {
            throw new UCenterException($e->getMessage(), previous: $e);
        }

        $status = $response->getStatusCode();
        $json = json_decode((string) $response->getBody(), true) ?? [];

        if ($status >= 400 || ($json['code'] ?? -1) !== 0) {
            if ($this->autoRefresh && $auth === 'user' && $status === 401 && $this->refreshToken && !$retried) {
                $this->refreshToken();
                return $this->request($method, $path, $body, $auth, true, $multipart);
            }
            throw new UCenterException(
                $json['message'] ?? '请求失败',
                $json['code'] ?? -1,
                $status
            );
        }

        return $json['data'] ?? [];
    }

    /** @param array<string, mixed> $data */
    private function saveTokensFromLogin(array $data): void
    {
        $tokens = $data['tokens'] ?? [];
        $this->accessToken = $tokens['access_token'] ?? null;
        $this->refreshToken = $tokens['refresh_token'] ?? null;
    }
}
