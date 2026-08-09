<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Actions;

use FChubMultiCurrency\Domain\ValueObjects\PersistContextResult;
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

    /**
     * Persists the preference wherever the current settings and visitor allow, and reports back
     * which channels were actually written. Callers must not assume success: a logged-out visitor
     * with cookie persistence disabled has nowhere to keep a preference.
     */
    public function execute(string $currencyCode): PersistContextResult
    {
        $cookieEnabled = $this->optionStore->get('cookie_enabled', 'yes') === 'yes';
        $accountPersistenceEnabled = $this->optionStore->get('account_persistence_enabled', 'yes') === 'yes';

        $cookieStored = false;
        $userMetaStored = false;

        if ($cookieEnabled) {
            $lifetimeDays = (int) $this->optionStore->get('cookie_lifetime_days', 90);
            $this->repository->saveCookie($currencyCode, $lifetimeDays);
            $cookieStored = true;
        }

        $userId = get_current_user_id();

        if ($userId > 0 && $accountPersistenceEnabled) {
            $this->repository->saveUserMeta($userId, $currencyCode);
            $userMetaStored = true;
        }

        return new PersistContextResult(
            cookieStored: $cookieStored,
            userMetaStored: $userMetaStored,
        );
    }
}
