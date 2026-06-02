<?php

namespace App\Service\Http;

use App\Entity\IConnection;
use App\Utilities\Timer;

class HttpClient
{
    private $auth;
    private $authType;
    private $baseUrl;
    private $ch;
    private $fetchedData;
    private $httpCode;
    private $httpHeader = [
        'Accept: application/json',
        'Content-Type: application/json'
    ];
    private $timer;
    private $rpm;
    private $rateLimitKey;
    private $rateLimitLabel;
    private const MAX_ATTEMPTS = 3;
    private const WAIT_TIME_STEP = 10; // seconds
    private const RATE_LIMIT_WINDOW_SECONDS = 60;
    private const RATE_LIMIT_SAFETY_FACTOR = 0.85;
    private const RATE_LIMIT_429_BASE_PENALTY_SECONDS = 30;
    private const RATE_LIMIT_429_MAX_PENALTY_SECONDS = 180;
    private $tryColors = [
        "\033[0;32m", // green
        "\033[0;33m", // yellow
        "\033[0;31m", // red
    ];
    private static $processRateLimitStore = [];
    private $rateLimitStoreFallbackNotified = false;
    

    public function __construct()
    {
        $this->timer = new Timer;
    }

    public function getHttpCode()
    {
        return $this->httpCode;
    }

    public function getContent()
    {
        $this->removeUnprintableChars();
        return $this->fetchedData;
    }

    public function request(IConnection $apiConn, $path)
    {
        $this->setParams($apiConn);
        $this->timer->start();
        $attempt = 1;
        $success = false;

        do {
            $this->enforceRateLimit();
            echo PHP_EOL . $this->tryColors[$attempt - 1] . "Próba $attempt" . "\033[0m" . PHP_EOL;
            $this->ch = curl_init();
            curl_setopt($this->ch, CURLOPT_URL, $this->baseUrl . $path);
            curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, 1);
            $this->setAuthHeaders();
            curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->httpHeader);
            curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($this->ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($this->ch, CURLOPT_TIMEOUT, 300);

            $this->fetchedData = curl_exec($this->ch);
            $this->httpCode = (int) curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
            if ($this->fetchedData === false) {
                if (curl_errno($this->ch) == CURLE_OPERATION_TIMEDOUT) {
                    $attempt++;
                    if ($attempt >= 3) {
                        $this->timer->stop();
                        $pingResult = $this->ping($apiConn);
                        throw new \Exception("Przekroczono czas oczekiwania na odpowiedź serwera po 3 próbach. " . $pingResult, 1);
                    }
                } else {
                    $this->timer->stop();
                    throw new \Exception("Błąd podczas pobierania danych: " . curl_error($this->ch), 1);
                }

                sleep(self::WAIT_TIME_STEP * $attempt);
            } elseif ($this->httpCode === 429) {
                $attempt++;
                $penaltySeconds = $this->compute429PenaltySeconds($attempt);
                $this->applyRateLimitPenalty($penaltySeconds);

                if ($attempt > self::MAX_ATTEMPTS) {
                    $this->timer->stop();
                    throw new \Exception("Osiągnięto limit zapytań API (429) po " . self::MAX_ATTEMPTS . " próbach.", 1);
                }

                echo PHP_EOL . sprintf(
                    "[429 RETRY] %s: próba %d/%d, odczekuję %ds",
                    $this->rateLimitLabel,
                    $attempt,
                    self::MAX_ATTEMPTS,
                    $penaltySeconds
                ) . PHP_EOL;
                sleep($penaltySeconds);
            } else
                $success = true;

            curl_close($this->ch);
        } while ($attempt <= self::MAX_ATTEMPTS && !$success);

