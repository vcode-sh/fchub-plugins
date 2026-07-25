<?php

namespace FChubHub\Http;

use Closure;
use FChubHub\Operations\OperationError;
use FChubHub\Operations\ProductOperationService;
use UnexpectedValueException;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * Turns operations into responses, and failures into sentences.
 *
 * Two shapes leave this class and no others. A success carries the whole
 * refreshed picture — products, summary, catalogue provenance, and what this
 * account is allowed to do — plus a notice when something actually changed. A
 * failure carries a stable code, one friendly sentence, and the product it was
 * about. Filesystem paths, stack traces, remote bodies, and WordPress's own
 * internal error strings stay on the server, where the debug log can have them.
 */
final class ProductController
{
    /** Action id as it appears in the URL, mapped to the operation behind it. */
    public const ACTIONS = [
        'install' => 'install',
        'install-and-activate' => 'installAndActivate',
        'activate' => 'activate',
        'update' => 'update',
        'deactivate' => 'deactivate',
    ];

    /**
     * What each failure means in HTTP terms. 409 covers the "the site is not in
     * the right state for this" family, 502 the "upstream did not co-operate"
     * one.
     */
    private const STATUSES = [
        'insufficient_capability' => 403,
        'product_unknown' => 404,
        'operation_unknown' => 404,
        'product_incompatible' => 409,
        'product_already_installed' => 409,
        'product_not_installed' => 409,
        'product_already_active' => 409,
        'product_not_active' => 409,
        'update_unavailable' => 409,
        'package_host_not_allowed' => 502,
        'package_unavailable' => 502,
        'checksum_invalid' => 502,
        'package_verification_failed' => 502,
        'installation_failed' => 500,
        'activation_failed' => 500,
        'version_mismatch' => 500,
        'catalogue_unavailable' => 503,
        // 2xx on purpose: the mutation behind this one already succeeded, and
        // only the refreshed view of the site is missing.
        'refresh_failed_after_operation' => 200,
    ];

    /**
     * Failures that can be thrown once files are already on disk.
     *
     * `installation_failed` fires after the upgrader has been and gone,
     * `version_mismatch` means the product is installed at a version nobody
     * asked for, and `activation_failed` includes the install-and-activate that
     * got halfway. All three leave the site changed, so their answers carry the
     * refreshed picture under `state` — otherwise the screen goes on insisting
     * a product sitting in wp-content/plugins/ is not installed, and the next
     * click gets a 409 saying the opposite.
     *
     * Codes rather than throw sites, deliberately: two of these have a branch
     * that changed nothing, and re-reading the site is honest either way.
     */
    private const STATEFUL_FAILURES = [
        'installation_failed',
        'version_mismatch',
        'activation_failed',
    ];

    /**
     * Catalogue fields the interface is allowed to see. Package and checksum
     * URLs are not among them: the browser has no business downloading a
     * release, and an interface that never receives a URL cannot leak one.
     */
    private const PRODUCT_FIELDS = [
        'name',
        'description',
        'version',
        'requires_wp',
        'requires_php',
        'dependencies',
        'docs_url',
        'release_url',
    ];

    /**
     * What FCHub will accept as a slug, in one place. Routes builds both its
     * URL pattern and its argument validator from this, so the route, the
     * validator and this controller cannot drift apart on what a slug is.
     */
    public const SLUG_PATTERN = '[a-z0-9-]{1,64}';

    /**
     * @param Closure(string): void $logger Internal diagnostics sink.
     */
    public function __construct(
        private readonly ProductOperationService $service,
        private readonly Closure $logger
    ) {
    }

    public static function forSite(): self
    {
        return new self(ProductOperationService::forSite(), ProductOperationService::debugLogger());
    }

    public function products(WP_REST_Request $request): WP_REST_Response
    {
        try {
            return $this->envelope($this->service->snapshot());
        } catch (UnexpectedValueException $error) {
            return $this->report($this->damagedCatalogue($error), null);
        }
    }

    public function refresh(WP_REST_Request $request): WP_REST_Response
    {
        try {
            // Forced, because a refresh button that consults a cache is
            // decoration rather than a button.
            $snapshot = $this->service->snapshot(true);

            return $this->envelope($snapshot, $this->refreshNotice((string) $snapshot['source']));
        } catch (UnexpectedValueException $error) {
            return $this->report($this->damagedCatalogue($error), null);
        }
    }

    public function operate(string $action, WP_REST_Request $request): WP_REST_Response
    {
        $slug = $this->slug($request);

        try {
            $method = self::ACTIONS[$action] ?? null;

            if ($method === null) {
                throw OperationError::create('operation_unknown', [], $action);
            }

            $outcome = $this->service->{$method}((string) $slug);
        } catch (OperationError $error) {
            return $this->report($error, $slug, state: $this->stateAfter($error));
        } catch (UnexpectedValueException $error) {
            return $this->report($this->damagedCatalogue($error), $slug);
        }

        // Deliberately outside the block above. Past this line the operation
        // has already happened, so this reports a succeeded mutation with a
        // missing view — not a failure — under its own code and a 2xx.
        try {
            return $this->envelope($this->service->snapshot(), (string) $outcome['notice']);
        } catch (UnexpectedValueException $error) {
            return $this->report(
                OperationError::create('refresh_failed_after_operation', [], $error->getMessage()),
                $slug,
                succeeded: true
            );
        }
    }

    /**
     * @param array{source: string, last_refresh: string|null, catalogue: array<string, mixed>, site: array{wp: string, php: string, fluentcart: string|null}, states: array<string, array<string, mixed>>} $snapshot
     */
    private function envelope(array $snapshot, ?string $notice = null): WP_REST_Response
    {
        $payload = $this->payload($snapshot);

        if ($notice !== null) {
            $payload['notice'] = $notice;
        }

        return new WP_REST_Response($payload, 200);
    }

