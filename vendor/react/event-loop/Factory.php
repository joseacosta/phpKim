<?php

namespace React\EventLoop;

class Factory
{
    public static function create()
    {
        // @codeCoverageIgnoreStart
        if (function_exists('event_base_new')) {
        	echo "\nel Loop que se se va a usar es LibEventLoop\n";
            return new LibEventLoop();
        } else if (class_exists('libev\EventLoop')) {
        	echo "\nel Loop que se se va a usar es LibEvLoop\n";
            return new LibEvLoop;
        } else if (class_exists('EventBase')) {
        	echo "\nel Loop que se se va a usar es ExtEventLoop\n";
            return new ExtEventLoop;
        }

        echo "\nel Loop que se se va a usar es StreamSelectLoop\n";
        return new StreamSelectLoop();
        // @codeCoverageIgnoreEnd
    }
}
