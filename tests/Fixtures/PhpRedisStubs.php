<?php

declare(strict_types=1);

if (!class_exists('RedisException')) {
    class RedisException extends RuntimeException
    {
    }
}

if (!class_exists('Redis')) {
    class Redis
    {
        public const OPT_READ_TIMEOUT = 3;

        public function connect(string $host, int $port = 6379, float|int $timeout = 0): bool
        {
            return true;
        }

        public function pconnect(
            string    $host,
            int       $port = 6379,
            float|int $timeout = 0,
            string    $persistent_id = ''
        ): bool {
            return true;
        }

        public function auth(string $password): bool
        {
            return true;
        }

        public function select(int $db): bool
        {
            return true;
        }

        public function setOption(int $option, mixed $value): bool
        {
            return true;
        }

        public function getOption(int $option): mixed
        {
            return 0;
        }

        public function close(): bool
        {
            return true;
        }

        public function echo(string $message): mixed
        {
            return true;
        }

        public function get(string $key): mixed
        {
            return false;
        }

        public function set(string $key, mixed $value): bool
        {
            return true;
        }

        public function del(string|array $key): int
        {
            return 1;
        }

        public function subscribe(array $channels, callable $callback): bool
        {
            return true;
        }

        public function psubscribe(array $patterns, callable $callback): bool
        {
            return true;
        }

        public function monitor(): bool
        {
            return true;
        }

        public function rawCommand(mixed ...$arguments): mixed
        {
            return false;
        }
    }
}

if (!class_exists('RedisCluster')) {
    class RedisCluster
    {
        public function close(): bool
        {
            return true;
        }

        public function echo(string $message): mixed
        {
            return true;
        }
    }
}
