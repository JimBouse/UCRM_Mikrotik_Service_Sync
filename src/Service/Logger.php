<?php

namespace Ubnt\Plugins\MikrotikSync\Service;

use Ubnt\UcrmPluginSdk\Service\PluginLogManager;

class Logger
{
    private $pluginLogManager;
    private $debugEnabled;
    private $uuid;

    public function __construct(bool $debugEnabled = false)
    {
        $this->pluginLogManager = PluginLogManager::create();
        $this->debugEnabled = $debugEnabled;
        $this->uuid = null;
    }

    public function setUuid(?string $uuid): void
    {
        $this->uuid = $uuid;
    }

    private function formatMessage(string $level, string $message): string
    {
        $timestamp = microtime(true);
        $date = new \DateTime();
        $microseconds = (int)(($timestamp - floor($timestamp)) * 1000000);
        $formattedTimestamp = $date->format('Y-m-d H:i:s') . '.' . str_pad((string)$microseconds, 6, '0', STR_PAD_LEFT);
        
        if ($this->uuid) {
            return sprintf('[%s] [UUID: %s] [%s] %s', $formattedTimestamp, $this->uuid, strtoupper($level), $message);
        }
        return sprintf('[%s] [%s] %s', $formattedTimestamp, strtoupper($level), $message);
    }

    public function debug(string $message): void
    {
        if ($this->debugEnabled) {
            $this->pluginLogManager->appendLog($this->formatMessage('DEBUG', $message));
        }
    }

    public function info(string $message): void
    {
        $this->pluginLogManager->appendLog($this->formatMessage('INFO', $message));
    }

    public function notice(string $message): void
    {
        $this->pluginLogManager->appendLog($this->formatMessage('NOTICE', $message));
    }

    public function warning(string $message): void
    {
        $this->pluginLogManager->appendLog($this->formatMessage('WARNING', $message));
    }

    public function error(string $message): void
    {
        $this->pluginLogManager->appendLog($this->formatMessage('ERROR', $message));
    }

    public function critical(string $message): void
    {
        $this->pluginLogManager->appendLog($this->formatMessage('CRITICAL', $message));
    }

    public function alert(string $message): void
    {
        $this->pluginLogManager->appendLog($this->formatMessage('ALERT', $message));
    }

    public function emergency(string $message): void
    {
        $this->pluginLogManager->appendLog($this->formatMessage('EMERGENCY', $message));
    }

    /**
     * Clean log entries older than 48 hours
     * Logs success or failure of the cleanup operation
     *
     * @return bool True if successful, false otherwise
     */
    public function cleanOldLogs(): bool
    {
        try {
            $logFile = __DIR__ . '/../../data/plugin.log';
            
            // Check if log file exists
            if (!file_exists($logFile)) {
                $this->info("Log cleanup: plugin.log not found, nothing to clean");
                return true;
            }

            $fortyEightHoursAgo = time() - (48 * 3600);
            $lines = file($logFile, FILE_SKIP_EMPTY_LINES);
            
            if (!$lines) {
                $this->info("Log cleanup: plugin.log is empty, nothing to clean");
                return true;
            }

            $originalCount = count($lines);
            $keptLines = [];

            foreach ($lines as $line) {
                // Extract timestamp from log line format: [YYYY-MM-DD HH:MM:SS.xxxxxx]
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\.(\d+)\]/', $line, $matches)) {
                    $lineTimestamp = strtotime($matches[1]);
                    if ($lineTimestamp > $fortyEightHoursAgo) {
                        $keptLines[] = $line;
                    }
                } else {
                    // Keep lines that don't match the timestamp format (just in case)
                    $keptLines[] = $line;
                }
            }

            $removedCount = $originalCount - count($keptLines);
            
            // Write back the filtered lines
            $written = file_put_contents($logFile, implode('', $keptLines));
            
            if ($written === false) {
                $this->error("Log cleanup: Failed to write cleaned log file");
                return false;
            }

            $this->info("Log cleanup: Removed {$removedCount} old entries from plugin.log (kept {$keptLines} entries)");
            return true;

        } catch (\Exception $e) {
            $this->error("Log cleanup exception: {$e->getMessage()}");
            return false;
        }
    }
}
