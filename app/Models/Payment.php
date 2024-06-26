<?php

namespace App\Models;

use App\Core\Configuration;
use App\Core\Database;
use App\Core\Logger;
use App\Services\Tron\Network;
use DateTime;
use Exception;
use Throwable;


/**
 * handle payment
 */
class Payment
{
    private static ?Payment $instance = null;

    private Configuration $configuration;
    private Database $db;
    private User $user;
    private Logger $logger;
    protected Gateway $gateway;
    protected float $userId = 0;
    protected float $amount = 0;
    private string $currency;
    private float $rate;
    private string $address;
    private float $total;
    private int $trackingCode;

    public const DEFAULT_CURRENCY = Currency::TRX;
    public const TRACKING_CODE_LENGTH = 3;
    public const RATE_GUARANTEE_TIME = 15 * 60; // 15 minutes

    /**
     * payment singleton instance getter
     *
     * @return Payment|null
     */
    public static function getInstance(): ?Payment
    {
        if (self::$instance === null)
            self::$instance = new Payment();
        return self::$instance;
    }

    /**
     * payment constructor
     */
    private function __construct()
    {
        $this->configuration = Configuration::getInstance();
        $this->db = Database::getInstance();
        $this->user = User::getInstance();
        $this->logger = new Logger('payment.log');

        $this->setGateway();
        $this->currency = self::DEFAULT_CURRENCY;
        $this->address = $this->getDefaultAddress($this->currency);
    }

    /**
     * @return Gateway
     */
    public function getGateway(): Gateway
    {
        return $this->gateway;
    }

    /**
     * @param int|null $gatewayId
     */
    public function setGateway(?int $gatewayId = null): void
    {
        try {
            $this->gateway = new Gateway($gatewayId);
        } catch (Exception $e) {
            $this->logger->write($e->getMessage());
            $this->gateway = new Gateway();
        }
    }

    /**
     * @return float
     */
    public function getUserId(): float
    {
        return $this->userId;
    }

    /**
     * @param float $userId
     */
    public function setUserId(float $userId): void
    {
        $this->userId = $userId;
        // check user existence
        if ($userId > 0) {
            try {
                $this->user->findUserById($userId);
            } catch (Exception $e) {
                $this->logger->write($e->getMessage());
            }
        }
    }

    /**
     * @return float
     */
    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * @param float $amount
     */
    public function setAmount(float $amount): void
    {
        $this->amount = $amount;
        // get total amount
        if ($amount > 0) {
            try {
                $this->trackingCode = $this->generateTrackingCode();
                $this->rate = $this->getGateway()->getRate($this->currency, Gateway::BITPIN);
                $this->total = $this->calcTotal($amount);
            } catch (Exception $e) {
                $this->logger->write($e->getMessage());
            }
        }
    }

    /**
     * @param string $currency
     * @param bool $dynamic
     * @return string
     */
    private function getDepositAddress(string $currency = self::DEFAULT_CURRENCY, bool $dynamic = true): string
    {
        if ($dynamic) {
            try {
                $address = $this->getDynamicAddress($currency);
                if (!empty($address)) {
                    // save generate address to database
                    $this->saveAddress($address);
                    // return new address
                    return $address['address_base58'];
                }
            } catch (Exception $e) {
                $this->logger->write($e->getMessage());
            }
        }
        // return default address
        return $this->getDefaultAddress($currency);
    }

    /**
     * @param string $currency
     * @return string
     */
    private function getDefaultAddress(string $currency = self::DEFAULT_CURRENCY): string
    {
        $addresses = [
            Currency::TRX => $this->configuration->get('DEPOSIT_ADDRESS_TRX'),
            Currency::USDT => $this->configuration->get('DEPOSIT_ADDRESS_USDT')
        ];
        return $addresses[$currency];
    }

    /**
     * @throws Exception
     */
    private function getDynamicAddress(string $currency = self::DEFAULT_CURRENCY): array
    {
        switch ($currency) {
            case Currency::TRX:
                $tron = Network::getInstance();
                $tron->generateAddress();
                return $tron->getAddress() ?
                    $tron->getAddress()->getRawData() :
                    throw new Exception("Can not generate TRX address", 400);
            case Currency::USDT:
                throw new Exception("USDT dynamic address is not supported yet", 400);
            default:
                $this->logger->write("Can not generate $currency address");
                return [];
        }
    }

