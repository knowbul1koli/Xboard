<?php
namespace Plugin\InfuraUSDT;

use App\Services\Plugin\AbstractPlugin;
use App\Contracts\PaymentInterface;
use Illuminate\Support\Facades\Redis;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['InfuraUSDT'] = [
                    'name'        => $this->getConfig('display_name', 'USDT (ERC20)'),
                    'icon'        => $this->getConfig('icon', '💰'),
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
            'wallet_address' => [
                'label'       => 'ETH 收款地址',
                'type'        => 'string',
                'required'    => true,
                'description' => '你的 ETH 钱包地址（0x 开头）'
            ],
            'infura_api_key' => [
                'label'       => 'Infura API Key',
                'type'        => 'string',
                'required'    => true,
                'description' => '从 developer.metamask.io 获取'
            ],
            'rate_markup' => [
                'label'       => '汇率加价（%）',
                'type'        => 'string',
                'required'    => false,
                'description' => '在实时汇率基础上加价，例如填 3 表示加价 3%，留空则不加价'
            ],
        ];
    }

    private function getUsdtRate(): float
    {
        $cacheKey = 'usdt_cny_rate';
        $cached = Redis::get($cacheKey);
        if ($cached && (float)$cached > 1) return (float) $cached;

        // 主接口：Coinbase
        try {
            $response = file_get_contents(
                'https://api.coinbase.com/v2/exchange-rates?currency=USDT',
                false,
                stream_context_create(['http' => ['timeout' => 5]])
            );
            $data = json_decode($response, true);
            $rate = $data['data']['rates']['CNY'] ?? null;
            if ($rate && (float)$rate > 1) {
                Redis::setex($cacheKey, 300, (float)$rate);
                return (float) $rate;
            }
        } catch (\Exception $e) {}

        // 备用接口：CoinGecko
        try {
            $response = file_get_contents(
                'https://api.coingecko.com/api/v3/simple/price?ids=tether&vs_currencies=cny',
                false,
                stream_context_create(['http' => ['timeout' => 5]])
            );
            $data = json_decode($response, true);
            $rate = $data['tether']['cny'] ?? null;
            if ($rate && (float)$rate > 1) {
                Redis::setex($cacheKey, 300, (float)$rate);
                return (float) $rate;
            }
        } catch (\Exception $e) {}

        return 7.3;
    }

    public function pay($order): array
    {
        $rate = $this->getUsdtRate();
        $markup = floatval($this->getConfig('rate_markup', 0));
        if ($markup > 0) {
            $rate = $rate * (1 - $markup / 100);
        }

        $cnyAmount    = $order['total_amount'] / 100;
        $usdtAmount   = round($cnyAmount / $rate, 2);
        $uniqueAmount = $usdtAmount + (rand(1, 99) / 10000);
        $amountStr    = number_format($uniqueAmount, 4, '.', '');

        Redis::setex('usdt_order_' . $amountStr, 1800, $order['trade_no']);

        $query = http_build_query([
            'address'    => $this->getConfig('wallet_address'),
            'amount'     => $amountStr,
            'trade_no'   => $order['trade_no'],
            'expire_at'  => now()->addMinutes(30)->timestamp,
            'return_url' => $order['return_url'],
        ]);

        return [
            'type' => 1,
            'data' => '/pay-usdt-erc20/index.html?' . $query,
        ];
    }

    public function notify($params): array|bool
    {
        $tradeNo = $params['trade_no'] ?? null;
        if (!$tradeNo) return false;

        return [
            'trade_no'    => $tradeNo,
            'callback_no' => $params['tx_hash'] ?? $tradeNo,
        ];
    }
}
