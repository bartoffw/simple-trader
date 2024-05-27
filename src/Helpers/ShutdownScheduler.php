<?php

namespace SimpleTrader\Helpers;

use SimpleTrader\Exceptions\ShutdownException;

class ShutdownScheduler
{
    protected array $callbacks;


    public function __construct()
    {
        $this->callbacks = [];
        register_shutdown_function(array($this, 'callRegisteredShutdown'));
    }

    public function registerShutdownEvent(): void
    {
        $callback = func_get_args();

        if (empty($callback)) {
            throw new ShutdownException('No callback passed to '.__FUNCTION__.' method');
        }
        if (!is_callable($callback[0])) {
            throw new ShutdownException('Invalid callback passed to the '.__FUNCTION__.' method');
        }
        $this->callbacks[] = $callback;
    }

    protected function callRegisteredShutdown(): void
    {
        foreach ($this->callbacks as $arguments) {
            $callback = array_shift($arguments);
            call_user_func_array($callback, $arguments);
        }
    }
}