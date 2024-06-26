<?php

namespace App\Services\Tron\Scanner;

use App\Core\Configuration;
use App\Core\Logger;
use App\Models\Payment;
use App\Models\User;
use App\Services\Tron\Network;
use IEXBase\TronAPI\Exception\TronException;
use Exception;
use function Amp\delay;

class Block
{
    private Logger $logger;
    private User $user;
    private Payment $payment;
    private Configuration $configuration;
    private Network $tron;

    private int $lastCheckedBlockNumber;
    private bool $verbose; // log every block

    private const BLOCK_CHECKED = 0;
    private const BLOCK_NEW = 1;
    private const BLOCK_MISSED = 2;

    public function __construct(bool $verbose = false)
    {
        $this->logger = new Logger('block.log');
        $this->configuration = Configuration::getInstance();
        $this->user = User::getInstance();
        $this->payment = Payment::getInstance();
        $this->tron = Network::getInstance();
        $this->loadLastCheckedBlock();
        $this->verbose = $verbose;
    }

    /**
     * load last checked block from configuration
     *
     * @return void
     */
    private function loadLastCheckedBlock(): void
    {
        $blockNumber = $this->configuration->fetch('tron', 'last_checked_block');
        if ($blockNumber) {
            $this->lastCheckedBlockNumber = $blockNumber;
        } else {
            try {
                $this->lastCheckedBlockNumber = $this->tron->getBlockNumber($this->tron->getLatestBlock());
            } catch (TronException $e) {
                $this->logger->write($e->getMessage());
                $this->lastCheckedBlockNumber = 0;
            }
        }
    }

    /**
     * start block scanner
     *
     * @return void
     */
    public function start(): void
    {
        // loop forever
        while (true) {
            try {
                $this->scanBlock();
            } catch (Exception|TronException $e) {
                $this->logger->write($e->getMessage());
            }
        }
    }

    /**
     * scan last block for transactions and wait for next block
     *
     * @return void
     * @throws TronException
     */
    private function scanBlock(): void
    {
        // get start time
        $timer = microtime(true);

        // find transactions related to unverified payments addresses in block
        $transactions = $this->findTransactionsInLastBlock();

        // verify found transactions
        $this->verifyTransactions($transactions);

        // get execution time by subtracting start time from current time
        $timer = microtime(true) - $timer;
        // sleep 3 seconds minus the time it took to scan the block and 200ms for good measure
        delay(max(3 - $timer - 0.2, 0));
    }

    /**
     * find transactions related to unverified payments addresses in last block
     *
     * @return array
     * @throws TronException
     */
    private function findTransactionsInLastBlock(): array
    {
        // get new block
        $newBlock = $this->tron->getLatestBlock();
        $newBlockNumber = $this->tron->getBlockNumber($newBlock);

        // check by last block number
        return match ($this->checkBlockNumber($newBlockNumber)) {
            self::BLOCK_NEW => $this->checkBlockForTransactions($newBlock),
            self::BLOCK_MISSED => $this->checkMissedBlocks($newBlockNumber),
            default => [], // self::BLOCK_CHECKED
        };
    }

    /**
     * @param int $newBlockNumber
     * @return array
     * @throws TronException
     */
    private function checkMissedBlocks(int $newBlockNumber): array
    {
        $found = [];
        // loop through missed blocks one by one and check for transactions
        for ($i = $this->lastCheckedBlockNumber + 1; $i <= $newBlockNumber; $i++) {
            // start timing how long it takes to fetch a missed block
            $fetchTime = microtime(true);
            // get missed block
            try {
                $missedBlock = $this->tron->getBlockByNumber($i);
            } catch (TronException $e) {
                $this->logger->write($e->getMessage());
                // if block not found, wait 500 milliseconds and try again
                delay(0.5);
                $i--; // decrement $i to check the same block again
                continue;
            }
            // check missed block for transactions
            $found = array_merge($found, $this->checkBlockForTransactions($missedBlock, true));
            // calculate time it took to fetch a missed block
            $fetchTime = microtime(true) - $fetchTime;
            // wait 200 milliseconds between missed blocks to avoid rate limit
            if ($fetchTime < 0.2)
                delay(max(0.2 - $fetchTime, 0));
        }
        return $found;
    }

    /**
     * check block for transactions
     *
     * @param array $block
     * @param bool $missed
     * @return array
     * @throws TronException
     */
    private function checkBlockForTransactions(array $block, bool $missed = false): array
    {
        // find transactions in block by addresses
        $result = $this->tron->findTransactionsInBlockByAddress($this->tron->loadAddresses(), $block);
        // set new block number to last checked block number
        $blockNumber = $this->tron->getBlockNumber($block);
        $this->lastCheckedBlockNumber = $blockNumber;
        $this->configuration->store('tron', 'last_checked_block', $blockNumber);
        // log block
        if ($this->verbose or $result)
            $this->logger->write('checked ' . ($missed ? 'missed' : 'new') . " block $blockNumber [found " . count($result). " addresses]");
        return $result;
    }

    /**
     * check block number for new block
     *
     * @param int $blockNumber
     * @return int
     */
    private function checkBlockNumber(int $blockNumber): int
    {
        $latestBlock = $this->lastCheckedBlockNumber;
        return $latestBlock == $blockNumber ? self::BLOCK_CHECKED :
            ($latestBlock + 1 == $blockNumber ? self::BLOCK_NEW :
                ($latestBlock + 1 < $blockNumber ? self::BLOCK_MISSED : self::BLOCK_CHECKED));
    }

    /**
     * verify transactions and update payment and user
     *
     * @param array $transactions
     * @return void
     */
    private function verifyTransactions(array $transactions): void
    {
        foreach ($transactions as $transaction) {
            try {
                $this->logger->write('Transaction: ' . PHP_EOL . json_encode($transaction));
                // find address by transaction
                $address = $this->tron->findAddressInTransaction($transaction);
                $this->logger->write('Payment received on: ' . json_encode($address->getAddress(true)));
                // get transaction data
                $amount = $this->tron->getTransactionAmount($transaction);
                $hash = $this->tron->getTransactionHash($transaction);
                $time = $this->tron->getTransactionTimestamp($transaction);
                // find payment by address
                $payment = $this->payment->findByAddress($address->getAddress(true));
                // update payment
                $this->payment->update($payment, $amount, $hash, $time);
                // update user
                $this->user->paid($payment);
                // transfer payment to default deposit address
                $this->tron->transferLater($address);
            } catch (Exception $e) {
                $this->logger->write($e->getMessage());
            }
        }
    }
}
