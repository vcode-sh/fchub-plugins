<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

defined('ABSPATH') || exit;

use CartShift\Core\Container;
use CartShift\Domain\Subscription\Package\PackageContextRepository;
use CartShift\Domain\Subscription\Package\SubscriptionPackageReader;
use CartShift\Domain\Transfer\Legacy\LegacyCommandPolicy;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The private cross-site package, as the audit screen sees it.
 *
 * ONE OF THESE ENDPOINTS WRITES, AND IT SAYS SO. §11 Phase A: "Saving mapping
 * decisions and accepting a deliberate manual fallback are separate, explicit
 * CartShift configuration writes and must never be described as audit."
 * Preparing a package is the third of those, and what it writes is four
 * strings — source key, absolute private path, records checksum, selection
 * fingerprint — in one CartShift option. It copies nothing, and it creates no
 * customer, no order, no subscription and no ID-map row.
 *
 * That distinction is carried in the response rather than left to a docblock,
 * because the operator pressing this button has just read a screen headed
 * "this mode writes nothing". `write.kind` is `cartshift_configuration`, and
 * the audit endpoint lists the same three actions under
 * `writes.configuration_writes`, so the two screens tell one story.
 *
 * Listing is a read. Forgetting removes the descriptor and leaves the file
 * exactly where it was: a package holds every customer and order in the
 * migration, and forgetting where it is has never been the same as deleting it.
 * Deleting the file itself stays on the command line, where it can prove the
 * checksum still matches before unlinking anything.
 */
final class SubscriptionPackageController
{
    private const string NAMESPACE = 'cartshift/v1';

    private const string WRITE_KIND = 'cartshift_configuration';

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/subscriptions/packages', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/subscriptions/packages/prepare', [
            'methods'             => 'POST',
            'callback'            => [$this, 'prepare'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/subscriptions/packages/forget', [
            'methods'             => 'POST',
            'callback'            => [$this, 'forget'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Which packages this install already knows where to find. A read.
     */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(['data' => [
            'packages' => (new PackageContextRepository())->all(),
            'writes'   => ['nothing' => true],
        ]]);
    }

    /**
     * Remember where a validated package is, so the mapping UI can read its
     * `ProductRecord` candidates across requests.
     *
     * Validated first, and refused when it does not check out. A descriptor
     * pointing at a file that is not a package is worse than no descriptor: the
     * mapping screen would offer candidates nobody can read, and stage would
     * fail at revalidation for a reason that has nothing to do with the data.
     */
    public function prepare(WP_REST_Request $request): WP_REST_Response
    {
        return $this->refuseLegacyWrite('rest:POST:cartshift/v1/subscriptions/packages/prepare');

        $file = trim((string) ($request->get_param('file') ?? ''));

        if ($file === '') {
            return $this->refuse('Preparing a package needs an absolute path to one.', 400);
        }

        $result = (new SubscriptionPackageReader())->validate($file);

        if (!$result['ok'] || $result['manifest'] === null || $result['path'] === null) {
            return $this->refuse(
                sprintf(
                    'Refused to prepare that file: %s. Nothing was written.',
                    implode(', ', array_column($result['failures'], 'code')) ?: 'it is not a readable package',
                ),
                422,
            );
        }

        (new PackageContextRepository())->remember(
            $result['manifest']->sourceKey,
            $result['path'],
            $result['checksum'],
            $result['manifest']->selectionFingerprint,
        );

        return new WP_REST_Response(['data' => [
            'prepared'   => true,
            'source_key' => $result['manifest']->sourceKey,
            'path'       => $result['path'],
            'checksum'   => $result['checksum'],
            'write'      => [
                'kind'    => self::WRITE_KIND,
                'wrote'   => 'Four strings in one CartShift option: source key, private path, records '
                    . 'checksum, selection fingerprint.',
                'did_not' => 'No customer, order, subscription, transaction or ID-map row. The package '
                    . 'itself was not copied.',
            ],
        ]]);
    }

    /**
     * Drop a descriptor. The file stays where it is.
     */
    public function forget(WP_REST_Request $request): WP_REST_Response
    {
        return $this->refuseLegacyWrite('rest:POST:cartshift/v1/subscriptions/packages/forget');

        $sourceKey = trim((string) ($request->get_param('source_key') ?? ''));

        if ($sourceKey === '') {
            return $this->refuse('Forgetting a package needs the source key it was prepared under.', 400);
        }

        if (($request->get_param('confirm') ?? false) === false) {
            return $this->refuse('Confirm before forgetting a prepared package.', 400);
        }

        $forgotten = (new PackageContextRepository())->forget($sourceKey);

        return new WP_REST_Response(['data' => [
            'forgotten'  => $forgotten,
            'source_key' => $sourceKey,
            'message'    => $forgotten
                ? 'Forgotten. The package file is still where it was — deleting it is a separate command '
                . 'that checks the checksum first.'
                : sprintf('Nothing was prepared for "%s".', $sourceKey),
            'write'      => ['kind' => self::WRITE_KIND],
        ]]);
    }

    private function refuseLegacyWrite(string $entryPoint): WP_REST_Response
    {
        return new WP_REST_Response(
            ['data' => (new LegacyCommandPolicy())->refusalPayload($entryPoint)],
            410,
        );
    }

    private function refuse(string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['data' => ['message' => $message, 'prepared' => false]], $status);
    }
}
