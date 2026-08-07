<?php

declare(strict_types=1);

/**
 * Extra stubs required by the mapper tests, kept out of test-bootstrap.php so the mapper
 * suite can grow its own fixtures without fighting the shared bootstrap.
 *
 * Everything here is guarded, so requiring this file after (or instead of) the bootstrap
 * is always safe.
 */

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!class_exists('WC_DateTime')) {
    /**
     * Faithful stand-in for WooCommerce's WC_DateTime.
     *
     * The behaviour that matters for the mappers is the split between the two renderings,
     * copied from woocommerce/includes/class-wc-datetime.php:
     *
     *   - date($format)   -> gmdate($format, getTimestamp() + getOffset())  — SITE-LOCAL
     *   - getTimestamp()  -> the plain UTC epoch                            — UTC
     *
     * So `date()` is correct for wp_posts.post_date and wrong for anything GMT.
     */
    class WC_DateTime extends DateTime
    {
        protected int $utc_offset = 0;

        /**
         * Set a fixed UTC offset in seconds, standing in for the site timezone.
         */
        public function set_utc_offset(int $offset): void
        {
            $this->utc_offset = $offset;
        }

        public function getOffset(): int
        {
            return $this->utc_offset ?: parent::getOffset();
        }

        public function getOffsetTimestamp(): int
        {
            return $this->getTimestamp() + $this->getOffset();
        }

        /**
         * Render in site-local time, exactly as WC_DateTime::date() does.
         */
        public function date(string $format): string
        {
            return gmdate($format, $this->getOffsetTimestamp());
        }

        public function date_i18n(string $format = 'Y-m-d'): string
        {
            return $this->date($format);
        }
    }
}

if (!function_exists('cartshift_test_wc_date')) {
    /**
     * Build a WC_DateTime at a given UTC instant with a fixed site offset.
     *
     * @param string $utc        UTC instant, e.g. '2024-01-15 10:30:00'.
     * @param int    $offsetHours Site UTC offset in hours (positive east of Greenwich).
     */
    function cartshift_test_wc_date(string $utc, int $offsetHours = 0): WC_DateTime
    {
        $date = new WC_DateTime($utc, new DateTimeZone('UTC'));
        $date->set_utc_offset($offsetHours * HOUR_IN_SECONDS);

        return $date;
    }
}
