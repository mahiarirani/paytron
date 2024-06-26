<?php

namespace App\Services\Tron;

use App\Core\Configuration;
use App\Core\Database;
use App\Core\Logger;
use IEXBase\TronAPI\Exception\TronException;
use IEXBase\TronAPI\Provider\HttpProvider;
use IEXBase\TronAPI\Tron;
use IEXBase\TronAPI\TronAddress;
use function Amp\async;
use function Amp\delay;

class Network
{
    private static ?Network $instance = null;
    private Logger $logger;
    private Configuration $configuration;
    private Tron $tron;
    protected ?TronAddress $address = null;

    private function __construct()
    {
        $this->configuration = Configuration::getInstance();
        $this->logger = new Logger('tron.log');

        try {
            $fullNode = new HttpProvider($this->configuration->get('TRON_API_URL'));
            $solidityNode = new HttpProvider($this->configuration->get('TRON_API_URL'));
            $eventServer = new HttpProvider($this->configuration->get('TRON_API_URL'));

            $this->tron = new Tron($fullNode, $solidityNode, $eventServer);

            if (!$this->tron->getManager()->getProviders()['fullNode']->isConnected())
                throw new TronException('Failed to connect to Tron node');
        } catch (TronException $e) {
            $this->logger->write(($e->getMessage()));
        }
    }

    /**
     * generate new address
     * @return void
     */
    public function generateAddress(): void
    {
        try {
            $address = $this->tron->generateAddress();
            $this->setAddress($address);
            $this->logger->write('New account created: ' . json_encode($this->getAddress()->getRawData()));
            return;
        } catch (TronException $e) {
            $this->logger->write($e->getMessage());
        }
    }

    /**
     * @return TronAddress|null
     */
    public function getAddress(): ?TronAddress
    {
        return $this->address;
    }

    /**
     * @param TronAddress|null $address
     */
    private function setAddress(?TronAddress $address = null): void
    {
        $this->address = $address;
    }

    /**
     * get address balance
     */
    public function getBalance(TronAddress $address): float
    {
        try {
            return $this->tron->getBalance($address->getAddress(true), true);
        } catch (TronException $e) {
            $this->logger->write($e->getMessage());
            return 0;
        }
    }

    /**
     * check if address has balance
     *
     * @param TronAddress $address
     * @param float $emptyBase
     * @return bool
     */
    public function checkBalance(TronAddress $address, float $emptyBase = 0.0001): bool
    {
        return $this->getBalance($address) > $emptyBase;
    }

    public function getTransactions(TronAddress $address): array
    {
        try {
            $url = 'v1/accounts/' . $address->getAddress(true) . '/transactions';
            $result = $this->tron->getManager()->request($url, method: 'get');
            if ($result['success'])
                return $result['data'];
        } catch (TronException $e) {
            $this->logger->write($e->getMessage());
        }
        return [];
    }

    public function findDepositTransaction(array $transactions): array
    {
        foreach ($transactions as $transaction) {
            if ($this->getTransactionAmount($transaction) > 1) {
                return $transaction;
            }
        }
        return [];
    }

    public function checkTransactionAddress(array $transaction, TronAddress $address): bool
    {
        return $transaction['raw_data']['contract'][0]['parameter']['value']['to_address'] == $address->getAddress();
    }

    public function getTransactionHash(array $transaction): string
    {
        return $transaction['txID'];
    }

    public function getTransactionTimestamp(array $transaction): int
    {
        return intval($transaction['raw_data']['timestamp'] / 1000);
    }

    /**
     * @param array $transaction
     * @return float
     */
    public function getTransactionAmount(array $transaction): float
    {
        return $transaction['raw_data']['contract'][0]['parameter']['value']['amount'] / 1000000;
    }

    /**
     * transfer TRX from one address to another
     *
     * @param TronAddress $from
     * @param TronAddress|null $to // default is deposit address
     * @param float|null $amount // default is balance of from address
     * @return void
     * @throws TronException
     */
    public function transfer(TronAddress $from, TronAddress $to = null, float $amount = null): void
    {
        $amount = $amount ?? $this->getBalance($from);
        if ($amount <= 0)
            throw new TronException('Invalid amount');
        $to = $to ?? $this->getDefaultDepositAddress();
        $this->tron->setPrivateKey($from->getPrivateKey());
        $result = $this->tron->sendTransaction($to->getAddress(true), $amount, $from->getAddress(true));
        if ($result['result']) {
            $this->logger->write('Amount transferred from ' . $from->getAddress(true));
            return;
        }
        throw new TronException('Failed to transfer TRX');
    }

    /**
     * transfer TRX from one address to another after delay
     *
     * @param TronAddress $from
     * @param TronAddress|null $to // default is deposit address
     * @param float|null $amount // default is balance of from address
     * @return void
     */
    public function transferLater(TronAddress $from, TronAddress $to = null, float $amount = null): void
    {
        async(function () use ($from, $to, $amount): void {
            try {
                $this->transfer($from, $to, $amount);
            } catch (TronException $e) {
                $this->logger->write($e->getMessage());
                delay(60); // wait one minute and try again
                $this->transferLater($from, $to, $amount);
            }
        });
    }


    /**
     * get default deposit address
     *
     * @return TronAddress
     */
    private function getDefaultDepositAddress(): TronAddress
    {
        try {
            return new TronAddress([
                'address_base58' => $this->configuration->get('DEPOSIT_ADDRESS_TRX'),
                'address_hex' => '',
                'private_key' => '',
                'public_key' => ''
            ]);
        } catch (TronException $e) {
            $this->logger->write($e->getMessage());
            return $this->getDefaultDepositAddress();
        }
    }

