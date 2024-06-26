<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use Exception;

class Database
{
    private static ?Database $instance = null;
    private Logger $logger;
    private ?PDO $db;
    const MYSQL = 'mysql';
    const SQLITE = 'sqlite';
    private string $dbms;
    private string $host;
    private string $port;
    private string $user;
    private string $pass;
    private string $name;
    private ?string $charset;

    private function __construct(string $dbms = self::SQLITE)
    {
        $this->logger = new Logger('database.log');

        $this->dbms = $dbms;
        switch ($this->dbms) {

            case self::SQLITE:
                $this->name = 'database/db.sqlite';
                break;

            case self::MYSQL:
                $config = Configuration::getInstance();
                $this->host = $config->get('DB_HOST');
                $this->port = $config->get('DB_PORT');
                $this->user = $config->get('DB_USER');
                $this->pass = $config->get('DB_PASSWORD');
                $this->name = $config->get('DB_NAME');
                $this->charset = $config->get('DB_CHARSET');
                break;

            default:
                $this->logger->write('Invalid database type');
        }

        $this->createConnection();
    }

    /**
     * create a connection to database
     * @return void
     */
    private function createConnection(): void
    {
        switch ($this->dbms) {

            case self::MYSQL:
                $dsn = "mysql:host=$this->host;port=$this->port;dbname=$this->name";
                $this->db = new PDO($dsn, $this->user, $this->pass);
                $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); // return array
                $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // throw exception on error
                if ($this->charset)
                    $this->db->exec("SET NAMES '$this->charset'");
                break;

            case self::SQLITE:
                $dsn = "sqlite:$this->name";
                $this->db = new PDO($dsn);
                break;
        }
    }

    /**
     * perform a query on database to check if connection is OK
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        try {
            return !is_null($this->db) && !($this->db->query('SELECT 1') === false);
        } catch (PDOException $ex) {
            $this->logger->write($ex->getMessage());
            return false;
        }
    }

    /**
     * check database connection
     *
     * @param bool $silent
     * @return void
     * @throws Exception
     */
    public function checkConnection(bool $silent = true): void
    {
        $silent ?: $this->logger->write('Checking database connection...');

        if ($this->isConnected()) {
            $silent ?: $this->logger->write('Database connection is OK');
            return;
        }

        $silent ?: $this->logger->write('Connection to database lost. Reconnecting...');
        $this->createConnection();

        if ($this->isConnected()) {
            $silent ?: $this->logger->write('Reconnected to database');
            return;
        }

        throw new Exception('Connection failed');
    }

    /**
     * manually close database connection
     *
     * @return void
     */
    public function closeConnection(): void
    {
        $this->db = null;
    }

    /**
     * close database connection after script execution
     */
    public function __destruct()
    {
        $this->db = null;
    }

    /**
     * Fetch a query
     * SELECT
     *
     * @param string $sql
     * @param array $params
     * @return array
     */
    public function fetch(string $sql, array $params = []): array
    {
        $statement = null;
        $result = [];

        try {
            $statement = $this->db->prepare($sql);
            $statement->execute($params);
            $result = $statement->fetchAll();
        } catch (PDOException $ex) {
            $this->logger->write($ex->getMessage());
        } finally {
            $statement->closeCursor();
        }

        return $result;
    }

    /**
     * Execute a Query
     * DELETE, UPDATE
     *
     * @param string $sql
     * @param array $params
     * @return bool
     */
    public function execute(string $sql, array $params = []): bool
    {
        $statement = null;
        $result = false;

        try {
            $statement = $this->db->prepare($sql);
            $result = $statement->execute($params);
        } catch (PDOException $ex) {
            $this->logger->write($ex->getMessage());
        } finally {
            $statement->closeCursor();
        }

        return $result;
    }

    /**
     * Insert an item
     * INSERT
     *
     * @param string $sql
     * @param array $params
     * @return integer
     */
    public function insert(string $sql, array $params = []): int
    {
        $statement = null;
        $result = 0;

        try {
            $statement = $this->db->prepare($sql);
            $statement->execute($params);
            $result = intval($this->db->lastInsertId());
        } catch (PDOException $ex) {
            $this->logger->write($ex->getMessage());
        } finally {
            $statement->closeCursor();
        }

        return $result;
    }

    /**
     * @return Database|null
     */
    public static function getInstance(): ?Database
    {
        if (!self::$instance)
            self::$instance = new Database();

        return self::$instance;
    }
}
