<?php

declare(strict_types=1);

namespace FChubMemberships\FluentCRM\Actions;

defined('ABSPATH') || exit;

use FluentCrm\App\Services\Funnel\FunnelHelper;
use FChubMemberships\Support\Logger;
use Throwable;

final readonly class MembershipActionOutcome
{
    /** @var list<string> */
    private const DETAIL_KEYS = [
        'created',
        'updated',
        'total',
        'failed',
        'revoked',
        'grace_started',
        'retained',
        'affected',
    ];

    public function __construct(
        public bool $success,
        public bool $partial,
        public string $reason,
        public array $details = []
    ) {
    }

    public static function fromGrantResult(array $result): self
    {
        $details = self::resultDetails($result);
        $affected = $details['created'] + $details['updated'];

        if (!empty($result['partial'])) {
            return new self(false, true, 'partial', $details);
        }

        if (!empty($result['blocked'])) {
            return new self(false, false, 'blocked', $details);
        }

        if ($details['failed'] > 0) {
            return new self(false, false, 'failed', $details);
        }

        if ($affected === 0) {
            return new self(false, false, 'zero_rows', $details);
        }

        return new self(true, false, 'complete', $details);
    }

    public static function fromRevokeResult(array $result): self
    {
        $details = self::resultDetails($result);

        if (!empty($result['partial'])) {
            return new self(false, true, 'partial', $details);
        }

        if ($details['retained'] > 0) {
            return new self(false, false, 'retained', $details);
        }

        if ($details['failed'] > 0 || empty($result['success'])) {
            return new self(false, false, 'failed', $details);
        }

        if ($details['grace_started'] > 0) {
            return new self(true, false, 'deferred', $details);
        }

        if ($details['revoked'] === 0) {
            return new self(false, false, 'zero_rows', $details);
        }

        return new self(true, false, 'complete', $details);
    }

    public static function fromAffectedRows(int $count): self
    {
        return new self(
            $count > 0,
            false,
            $count > 0 ? 'complete' : 'zero_rows',
            ['affected' => max(0, $count)]
        );
    }

    public static function fromThrowable(Throwable $exception, ?string $stage = null, int $affected = 0): self
    {
        $details = ['exception_type' => $exception::class];
        if ($stage !== null && $stage !== '') {
            $details['stage'] = $stage;
        }
        if ($affected > 0) {
            $details['affected'] = $affected;
        }

        return new self(false, $affected > 0, 'runtime_exception', $details);
    }

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function skip(int $funnelSubscriberId, int $sequenceId, string $actionName): void
    {
        FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequenceId, 'skipped');

        $payload = [
            'action' => $actionName,
            'reason' => $this->reason,
            'partial' => $this->partial,
            'details' => $this->details,
        ];

        Logger::error(
            'FluentCRM membership action skipped',
            'A membership mutation did not complete.',
            $payload
        );

        do_action('fchub_memberships/fluentcrm_action_failed', $actionName, $this);
    }

    private static function resultDetails(array $result): array
    {
        $details = [];
        foreach (self::DETAIL_KEYS as $key) {
            $details[$key] = max(0, (int) ($result[$key] ?? 0));
        }

        return $details;
    }
}
