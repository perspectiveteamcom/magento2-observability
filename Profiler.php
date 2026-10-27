<?php
declare(strict_types=1);
namespace Perspective\Observability;

final class Profiler
{
    private static ?\ExcimerProfiler $profiler = null;
    private static ?\ExcimerTimer $timer = null;
    private static float $started = 0;
    private static float $retryAt = 0;
    private static bool $busy = false;
    private static bool $closing = false;

    public static function register(): void
    {
        if (self::$profiler || !extension_loaded('excimer') || !extension_loaded('curl')
            || !filter_var(getenv('OBSERVABILITY_PROFILING_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        self::guard(static function (): void {
            $configuredRatio = getenv('OBSERVABILITY_PROFILING_RATIO');
            $ratio = max(0, min(1, (float) ($configuredRatio === false || $configuredRatio === '' ? '0.1' : $configuredRatio)));
            // Do not advance the application's mt_rand() sequence for telemetry sampling.
            if (PHP_SAPI !== 'cli' && random_int(0, PHP_INT_MAX) / PHP_INT_MAX >= $ratio) {
                return;
            }
            self::$profiler = new \ExcimerProfiler();
            self::$profiler->setEventType(EXCIMER_CPU);
            self::$profiler->setPeriod(1 / 19);
            self::$profiler->setMaxDepth(128);
            self::$profiler->setFlushCallback(static function ($log): void {
                self::guard(static function () use ($log): void { self::send($log); });
            }, 285);
            self::$started = microtime(true);
            self::$profiler->start();
            self::$timer = new \ExcimerTimer();
            self::$timer->setPeriod(15);
            self::$timer->setCallback(static function (): void {
                self::guard(static function (): void {
                    if (!self::$busy && !self::$closing) {
                        self::send(self::$profiler->flush());
                    }
                });
            });
            self::$timer->start();
            register_shutdown_function(static function (): void {
                self::guard(static function (): void {
                    self::$closing = true;
                    self::$timer->stop();
                    self::$profiler->stop();
                    self::$profiler->clearFlushCallback();
                    self::send(self::$profiler->flush());
                });
            });
        });
    }

    private static function guard(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $error) {
            self::$closing = true;
            self::$busy = false;
            // Timer and shutdown callbacks run outside Hook's protection.
            foreach ([self::$timer, self::$profiler] as $source) {
                if ($source) {
                    try {
                        $source->stop();
                    } catch (\Throwable $stopError) {
                        // A failed native stop must not replace an application exception.
                    }
                }
            }
        }
    }

    private static function send(\ExcimerLog $log): void
    {
        if (self::$busy) {
            return;
        }
        self::$busy = true;
        $until = microtime(true);
        $curl = null;
        try {
            self::$profiler->stop();
            if ($log->getEventCount() === 0 || $until < self::$retryAt) {
                return;
            }
            $folded = $log->formatCollapsed();
            if (defined('BP')) {
                $folded = str_replace(BP . '/', 'magento/', $folded);
            }
            $payload = json_encode(['from' => self::$started, 'until' => $until, 'sample_rate' => 19,
                'runtime' => PHP_SAPI === 'cli' ? 'cli' : 'web', 'folded' => $folded], JSON_THROW_ON_ERROR);
            if (strlen($payload) > 512 * 1024) {
                return;
            }
            $endpoint = getenv('OBSERVABILITY_PROFILING_ENDPOINT') ?: 'http://profile-gateway:8080/v1/profiles';
            $curl = curl_init($endpoint);
            if ($curl === false) { throw new \RuntimeException('Cannot create profile transport'); }
            $configured = curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $data): int { return strlen($data); },
                CURLOPT_CONNECTTIMEOUT_MS => 50, CURLOPT_TIMEOUT_MS => 100, CURLOPT_NOSIGNAL => true]);
            if (!$configured) { throw new \RuntimeException('Cannot configure profile transport'); }
            curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status !== 202) {
                self::$retryAt = $until + 5;
            }
        } catch (\Throwable $error) {
            self::$retryAt = $until + 5;
        } finally {
            if (PHP_VERSION_ID < 80000 && $curl !== null && $curl !== false) {
                try { curl_close($curl); } catch (\Throwable $error) {}
            }
            self::$started = microtime(true);
            self::$busy = false;
            if (!self::$closing) {
                // A failing restart is caught by the callback's outer guard.
                self::$profiler->start();
            }
        }
    }
}