    /**
     * The refreshed picture to send with a failure that already changed the
     * site, or null when this failure changed nothing.
     *
     * A catalogue that has fallen over since the operation is not worth losing
     * the original error for, so it costs the state rather than the answer.
     *
     * @return array<string, mixed>|null
     */
    private function stateAfter(OperationError $error): ?array
    {
        if (!in_array($error->code(), self::STATEFUL_FAILURES, true)) {
            return null;
        }

        try {
            return $this->payload($this->service->snapshot());
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /**
     * @param array{source: string, last_refresh: string|null, catalogue: array<string, mixed>, site: array{wp: string, php: string, fluentcart: string|null}, states: array<string, array<string, mixed>>} $snapshot
     * @return array<string, mixed>
     */
    private function payload(array $snapshot): array
    {
        $products = [];
        $active = 0;
        $updates = 0;
        $issues = 0;

        foreach ($snapshot['states'] as $slug => $state) {
            $catalogueEntry = $snapshot['catalogue']['products'][$slug] ?? [];
            $entry = ['slug' => (string) $slug];

            foreach (self::PRODUCT_FIELDS as $field) {
                $entry[$field] = $catalogueEntry[$field] ?? null;
            }

            // The resolver carries the same slug, so array_merge overwrites
            // this entry's copy with an identical value.
            $products[] = array_merge($entry, $state);

            $active += $state['lifecycle'] === 'active' ? 1 : 0;
            $updates += $state['update'] === 'available' ? 1 : 0;

            // Anything that is not plainly compatible is worth surfacing:
            // `unknown` withholds actions just as firmly as `blocked` does,
            // and a count that ignored it would leave the customer wondering
            // where the buttons went.
            $issues += $state['compatibility'] === 'compatible' ? 0 : 1;
        }

        return [
            'products' => $products,
            'summary' => [
                'active' => $active,
                'updates' => $updates,
                'compatibility_issues' => $issues,
            ],
            'catalogue' => [
                'source' => $snapshot['source'],
                'last_refresh' => $snapshot['last_refresh'],
            ],
            // What this site is, so the System screen can answer "what PHP am
            // I on?" without sending anybody back to Site Health. Three
            // values, no diagnostics dump: these are the ones every
            // compatibility decision on this page was made against.
            'site' => $snapshot['site'],
            // Derived from the same capability map the routes and the service
            // enforce, so this cannot start advertising a button the operation
            // behind it would refuse.
            'capabilities' => [
                'install' => ProductOperationService::userCan('install'),
                'activate' => ProductOperationService::userCan('activate'),
                'update' => ProductOperationService::userCan('update'),
            ],
        ];
    }

    /**
     * The four-key shape, for every response that cannot carry a full envelope.
     *
     * `success` answers exactly one question — did the operation finish? — and
     * is not a synonym for "everything went to plan". A mutation that succeeded
     * and then could not be re-read reports `true` with a code explaining why
     * the envelope is missing, because "did my install happen?" deserves a
     * straight answer.
     *
     * The mirror of that is `state`: a fifth key, present only when the
     * operation did *not* finish but the site changed under it anyway. It holds
     * the same payload a success would have carried, so the screen can tell the
     * truth about a product that is now half-installed instead of being told
     * "nothing happened" about something that plainly did.
     *
     * @param array<string, mixed>|null $state
     */
    private function report(
        OperationError $error,
        ?string $slug,
        bool $succeeded = false,
        ?array $state = null
    ): WP_REST_Response {
        ($this->logger)($error->code() . ': ' . $error->internalContext());

        $payload = [
            'success' => $succeeded,
            'code' => $error->code(),
            'message' => $error->publicMessage(),
            'product' => $slug,
        ];

        if ($state !== null) {
            $payload['state'] = $state;
        }

        return new WP_REST_Response($payload, self::STATUSES[$error->code()] ?? 500);
    }

    /**
     * The bundled catalogue is the last line of the fallback chain, so a throw
     * from it means the plugin's own files are damaged. That is worth saying
     * plainly, and worth logging in full — but the exception text names a path,
     * so it stays on this side of the wire.
     */
    private function damagedCatalogue(UnexpectedValueException $error): OperationError
    {
        return OperationError::create('catalogue_unavailable', [], $error->getMessage());
    }

    private function refreshNotice(string $source): string
    {
        return match ($source) {
            'remote' => __('Catalogue refreshed.', 'fchub'),
            'last_good' => __(
                'The catalogue could not be reached, so this is the last copy FCHub trusted.',
                'fchub'
            ),
            default => __(
                'The catalogue could not be reached, so this is the copy that shipped with FCHub.',
                'fchub'
            ),
        };
    }

    /**
     * A slug or nothing, read from the URL and only the URL.
     *
     * get_param() would search the request body first, so a POST to
     * /products/fchub-p24/install carrying {"slug":"fchub-memberships"} would
     * act on Memberships. Nothing unsafe follows from that — the body value
     * faces the same validation, the catalogue still decides, and the route
     * fixes the capability — but the URL would stop being an honest record of
     * what happened, which is a poor thing for an audit log to discover later.
     */
    private function slug(WP_REST_Request $request): ?string
    {
        $slug = $request->get_url_params()['slug'] ?? null;

        if (!is_string($slug) || preg_match('/^' . self::SLUG_PATTERN . '$/D', $slug) !== 1) {
            return null;
        }

        return $slug;
    }
}