    /**
     * get payment url for user
     *
     * @return string
     * @throws Exception
     */
    public function getPaymentUrl(): string
    {
        if ($this->getUserId() == 0 or $this->getAmount() == 0)
            throw new Exception("Amount or User ID is not set", 400);

        $this->address = $this->getDepositAddress($this->currency, (bool)$this->configuration->get('DEPOSIT_DYNAMIC_ADDRESS'));
        $address = $this->address;
        if ($this->configuration->get('PAYMENT_MIDDLE_PAGE')) {
            $url = $this->configuration->get('PAYMENT_MIDDLE_URL');
            $data = base64_encode(json_encode([
                'gateway' => $this->getGateway()->getId(),
                'fiat' => $this->getAmount(),
                'code' => $this->trackingCode,
                'address' => $address,
            ]));
            $endpoint = $url . "?data=$data";
        } else {
            $url = $this->getGateway()->getPaymentUrl();
            $total = $this->total;
            $currency = $this->currency;
            $endpoint = $url . "?amount=$total&currency=$currency&address=$address";
        }

        $this->user->updateStatus($this->getUserId());
        $this->save();

        return $endpoint;
    }

    /**
     * get payment amount based on fee and amount
     *
     * @param float $amount
     * @return float
     * @throws Exception
     */
    private function calcTotal(float $amount): float
    {
        try {
            $total = $this->convertToCrypto($amount - $this->calcFee($amount), $this->currency);
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
        // replace last decimals with tracking code
        return self::replaceLastDecimals($total, $this->trackingCode, Currency::PRECISION[$this->currency]);
    }

    /**
     * convert amount to currency based on rate
     *
     * @param float $amount
     * @param string $currency
     * @return float
     * @throws Exception
     */
    private function convertToCrypto(float $amount, string $currency): float
    {
        // (amount - fee) / rate
        $result = $amount / $this->rate;

        // check minimum payment
        if ($result < $this->getGateway()->getFee($currency)['minimum']['value'])
            throw new Exception("Amount is less than minimum amount", 400);

        // limit decimal places to currency precision
        return round($result, Currency::PRECISION[$currency]);
    }

    /**
     * calc fee based on amount and currency as IRT
     *
     * @param float $amount
     * @param string|null $currency
     * @param float|null $rate
     * @param array|null $fee_system
     * @return float
     */
    public function calcFee(float $amount, ?string $currency = null, ?float $rate = null, ?array $fee_system = null): float
    {
        $currency = $currency ?? $this->currency;
        $fee_system = $fee_system ?? $this->getGateway()->getFee($currency);
        try {
            if (is_null($rate))
                $rate = $this->rate ?: $this->getGateway()->getRate($currency);
        } catch (Exception $e) {
            $this->logger->write($e->getMessage());
            $rate = $this->rate;
        }
        // exchange limit to IRT if not same
        if (isset($fee_system['limit']) and $fee_system['limit']['type'] != Currency::IRT)
            $fee_system['limit']['value'] *= $rate;

        // repeat fee calculation 3 times to get final fee after fee drop effect of conversion
        $total = $amount;
        for ($i = 0; $i <= 3; $i++) {
            // check limit
            if (!isset($fee_system['limit']))
                // fee is always fixed when no limit
                $fee = $fee_system['fix']['value'];
            else if ($total < $fee_system['limit']['value'])
                // check fee type
                if ($fee_system['fix']['type'] == Currency::IRT)
                    $fee = $fee_system['fix']['value'];
                // exchange fee to IRT if not same
                else
                    $fee = $total * $fee_system['fix']['value'];
            else
                $fee = $total * $fee_system['over']['value'] / 100;
            $total = $amount - $fee;
        }

        // check for extra fee
        if (isset($fee_system['extra']))
            $fee += min($total * $fee_system['extra']['value'] / 100, $fee_system['extra']['max']);

        return round($fee, -1);
    }

    /**
     * @param float $number
     * @param int $target
     * @param int|null $decimalLength
     * @param int|null $targetLength
     * @return float
     */
    private static function replaceLastDecimals(float $number, int $target, ?int $decimalLength = Currency::PRECISION[self::DEFAULT_CURRENCY], ?int $targetLength = self::TRACKING_CODE_LENGTH): float
    {
        $number = (string)$number;
        $target = (string)$target;
        // check if number has decimal
        if (!str_contains($number, '.'))
            return $number;
        // get number length including decimal point
        $numberLength = strlen(substr($number, 0, strpos($number, '.') + 1));
        // fill digits with zero if number length is more than current length
        $decimalLength = $decimalLength ? $decimalLength + $numberLength : strlen($number) - $numberLength;
        $number = str_pad($number, $decimalLength, '0');
        // fill left digits with zero if target length is more than current length
        $targetLength = $targetLength ?? strlen($target);
        $target = str_pad($target, $targetLength, '0', STR_PAD_LEFT);
        // remove last digits
        $number = substr($number, 0, -$targetLength);
        // add target
        $number .= $target;
        // round decimal point to specified number length
        return (float)$number;
    }

    /**
     * generate tracking code
     *
     * @return int
     * @throws Exception
     */
    private function generateTrackingCode(): int
    {
        // get used tracking codes
        $codes = $this->db->fetch('
            SELECT tracking_code 
            FROM payment
            WHERE verified_at IS NULL
            ORDER BY created_at
        ');
        $codes = array_map(fn($code) => $code['tracking_code'], $codes);
        // check if all codes are used
        if (count($codes) >= 900)
            // reuse oldest issued code
            return $codes[0];
        // generate random number until not used
        $trackingCode = random_int(100, 999);
        while (in_array($trackingCode, $codes))
            $trackingCode = random_int(100, 999);
        return $trackingCode;
    }

    /**
     * save payment to db
     *
     * @return void
     */
    private function save(): void
    {
        $this->db->insert('
            INSERT INTO payment (
                user_id,
                tracking_code,
                fiat_amount,
                gateway_id,
                gateway_rate,
                crypto_amount,
                crypto_currency,
                fee,
                address
            ) VALUES (
                :userId,
                :trackingCode,
                :fiatAmount,
                :gatewayId,
                :gatewayRate,
                :cryptoAmount,
                :cryptoCurrency,
                :fee,
                :address
            )
        ', [
            'userId' => $this->getUserId(),
            'trackingCode' => $this->trackingCode,
            'fiatAmount' => $this->getAmount(),
            'gatewayId' => $this->getGateway()->getId(),
            'gatewayRate' => $this->rate,
            'cryptoAmount' => $this->total,
            'cryptoCurrency' => $this->currency,
            'fee' => $this->calcFee($this->getAmount()),
            'address' => $this->address
        ]);
    }

    /**
     * save address to db
     *
     * @param array $address
     * @return void
     */
    private function saveAddress(array $address): void
    {
        $this->db->insert('
            INSERT INTO addresses (
                address_hex,
                address_base58,
                private_key,
                public_key
            ) VALUES (
                :addressHex,
                :addressBase58,
                :privateKey,
                :publicKey
            )
        ', [
            'addressHex' => $address['address_hex'],
            'addressBase58' => $address['address_base58'],
            'privateKey' => $address['private_key'],
            'publicKey' => $address['public_key']
        ]);
    }

