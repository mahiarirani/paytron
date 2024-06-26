<?php

namespace App\Services\Tron\Scanner;

use App\Core\Logger;
use App\Services\Tron\Network;
use Exception;
use IEXBase\TronAPI\TronAddress;

class Balance
{
    private Logger $logger;
    private Network $tron;
    private string $scanMode;
    public const SCAN_ALL = 'all'; // all addresses
    public const SCAN_LAST_3_DAYS = '3d'; // last 3 days verified addresses

    public function __construct(string $mode = null)
    {
        $this->tron = Network::getInstance();
        $this->logger = new Logger('balance.log');
        $this->scanMode = $mode ?? self::SCAN_LAST_3_DAYS;
    }

    /**
     * start balance scanner
     *
     * @return void
     */
    public function start(): void
    {
        $this->scanAddresses();
    }

    /**
     * search verified address on tron network to check if it has balance
     *
     * @return void
     */
    public function scanAddresses(): void
    {
        $addresses = $this->loadAddress();
        $this->logger->write("Scanning " . ($this->scanMode != self::SCAN_ALL ? '' : '[ALL] ') . count($addresses) . " addresses balance"
            . PHP_EOL . json_encode(array_map(fn($address) => $address->getAddress(true), $addresses)));
        foreach ($addresses as $address) {
            try {
                if ($this->tron->checkBalance($address)) {
                    $this->logger->write('Balance found on: ' . json_encode($address->getAddress(true)));
                    $this->tron->transfer($address);
                }
                sleep(5); // wait 5 seconds before checking next address
            } catch (Exception $e) {
                $this->logger->write($e->getMessage());
            }
        }
    }

    /**
     * @return TronAddress[]
     */
    private function loadAddress(): array
    {
        switch ($this->scanMode) {
            case self::SCAN_ALL:
                $addresses = $this->tron->loadAllAddresses();
                break;
            case self::SCAN_LAST_3_DAYS:
                $addresses = $this->tron->loadAddressesByTime(0, 3 * 24 * 60, true);
                break;
        }
        return $addresses ?? [];
    }
}
