<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

final class FluentCommunityProRuntimeHarnessContractTest extends TestCase
{
    public function test_provider_cleanup_is_armed_before_mutation_and_verified_before_behaviour_failure(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/runtime/fluentcommunity-pro-runtime-certification.php'
        );

        self::assertIsString($source);

        $badgeAttempt = strpos($source, '$badgeCatalogueMutationAttempted = true;');
        $badgeDispatch = strpos($source, '$badgeResponse = rest_get_server()->dispatch($badgeRequest);');
        $featureAttempt = strpos($source, '$featureConfigMutationAttempted = true;');
        $featureDispatch = strpos($source, '$featureResponse = rest_get_server()->dispatch($featureRequest);');
        $fingerprintCheck = strpos($source, '$cleanupVerified = $after === $before;');
        $behaviourRethrow = strpos($source, 'if ($failure instanceof Throwable) {');

        self::assertIsInt($badgeAttempt);
        self::assertIsInt($badgeDispatch);
        self::assertLessThan($badgeDispatch, $badgeAttempt);
        self::assertIsInt($featureAttempt);
        self::assertIsInt($featureDispatch);
        self::assertLessThan($featureDispatch, $featureAttempt);
        self::assertIsInt($fingerprintCheck);
        self::assertIsInt($behaviourRethrow);
        self::assertLessThan($behaviourRethrow, $fingerprintCheck);
    }
}