    /**
     * get tracking code from last three digits of transaction amount
     *
     * @param float $amount
     * @param int $numberLength
     * @param int $targetLength
     * @return int
     */
    private static function getTrackingCodeFromAmount(float $amount, int $numberLength = Currency::PRECISION[Payment::DEFAULT_CURRENCY], int $targetLength = Payment::TRACKING_CODE_LENGTH): int
    {
        $amount = (string)$amount;
        // get the chars after decimal point
        $decimals = substr($amount, strpos($amount, '.') + 1);
        // convert number to 6 digit by placing 0 in front of it
        $decimals = str_pad($decimals, $numberLength, '0');
        // get last three digits
        return (int)substr($decimals, -$targetLength);
    }

    /**
     * find payment record by tracking code
     *
     * @param int $trackingCode
     * @return array
     * @throws Exception
     */
    public function findByTrackingCode(int $trackingCode): array
    {
        $payments = $this->db->fetch('
            SELECT * 
            FROM payment 
            WHERE tracking_code = :tracking_code and verified_at is null
            ORDER BY created_at DESC
            LIMIT 1
        ', [
            'tracking_code' => $trackingCode
        ]);
        if ($payments)
            return $payments[0];
        throw new Exception('Payment not found');
    }

    /**
     * find payment by address
     *
     * @param string $address
     * @return array
     * @throws Exception
     */
    public function findByAddress(string $address): array
    {
        $payments = $this->db->fetch('
            SELECT * 
            FROM payment 
            WHERE address = ? AND verified_at IS NULL
            ORDER BY created_at DESC
            LIMIT 1
        ', [$address]);
        if (count($payments) == 0)
            throw new Exception('Payment not found');
        return $payments[0];
    }

    /**
     * find payment by tracking code in the amount last three decimal digits
     *
     * @param float $amount
     * @param string $currency
     * @return array
     */
    public function findByAmount(float $amount, string $currency = Payment::DEFAULT_CURRENCY): array
    {
        try {
            // get payment data from amount tracking code
            $trackingCode = self::getTrackingCodeFromAmount($amount, Currency::PRECISION[$currency]);
            return $this->findByTrackingCode($trackingCode);
        } catch (Exception $ex) {
            $this->logger->write($ex->getMessage());
            return [];
        }
    }

    /**
     * @param $payment
     * @param $amount
     * @param $hash
     * @param $time
     * @return void
     * @throws Exception
     */
    public function update(&$payment, $amount, $hash, $time): void
    {
        // update payment verification fields
        $this->verified($payment, $amount, $hash, $time);
        // update payment confirmation fields
        $this->confirmed($payment, ...array_values($this->confirmAmount($payment)));
    }

    public function verified(array &$payment, float $amount, string $hash, int $time): void
    {
        $this->db->execute('
            UPDATE payment
            SET verified_amount = :verified_amount,
                verified_at = :verified_at,
                hash = :hash
            WHERE id = :payment_id
        ', [
            'verified_amount' => $amount,
            'verified_at' => date('Y-m-d H:i:s', $time),
            'hash' => $hash,
            'payment_id' => $payment['id'],
        ]);
        $payment['verified_amount'] = $amount;
        $payment['verified_at'] = date('Y-m-d H:i:s', $time);
        $payment['hash'] = $hash;
    }

    /**
     * update payment record
     *
     * @param array $payment
     * @param float $fiat
     * @param float $fee
     * @param float $rate
     * @return void
     */
    public function confirmed(array &$payment, float $fiat, float $fee, float $rate): void
    {
        $this->db->execute('
            UPDATE payment
            SET confirmed_fiat = :confirmed_fiat,
                confirmed_fee = :confirmed_fee,
                confirmed_rate = :confirmed_rate,
                confirmed_at = :confirmed_at
            WHERE id = :payment_id
        ', [
            'confirmed_fiat' => $fiat,
            'confirmed_fee' => $fee,
            'confirmed_rate' => $rate,
            'confirmed_at' => date('Y-m-d H:i:s'),
            'payment_id' => $payment['id'],
        ]);
        $payment['confirmed_fiat'] = $fiat;
        $payment['confirmed_fee'] = $fee;
        $payment['confirmed_rate'] = $rate;
        $payment['confirmed_at'] = date('Y-m-d H:i:s');
    }

    /**
     * confirm payment amount
     *
     * @param array $payment
     * @return array [fiat, fee, rate]
     */
    private function confirmAmount(array $payment): array
    {
        $amount = $payment['verified_amount'];
        if ($this->checkLatePaymentTime($payment['verified_at'], $payment['created_at'])) {
            try {
                $this->setGateway($payment['gateway_id']);
                $rate = $this->getGateway()->getRate($payment['crypto_currency']);
                $fiat = round($amount * $rate, -3);
                $fee = $this->calcFee($fiat, $payment['crypto_currency'], $rate);
                $fiat += $fee;
                $fiat = round($fiat, -3);
            } catch (Throwable $e) {
                // keep old rate if fail to get new rate
                $this->logger->write($e->getMessage());
                $fee = (float)$payment['fee'];
                $rate = (float)$payment['gateway_rate'];
                $fiat = (float)$payment['fiat_amount'];
            }
        } else {
            // keep old rate
            $fee = (float)$payment['fee'];
            $rate = (float)$payment['gateway_rate'];
            $fiat = (float)$payment['fiat_amount'];
        }
        return [
            'fiat' => $fiat,
            'fee' => $fee,
            'rate' => $rate
        ];
    }

    private function checkLatePaymentTime(string|int $paid, int|string $created): bool
    {
        // get payment time
        try {
            if (is_string($paid))
                $paid = (new DateTime($paid))->getTimestamp();
            if (is_string($created))
                $created = (new DateTime($created))->getTimestamp();
        } catch (Throwable $e) {
            $this->logger->write($e->getMessage());
            $created = time();
        }
        // check if transaction time is later than 15 minutes
        $minutes = self::RATE_GUARANTEE_TIME;
        return ($paid - $created) > $minutes;
    }

    /**
     * @param int $id
     * @return array
     * @throws Exception
     */
    public function findById(int $id): array
    {
        $payments = $this->db->fetch('
            SELECT * 
            FROM payment 
            WHERE id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ', [$id]);
        if ($payments)
            return $payments[0];
        throw new Exception('Payment not found');
    }
}
