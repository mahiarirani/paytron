<?php

namespace App\Services\KuCoin;

use App\Core\Configuration;
use App\Core\Logger;
use App\Models\Payment;
use KuCoin\SDK\Auth;
use KuCoin\SDK\Exceptions\BusinessException;
use KuCoin\SDK\Exceptions\HttpException;
use KuCoin\SDK\Exceptions\InvalidApiUriException;
use KuCoin\SDK\PrivateApi\Account;
use KuCoin\SDK\PrivateApi\Deposit;
use KuCoin\SDK\PrivateApi\Order;
use KuCoin\SDK\PrivateApi\WebSocketFeed;
use KuCoin\SDK\PrivateApi\Withdrawal;
use KuCoin\SDK\PublicApi\Symbol;
use Exception;
use Throwable;

class KuCoin
{
    private static ?KuCoin $instance = null;
    private Configuration $configuration;
    private Logger $logger;
    private Auth $auth;
    protected Symbol $symbol;
    protected Account $account;
    protected Deposit $deposit;
    protected Withdrawal $withdrawal;
    protected Order $order;
    private WebSocketFeed $publicFeed;
    private WebSocketFeed $privateFeed;

    public const DEFAULT_CURRENCY = Payment::DEFAULT_CURRENCY;
    public const PRIVATE_CHANNEL = true;
    public const PUBLIC_CHANNEL = false;

    private function __construct()
    {
        $this->logger = new Logger('kucoin.log');

        $this->configuration = Configuration::getInstance();
        $this->auth = new Auth(
            $this->configuration->get('KC_API_KEY'),
            $this->configuration->get('KC_API_SECRET'),
            $this->configuration->get('KC_API_PASSPHRASE'),
            Auth::API_KEY_VERSION_V2
        );
        try {
            $this->setSymbol(new Symbol());
            $this->setAccount(new Account($this->auth));
            $this->setDeposit(new Deposit($this->auth));
            $this->setWithdrawal(new Withdrawal($this->auth));
            $this->setOrder(new Order($this->auth));
            $this->publicFeed = new WebSocketFeed();
            $this->privateFeed = new WebSocketFeed($this->auth);
        } catch (Exception $e) {
            $this->logger->write($e->getMessage());
        }
    }

    /**
     * @return Symbol
     */
    public function getSymbol(): Symbol
    {
        return $this->symbol;
    }

    /**
     * @param Symbol $symbol
     */
    private function setSymbol(Symbol $symbol): void
    {
        $this->symbol = $symbol;
    }

    /**
     * @return Account
     */
    public function getAccount(): Account
    {
        return $this->account;
    }

    /**
     * @param Account $account
     */
    private function setAccount(Account $account): void
    {
        $this->account = $account;
    }

    /**
     * @return Deposit
     */
    public function getDeposit(): Deposit
    {
        return $this->deposit;
    }

    /**
     * @param Deposit $deposit
     */
    private function setDeposit(Deposit $deposit): void
    {
        $this->deposit = $deposit;
    }

    /**
     * @return Withdrawal
     */
    public function getWithdrawal(): Withdrawal
    {
        return $this->withdrawal;
    }

    /**
     * @param Withdrawal $withdrawal
     */
    private function setWithdrawal(Withdrawal $withdrawal): void
    {
        $this->withdrawal = $withdrawal;
    }

    /**
     * @return Order
     */
    public function getOrder(): Order
    {
        return $this->order;
    }

    /**
     * @param Order $order
     */
    private function setOrder(Order $order): void
    {
        $this->order = $order;
    }

    /**
     * get deposit list of default currency
     *
     * @param int $page
     * @param int $pageSize
     * @return array
     */
    public function getDepositList(int $page = 1, int $pageSize = 100): array
    {
        try {
            return $this->getDeposit()->getDeposits(['currency' => self::DEFAULT_CURRENCY], ['currentPage' => $page, 'pageSize' => $pageSize]);
        } catch (InvalidApiUriException|HttpException|BusinessException $e) {
            $this->logger->write($e->getMessage());
            return [];
        }
    }

    /**
     * subscribe to a channel topic
     *
     * @param bool $isPrivate
     * @param string $topic
     * @param callable $onMessage
     */
    public function subscribeChannel(bool $isPrivate, string $topic, callable $onMessage): void
    {
        try {
            $query = ['connectId' => uniqid('', true)];
            $this->logger->write('Subscribing to ' . $topic);
            if ($isPrivate)
                $this->privateFeed->subscribePrivateChannel($query, ['topic' => $topic], $onMessage, $this->logClose());
            else
                $this->publicFeed->subscribePublicChannel($query, ['topic' => $topic], $onMessage, $this->logClose());
        } catch (Throwable $th) {
            $this->logger->write('Error subscribing to ' . $topic);
            $this->logger->write($th->getMessage());
        }
    }

    /**
     * @return callable
     */
    private function logClose(): callable
    {
        return function () {
            $this->logger->write('Subscription closed');
        };
    }

