<?php

namespace App\Services\KuCoin\Scanner;

use App\Core\Database;
use App\Core\Logger;
use App\Models\Payment;
use App\Models\User;
use App\Services\KuCoin\KuCoin;
use Exception;

class Deposit
{

    private Database $db;
    private User $user;
    private Payment $payment;
    private Logger $logger;
    private KuCoin $kuCoin;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->user = User::getInstance();
        $this->payment = Payment::getInstance();
        $this->kuCoin = KuCoin::getInstance();
        $this->logger = new Logger('verify.log');
    }

    /**
     * start deposit watcher
     *
     * @return void
     */
    public function start(): void
    {
        while (true)
            $this->watchDeposit();
    }

    /**
     * initialize websocket connection to watch deposits real time
     *
     * @return void
     */
    public function watchDeposit(): void
    {
        $this->logger->write('Deposit watcher started');
        $this->kuCoin->subscribeChannel(KuCoin::PRIVATE_CHANNEL, '/account/balance', function (array $message) {
            try {
                $this->db->checkConnection(false);
                $data = $message['data'];
                if ($data['relationEvent'] == 'main.deposit') {
                    // log deposit message
                    $this->logger->write('Received Deposit: ' . PHP_EOL . json_encode($message));
                    // get transaction data
                    $amount = (float)$data['availableChange'];
                    $time = (int)($data['time'] / 1000);
                    $currency = $data['currency'];
                    $hash = $data['relationContext']['txId'];
                    $hash = substr($hash, 0, strpos($hash, '@'));
                    // find payment
                    $payment = $this->payment->findByAmount($amount, $currency);
                    if (!$payment)
                        throw new Exception('Payment not found');
                    // update payment
                    $this->payment->update($payment, $amount, $hash, $time);
                    // update user
                    $this->user->paid($payment);
                    // log success
                    $this->logger->write('Payment Verified: ' . $payment['id']);
                }
            } catch (Exception $ex) {
                $this->logger->write($ex->getMessage());
            } finally {
                $this->db->closeConnection();
            }
        });
    }
}