    /**
     * get block by number
     *
     * @param int $number
     * @return array
     * @throws TronException
     */
    public function getBlockByNumber(int $number): array
    {
        try {
            return $this->tron->getBlockByNumber($number);
        } catch (TronException $e) {
            $this->logger->write($e->getMessage());
            throw $e;
        }
    }

    /**
     * get latest block
     *
     * @return array
     * @throws TronException
     */
    public function getLatestBlock(): array
    {
        try {
            return $this->tron->getLatestBlocks()[0];
        } catch (TronException $e) {
            $this->logger->write($e->getMessage());
            throw $e;
        }
    }

    /**
     * get latest blocks
     *
     * @param int $limit
     * @return array
     */
    public function getLatestBlocks(int $limit = 1): array
    {
        try {
            return $this->tron->getLatestBlocks($limit);
        } catch (TronException $e) {
            $this->logger->write($e->getMessage());
            return [];
        }
    }

    /**
     * get block number
     *
     * @param array $block
     * @return int
     * @throws TronException
     */
    public function getBlockNumber(array $block): int
    {
        // check if block is valid
        if (empty($block))
            throw new TronException('Invalid block');
        return $block['block_header']['raw_data']['number'];
    }

    /**
     * search for addresses in the block
     *
     * @param TronAddress[] $addresses
     * @param array $block
     * @return array
     */
    public function findTransactionsInBlockByAddress(array $addresses, array $block): array
    {
        // return empty array if no addresses are given
        if (!$addresses)
            return [];
        // get addresses hex as array
        $addresses = array_map(fn($address) => $address->getAddress(), $addresses);
        // search for addresses in block
        $found = [];
        foreach ($block['transactions'] as $transaction) {
            if (isset($transaction['raw_data']['contract'][0]['parameter']['value']['to_address'])) {
                $address = $transaction['raw_data']['contract'][0]['parameter']['value']['to_address'];
                if (in_array($address, $addresses)) {
                    $found[] = $transaction;
                }
            }
        }
        return $found;
    }

    /**
     * find address in database by address hex
     *
     * @param string $addressHex
     * @return array
     * @throws TronException
     */
    public function findAddressByHex(string $addressHex): array
    {
        $address = Database::getInstance()->fetch('SELECT * FROM addresses WHERE address_hex = ? LIMIT 1', [$addressHex]);
        if ($address)
            return $address[0];
        throw new TronException('Address not found');
    }


    /**
     * get address by transaction
     *
     * @param array $transaction
     * @param string $type
     * @return TronAddress
     * @throws TronException
     */
    public function findAddressInTransaction(array $transaction, string $type = 'deposit'): TronAddress
    {
        $address = $transaction['raw_data']['contract'][0]['parameter']['value'][$type == 'deposit' ? 'to_address' : 'owner_address'];
        return $this->getTronAddress($this->findAddressByHex($address));
    }

    /**
     * get tron address object
     *
     * @param array $address
     * @return TronAddress
     */
    public function getTronAddress(array $address): TronAddress
    {
        try {
            return new TronAddress([
                'address_base58' => $address['address_base58'],
                'address_hex' => $address['address_hex'],
                'private_key' => $address['private_key'],
                'public_key' => $address['public_key']
            ]);
        } catch (TronException $e) {
            $this->logger->write($e->getMessage());
            return $this->getTronAddress($address);
        }
    }

    /**
     * load all addresses
     *
     * @return TronAddress[]
     */
    public function loadAllAddresses(): array
    {
        $addresses = Database::getInstance()->fetch('SELECT * FROM addresses');
        return array_map(function ($address) {
            return $this->getTronAddress($address);
        }, $addresses);
    }

    /**
     * load addresses
     *
     * @return TronAddress[]
     */
    public function loadAddresses(bool $verified = false): array
    {
        $addresses = Database::getInstance()->fetch('
                SELECT addresses.*, payment_log.id as payment_log_id 
                FROM addresses
                INNER JOIN payment_log 
                    ON payment_log.address = addresses.address_base58
                WHERE TRUE AND 
                    payment_log.verified_at IS ' . ($verified ? 'NOT NULL' : 'NULL'));
        return array_map(function ($address) {
            return $this->getTronAddress($address);
        }, $addresses);
    }

    /**
     * load unverified addresses between lower and higher minutes
     *
     * @param int $lowerThanMinutes
     * @param int $higherThanMinutes
     * @param bool $verified
     * @return TronAddress[]
     */
    public function loadAddressesByTime(int $lowerThanMinutes, int $higherThanMinutes, bool $verified = false): array
    {
        $addresses = Database::getInstance()->fetch('
            SELECT addresses.*, payment_log.id as payment_log_id 
            FROM addresses
            INNER JOIN payment_log 
                ON payment_log.address = addresses.address_base58
            WHERE TRUE AND  
                payment_log.created_at + INTERVAL ' . $lowerThanMinutes . ' MINUTE < NOW() AND
                payment_log.created_at + INTERVAL ' . $higherThanMinutes . ' MINUTE >= NOW() AND
                payment_log.verified_at IS  ' . ($verified ? 'NOT NULL' : 'NULL'));
        return array_map(function ($address) {
            return $this->getTronAddress($address);
        }, $addresses);
    }

    public static function getInstance(): ?Network
    {
        if (self::$instance === null)
            self::$instance = new Network();
        return self::$instance;
    }
}
