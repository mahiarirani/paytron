<?php

namespace App\Services\KuCoin\Scanner;

use App\Core\Logger;
use App\Services\KuCoin\KuCoin;
use Exception;

class Conversion
{
    private KuCoin $kuCoin;
    private Logger $logger;

    public function __construct()
    {
        $this->kuCoin = KuCoin::getInstance();
        $this->logger = new Logger('convert.log');
    }

    /**
     * start conversion watcher
     *
     * @return void
     */
    public function start(): void
    {
        $this->logger->write('Conversion watcher started');
        while (true) {
            KuCoin::getInstance()->subscribeChannel(KuCoin::PRIVATE_CHANNEL, '/account/balance', function (array $message) {
                try {
                    // check if deposit is in main account and in default currency
                    $deposit = $message['data'];
                    if ($deposit['relationEvent'] == 'main.deposit' and $deposit['currency'] == KuCoin::DEFAULT_CURRENCY) {
                        $this->logger->write('Received Deposit' . PHP_EOL . json_encode($message));
                        $result = $this->kuCoin->convert();
                        $this->logger->write("Creating order $result[orderId] Sell $result[amount]  $result[symbol] at $result[rate]");
                    } else if ($deposit['relationEvent'] == 'trade.setted' and $deposit['currency'] == 'USDT') {
                        $this->logger->write('Trade Set' . PHP_EOL . json_encode($message));
                        // transfer amount from trading account to main funds account
                        $this->kuCoin->innerTransfer('trade', 'main', currency: 'USDT');
                    }
                } catch (Exception $e) {
                    $this->logger->write($e->getMessage());
                }
            });
        }
    }
}
