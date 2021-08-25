<?php

namespace bychekru\git_ranker;

class Profiler {
    private static $startTime;
    /**
     * Starts calculating time
     */
    public static function start() {
        self::$startTime = microtime(1);
    }

    /**
     * Gives the result
     */
    public static function time() {
        return microtime(1) - self::$startTime;
    }
}
