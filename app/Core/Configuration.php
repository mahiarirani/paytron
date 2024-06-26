<?php

namespace App\Core;

use Dotenv\Dotenv;

class Configuration {
    private static ?Configuration $instance = null;

    private function __construct()
    {
        // load .env file
        $dotenv = Dotenv::createUnsafeMutable(__DIR__ . '/../../');
        $dotenv->safeLoad();

        // set application timezone
        $this->setTimezone($this->get('APP_TIMEZONE'));

        // check config folder exists
        if (!is_dir(__DIR__ . '/../../config'))
            mkdir(__DIR__ . '/../../config');
    }

    /**
     * @return string
     */
    public function getTimezone(): string
    {
        return date_default_timezone_get();
    }

    /**
     * @param string $timezone // php timezones
     * @link https://www.php.net/manual/en/timezones.php
     */
    public function setTimezone(string $timezone): void
    {
        if ($this->getTimezone() != $timezone)
            date_default_timezone_set($timezone);
    }

    /**
     * @return Configuration|null
     */
    public static function getInstance(): ?Configuration
    {
        if(!self::$instance)
        {
            self::$instance = new Configuration();
        }

        return self::$instance;
    }

    /**
     * @param string $key
     * @return string|null
     */
    public function get(string $key): ?string
    {
        return getenv($key);
    }

    /**
     * @param string $key
     * @param string $value
     * @return void
     */
    public function set(string $key, string $value): void
    {
        putenv("$key=$value");
    }

    private function configRead(string $config): array
    {
        $file = __DIR__ . '/../../config/' . $config . '.json';
        // check if config file exists
        if (file_exists($file))
            // return config file data
            return json_decode(file_get_contents($file), true);
        // create new config file
        file_put_contents($file, json_encode([]));
        return [];
    }

    private function configWrite(string $config, array $data): void
    {
        // write new data to config file
        $file = __DIR__ . '/../../config/' . $config . '.json';
        file_put_contents($file, json_encode($data));
    }

    /**
     * use config json files to fetch data
     *
     * @param string $config
     * @param string $field
     * @return string
     */
    public function fetch(string $config, string $field): mixed
    {
        return $this->configRead($config)[$field] ?? null;
    }

    /**
     * use config json files to store data
     *
     * @param string $config
     * @param string $field
     * @param $value
     * @return void
     */
    public function store(string $config, string $field, $value): void
    {
        $data = $this->configRead($config);
        $data[$field] = $value;
        $this->configWrite($config, $data);
    }
}