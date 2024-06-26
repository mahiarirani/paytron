<?php

namespace App\Models;

use App\Core\Configuration;
use App\Core\Database;
use App\Core\Logger;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;


class User
{
    private static ?User $instance = null;

    private Logger $logger;
    private Database $db;
    private Configuration $configuration;


    public const STATUS_PAYMENT_PENDING = 'GeneratePayLink';
    public const STATUS_MAIN = 'main';

    private function __construct()
    {
        $this->logger = new Logger('user.log');
        $this->db = Database::getInstance();
        $this->configuration = Configuration::getInstance();
    }

    /**
     * find user by id
     *
     * @param int $id
     * @return array
     * @throws Exception
     */
    public function findUserById(int $id): array
    {
        $users = $this->db->fetch('
            SELECT * 
            FROM users 
            WHERE id = ?
            LIMIT 1
        ', [$id]);
        if ($users)
            return $users[0];
        throw new Exception('User not found');
    }

    /**
     * insert user balance
     *
     * @param int $userId
     * @param float $amount
     * @return void
     */
    public function increaseBalance(int $userId, float $amount): void
    {
        $this->db->execute('
            UPDATE users
            SET balance = balance + :amount
            WHERE id = :user_id
        ', [
            'amount' => $amount,
            'user_id' => $userId
        ]);
    }

    /**
     * update user status
     *
     * @param int $userId
     * @param string $status
     * @return void
     */
    public function updateStatus(int $userId, string $status = self::STATUS_PAYMENT_PENDING): void
    {
        $this->db->execute('
            UPDATE users
            SET status = :status
            WHERE id = :user_id
        ', [
            'status' => $status,
            'user_id' => $userId
        ]);
    }


    /**
     * reset user status
     *
     * @param int $userId
     * @return void
     */
    public function resetStatus(int $userId): void
    {
        $this->db->execute('
            UPDATE users
            SET status = :status
            WHERE id = :user_id
        ', [
            'status' => self::STATUS_MAIN,
            'user_id' => $userId
        ]);
    }

    /**
     * update user balance
     *
     * @param int $userId
     * @param float $balance
     * @return void
     */
    public function updateBalance(int $userId, float $balance): void
    {
        $this->db->execute('
            UPDATE users
            SET balance = :balance
            WHERE id = :user_id
        ', [
            'amount' => $balance,
            'user_id' => $userId
        ]);
    }

    /**
     * update user according to payment
     *
     * @param array $payment
     * @return void
     */
    public function paid(array $payment): void
    {
        try {
            // find user in payment
            $user = $this->findUserById($payment['user_id']);
            // increase balance
            $this->increaseBalance($user['id'], $payment['confirmed_fiat']);
            // reset status to main
            $this->resetStatus($user['id']);
            // send user notification
            $this->notify($payment['hash']);
        } catch (Exception $e) {
            $this->logger->write($e->getMessage());
        }
    }

    /**
     * @param string $hash
     * @return void
     */
    public function notify(string $hash): void
    {
        try {
            $secret = $this->configuration->get('SECRET');
            $baseUrl = $this->configuration->get('SERVER_URL');
            $client = new Client();
            $request = new Request('GET', $baseUrl . '/verify_c.php?hash=' . $hash . '&sec=' . $secret);

            $response = $client->send($request);
        if ($response->getStatusCode() != 200)
            throw new Exception("Couldn't connect to notification endpoint", 204);
        } catch (Exception|GuzzleException $e) {
            $this->logger->write($e->getMessage());
        }
    }

    public static function getInstance(): User
    {
        if (self::$instance === null)
            self::$instance = new User();
        return self::$instance;
    }
}