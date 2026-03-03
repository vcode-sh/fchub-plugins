<?php

declare(strict_types=1);

namespace FChubWishlist\Tests\Unit\Http;

use FChubWishlist\Http\Controllers\Admin\SettingsController;
use FChubWishlist\Support\Constants;
use FChubWishlist\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SettingsControllerTest extends TestCase
{
    #[Test]
    public function testGetReturnsMergedDefaults(): void
    {
        // No saved settings — should return defaults
        $request = new \WP_REST_Request('GET', '/admin/settings');
        $response = SettingsController::get($request);

        $data = $response->get_data();
        $this->assertArrayHasKey('data', $data);

        $settings = $data['data'];
        $this->assertSame('yes', $settings['enabled']);
        $this->assertSame('yes', $settings['guest_wishlist_enabled']);
        $this->assertSame('yes', $settings['auto_remove_purchased']);
        $this->assertSame(100, $settings['max_items_per_list']);
        $this->assertSame('heart', $settings['icon_style']);
    }

    #[Test]
    public function testGetMergesSavedWithDefaults(): void
    {
        $this->setOption(Constants::OPTION_SETTINGS, [
            'enabled'            => 'no',
            'max_items_per_list' => 50,
        ]);

        $request = new \WP_REST_Request('GET', '/admin/settings');
        $response = SettingsController::get($request);

        $data = $response->get_data();
        $settings = $data['data'];

        // Overridden
        $this->assertSame('no', $settings['enabled']);
        $this->assertSame(50, $settings['max_items_per_list']);

        // Defaults still present
        $this->assertSame('yes', $settings['guest_wishlist_enabled']);
        $this->assertSame('heart', $settings['icon_style']);
    }

    #[Test]
    public function testUpdateReturns422ForEmptyBody(): void
    {
        $request = new \WP_REST_Request('PUT', '/admin/settings');
        $request->set_json_params([]);

        $response = SettingsController::update($request);

        $this->assertSame(422, $response->get_status());
    }

    #[Test]
    public function testUpdateSavesValidSettings(): void
    {
        $request = new \WP_REST_Request('PUT', '/admin/settings');
        $request->set_json_params([
            'enabled'            => 'no',
            'max_items_per_list' => 50,
            'icon_style'         => 'star',
        ]);

        $response = SettingsController::update($request);

        $data = $response->get_data();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('message', $data);

        $settings = $data['data'];
        $this->assertSame('no', $settings['enabled']);
        $this->assertSame(50, $settings['max_items_per_list']);
        $this->assertSame('star', $settings['icon_style']);
    }

    #[Test]
    public function testUpdateValidatesToggleFields(): void
    {
        $request = new \WP_REST_Request('PUT', '/admin/settings');
        $request->set_json_params([
            'enabled' => 'invalid_value',
        ]);

        $response = SettingsController::update($request);
        $settings = $response->get_data()['data'];

        // Invalid value should be sanitised to 'no'
        $this->assertSame('no', $settings['enabled']);
    }

    #[Test]
    public function testUpdateValidatesIconStyle(): void
    {
        $request = new \WP_REST_Request('PUT', '/admin/settings');
        $request->set_json_params([
            'icon_style' => 'invalid_icon',
        ]);

        $response = SettingsController::update($request);
        $settings = $response->get_data()['data'];

        // Invalid icon style should be reset to default
        $this->assertSame('heart', $settings['icon_style']);
    }

    #[Test]
    public function testUpdateClampsMaxItems(): void
    {
        $request = new \WP_REST_Request('PUT', '/admin/settings');
        $request->set_json_params([
            'max_items_per_list' => 5000,
        ]);

        $response = SettingsController::update($request);
        $settings = $response->get_data()['data'];

        // Should be clamped to 1000
        $this->assertSame(1000, $settings['max_items_per_list']);
    }

    #[Test]
    public function testUpdatePreservesExistingSettings(): void
    {
        $this->setOption(Constants::OPTION_SETTINGS, [
            'icon_style' => 'star',
        ]);

        $request = new \WP_REST_Request('PUT', '/admin/settings');
        $request->set_json_params([
            'enabled' => 'no',
        ]);

        $response = SettingsController::update($request);
        $settings = $response->get_data()['data'];

        $this->assertSame('no', $settings['enabled']);
        $this->assertSame('star', $settings['icon_style']);
    }
}
