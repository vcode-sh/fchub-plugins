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

if (!class_exists(BaseAction::class)) {
    class BaseAction
    {
        protected $actionName = '';
        protected $priority = 10;

        public function __construct()
        {
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
            return $GLOBALS['fluentcrm_mock_subscriber'] ?? null;
        }

        public static function ifAlreadyInFunnel($funnelId, $subscriberId)
        {
            return $GLOBALS['fluentcrm_mock_already_in_funnel'] ?? false;
        }

        public static function removeSubscribersFromFunnel($funnelId, $subscriberIds)
        {
            $GLOBALS['fluentcrm_removed_from_funnel'][] = [
                'funnel_id' => $funnelId,
                'subscriber_ids' => $subscriberIds,
            ];
        }

        public static function getUpdateOptions()
        {
            return [
                'update'               => 'Update if Exist',
                'skip_all_if_exist'    => 'Skip if Exist',
            ];
        }

        public static function changeFunnelSubSequenceStatus($funnelSubscriberId, $sequenceId, $status)
        {
            $GLOBALS['fluentcrm_sequence_status_changes'][] = [
                'funnel_subscriber_id' => $funnelSubscriberId,
                'sequence_id' => $sequenceId,
                'status' => $status,
            ];
        }
    }
}

if (!class_exists(FunnelProcessor::class)) {
    class FunnelProcessor
    {
        public function startFunnelSequence($funnel, $subscriberData, $context = [])
        {
            $GLOBALS['fluentcrm_funnel_sequences'][] = [
                'funnel' => $funnel,
                'subscriber_data' => $subscriberData,
                'context' => $context,
            ];
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
            if (is_object($array)) {
                $array = (array) $array;
            }
            if (is_array($array) && array_key_exists($key, $array)) {
                return $array[$key];
            }
            return $default;
        }
    }
}

namespace FluentCrm\App\Models;

if (!class_exists(Subscriber::class)) {
    class Subscriber
    {
        public $id = 0;
        public $user_id = 0;
        public $email = '';

        public function getWpUserId(): int
        {
            return (int) $this->user_id;
        }
    }
}

namespace FluentCrm\App\Services\Html;

if (!class_exists(TableBuilder::class)) {
    class TableBuilder
    {
        private array $header = [];
        private array $rows = [];

        public function setHeader(array $header): void
        {
            $this->header = $header;
        }

        public function addRow(array $row): void
        {
            $this->rows[] = $row;
        }

        public function getHtml(): string
        {
            $html = '<table>';
            $html .= '<thead><tr>';
            foreach ($this->header as $key => $label) {
                $html .= '<th>' . $label . '</th>';
            }
            $html .= '</tr></thead>';
            $html .= '<tbody>';
            foreach ($this->rows as $row) {
                $html .= '<tr>';
                foreach ($this->header as $key => $label) {
                    $html .= '<td>' . ($row[$key] ?? '') . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            return $html;
        }
    }
}
