<?php

namespace FChubMemberships\Modules\Automation;

use FChubMemberships\Core\Container;
use FChubMemberships\Core\Contracts\ModuleInterface;
use FChubMemberships\Integration\FluentCrmSync;

defined('ABSPATH') || exit;

final class FluentCrmAutomationModule implements ModuleInterface
{
    /** @var null|callable(): bool */
    private $capabilityProbe;
    /** @var null|callable(): void */
    private $automationBoot;

    public function __construct(
        private ?bool $fluentCrmInstalled = null,
        ?callable $capabilityProbe = null,
        ?callable $automationBoot = null
    ) {
        $this->capabilityProbe = $capabilityProbe;
        $this->automationBoot = $automationBoot;
    }

    public function key(): string
    {
        return 'fluentcrm_automation';
    }

    public function register(Container $container): void
    {
        add_action('init', [$this, 'bootAutomation'], 30);
    }

    public function bootAutomation(): void
    {
        if (!$this->hasFluentCrm()) {
            return;
        }

        $compatible = $this->capabilityProbe
            ? (bool) ($this->capabilityProbe)()
            : self::isCompatible();
        if (!$compatible) {
            add_action('admin_notices', [$this, 'renderCompatibilityNotice']);
            return;
        }

        if ($this->automationBoot) {
            ($this->automationBoot)();
            return;
        }

        \FChubMemberships\FluentCRM\FluentCrmAutomation::boot();
    }

    public function hasFluentCrm(): bool
    {
        return $this->fluentCrmInstalled ?? defined('FLUENTCRM');
    }

    public static function isCompatible(?callable $classExists = null, ?callable $methodExists = null): bool
    {
        return FluentCrmSync::hasRequiredCapabilities(
            'automation',
            null,
            $classExists,
            $methodExists
        );
    }

    public function renderCompatibilityNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            . esc_html__('FCHub Memberships could not enable FluentCRM automation because the required funnel APIs are unavailable or outdated.', 'fchub-memberships')
            . '</p></div>';
    }
}
