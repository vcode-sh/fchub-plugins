<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Actions;

use FChubMultiCurrency\Storage\PreferenceRepository;

defined('ABSPATH') || exit;

final class PersistContextAction
{
    public function __construct(
        private PreferenceRepository $repository,
    ) {
    }

    public function execute(string $currencyCode): void
    {
        $this->repository->saveCookie($currencyCode);

        $userId = get_current_user_id();

        if ($userId > 0) {
            $this->repository->saveUserMeta($userId, $currencyCode);
        }
    }
}
