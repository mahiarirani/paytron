<?php

namespace App\Core;

class Logger {
    private string $logFile;

    public function __construct(string $logFile='scripts.log', string $logPath=__DIR__ . '/../../log/')
    {
        // check path exists if not create directory
        if (!file_exists($logPath)) {
            mkdir($logPath, 0777, true);
        }
        $this->logFile = $logPath . $logFile;
    }

    /**
     * log message to log file
     * @param $message
     * @return void
     */
    public function write($message): void
    {
        $log = '#' . PHP_EOL . date('Y-m-d H:i:s') . PHP_EOL . '----------' . PHP_EOL . $message . PHP_EOL . '#';
        error_log($log , 3, $this->logFile);
    }
}
