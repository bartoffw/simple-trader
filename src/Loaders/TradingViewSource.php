<?php

namespace SimpleTrader\Loaders;

use Carbon\Carbon;
use Exception;
use SimpleTrader\Exceptions\LoaderException;
use SimpleTrader\Helpers\Ohlc;
use WebSocket\Client;

/**
 * // TradingView
 * // https://github.com/Textalk/websocket-php
 * // https://github.com/0xrushi/tradingview-scraper/issues/1
 */
class TradingViewSource implements SourceInterface
{
    protected Client $client;
    protected string $token = 'unauthorized_user_token';
    protected string $session;
    protected string $chartSession;


    public function __construct(protected $url = 'wss://data.tradingview.com/socket.io/websocket', protected $login = '', protected $password = '')
    {
        $this->client = new Client($this->url, [
            'headers' => [
                'Accept-Encoding' => 'gzip, deflate, br',
                # 'Accept-Language': 'en-US,en;q=0.9',
                # 'Cache-Control': 'no-cache',
                # 'Connection': 'Upgrade',
                'Host' => 'data.tradingview.com',
                'Origin' => 'https://www.tradingview.com',
                # 'Pragma': 'no-cache',
                # 'Sec-WebSocket-Extensions': 'permessage-deflate; client_max_window_bits',
                # 'Sec-WebSocket-Key': 'Qf9IDRKqcgNBrNs7X4FK9w==',
                # 'Sec-WebSocket-Version': 13,
                # 'Upgrade': 'websocket',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.111 Safari/537.36'
            ]
        ]);
        $this->session = $this->generateSession('qs_');
        $this->chartSession = $this->generateSession('cs_');
    }

    protected function generateSession($prefix)
    {
        return $prefix . substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyz'), 1, 12);
    }

    protected function formatQuotes(array $rawData, $id = 'sds_1'): array
    {
        $result = [];
        if (empty($rawData[1][$id]['s']) ||
            !empty($rawData[1][$id]['lbs']['bar_close_time']) && time() < $rawData[1][$id]['lbs']['bar_close_time']) {
            return $result;
        }
        foreach ($rawData[1][$id]['s'] as $quote) {
            $ohlc = $quote['v'];
            $result[] = new Ohlc(Carbon::createFromTimestamp($ohlc[0]), $ohlc[1], $ohlc[2], $ohlc[3], $ohlc[4], $ohlc[5] ?? 0);
        }
        return $result;
    }

    protected function sendQuotesRequest(string $symbol, string $interval = '1D', int $barCount = 10): array
    {
        $this->sendMessage('set_auth_token', [$this->token]);
        $this->sendMessage('chart_create_session', [$this->chartSession, '']);
        $this->sendMessage('quote_create_session', [$this->session]);
        $this->sendMessage('quote_set_fields', [
            $this->session,
            'ch',
            'chp',
            'current_session',
            'description',
            'local_description',
            'language',
            'exchange',
            'fractional',
            'is_tradable',
            'lp',
            'lp_time',
            'minmov',
            'minmove2',
            'original_name',
            'pricescale',
            'pro_name',
            'short_name',
            'type',
            'update_mode',
            'volume',
            'currency_code',
            'rchp',
            'rtc',
        ]);
        $this->sendMessage('switch_timezone', [$this->chartSession, "exchange"]);

        $this->sendMessage('quote_add_symbols', [$this->session, $symbol]);
        $this->sendMessage('quote_fast_symbols', [$this->session, $symbol]);

        $this->sendMessage("resolve_symbol", [
            $this->chartSession, 'sds_sym_1', '={"symbol":"' . $symbol . '","adjustment":"splits","session":"regular"}',
        ]);
        $this->sendMessage('create_series', [
            $this->chartSession, 'sds_1', 's1', 'sds_sym_1', $interval, $barCount//, ''
        ]);

        $results = [];
        while (true) {
            try {
                $result = $this->client->receive();
                $results[] = $result;
                if (str_contains($result, 'series_completed')) {
                    break;
                }
            } catch (Exception $e) {
                break;
            }
        }
        $this->client->close();
        $formattedResult = [];
        $size = null;
        foreach ($results as $result) {
            foreach (explode('~m~', $result) as $element) {
                if (empty($element)) {
                    continue;
                }
                if (!$size) {
                    $size = (int)$element;
                } else {
                    $data = json_decode($element, true);
                    if (!empty($data['m']) && !empty($data['p'])) {
                        if (isset($formattedResult[$data['m']])) {
                            throw new LoaderException("Field with name '{$data['m']}' already exists.");
                        }
                        $formattedResult[$data['m']] = $data['p'];
                    }
                    $size = null;
                }
            }
        }

        return empty($formattedResult['timescale_update']) ? [] : $this->formatQuotes($formattedResult['timescale_update']);
    }

    public function sendMessage($function, array $parameters): void
    {
        $message = json_encode([
            'm' => $function,
            'p' => $parameters
        ]);
        $this->client->text('~m~' . strlen($message) . '~m~' . $message);
    }

    /**
     * @throws LoaderException
     */
    public function getQuotes(string $symbol, string $exchange, string $interval = '1D', int $barCount = 10): array
    {
        $this->client->receive();

        $symbol = $exchange . ':' . $symbol;
        return $this->sendQuotesRequest($symbol, $interval, $barCount);
    }
}