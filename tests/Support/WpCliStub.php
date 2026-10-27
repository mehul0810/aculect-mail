<?php

declare(strict_types=1);

if ( ! defined('WP_CLI')) {
    define('WP_CLI', true);
}

if ( ! class_exists('WP_CLI')) {
    final class WP_CLI
    {
        /** @var array<int,array{name:string,command:mixed}> */
        public static array $commands = [];

        /** @var array<int,string> */
        public static array $lines = [];

        public static function add_command(string $name, mixed $command): void
        {
            self::$commands[] = [
                'name' => $name,
                'command' => $command,
            ];
        }

        public static function line(string $message): void
        {
            self::$lines[] = $message;
        }

        public static function error(string $message): void
        {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Unit-test exception captures CLI control flow; it is never rendered as HTML.
            throw new \RuntimeException($message);
        }

        public static function reset(): void
        {
            self::$commands = [];
            self::$lines = [];
        }
    }
}
