<?php

namespace FChubHub\Operations;

use RuntimeException;

defined('ABSPATH') || exit;

/**
 * The one failure type every product operation throws, carrying two things
 * that must never be confused with each other:
 *
 *   - a stable public code and a sentence a customer can act on, both safe to
 *     put in an HTTP response;
 *   - an internal context string that exists purely for a debug log, and never
 *     leaves the server.
 *
 * The message table below is the only place FCHub decides how a failure sounds.
 * Scatter those sentences across throw sites and the interface starts
 * apologising in five slightly different dialects.
 *
 * Message keys may carry a dotted suffix — `product_incompatible.php` and
 * `product_incompatible.wp` are different sentences about the same public code.
 * The client sees the code; only the wording changes.
 */
final class OperationError extends RuntimeException
{
    public function __construct(
        private readonly string $publicCode,
        string $publicMessage,
        private readonly string $internalContext = ''
    ) {
        parent::__construct($publicMessage);
    }

    /**
     * @param list<string> $args Fillers for the message template, in order.
     * @param string $context Internal detail for the debug log. Never rendered.
     */
    public static function create(string $messageKey, array $args = [], string $context = ''): self
    {
        return new self(self::codeFor($messageKey), self::messageFor($messageKey, $args), $context);
    }

    public function code(): string
    {
        return $this->publicCode;
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }

    public function internalContext(): string
    {
        return $this->internalContext;
    }

    /**
     * @param list<string> $args
     */
    public static function messageFor(string $messageKey, array $args = []): string
    {
        $messages = self::messages();

        // An unknown key means somebody invented a failure without giving it a
        // sentence. The customer gets the honest catch-all rather than a
        // formatting error dressed up as an apology.
        if (!isset($messages[$messageKey])) {
            return $messages['operation_failed'];
        }

        return $args === [] ? $messages[$messageKey] : vsprintf($messages[$messageKey], $args);
    }

    public static function codeFor(string $messageKey): string
    {
        return explode('.', $messageKey, 2)[0];
    }

    /**
     * Built per call rather than held in a constant, because translations only
     * exist once WordPress has loaded them.
     *
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return [
            'operation_failed' => __('Something went wrong, so nothing was changed.', 'fchub'),
            'operation_unknown' => __('That is not something FCHub knows how to do.', 'fchub'),
            'catalogue_unavailable' => __(
                'The FCHub catalogue could not be read. Reinstalling FCHub should sort it out.',
                'fchub'
            ),
            // Its own code, not a flavour of catalogue_unavailable: the
            // operation already succeeded here, and "did my install happen?"
            // is the one question a public code has to answer without
            // ambiguity.
            'refresh_failed_after_operation' => __(
                'That worked, but FCHub could not read its catalogue afterwards. A page reload should sort it out.',
                'fchub'
            ),
            'product_unknown' => __('That product is not in the FCHub catalogue, so there is nothing to do here.', 'fchub'),
            'insufficient_capability' => __('Your account is not allowed to make that change on this site.', 'fchub'),

            'product_incompatible.php' => __('%1$s needs PHP %2$s before it can be activated.', 'fchub'),
            'product_incompatible.wp' => __('%1$s needs WordPress %2$s before it can be activated.', 'fchub'),
            'product_incompatible.dependency' => __('%1$s needs %2$s to be installed and active first.', 'fchub'),
            'product_incompatible.unknown' => __(
                '%1$s has a requirement FCHub cannot check here, so it was left alone.',
                'fchub'
            ),

            'product_already_installed' => __('%s is already installed.', 'fchub'),
            'product_not_installed' => __('%s is not installed yet.', 'fchub'),
            'product_already_active' => __('%s is already switched on.', 'fchub'),
            'product_not_active' => __('%s is already switched off.', 'fchub'),
            'update_unavailable' => __('There is no newer release of %s to install right now.', 'fchub'),

            'package_host_not_allowed' => __('That download is not a trusted FCHub release, so it was refused.', 'fchub'),
            'package_unavailable' => __(
                'The download could not be reached, so nothing was changed. Worth another go in a minute.',
                'fchub'
            ),
            'checksum_invalid' => __('The release checksum could not be read.', 'fchub'),
            'package_verification_failed' => __(
                'The package did not pass its safety check, so nothing was changed.',
                'fchub'
            ),

            'installation_failed' => __(
                'WordPress could not install that package. No other product was touched.',
                'fchub'
            ),
            'activation_failed.plain' => __('%s could not be switched on, so nothing was changed.', 'fchub'),
            // Install-and-activate that got halfway. Claiming nothing changed
            // here would be a lie the Plugins screen immediately exposes.
            'activation_failed.after_install' => __('%s is installed, but it could not be switched on.', 'fchub'),
            'version_mismatch' => __(
                '%1$s installed, but the files are not the %2$s release we expected. Worth a look on the Plugins screen.',
                'fchub'
            ),
        ];
    }
}
