<?php

namespace App\Models;

use App\Core\Configuration;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;

class Gateway
{
    public const DEFAULT = self::DIGISWAP;
    public const WESWAP = 1;
    private const WESWAP_FEE = [
        Currency::TRX => [
            'minimum' => [
                'type' => Currency::TRX,
                'value' => 2,
            ],
            'limit' => [
                'type' => Currency::IRT,
                'value' => 250000,
            ],
            'fix' => [
                'type' => Currency::IRT,
                'value' => 15000
            ],
            'over' => [
                'type' => 'percent',
                'value' => 6
            ]
        ],
        Currency::USDT => [
            'minimum' => [
                'type' => Currency::USDT,
                'value' => 1,
            ],
            'limit' => [
                'type' => Currency::USDT,
                'value' => 25,
            ],
            'fix' => [
                'type' => Currency::USDT,
                'value' => 2.5
            ],
            'over' => [
                'type' => 'percent',
                'value' => 5
            ]
        ]
    ];
    public const DIGISWAP = 2;
    private const DIGISWAP_FEE = [
        Currency::TRX => [
            'minimum' => [
                'type' => Currency::TRX,
                'value' => 2,
            ],
            'fix' => [
                'type' => Currency::IRT,
                'value' => 15000
            ]
        ]
    ];
    public const CHNAGETO = 3;
    public const BITPIN = 4;
    public const FEE = [
        self::WESWAP => self::WESWAP_FEE,
        self::DIGISWAP => self::DIGISWAP_FEE,
        self::CHNAGETO => self::WESWAP_FEE
    ];

    private Configuration $configuration;

    protected int $id;
    protected string $name;
    protected array $currencies;
    protected string $paymentUrl;
    protected string $rateUrl;
    protected array $fee;

    /**
     * @throws Exception
     */
    public function __construct(?int $gatewayId = null)
    {
        $this->configuration = Configuration::getInstance();
        $this->id = $gatewayId ?: self::DEFAULT;
        $this->name = match ($this->id) {
            self::WESWAP => 'WESWAP',
            self::DIGISWAP => 'DIGISWAP',
            self::CHNAGETO => 'CHANGETO',
            default => throw new Exception('Gateway not found')
        };
        $this->fee = self::FEE[$this->id];
        $this->currencies = array_keys($this->fee);
        $this->paymentUrl = $this->configuration->get($this->name . '_PAY_URL');
        $this->rateUrl = $this->configuration->get($this->name . '_RATE_URL');
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    public function getPaymentUrl(): string
    {
        return $this->paymentUrl;
    }

    public function getRateUrl(): string
    {
        return $this->rateUrl;
    }

    public function getFee(?string $currency = null): array
    {
        return $currency
            ? $this->fee[$currency]
            : $this->fee;
    }

    /**
     * get rate from payment gateway
     *
     * @param string $currency
     * @param int|null $gatewayId
     * @return float
     * @throws Exception
     */
    public function getRate(string $currency = Payment::DEFAULT_CURRENCY, int $gatewayId = null): float
    {
        // check if currency is supported by this gateway
        if (!in_array($currency, $this->currencies))
            throw new Exception('Currency not supported by this gateway');

        $client = new Client();
        $request = new Request('GET', $this->getRateUrl());

        try {
            $response = $client->send($request, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }

        if ($response->getStatusCode() != 200)
            throw new Exception("Couldn't fetch rate from payment gateway", 204);

        // parse response and return rate for given currency in IRT
        $result = json_decode($response->getBody()->getContents(), true);
        return match ($gatewayId ?? $this->getId()) {
            Gateway::BITPIN, => $result['results'][261]['price'],
            Gateway::WESWAP, Gateway::CHNAGETO => $result['result'][$currency],
            Gateway::DIGISWAP => $result['assets'][match ($currency) {
                    Currency::PM => 0,
                    Currency::TRX => 1,
                }]['usd_price'] * $result['usd_sell_price'],
        };
    }
}
