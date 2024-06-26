<?php

namespace App\Services\Tron\Scanner;

use App\Core\Logger;
use App\Models\Payment;
use App\Models\User;
use App\Services\Tron\Network;
use Exception;
use IEXBase\TronAPI\TronAddress;

class Address
{
    private Logger $logger;
    private User $user;
    private Payment $payment;
    private Network $tron;

    protected string $time;

    // all times are in minutes representing the creation time of payments
    public const SCAN_NOW = 0;
    public const SCAN_MANUAL = 3 * 30 * 24 * 60; // 90 days
    public const SCAN_EVERY_TWENTY_SECONDS = 20; // 20 minutes
    public const SCAN_EVERY_MINUTE = 3 * 60; // 3 hour
    public const SCAN_EVERY_FIVE_MINUTES = 6 * 60; // 6 hours
    public const SCAN_EVERY_QUARTER = 12 * 60; // 12 hours
    public const SCAN_EVERY_HOUR = 24 * 60; // 1 day
    public const SCAN_EVERY_THREE_HOURS = 3 * 24 * 60; // 3 days
    public const SCAN_EVERY_SIX_HOURS = 7 * 24 * 60; // 7 days (1 week)
    public const SCAN_EVERY_DAY = 30 * 24 * 60; // 30 days

    public function __construct(string $time = null)
    {
        $this->logger = new Logger('address.log');
        $this->payment = Payment::getInstance();
        $this->user = User::getInstance();
        $this->tron = Network::getInstance();
        $this->setTime($time ?? self::SCAN_MANUAL);
    }

    /**
     * start address scanner
     *
     * @return void
     */
    public function start(): void
    {
        switch ($this->getTime()) {
            case '20s':
                $this->scanAddresses(self::SCAN_EVERY_TWENTY_SECONDS);
                break;
            case '1m':
                $this->scanAddresses(self::SCAN_EVERY_MINUTE);
                break;
            case '5m':
                $this->scanAddresses(self::SCAN_EVERY_FIVE_MINUTES);
                break;
            case '15m':
                $this->scanAddresses(self::SCAN_EVERY_QUARTER);
                break;
            case '1h':
                $this->scanAddresses(self::SCAN_EVERY_HOUR);
                break;
            case '3h':
                $this->scanAddresses(self::SCAN_EVERY_THREE_HOURS);
                break;
            case '6h':
                $this->scanAddresses(self::SCAN_EVERY_SIX_HOURS);
                break;
            case '1d':
                $this->scanAddresses(self::SCAN_EVERY_DAY);
                break;
            default:
                $this->scanAddresses(self::SCAN_MANUAL);
        }
    }

    /**
     * set time of execution
     *
     * @param string $time
     * @return void
     */
    private function setTime(string $time): void
    {
        // check if time is valid
        if ($time == '20s' || $time == '1m' || $time == '5m' || $time == '15m' || $time == '1h' || $time == '3h' || $time == '6h' || $time == '1d')
            $this->time = $time;
        else
            $this->time = self::SCAN_MANUAL;
    }

    /**
     * @return string
     */
    public function getTime(): string
    {
        return $this->time;
    }

    /**
     * search unverified payments on tron network
     *
     * @param int $highTime
     * @param int $lowTime
     * @return void
     */
    public function scanAddresses(int $highTime, int $lowTime = self::SCAN_NOW): void
    {
        $addresses = $this->tron->loadAddressesByTime($lowTime, $highTime);
        $count = count($addresses);
        if ($count)
            $this->logger->write("Scanning $count addresses created in between last $lowTime and $highTime minutes:"
                . PHP_EOL . json_encode(array_map(fn($address) => $address->getAddress(true), $addresses)));
        foreach ($addresses as $address) {
            try {
                if ($this->tron->checkBalance($address))
                    $this->verifyPaymentByAddressTransactions($address);
                sleep(5); // wait 5 seconds before checking next address
            } catch (Exception $e) {
                $this->logger->write($e->getMessage());
            }
        }
    }

    /**
     * verify payment details by address transactions
     *
     * @param TronAddress $address
     * @return void
     */
    private function verifyPaymentByAddressTransactions(TronAddress $address): void
    {
        try {
            $this->logger->write('Payment received on: ' . json_encode($address->getAddress(true)));
            // find payment by address
            $payment = $this->payment->findByAddress($address->getAddress(true));
            // check if payment matches any of the transactions at the address
            $transaction = $this->tron->findDepositTransaction($this->tron->getTransactions($address));
            // if any transaction then double check found transaction by address hex
            if ($transaction and $this->tron->checkTransactionAddress($transaction, $address)) {
                $this->logger->write('Transaction: ' . PHP_EOL . json_encode($transaction));
                // get transaction data
                $amount = $this->tron->getTransactionAmount($transaction);
                $hash = $this->tron->getTransactionHash($transaction);
                $time = $this->tron->getTransactionTimestamp($transaction);
                // update payment
                $this->payment->update($payment, $amount, $hash, $time);
                // update user
                $this->user->paid($payment);
                // transfer payment to default deposit address
                $this->logger->write('Transfer to default deposit address');
                $this->tron->transfer($address);
            }
        } catch (Exception $e) {
            $this->logger->write($e->getMessage());
        }
    }
}
