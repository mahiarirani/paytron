<?php

namespace Scripts;

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Logger;
use App\Services\KuCoin\Scanner\Conversion;
use App\Services\KuCoin\Scanner\Deposit;
use App\Services\Tron\Scanner\Address;
use App\Services\Tron\Scanner\Balance;
use App\Services\Tron\Scanner\Block;

class Scanner
{
    private Logger $logger;
    private string $scanner;
    private array $params;

    public function __construct()
    {
        $this->logger = new Logger('scanner.log');
        [$this->scanner, $this->params] = $this->getParams();
    }

    private function getParams(): array
    {
        // read arguments from command line with for loop
        global $argv;

        $scanner = $argv[1] ?? '';
        $params = [];
        for ($i = 2; $i < count($argv); $i++) {
            list($key, $val) = explode('=', $argv[$i]);
            $params[$key] = $val;
        }
        return [strtolower($scanner), $params];
    }

    public function start(): void
    {
        $this->logger->write("Starting scanner `$this->scanner`" . (empty($this->params) ? '' : (' with params: ' . json_encode($this->params))));
        match ($this->scanner) {
            'block' => (new Block(boolval($this->params['verbose'] ?? false)))->start(),
            'conversion' => (new Conversion())->start(),
            'deposit' => (new Deposit())->start(),
            'balance' => (new Balance($this->params['mode'] ?? null))->start(),
            'address' => (new Address($this->params['time'] ?? null))->start(),
            default => $this->logger->write("Scanner `$this->scanner` not found"),
        };
    }
}

(new Scanner())->start();