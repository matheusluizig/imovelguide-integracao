<?php

declare(strict_types=1);

namespace Illuminate\Support\Facades {
    class Log
    {
        public static array $logs = [];

        public static function info(string $message, array $context = []): void
        {
            self::$logs[] = ['level' => 'info', 'message' => $message, 'context' => $context];
        }

        public static function warning(string $message, array $context = []): void
        {
            self::$logs[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
        }

        public static function error(string $message, array $context = []): void
        {
            self::$logs[] = ['level' => 'error', 'message' => $message, 'context' => $context];
        }

        public static function reset(): void
        {
            self::$logs = [];
        }
    }

    class Cache
    {
        public static array $store = [];

        public static function get(string $key)
        {
            return self::$store[$key] ?? null;
        }

        public static function put(string $key, $value, $ttl): void
        {
            self::$store[$key] = $value;
        }

        public static function reset(): void
        {
            self::$store = [];
        }
    }
}

namespace Illuminate\Support {
    class Str
    {
        public static function contains($haystack, $needles): bool
        {
            foreach ((array) $needles as $needle) {
                if ($needle === '') {
                    continue;
                }

                if (strpos((string) $haystack, (string) $needle) !== false) {
                    return true;
                }
            }

            return false;
        }
    }
}

namespace Illuminate\Support\Facades {
    use Tests\Stubs\Http\PendingRequest;

    class Http
    {
        public static array $sequence = [];

        public static function setSequence(array $sequence): void
        {
            self::$sequence = $sequence;
        }

        public static function reset(): void
        {
            self::$sequence = [];
        }

        public static function popResponse()
        {
            if (empty(self::$sequence)) {
                throw new \RuntimeException('No HTTP responses configured.');
            }

            $next = array_shift(self::$sequence);

            if ($next instanceof \Closure) {
                return $next();
            }

            return $next;
        }

        public static function timeout(int $seconds): PendingRequest
        {
            return new PendingRequest($seconds);
        }
    }
}

namespace Tests\Stubs\Http {
    use Illuminate\Support\Facades\Http;

    class PendingRequest
    {
        public function __construct(private int $timeout)
        {
        }

        public function retry(int $times, int $sleep): self
        {
            return $this;
        }

        public function withHeaders(array $headers): self
        {
            return $this;
        }

        public function withOptions(array $options): self
        {
            return $this;
        }

        public function get(string $url)
        {
            $next = Http::popResponse();

            if ($next instanceof \Closure) {
                $next = $next($url);
            }

            if ($next instanceof \Exception) {
                throw $next;
            }

            if (is_string($next)) {
                return new Response(true, 200, $next);
            }

            return $next;
        }
    }

    class Response
    {
        public function __construct(
            private bool $successful = true,
            private int $status = 200,
            private string $body = '',
            private array $headers = []
        ) {
        }

        public function successful(): bool
        {
            return $this->successful;
        }

        public function status(): int
        {
            return $this->status;
        }

        public function body(): string
        {
            return $this->body;
        }

        public function headers(): array
        {
            return $this->headers;
        }
    }
}

namespace Illuminate\Http\Client {
    class ConnectionException extends \Exception
    {
    }

    class RequestException extends \Exception
    {
    }
}

namespace App\Integracao\Application\Services {
    class XMLIntegrationLoggerService
    {
        public function __construct(...$args)
        {
        }

        public function loggerErrWarn(string $problem): void
        {
        }

        public function loggerDone(int $total, int $countDone, string $problems = ''): void
        {
        }
    }
}

namespace App\Integracao\Infrastructure\Repositories {
    class IntegrationRepository
    {
    }
}

namespace App\Integracao\Domain\Entities {
    class Integracao
    {
        public int $id = 0;
        public string $link = '';
        public $user;
        public $system;

        public function save(): void
        {
        }
    }
}
