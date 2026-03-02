<?php

/**
 * Stub classes for FluentCRM dependencies used in unit tests.
 * These provide minimal implementations so plugin classes can be loaded
 * without the actual FluentCRM plugin installed.
 */

namespace FluentCrm\App\Services\Funnel;

if (!class_exists(BaseTrigger::class)) {
    class BaseTrigger
    {
        protected $triggerName = '';
        protected $actionArgNum = 1;
        protected $priority = 10;

        public function __construct()
        {
        }
    }
}

if (!class_exists(BaseBenchMark::class)) {
    class BaseBenchMark
    {
        protected $triggerName = '';
        protected $actionArgNum = 1;
        protected $priority = 10;

        public function __construct()
        {
        }

        protected function benchmarkTypeField()
        {
            return [];
        }

        protected function canEnterField()
        {
            return [];
        }
    }
}

if (!class_exists(FunnelHelper::class)) {
    class FunnelHelper
    {
        public static function prepareUserData($user)
        {
            return [];
        }

        public static function getSubscriber($email)
        {
            return null;
        }

        public static function ifAlreadyInFunnel($funnelId, $subscriberId)
        {
            return false;
        }

        public static function removeSubscribersFromFunnel($funnelId, $subscriberIds)
        {
        }
    }
}

if (!class_exists(FunnelProcessor::class)) {
    class FunnelProcessor
    {
        public function startFunnelSequence($funnel, $subscriberData, $context = [])
        {
        }

        public function startFunnelFromSequencePoint($benchmark, $subscriber)
        {
        }
    }
}

namespace FluentCrm\Framework\Support;

if (!class_exists(Arr::class)) {
    class Arr
    {
        public static function get($array, $key, $default = null)
        {
            if (is_array($array) && array_key_exists($key, $array)) {
                return $array[$key];
            }
            return $default;
        }
    }
}
