<?php

declare(strict_types=1);

/**
 * The bare existence of WooCommerce Subscriptions.
 *
 * ProductTypes::supported() gates the two subscription product types on
 * class_exists('WC_Subscriptions') and nothing else, so this empty shell is the
 * whole of what a test needs to describe a store that has the add-on.
 *
 * NEVER require this file from a test that is not process-isolated. A class,
 * once declared, cannot be undeclared, so loading it in the shared process
 * would silently move every later test in the run onto the four-type list —
 * including the several that assert the two-type one. Isolated callers must
 * carry BOTH #[RunInSeparateProcess] and #[PreserveGlobalState(false)]; the
 * first alone re-serialises the parent's declared classes into the child.
 */

if (!class_exists('WC_Subscriptions')) {
    class WC_Subscriptions
    {
    }
}
