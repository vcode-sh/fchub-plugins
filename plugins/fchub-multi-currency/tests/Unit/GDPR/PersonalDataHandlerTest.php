<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\GDPR;

use FChubMultiCurrency\GDPR\PersonalDataHandler;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PersonalDataHandlerTest extends TestCase
{
    #[Test]
    public function testExportUsesPaginationAndMarksDoneFalseWhenPageIsFull(): void
    {
        $user = (object) ['ID' => 77];
        $GLOBALS['wp_mock_users']['email:user@example.com'] = $user;
        $this->setUserMeta(77, '_fchub_mc_currency', 'EUR');

        $events = [];
        for ($i = 1; $i <= 50; $i++) {
            $events[] = (object) [
                'id'         => $i,
                'event'      => 'currency_switched',
                'created_at' => '2026-03-01 12:00:00',
            ];
        }
        $this->setWpdbMockResults($events);

        $result = PersonalDataHandler::exportPersonalData('user@example.com', 1);

        $this->assertFalse($result['done']);
        $this->assertCount(51, $result['data']); // preference + 50 events
    }

    #[Test]
    public function testExportSkipsPreferenceAfterFirstPage(): void
    {
        $user = (object) ['ID' => 77];
        $GLOBALS['wp_mock_users']['email:user@example.com'] = $user;
        $this->setUserMeta(77, '_fchub_mc_currency', 'EUR');

        $this->setWpdbMockResults([
            (object) [
                'id'         => 201,
                'event'      => 'currency_switched',
                'created_at' => '2026-03-02 13:00:00',
            ],
        ]);

        $result = PersonalDataHandler::exportPersonalData('user@example.com', 2);

        $this->assertTrue($result['done']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('fchub-mc-event-201', $result['data'][0]['item_id']);
    }
}
