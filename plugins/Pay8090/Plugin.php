<?php
namespace Plugin\Pay8090;
use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['Pay8090'] = [
                    'name'        => $this->getConfig('display_name', '8090支付'),
                    'icon'        => $this->getConfig('icon', '💳'),
                    'plugin_code' => $this->getPluginCode(),
                    'type'        => 'plugin'
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'display_name' => [
                'label'       => '前台显示名称',
                'type'        => 'string',
                'description' => '例如：支付宝 / 微信支付'
            ],
            'url' => [
                'label'       => '支付网关地址',
                'type'        => 'string',
                'required'    => true,
                'description' => '例如：http://epy.odh36826.com（末尾不加斜杠）'
            ],
            'pid' => [
                'label'       => '商户ID',
                'type'        => 'string',
                'required'    => true,
                'description' => '8090支付商户ID'
            ],
            'key' => [
                'label'       => '商户密钥',
                'type'        => 'string',
                'required'    => true,
                'description' => '8090支付商户密钥'
            ],
            'type' => [
                'label'       => '支付类型',
                'type'        => 'string',
                'required'    => true,
                'description' => 'alipay(支付宝) / wxpay(微信支付) / usdt(USDT)'
            ],
        ];
    }

    /**
     * MD5 签名：非空参数排除 sign/sign_type，ASCII 升序，& 拼接后加 key
     */
    private function buildSign(array $params): string
    {
        $filtered = array_filter($params, function ($v, $k) {
            return $k !== 'sign'
                && $k !== 'sign_type'
                && $v !== ''
                && $v !== null;
        }, ARRAY_FILTER_USE_BOTH);

        ksort($filtered);

        $parts = [];
        foreach ($filtered as $k => $v) {
            $parts[] = "{$k}={$v}";
        }

        return md5(implode('&', $parts) . $this->getConfig('key'));
    }

    /**
     * 服务器调用 mapi.php，拿到真实支付跳转链接
     * 用户浏览器直接跳到支付宝/微信，不经过 epy 域名
     */
    private function getPayUrl(array $params): ?string
    {
        $url = $this->getConfig('url') . '/mapi.php?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);

        if (!$result) return null;

        $json = json_decode($result, true);
        if (!$json || ($json['code'] ?? 0) != 1) return null;

        // 优先返回跳转 URL，其次返回二维码链接
        return $json['payurl'] ?? $json['qrcode'] ?? $json['urlscheme'] ?? null;
    }

    public function pay($order): array
    {
        $params = [
            'pid'          => $this->getConfig('pid'),
            'type'         => $this->getConfig('type'),
            'out_trade_no' => $order['trade_no'],
            'notify_url'   => $order['notify_url'],
            'return_url'   => $order['return_url'],
            'name'         => $order['trade_no'],
            'money'        => number_format($order['total_amount'] / 100, 2, '.', ''),
            'clientip'     => request()->ip(),
            'device'       => 'pc',
            'sign_type'    => 'MD5',
        ];

        $params['sign'] = $this->buildSign($params);

        // 服务器端拿真实支付链接
        $payUrl = $this->getPayUrl($params);

        if ($payUrl) {
            // 拿到真实链接，用户直接跳支付宝/微信，不经过 epy 域名
            return [
                'type' => 1,
                'data' => $payUrl,
            ];
        }

        // 降级：让浏览器直接跳网关（服务器调用失败时）
        return [
            'type' => 1,
            'data' => $this->getConfig('url') . '/submit.php?' . http_build_query($params),
        ];
    }

    public function notify($params): array|bool
    {
        $sign = $params['sign'] ?? null;
        if (!$sign) return false;

        if ($sign !== $this->buildSign($params)) {
            return false;
        }

        if (($params['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return false;
        }

        return [
            'trade_no'      => $params['out_trade_no'],
            'callback_no'   => $params['trade_no'],
            'custom_result' => 'success',
        ];
    }
}
