<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\SettingsController;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class SettingsControllerContractTest extends PluginTestCase
{
    public function test_save_persists_paused_restriction_message(): void
    {
        $request = new \WP_REST_Request('POST', '/fchub-memberships/v1/admin/settings', [
            'restriction_message_paused' => 'Paused memberships need a custom message.',
        ]);

        $response = SettingsController::save($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('Paused memberships need a custom message.', $data['data']['restriction_message_paused']);
        $this->assertSame('Paused memberships need a custom message.', SettingsController::getSettings()['restriction_message_paused']);
    }
}