        if ($success)
            $this->timer->stop();
    }

    public function requestMulti(IConnection $apiConn, array $paths): array
    {
        $this->setParams($apiConn);
        $results = [];
        $attempts = [];
        $maxAttempts = self::MAX_ATTEMPTS;

        // Initialize attempts
        foreach ($paths as $key => $path) {
            $attempts[$key] = 1;
        }

        $pending = $paths;

        while (!empty($pending)) {
            $this->timer->start(); // Start timing the batch

            $multiHandle = curl_multi_init();
            $handles = [];

            // Prepare handles for pending requests
            foreach ($pending as $key => $path) {
                $this->enforceRateLimit();
                echo PHP_EOL . $this->baseUrl . $path;
                echo PHP_EOL . $this->tryColors[$attempts[$key] - 1] . "Próba " . $attempts[$key] . "\033[0m" . PHP_EOL;
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $path);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                
                // Set authentication for this handle
                $this->setAuthHeaders($ch);
                
                curl_setopt($ch, CURLOPT_HTTPHEADER, $this->httpHeader);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
                curl_setopt($ch, CURLOPT_TIMEOUT, 300);
                curl_multi_add_handle($multiHandle, $ch);
                $handles[$key] = $ch;
            }

            // Execute all requests
            $running = null;
            do {
                curl_multi_exec($multiHandle, $running);
                curl_multi_select($multiHandle);
            } while ($running > 0);

            // Collect results and determine which need retry
            $nextPending = [];
            foreach ($handles as $key => $ch) {
                $content = curl_multi_getcontent($ch);
                $error = curl_errno($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($httpCode === 429) {
                    $this->applyRateLimitPenalty($this->compute429PenaltySeconds($attempts[$key] + 1));
                }

                if ($content === false || $error || $httpCode >= 500 || $httpCode === 429 || empty($content)) {
                    if ($attempts[$key] < $maxAttempts) {
                        $attempts[$key]++;
                        $nextPending[$key] = $paths[$key];
                    } else {
                        $results[$key] = $content; // Save whatever we got (could be false)
                    }
                } else {
                    $results[$key] = $content;
                }
                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);
            }
            curl_multi_close($multiHandle);

            $this->timer->stop(); // Stop timing the batch
            echo "\nCzas batcha: " . $this->timer->getInterval() . "s\n";

            if (!empty($nextPending)) {
                sleep(self::WAIT_TIME_STEP); // Wait before retrying
            }
            $pending = $nextPending;
        }

        // Ensure results are in the same order as input
        ksort($results);

        return $results;
    }

    public function getRequestTime()
    {
        return $this->timer->getInterval();
    }

    private function setAuthHeaders($ch = null)
    {
        $handle = $ch !== null ? $ch : $this->ch;
        
        // Reset headers to default before adding auth headers
        $this->httpHeader = [
            'Accept: application/json',
            'Content-Type: application/json'
        ];
        
        switch (strtoupper($this->authType)) {
            case 'BASIC_AUTH':
                curl_setopt($handle, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($handle, CURLOPT_USERPWD, "$this->auth");
                break;
            case 'WEBAPIKEY':
                array_push($this->httpHeader, $this->auth);
                break;
            default:
                throw new \Exception("Nieznany lub niepoprawny sposób autoryzacji", 1);
        }
    }

    private function setParams(IConnection $apiConn)
    {
        $this->auth = $apiConn->getAuth();
        $this->authType = $apiConn->getAuthType();
        $this->baseUrl = $apiConn->getBaseUrl();
        $this->rpm = $apiConn->getRpm();
        $this->rateLimitKey = hash('sha256', implode('|', [
            (string) $this->baseUrl,
            (string) $this->authType,
            (string) $this->auth,
        ]));
        $this->rateLimitLabel = sprintf('%s|%s', $apiConn->getName(), $this->baseUrl);
    }

    private function enforceRateLimit(): void
    {
        if ($this->rpm === null || $this->rpm <= 0 || $this->rateLimitKey === null) {
            return;
        }

        $effectiveRpm = max(1, (int) floor($this->rpm * self::RATE_LIMIT_SAFETY_FACTOR));

        while (true) {
            $lockHandle = $this->openRateLimitStoreHandle();
            if ($lockHandle === false) {
                $this->enforceRateLimitInProcess($effectiveRpm);
                return;
            }

            if (!flock($lockHandle, LOCK_EX)) {
                fclose($lockHandle);
                usleep(100000);
                continue;
            }

            rewind($lockHandle);
            $rawStore = stream_get_contents($lockHandle);
            $store = json_decode($rawStore ?: '{}', true);
            if (!is_array($store)) {
                $store = [];
            }

            $now = microtime(true);
            $store = $this->pruneRateLimitStore($store, $now);
            $bucket = $this->normalizeRateLimitBucket($store[$this->rateLimitKey] ?? null);
            $window = $bucket['timestamps'];

            $blockedUntil = (float) $bucket['blockedUntil'];
            $blockedForSeconds = max(0.0, $blockedUntil - $now);
            if ($blockedForSeconds > 0) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);

                echo PHP_EOL . sprintf(
                    "[RATE LIMIT] %s: aktywna blokada po 429, oczekiwanie %.2fs",
                    $this->rateLimitLabel,
                    $blockedForSeconds
                ) . PHP_EOL;
                usleep((int) ceil($blockedForSeconds * 1000000));
                continue;
            }

            if (count($window) < $effectiveRpm) {
                $window[] = $now;
                $store[$this->rateLimitKey] = [
                    'timestamps' => $window,
                    'blockedUntil' => 0.0,
                ];

                ftruncate($lockHandle, 0);
                rewind($lockHandle);
                fwrite($lockHandle, json_encode($store));
                fflush($lockHandle);
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                break;
            }

            $oldest = (float) $window[0];
            $waitSeconds = max(0, self::RATE_LIMIT_WINDOW_SECONDS - ($now - $oldest));
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);

            if ($waitSeconds > 0) {
                echo PHP_EOL . sprintf(
                    "[RATE LIMIT] %s: %d/min (efektywnie %d/min), oczekiwanie %.2fs przed kolejnym zapytaniem",
                    $this->rateLimitLabel,
                    $this->rpm,
                    $effectiveRpm,
                    $waitSeconds
                ) . PHP_EOL;
                usleep((int) ceil($waitSeconds * 1000000));
            }
        }
    }

    private function getRateLimitStorePath(): ?string
    {
        $envPath = $_ENV['HURTOWNIA_RATE_LIMIT_STORE_PATH'] ?? getenv('HURTOWNIA_RATE_LIMIT_STORE_PATH');
        if (!empty($envPath) && is_string($envPath) && trim($envPath) !== '') {
            return $envPath;
        }

        return null;
    }

    private function openRateLimitStoreHandle()
    {
        $path = $this->getRateLimitStorePath();
        if ($path === null) {
            return false;
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        if (!is_writable($directory)) {
            return false;
        }

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }

        return $handle;
    }

    private function enforceRateLimitInProcess(int $effectiveRpm): void
    {
        if (!$this->rateLimitStoreFallbackNotified) {
            echo PHP_EOL . sprintf(
                '[RATE LIMIT] %s: brak dostępu do pliku współdzielonego, używam limitu lokalnego procesu',
                $this->rateLimitLabel
            ) . PHP_EOL;
            $this->rateLimitStoreFallbackNotified = true;
        }

        while (true) {
            $now = microtime(true);
            self::$processRateLimitStore = $this->pruneRateLimitStore(self::$processRateLimitStore, $now);
            $bucket = $this->normalizeRateLimitBucket(self::$processRateLimitStore[$this->rateLimitKey] ?? null);
            $window = $bucket['timestamps'];

            $blockedForSeconds = max(0.0, (float) $bucket['blockedUntil'] - $now);
            if ($blockedForSeconds > 0) {
                usleep((int) ceil($blockedForSeconds * 1000000));
                continue;
            }

            if (count($window) < $effectiveRpm) {
                $window[] = $now;
                self::$processRateLimitStore[$this->rateLimitKey] = [
                    'timestamps' => $window,
                    'blockedUntil' => 0.0,
                ];
                break;
            }

            $oldest = (float) $window[0];
            $waitSeconds = max(0, self::RATE_LIMIT_WINDOW_SECONDS - ($now - $oldest));
            if ($waitSeconds > 0) {
                usleep((int) ceil($waitSeconds * 1000000));
            }
        }
    }

    private function pruneRateLimitStore(array $store, float $now): array
    {
        foreach ($store as $key => $bucketData) {
            $bucket = $this->normalizeRateLimitBucket($bucketData);
            $timestamps = $bucket['timestamps'];
            $blockedUntil = (float) $bucket['blockedUntil'];

            $filtered = array_values(array_filter(
                $timestamps,
                static fn($timestamp): bool => is_numeric($timestamp)
                    && ($now - (float) $timestamp) < self::RATE_LIMIT_WINDOW_SECONDS
            ));

            if (empty($filtered) && $blockedUntil <= $now) {
                unset($store[$key]);
                continue;
            }

            $store[$key] = [
                'timestamps' => $filtered,
                'blockedUntil' => $blockedUntil,
            ];
        }

        return $store;
    }

    private function normalizeRateLimitBucket($bucketData): array
    {
        if (is_array($bucketData) && array_key_exists('timestamps', $bucketData)) {
            return [
                'timestamps' => is_array($bucketData['timestamps']) ? $bucketData['timestamps'] : [],
                'blockedUntil' => isset($bucketData['blockedUntil']) && is_numeric($bucketData['blockedUntil'])
                    ? (float) $bucketData['blockedUntil']
                    : 0.0,
            ];
        }

        // Backward compatibility with the previous format where value was just timestamps array.
        if (is_array($bucketData)) {
            return [
                'timestamps' => $bucketData,
                'blockedUntil' => 0.0,
            ];
        }

        return [
            'timestamps' => [],
            'blockedUntil' => 0.0,
        ];
    }

    private function applyRateLimitPenalty(int $penaltySeconds): void
    {
        if ($this->rateLimitKey === null || $penaltySeconds <= 0) {
            return;
        }

        $lockHandle = $this->openRateLimitStoreHandle();
        if ($lockHandle === false) {
            $now = microtime(true);
            $bucket = $this->normalizeRateLimitBucket(self::$processRateLimitStore[$this->rateLimitKey] ?? null);
            $newBlockedUntil = $now + $penaltySeconds;
            $bucket['blockedUntil'] = max((float) $bucket['blockedUntil'], $newBlockedUntil);
            self::$processRateLimitStore[$this->rateLimitKey] = $bucket;
            self::$processRateLimitStore = $this->pruneRateLimitStore(self::$processRateLimitStore, $now);
            return;
        }

        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            return;
        }

        rewind($lockHandle);
        $rawStore = stream_get_contents($lockHandle);
        $store = json_decode($rawStore ?: '{}', true);
        if (!is_array($store)) {
            $store = [];
        }

        $bucket = $this->normalizeRateLimitBucket($store[$this->rateLimitKey] ?? null);
        $now = microtime(true);
        $newBlockedUntil = $now + $penaltySeconds;
        $bucket['blockedUntil'] = max((float) $bucket['blockedUntil'], $newBlockedUntil);
        $store[$this->rateLimitKey] = $bucket;

        $store = $this->pruneRateLimitStore($store, $now);

        ftruncate($lockHandle, 0);
        rewind($lockHandle);
        fwrite($lockHandle, json_encode($store));
        fflush($lockHandle);
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    private function compute429PenaltySeconds(int $attempt): int
    {
        $attemptPenalty = self::RATE_LIMIT_429_BASE_PENALTY_SECONDS * max(1, $attempt);
        $cappedPenalty = min($attemptPenalty, self::RATE_LIMIT_429_MAX_PENALTY_SECONDS);
        $jitter = random_int(0, 3);

        return $cappedPenalty + $jitter;
    }

    private function removeUnprintableChars()
    {
        $this->fetchedData = preg_replace('/[[:cntrl:]]/', '', $this->fetchedData);
    }

    private function ping(IConnection $apiAuth)
    {
        $regex = '/^(https?:\/\/)([a-zA-Z0-9\.\-]+)(:[0-9]+)?$/';
        $matches = [];
        preg_match($regex, $apiAuth->getBaseUrl(), $matches);
        $host = $matches[2];

        if ($matches[2])    
            return shell_exec("ping -c 3 $host");
        
        return "Nieprawidłowy adres URL: " . $host;
    }
}