    /**
     * @param string $account
     * @param string $currency
     * @return string
     * @throws BusinessException
     * @throws HttpException
     * @throws InvalidApiUriException
     */
    public function getBalance(string $account = 'main', string $currency = self::DEFAULT_CURRENCY): string
    {
        return $this->getAccount()->getList(['currency' => $currency, 'type' => $account])[0]['available'];
    }

    /**
     * @param string $from
     * @param string $to
     * @param float|null $amount // account remaining balance
     * @param string $currency
     * @return void
     * @throws BusinessException
     * @throws HttpException
     * @throws InvalidApiUriException
     */
    public function innerTransfer(string $from, string $to, ?float $amount = null, string $currency = self::DEFAULT_CURRENCY): void
    {
        // get remaining balance if amount is not specified
        if (!$amount)
            $amount = $this->getBalance($from, $currency);
        // return if account balance is empty
        if ($amount <= 0)
            return;
        // round amount to inner transfer base
        $innerTransferBase = 0.00000001;
        $amountDigits = strpos($amount, ".");
        $amountPrecision = strlen(strrchr($innerTransferBase, ".")) - 1;
        $amount = substr($amount, 0, $amountDigits + $amountPrecision + 1);
        $this->getAccount()->innerTransferV2(uniqid(), $currency, $from, $to, $amount);
    }

    /**
     * trade using KuCoin API
     *
     * @param float|null $amount // account remaining balance
     * @param string $fromCurrency
     * @param string $toCurrency
     * @return array
     * @throws BusinessException
     * @throws HttpException
     * @throws InvalidApiUriException
     */
    public function convert(?float $amount = null, string $fromCurrency = self::DEFAULT_CURRENCY, string $toCurrency = 'USDT'): array
    {
        try {
            // get trade data
            $trade = $this->getTradeData($fromCurrency, $toCurrency);
            $symbol = $trade['symbol'];
            $baseIncrement = $trade['baseIncrement'];
            $baseMinSize = $trade['baseMinSize'];
            $baseMaxSize = $trade['baseMaxSize'];
            $fundMin = $trade['minFunds'];
            $rate = $this->getSymbol()->getTicker($symbol)['price'];
            // check if amount is set
            if (!$amount)
                // get main funding account available balance
                $amount = $this->getBalance('main', $fromCurrency);
            // transfer amount from main account to trade account
            $this->innerTransfer('main', 'trade', $amount, $fromCurrency);
            // get trade account remaining balance
            $amount = $this->getBalance('trade', $fromCurrency);
            // round amount to base increment
            $amountDigits = strpos($amount, ".");
            $amountPrecision = strlen(strrchr($baseIncrement, ".")) - 1;
            $amount = substr($amount, 0, $amountDigits + $amountPrecision + 1); // amount with precision of base increment
            // check if amount is within range
            if ($amount < $baseMinSize || $amount > $baseMaxSize)
                throw new Exception('Amount is not within trading range');
            // check if fund is more than minimum
            if ($amount * $rate < $fundMin)
                throw new Exception('Amount is not enough to trade');
            // create order
            $orderId = $this->getOrder()->create([
                'clientOid' => uniqid(),
                'side' => 'sell',
                'symbol' => $symbol,
                'type' => 'market',
                'size' => $amount,
                'remark' => 'conversion rate : ' . $rate,
            ]);
            return [
                'orderId' => $orderId['orderId'],
                'amount' => $amount,
                'symbol' => $symbol,
                'rate' => $rate
            ];
        } catch (Exception|InvalidApiUriException|HttpException|BusinessException $e) {
            $this->logger->write($e->getMessage());
            throw $e;
        }
    }

    /**
     * @param string $from
     * @param string $to
     * @return array
     * @throws BusinessException
     * @throws HttpException
     * @throws InvalidApiUriException
     */
    private function getTradeData(string $from = self::DEFAULT_CURRENCY, string $to = 'USDT'): array
    {
        $symbols = $this->getSymbol()->getList();
        foreach ($symbols as $symbol)
            if ($symbol['symbol'] == $from . '-' . $to)
                break;
        return $symbol ?? [];
    }

    /**
     * find transaction by hash in deposits of default currency
     *
     * @param string $hash
     * @return array|null
     */
    public function findTransaction(string $hash): ?array
    {
        // search 5 pages of 10 items deposit transactions to find the hash
        for ($page = 1; $page <= 5; $page++) {
            $this->logger->write('Fetching deposit transactions page ' . $page . ' of 10');
            $deposits = $this->getDepositList(page: $page, pageSize: 10);
            if (empty($deposits['items']))
                break;
            $items = $deposits['items'];
            foreach ($items as $item)
                if ($hash == substr($item['walletTxId'], 0, strpos($item['walletTxId'], '@')))
                    return $item;
        }
        return null;
    }

    /**
     * @return KuCoin|null
     */
    public static function getInstance(): ?KuCoin
    {
        if (!self::$instance) {
            self::$instance = new KuCoin();
        }

        return self::$instance;
    }
}
