<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Actions;

use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\PreferenceRepository;

defined('ABSPATH') || exit;

final class PersistContextAction
{
    public function __construct(
        private PreferenceRepository $repository,
        private OptionStore $optionStore,
    ) {
    }

    public function execute(string $currencyCode): void
    {
        $lifetimeDays = (int) $this->optionStore->get('cookie_lifetime_days', 90);
        $this->repository->saveCookie($currencyCode, $lifetimeDays);

        $userId = get_current_user_id();

        if ($userId > 0) {
            $this->repository->saveUserMeta($userId, $currencyCode);
        }
    }
}
