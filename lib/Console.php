<?php

namespace slackr;


class Console
{
    /**
     * @var bool
     */
    private $debug;

    public function __construct(bool $debug)
    {
        $this->debug = $debug;
    }

    public function debug(string $message)
    {
        if ($this->debug) {
            echo sprintf('DEBUG: %s%s', $message, PHP_EOL);
        }
    }

    public static function info(string $message)
    {
        echo sprintf('%s%s', $message, PHP_EOL);
    }

    public static function error(string $message)
    {
        echo sprintf('ERROR: %s%s', $message, PHP_EOL);
    }
}