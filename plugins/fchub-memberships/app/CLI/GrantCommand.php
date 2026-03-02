<?php

namespace FChubMemberships\CLI;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Storage\PlanRuleRepository;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Reports\MemberStatsReport;
use WP_CLI;
use WP_CLI\Utils;
use WP_User;

/**
 * Manage membership grants, access, and reporting.
 *
 * ## EXAMPLES
 *
 *     # List active grants for a user
 *     wp fchub-membership list-grants --member=admin@example.com --status=active
 *
 *     # Grant a plan to a user
 *     wp fchub-membership grant --member=42 --plan=premium
 *
 *     # Check access for a user
 *     wp fchub-membership check --member=42 --plan=premium
 *
 *     # Run daily stats aggregation
 *     wp fchub-membership stats --period=30d
 */
class GrantCommand
{
    private GrantRepository $grantRepo;
    private PlanRepository $planRepo;
    private PlanRuleRepository $ruleRepo;
    private DripScheduleRepository $dripRepo;

    public function __construct()
    {
        $this->grantRepo = new GrantRepository();
        $this->planRepo = new PlanRepository();
        $this->ruleRepo = new PlanRuleRepository();
        $this->dripRepo = new DripScheduleRepository();
    }

    /**
     * List grants for a user.
     *
     * ## OPTIONS
     *
     * --member=<id|email>
     * : User ID or email address.
     *
     * [--status=<status>]
     * : Filter by grant status (active, expired, revoked).
     *
     * [--plan=<slug>]
     * : Filter by plan slug.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp fchub-membership list-grants --member=admin@example.com
     *     wp fchub-membership list-grants --member=42 --status=active --format=json
     *
     * @subcommand list-grants
     */
    public function list_grants($args, $assoc_args): void
    {
        $user = $this->resolveUser($assoc_args['member'] ?? '');

        $filters = [];
        if (!empty($assoc_args['status'])) {
            $filters['status'] = $assoc_args['status'];
        }

        if (!empty($assoc_args['plan'])) {
            $plan = $this->planRepo->findBySlug($assoc_args['plan']);
            if (!$plan) {
                WP_CLI::error(sprintf('Plan "%s" not found.', $assoc_args['plan']));
            }
            $filters['plan_id'] = $plan['id'];
        }

        $grants = $this->grantRepo->getByUserId($user->ID, $filters);

        if (empty($grants)) {
            WP_CLI::warning('No grants found for this user.');
            return;
        }

        $format = $assoc_args['format'] ?? 'table';
        $items = array_map(function ($grant) {
            $planTitle = '';
            if ($grant['plan_id']) {
                $plan = $this->planRepo->find($grant['plan_id']);
                $planTitle = $plan ? $plan['title'] : '(deleted)';
            }

            return [
                'ID'           => $grant['id'],
                'Plan'         => $planTitle,
                'Provider'     => $grant['provider'],
                'Resource'     => $grant['resource_type'] . ':' . $grant['resource_id'],
                'Status'       => $grant['status'],
                'Source'       => $grant['source_type'] . ':' . $grant['source_id'],
                'Expires'      => $grant['expires_at'] ?? 'never',
                'Created'      => $grant['created_at'],
            ];
        }, $grants);

        Utils\format_items($format, $items, ['ID', 'Plan', 'Provider', 'Resource', 'Status', 'Source', 'Expires', 'Created']);
    }

    /**
     * Grant a membership plan to a user.
     *
     * ## OPTIONS
     *
     * --member=<id|email>
     * : User ID or email address.
     *
     * --plan=<slug>
     * : Plan slug.
     *
     * [--expires=<date>]
     * : Expiration date (YYYY-MM-DD format).
     *
     * [--source=<source>]
     * : Source type for the grant.
     * ---
     * default: manual
     * ---
     *
     * ## EXAMPLES
     *
     *     wp fchub-membership grant --member=42 --plan=premium
     *     wp fchub-membership grant --member=admin@example.com --plan=basic --expires=2027-01-01
     */
    public function grant($args, $assoc_args): void
    {
        $user = $this->resolveUser($assoc_args['member'] ?? '');
        $plan = $this->resolvePlan($assoc_args['plan'] ?? '');

        $expiresAt = null;
        if (!empty($assoc_args['expires'])) {
            $expiresAt = $assoc_args['expires'] . ' 23:59:59';
            if (strtotime($expiresAt) === false) {
                WP_CLI::error('Invalid expiration date format. Use YYYY-MM-DD.');
            }
        }

        $sourceType = $assoc_args['source'] ?? 'manual';

        // Get plan rules to create grants
        $rules = $this->ruleRepo->getByPlanId($plan['id']);

        if (empty($rules)) {
            WP_CLI::error(sprintf('Plan "%s" has no content rules defined.', $plan['title']));
        }

        $created = 0;
        $skipped = 0;

        foreach ($rules as $rule) {
            $grantKey = GrantRepository::makeGrantKey(
                $user->ID,
                $rule['provider'],
                $rule['resource_type'],
                $rule['resource_id']
            );

            // Check if grant already exists
            $existing = $this->grantRepo->findByGrantKey($grantKey);
            if ($existing && $existing['status'] === 'active') {
                $skipped++;
                continue;
            }

            $dripAvailableAt = null;
            if ($rule['drip_type'] === 'delayed' && $rule['drip_delay_days'] > 0) {
                $dripAvailableAt = date('Y-m-d H:i:s', strtotime("+{$rule['drip_delay_days']} days"));
            } elseif ($rule['drip_type'] === 'fixed_date' && $rule['drip_date']) {
                $dripAvailableAt = $rule['drip_date'];
            }

            $this->grantRepo->create([
                'user_id'          => $user->ID,
                'plan_id'          => $plan['id'],
                'provider'         => $rule['provider'],
                'resource_type'    => $rule['resource_type'],
                'resource_id'      => $rule['resource_id'],
                'source_type'      => $sourceType,
                'source_id'        => 0,
                'grant_key'        => $grantKey,
                'status'           => 'active',
                'expires_at'       => $expiresAt,
                'drip_available_at' => $dripAvailableAt,
                'source_ids'       => [],
            ]);
            $created++;
        }

        WP_CLI::success(sprintf(
            'Granted plan "%s" to user #%d (%s). Created: %d, Skipped: %d.',
            $plan['title'],
            $user->ID,
            $user->user_email,
            $created,
            $skipped
        ));
    }

    /**
     * Revoke a membership plan from a user.
     *
     * ## OPTIONS
     *
     * --member=<id|email>
     * : User ID or email address.
     *
     * --plan=<slug>
     * : Plan slug.
     *
     * [--reason=<text>]
     * : Reason for revocation.
     *
     * ## EXAMPLES
     *
     *     wp fchub-membership revoke --member=42 --plan=premium
     *     wp fchub-membership revoke --member=admin@example.com --plan=basic --reason="Refund processed"
     */
    public function revoke($args, $assoc_args): void
    {
        $user = $this->resolveUser($assoc_args['member'] ?? '');
        $plan = $this->resolvePlan($assoc_args['plan'] ?? '');
        $reason = $assoc_args['reason'] ?? '';

        $grants = $this->grantRepo->getByUserId($user->ID, [
            'plan_id' => $plan['id'],
            'status'  => 'active',
        ]);

        if (empty($grants)) {
            WP_CLI::error(sprintf('No active grants found for user #%d on plan "%s".', $user->ID, $plan['title']));
        }

        $revoked = 0;
        foreach ($grants as $grant) {
            $meta = $grant['meta'];
            if ($reason) {
                $meta['revoke_reason'] = $reason;
            }
            $meta['revoked_by'] = 'cli';

            $this->grantRepo->update($grant['id'], [
                'status' => 'revoked',
                'meta'   => $meta,
            ]);

            // Remove pending drip notifications
            $this->dripRepo->deleteByGrantId($grant['id']);
            $revoked++;
        }

        WP_CLI::success(sprintf(
            'Revoked %d grant(s) for plan "%s" from user #%d (%s).',
            $revoked,
            $plan['title'],
            $user->ID,
            $user->user_email
        ));
    }

    /**
     * Revoke all grants associated with an order.
     *
     * ## OPTIONS
     *
     * --order=<id>
     * : FluentCart order ID.
     *
     * [--dry-run]
     * : Preview changes without applying them.
     *
     * ## EXAMPLES
     *
     *     wp fchub-membership revoke-by-order --order=123
     *     wp fchub-membership revoke-by-order --order=123 --dry-run
     *
     * @subcommand revoke-by-order
     */
    public function revoke_by_order($args, $assoc_args): void
    {
        $orderId = (int) ($assoc_args['order'] ?? 0);
        if ($orderId <= 0) {
            WP_CLI::error('Please provide a valid --order=<id>.');
        }

        $dryRun = Utils\get_flag_value($assoc_args, 'dry-run', false);
        $grants = $this->grantRepo->getBySourceId($orderId, 'order');

        if (empty($grants)) {
            WP_CLI::warning(sprintf('No grants found linked to order #%d.', $orderId));
            return;
        }

        WP_CLI::line(sprintf('Found %d grant(s) linked to order #%d.', count($grants), $orderId));

        foreach ($grants as $grant) {
            $label = sprintf(
                '  Grant #%d: user #%d, %s:%s, status=%s',
                $grant['id'],
                $grant['user_id'],
                $grant['resource_type'],
                $grant['resource_id'],
                $grant['status']
            );

            if ($dryRun) {
                WP_CLI::line('[DRY RUN] Would revoke: ' . $label);
                continue;
            }

            if ($grant['status'] !== 'active') {
                WP_CLI::line('Skipping (not active): ' . $label);
                continue;
            }

            $this->grantRepo->update($grant['id'], [
                'status' => 'revoked',
                'meta'   => array_merge($grant['meta'], [
                    'revoke_reason' => 'Order revocation via CLI',
                    'revoked_by'    => 'cli',
                ]),
            ]);
            $this->dripRepo->deleteByGrantId($grant['id']);
            WP_CLI::line('Revoked: ' . $label);
        }

        if ($dryRun) {
            WP_CLI::warning('Dry run complete. No changes were made.');
        } else {
            WP_CLI::success('Order grants revoked.');
        }
    }

    /**
     * Check if a user has access to a resource or plan.
     *
     * ## OPTIONS
     *
     * --member=<id|email>
     * : User ID or email address.
     *
     * [--resource-type=<type>]
     * : Resource type (e.g. post, page, course).
     *
     * [--resource-id=<id>]
     * : Resource ID.
     *
     * [--plan=<slug>]
     * : Check if user has an active grant for this plan.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp fchub-membership check --member=42 --plan=premium
     *     wp fchub-membership check --member=42 --resource-type=post --resource-id=100
     */
    public function check($args, $assoc_args): void
    {
        $user = $this->resolveUser($assoc_args['member'] ?? '');
        $format = $assoc_args['format'] ?? 'table';

        if (!empty($assoc_args['plan'])) {
            $plan = $this->resolvePlan($assoc_args['plan']);
            $grants = $this->grantRepo->getByUserId($user->ID, [
                'plan_id' => $plan['id'],
                'status'  => 'active',
            ]);

            $hasAccess = !empty($grants);

            $items = [[
                'User'   => sprintf('#%d (%s)', $user->ID, $user->user_email),
                'Check'  => sprintf('Plan: %s', $plan['title']),
                'Access' => $hasAccess ? 'YES' : 'NO',
                'Grants' => count($grants),
            ]];

            Utils\format_items($format, $items, ['User', 'Check', 'Access', 'Grants']);
            return;
        }

        if (empty($assoc_args['resource-type']) || empty($assoc_args['resource-id'])) {
            WP_CLI::error('Provide either --plan=<slug> or both --resource-type and --resource-id.');
        }

        $resourceType = $assoc_args['resource-type'];
        $resourceId = $assoc_args['resource-id'];

        // Check all providers
        $providers = ['wordpress_core', 'learndash'];
        $results = [];

        foreach ($providers as $provider) {
            $hasAccess = $this->grantRepo->hasAccessibleGrant($user->ID, $provider, $resourceType, $resourceId);
            if ($hasAccess) {
                $grant = $this->grantRepo->getActiveGrant($user->ID, $provider, $resourceType, $resourceId);
                $results[] = [
                    'Provider' => $provider,
                    'Resource' => $resourceType . ':' . $resourceId,
                    'Access'   => 'YES',
                    'Grant ID' => $grant ? $grant['id'] : '-',
                    'Expires'  => $grant ? ($grant['expires_at'] ?? 'never') : '-',
                ];
            }
        }

        if (empty($results)) {
            $results[] = [
                'Provider' => '*',
                'Resource' => $resourceType . ':' . $resourceId,
                'Access'   => 'NO',
                'Grant ID' => '-',
                'Expires'  => '-',
            ];
        }

        Utils\format_items($format, $results, ['Provider', 'Resource', 'Access', 'Grant ID', 'Expires']);
    }

    /**
     * Backfill grants from historical orders for a product.
     *
     * ## OPTIONS
     *
     * --product=<id>
     * : FluentCart product ID to backfill from.
     *
     * [--dry-run]
     * : Preview changes without applying them.
     *
     * [--limit=<n>]
     * : Maximum number of orders to process.
     * ---
     * default: 0
     * ---
     *
     * ## EXAMPLES
     *
     *     wp fchub-membership backfill --product=5 --dry-run
     *     wp fchub-membership backfill --product=5 --limit=100
     */
    public function backfill($args, $assoc_args): void
    {
        global $wpdb;

        $productId = (int) ($assoc_args['product'] ?? 0);
        if ($productId <= 0) {
            WP_CLI::error('Please provide a valid --product=<id>.');
        }

        $dryRun = Utils\get_flag_value($assoc_args, 'dry-run', false);
        $limit = (int) ($assoc_args['limit'] ?? 0);

        // Find membership feeds linked to this product
        $feedsTable = $wpdb->prefix . 'fct_order_integration_feeds';
        $feeds = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$feedsTable}
             WHERE product_id = %d AND integration_key = 'memberships'",
            $productId
        ), ARRAY_A);

        if (empty($feeds)) {
            WP_CLI::error(sprintf('No membership integration feeds found for product #%d.', $productId));
        }

        WP_CLI::line(sprintf('Found %d feed(s) for product #%d.', count($feeds), $productId));

        $ordersTable = $wpdb->prefix . 'fct_orders';
        $orderItemsTable = $wpdb->prefix . 'fct_order_items';

        $totalProcessed = 0;
        $totalCreated = 0;
        $totalSkipped = 0;

        foreach ($feeds as $feed) {
            $feedSettings = json_decode($feed['settings'] ?? '{}', true) ?: [];
            $planSlug = $feedSettings['plan_slug'] ?? '';

            if (!$planSlug) {
                WP_CLI::warning(sprintf('Feed #%d has no plan_slug configured. Skipping.', $feed['id']));
                continue;
            }

            $plan = $this->planRepo->findBySlug($planSlug);
            if (!$plan) {
                WP_CLI::warning(sprintf('Plan "%s" not found for feed #%d. Skipping.', $planSlug, $feed['id']));
                continue;
            }

            $rules = $this->ruleRepo->getByPlanId($plan['id']);
            if (empty($rules)) {
                WP_CLI::warning(sprintf('Plan "%s" has no rules. Skipping.', $plan['title']));
                continue;
            }

            // Get completed orders for this product
            $sql = "SELECT DISTINCT o.id AS order_id, o.user_id
                    FROM {$ordersTable} o
                    JOIN {$orderItemsTable} oi ON o.id = oi.order_id
                    WHERE oi.product_id = %d AND o.status = 'completed' AND o.user_id > 0
                    ORDER BY o.id ASC";
            $params = [$productId];

            if ($limit > 0) {
                $sql .= $wpdb->prepare(' LIMIT %d', $limit);
            }

            $orders = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

            $progress = Utils\make_progress_bar(
                sprintf('Processing feed #%d (%s)', $feed['id'], $plan['title']),
                count($orders)
            );

            foreach ($orders as $order) {
                $totalProcessed++;
                $userId = (int) $order['user_id'];
                $orderId = (int) $order['order_id'];

                foreach ($rules as $rule) {
                    $grantKey = GrantRepository::makeGrantKey(
                        $userId,
                        $rule['provider'],
                        $rule['resource_type'],
                        $rule['resource_id']
                    );

                    $existing = $this->grantRepo->findByGrantKey($grantKey);
                    if ($existing) {
                        $totalSkipped++;
                        continue;
                    }

                    if ($dryRun) {
                        WP_CLI::line(sprintf(
                            '[DRY RUN] Would create grant: user #%d, plan "%s", %s:%s (order #%d)',
                            $userId,
                            $plan['title'],
                            $rule['resource_type'],
                            $rule['resource_id'],
                            $orderId
                        ));
                    } else {
                        $this->grantRepo->create([
                            'user_id'       => $userId,
                            'plan_id'       => $plan['id'],
                            'provider'      => $rule['provider'],
                            'resource_type' => $rule['resource_type'],
                            'resource_id'   => $rule['resource_id'],
                            'source_type'   => 'order',
                            'source_id'     => $orderId,
                            'feed_id'       => (int) $feed['id'],
                            'grant_key'     => $grantKey,
                            'status'        => 'active',
                            'source_ids'    => [$orderId],
                        ]);
                    }

                    $totalCreated++;
                }

                $progress->tick();
            }

            $progress->finish();
        }

        WP_CLI::line(sprintf('Processed: %d orders, Created: %d grants, Skipped: %d', $totalProcessed, $totalCreated, $totalSkipped));

        if ($dryRun) {
            WP_CLI::warning('Dry run complete. No changes were made.');
        } else {
            WP_CLI::success('Backfill complete.');
        }
    }

    /**
     * Sync grants for a specific integration feed or plan.
     *
     * ## OPTIONS
     *
     * [--feed=<id>]
     * : Integration feed ID.
     *
     * [--plan=<slug>]
     * : Plan slug. Syncs all feeds linked to this plan.
     *
     * [--dry-run]
     * : Preview changes without applying them.
     *
     * ## EXAMPLES
     *
     *     wp fchub-membership sync --feed=10
     *     wp fchub-membership sync --plan=premium
     *     wp fchub-membership sync --feed=10 --dry-run
     */
    public function sync($args, $assoc_args): void
    {
        global $wpdb;

        $feedId = (int) ($assoc_args['feed'] ?? 0);
        $planSlugArg = $assoc_args['plan'] ?? '';
        $dryRun = Utils\get_flag_value($assoc_args, 'dry-run', false);

        if ($feedId > 0 && !empty($planSlugArg)) {
            WP_CLI::error('Provide either --feed or --plan, not both.');
        }

        if ($feedId <= 0 && empty($planSlugArg)) {
            WP_CLI::error('Please provide --feed=<id> or --plan=<slug>.');
        }

        if (!empty($planSlugArg)) {
            $this->syncByPlan($planSlugArg, $dryRun);
            return;
        }

        $feedsTable = $wpdb->prefix . 'fct_order_integration_feeds';
        $feed = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$feedsTable} WHERE id = %d AND integration_key = 'memberships'",
            $feedId
        ), ARRAY_A);

        if (!$feed) {
            WP_CLI::error(sprintf('Membership feed #%d not found.', $feedId));
        }

        $feedSettings = json_decode($feed['settings'] ?? '{}', true) ?: [];
        $planSlug = $feedSettings['plan_slug'] ?? '';

        if (!$planSlug) {
            WP_CLI::error('Feed has no plan_slug configured.');
        }

        $plan = $this->planRepo->findBySlug($planSlug);
        if (!$plan) {
            WP_CLI::error(sprintf('Plan "%s" not found.', $planSlug));
        }

        $rules = $this->ruleRepo->getByPlanId($plan['id']);
        if (empty($rules)) {
            WP_CLI::error(sprintf('Plan "%s" has no rules defined.', $plan['title']));
        }

        // Get all grants for this feed
        $grants = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fchub_membership_grants WHERE feed_id = %d",
            $feedId
        ), ARRAY_A);

        WP_CLI::line(sprintf('Feed #%d: plan "%s", %d existing grants, %d rules.', $feedId, $plan['title'], count($grants), count($rules)));

        // Check for grants that reference rules no longer in the plan
        $ruleKeys = [];
        foreach ($rules as $rule) {
            $ruleKeys[] = $rule['provider'] . ':' . $rule['resource_type'] . ':' . $rule['resource_id'];
        }

        $orphaned = 0;
        foreach ($grants ?: [] as $grant) {
            $key = $grant['provider'] . ':' . $grant['resource_type'] . ':' . $grant['resource_id'];
            if (!in_array($key, $ruleKeys, true) && $grant['status'] === 'active') {
                if ($dryRun) {
                    WP_CLI::line(sprintf('[DRY RUN] Would revoke orphaned grant #%d (%s)', $grant['id'], $key));
                } else {
                    $this->grantRepo->update((int) $grant['id'], ['status' => 'revoked']);
                }
                $orphaned++;
            }
        }

        WP_CLI::line(sprintf('Orphaned grants to revoke: %d', $orphaned));

        if ($dryRun) {
            WP_CLI::warning('Dry run complete. No changes were made.');
        } else {
            WP_CLI::success('Sync complete.');
        }
    }

    /**
     * Sync all feeds linked to a plan by slug.
     */
    private function syncByPlan(string $planSlug, bool $dryRun): void
    {
        global $wpdb;

        $plan = $this->planRepo->findBySlug($planSlug);
        if (!$plan) {
            WP_CLI::error(sprintf('Plan "%s" not found.', $planSlug));
        }

        $feedsTable = $wpdb->prefix . 'fct_order_integration_feeds';
        $feeds = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$feedsTable} WHERE integration_key = %s",
            'memberships'
        ), ARRAY_A);

        if (empty($feeds)) {
            WP_CLI::error('No membership integration feeds found.');
        }

        $matchingFeedIds = [];
        foreach ($feeds as $feed) {
            $settings = json_decode($feed['settings'] ?? '{}', true) ?: [];
            if (($settings['plan_slug'] ?? '') === $plan['slug']) {
                $matchingFeedIds[] = (int) $feed['id'];
            }
        }

        if (empty($matchingFeedIds)) {
            WP_CLI::error(sprintf('No feeds found linked to plan "%s".', $plan['title']));
        }

        WP_CLI::line(sprintf('Found %d feed(s) for plan "%s".', count($matchingFeedIds), $plan['title']));

        $rules = $this->ruleRepo->getByPlanId($plan['id']);
        if (empty($rules)) {
            WP_CLI::error(sprintf('Plan "%s" has no rules defined.', $plan['title']));
        }

        $ruleKeys = [];
        foreach ($rules as $rule) {
            $ruleKeys[] = $rule['provider'] . ':' . $rule['resource_type'] . ':' . $rule['resource_id'];
        }

        $totalOrphaned = 0;
        foreach ($matchingFeedIds as $fid) {
            $grants = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fchub_membership_grants WHERE feed_id = %d",
                $fid
            ), ARRAY_A);

            WP_CLI::line(sprintf('Feed #%d: %d existing grants, %d rules.', $fid, count($grants ?: []), count($rules)));

            foreach ($grants ?: [] as $grant) {
                $key = $grant['provider'] . ':' . $grant['resource_type'] . ':' . $grant['resource_id'];
                if (!in_array($key, $ruleKeys, true) && $grant['status'] === 'active') {
                    if ($dryRun) {
                        WP_CLI::line(sprintf('[DRY RUN] Would revoke orphaned grant #%d (%s)', $grant['id'], $key));
                    } else {
                        $this->grantRepo->update((int) $grant['id'], ['status' => 'revoked']);
                    }
                    $totalOrphaned++;
                }
            }
        }

        WP_CLI::line(sprintf('Orphaned grants to revoke: %d', $totalOrphaned));

        if ($dryRun) {
            WP_CLI::warning('Dry run complete. No changes were made.');
        } else {
            WP_CLI::success('Sync complete.');
        }
    }

    /**
     * Expire overdue grants and report results.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview changes without applying them.
     *
     * ## EXAMPLES
     *
     *     wp fchub-membership expire-check
     *     wp fchub-membership expire-check --dry-run
     *
     * @subcommand expire-check
     */
    public function expire_check($args, $assoc_args): void
    {
        $dryRun = Utils\get_flag_value($assoc_args, 'dry-run', false);

        if ($dryRun) {
            global $wpdb;
            $now = current_time('mysql');
            $table = $wpdb->prefix . 'fchub_membership_grants';

            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= %s",
                $now
            ));

            WP_CLI::line(sprintf('[DRY RUN] Would expire %d grant(s).', $count));
            WP_CLI::warning('Dry run complete. No changes were made.');
            return;
        }

        $expired = $this->grantRepo->expireOverdueGrants();
        WP_CLI::success(sprintf('Expired %d overdue grant(s).', $expired));
    }

    /**
     * Process pending drip notifications.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview changes without applying them.
     *
     * ## EXAMPLES
     *
     *     wp fchub-membership drip-process
     *     wp fchub-membership drip-process --dry-run
     *
     * @subcommand drip-process
     */
    public function drip_process($args, $assoc_args): void
    {
        $dryRun = Utils\get_flag_value($assoc_args, 'dry-run', false);
        $pending = $this->dripRepo->getPendingNotifications(100);

        if (empty($pending)) {
            WP_CLI::success('No pending drip notifications.');
            return;
        }

        WP_CLI::line(sprintf('Found %d pending drip notification(s).', count($pending)));

        $processed = 0;
        foreach ($pending as $notification) {
            $grant = $this->grantRepo->find($notification['grant_id']);
            $label = sprintf(
                '  Notification #%d: user #%d, grant #%d, rule #%d',
                $notification['id'],
                $notification['user_id'],
                $notification['grant_id'],
                $notification['plan_rule_id']
            );

            if ($dryRun) {
                WP_CLI::line('[DRY RUN] Would process: ' . $label);
                continue;
            }

            if (!$grant || $grant['status'] !== 'active') {
                $this->dripRepo->markFailed($notification['id']);
                WP_CLI::line('Skipped (grant inactive): ' . $label);
                continue;
            }

            $this->dripRepo->markSent($notification['id']);
            $processed++;
            WP_CLI::line('Processed: ' . $label);
        }

        if ($dryRun) {
            WP_CLI::warning('Dry run complete. No changes were made.');
        } else {
            WP_CLI::success(sprintf('Processed %d drip notification(s).', $processed));
        }
    }

    /**
     * Purge expired grants older than specified days.
     *
     * ## OPTIONS
     *
     * [--older-than=<days>]
     * : Only purge grants expired more than this many days ago.
     * ---
     * default: 90
     * ---
     *
     * [--dry-run]
     * : Preview changes without applying them.
     *
     * ## EXAMPLES
     *
     *     wp fchub-membership purge-expired --older-than=180
     *     wp fchub-membership purge-expired --dry-run
     *
     * @subcommand purge-expired
     */
    public function purge_expired($args, $assoc_args): void
    {
        global $wpdb;

        $olderThan = (int) ($assoc_args['older-than'] ?? 90);
        $dryRun = Utils\get_flag_value($assoc_args, 'dry-run', false);
        $table = $wpdb->prefix . 'fchub_membership_grants';

        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$olderThan} days"));

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE status IN ('expired', 'revoked')
               AND updated_at <= %s",
            $cutoff
        ));

        if ($count === 0) {
            WP_CLI::success('No expired grants to purge.');
            return;
        }

        WP_CLI::line(sprintf('Found %d expired/revoked grant(s) older than %d days.', $count, $olderThan));

        if ($dryRun) {
            WP_CLI::warning('Dry run complete. No changes were made.');
            return;
        }

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
             WHERE status IN ('expired', 'revoked')
               AND updated_at <= %s",
            $cutoff
        ));

        WP_CLI::success(sprintf('Purged %d expired/revoked grant(s).', $deleted));
    }

    /**
     * Debug access evaluation for a user and URL.
     *
     * ## OPTIONS
     *
     * --member=<id|email>
     * : User ID or email address.
     *
     * --url=<permalink>
     * : The URL/permalink to check access for.
     *
     * ## EXAMPLES
     *
     *     wp fchub-membership debug --member=42 --url=/premium-course/
     */
    public function debug($args, $assoc_args): void
    {
        $user = $this->resolveUser($assoc_args['member'] ?? '');
        $url = $assoc_args['url'] ?? '';

        if (empty($url)) {
            WP_CLI::error('Please provide a --url=<permalink>.');
        }

        WP_CLI::line(sprintf('Debug access for user #%d (%s)', $user->ID, $user->user_email));
        WP_CLI::line(sprintf('URL: %s', $url));
        WP_CLI::line('---');

        // Resolve URL to post ID
        $postId = url_to_postid($url);
        if ($postId <= 0) {
            // Try with site URL prefix
            $postId = url_to_postid(home_url($url));
        }

        if ($postId <= 0) {
            WP_CLI::warning('Could not resolve URL to a post ID.');
            WP_CLI::line('This URL may be a custom route or external URL.');
            return;
        }

        $post = get_post($postId);
        WP_CLI::line(sprintf('Resolved post: #%d "%s" (type: %s)', $post->ID, $post->post_title, $post->post_type));
        WP_CLI::line('');

        // Check protection rules
        $protectionRepo = new \FChubMemberships\Storage\ProtectionRuleRepository();
        $protection = $protectionRepo->findByResource($post->post_type, (string) $post->ID);

        if (!$protection) {
            WP_CLI::line('Protection: NONE (content is public)');
            WP_CLI::success('Access: ALLOWED (no protection rule)');
            return;
        }

        WP_CLI::line(sprintf('Protection: mode=%s, plans=%s',
            $protection['protection_mode'],
            $protection['plan_ids'] ? implode(',', $protection['plan_ids']) : 'any'
        ));

        // Check grants
        $activeGrants = $this->grantRepo->getByUserId($user->ID, ['status' => 'active']);
        WP_CLI::line(sprintf('Total active grants: %d', count($activeGrants)));

        // Check specific resource access
        $providers = ['wordpress_core', 'learndash'];
        $hasAccess = false;

        foreach ($providers as $provider) {
            $accessible = $this->grantRepo->hasAccessibleGrant($user->ID, $provider, $post->post_type, (string) $post->ID);
            if ($accessible) {
                $grant = $this->grantRepo->getActiveGrant($user->ID, $provider, $post->post_type, (string) $post->ID);
                WP_CLI::line(sprintf('  [%s] Grant #%d: plan_id=%s, expires=%s, drip=%s',
                    $provider,
                    $grant['id'],
                    $grant['plan_id'] ?? 'none',
                    $grant['expires_at'] ?? 'never',
                    $grant['drip_available_at'] ?? 'immediate'
                ));
                $hasAccess = true;
            } else {
                // Check if there's a non-accessible grant (drip not yet available)
                $activeGrant = $this->grantRepo->hasActiveGrant($user->ID, $provider, $post->post_type, (string) $post->ID);
                if ($activeGrant) {
                    $grant = $this->grantRepo->getActiveGrant($user->ID, $provider, $post->post_type, (string) $post->ID);
                    WP_CLI::line(sprintf('  [%s] Grant #%d: DRIP LOCKED until %s',
                        $provider,
                        $grant['id'],
                        $grant['drip_available_at']
                    ));
                }
            }
        }

        WP_CLI::line('');
        if ($hasAccess) {
            WP_CLI::success('Access: ALLOWED');
        } else {
            WP_CLI::error('Access: DENIED');
        }
    }

    /**
     * Show membership statistics.
     *
     * ## OPTIONS
     *
     * [--plan=<slug>]
     * : Filter stats to a specific plan.
     *
     * [--period=<period>]
     * : Time period for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp fchub-membership stats
     *     wp fchub-membership stats --plan=premium --period=90d
     */
    public function stats($args, $assoc_args): void
    {
        $format = $assoc_args['format'] ?? 'table';

        if (!empty($assoc_args['plan'])) {
            $plan = $this->resolvePlan($assoc_args['plan']);
            $this->showPlanStats($plan, $assoc_args['period'] ?? '30d', $format);
            return;
        }

        $report = new MemberStatsReport();
        $overview = $report->getOverview();
        $distribution = $report->getPlanDistribution();

        WP_CLI::line('=== Membership Overview ===');

        $overviewItems = [[
            'Active Members'     => $overview['active_members'],
            'New This Month'     => $overview['new_this_month'],
            'Churned This Month' => $overview['churned_this_month'],
            'Churn Rate'         => $overview['churn_rate'] . '%',
        ]];

        Utils\format_items($format, $overviewItems, ['Active Members', 'New This Month', 'Churned This Month', 'Churn Rate']);

        if (!empty($distribution)) {
            WP_CLI::line('');
            WP_CLI::line('=== Plan Distribution ===');
            $planItems = array_map(function ($d) {
                return [
                    'Plan'    => $d['plan_title'],
                    'Members' => $d['count'],
                ];
            }, $distribution);

            Utils\format_items($format, $planItems, ['Plan', 'Members']);
        }

        // Drip stats
        $pendingDrip = $this->dripRepo->countPending();
        $sentDrip = $this->dripRepo->countSent();
        WP_CLI::line('');
        WP_CLI::line(sprintf('Drip notifications: %d pending, %d sent', $pendingDrip, $sentDrip));

        // Expiring soon
        $expiringSoon = $this->grantRepo->getExpiringSoon(7, 5);
        if (!empty($expiringSoon)) {
            WP_CLI::line('');
            WP_CLI::line('=== Expiring Soon (7 days) ===');
            $expiringItems = array_map(function ($g) {
                return [
                    'User'    => '#' . $g['user_id'],
                    'Plan'    => $g['plan_id'] ?? '-',
                    'Expires' => $g['expires_at'],
                ];
            }, $expiringSoon);

            Utils\format_items($format, $expiringItems, ['User', 'Plan', 'Expires']);
        }
    }

    /**
     * Export members of a plan to a file.
     *
     * ## OPTIONS
     *
     * --plan=<slug>
     * : Plan slug.
     *
     * --format=<format>
     * : Export format.
     * ---
     * default: csv
     * options:
     *   - csv
     *   - json
     * ---
     *
     * --output=<file>
     * : Output file path.
     *
     * ## EXAMPLES
     *
     *     wp fchub-membership export-members --plan=premium --format=csv --output=/tmp/members.csv
     *
     * @subcommand export-members
     */
    public function export_members($args, $assoc_args): void
    {
        $plan = $this->resolvePlan($assoc_args['plan'] ?? '');
        $format = $assoc_args['format'] ?? 'csv';
        $output = $assoc_args['output'] ?? '';

        if (empty($output)) {
            WP_CLI::error('Please provide an --output=<file> path.');
        }

        $grants = $this->grantRepo->getByPlanId($plan['id'], ['status' => 'active']);

        if (empty($grants)) {
            WP_CLI::warning(sprintf('No active members found for plan "%s".', $plan['title']));
            return;
        }

        // Collect unique users
        $userIds = array_unique(array_column($grants, 'user_id'));
        $rows = [];

        foreach ($userIds as $userId) {
            $user = get_userdata($userId);
            if (!$user) {
                continue;
            }

            $userGrants = array_filter($grants, function ($g) use ($userId) {
                return $g['user_id'] === $userId;
            });

            $firstGrant = reset($userGrants);

            $rows[] = [
                'user_id'      => $userId,
                'email'        => $user->user_email,
                'display_name' => $user->display_name,
                'plan'         => $plan['title'],
                'status'       => 'active',
                'source_type'  => $firstGrant['source_type'],
                'grants_count' => count($userGrants),
                'created_at'   => $firstGrant['created_at'],
                'expires_at'   => $firstGrant['expires_at'] ?? '',
            ];
        }

        if ($format === 'json') {
            $content = wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            // CSV
            $fp = fopen($output, 'w');
            if (!$fp) {
                WP_CLI::error(sprintf('Cannot open file for writing: %s', $output));
            }

            // Header
            fputcsv($fp, array_keys($rows[0]));

            foreach ($rows as $row) {
                fputcsv($fp, $row);
            }

            fclose($fp);
            WP_CLI::success(sprintf('Exported %d member(s) to %s', count($rows), $output));
            return;
        }

        // JSON output
        $written = file_put_contents($output, $content);
        if ($written === false) {
            WP_CLI::error(sprintf('Cannot write to file: %s', $output));
        }

        WP_CLI::success(sprintf('Exported %d member(s) to %s', count($rows), $output));
    }

    /**
     * Show stats for a specific plan.
     */
    private function showPlanStats(array $plan, string $period, string $format): void
    {
        $range = $this->parsePeriod($period);

        $activeMembers = $this->grantRepo->countActiveMembers($plan['id']);
        $newMembers = $this->grantRepo->countNewMembers($range['from'], $range['to'], $plan['id']);
        $churnedMembers = $this->grantRepo->countChurnedMembers($range['from'], $range['to'], $plan['id']);
        $ruleCount = $this->ruleRepo->countByPlanId($plan['id']);

        WP_CLI::line(sprintf('=== Plan: %s (%s) ===', $plan['title'], $plan['slug']));

        $items = [[
            'Active Members'  => $activeMembers,
            'New (period)'    => $newMembers,
            'Churned (period)' => $churnedMembers,
            'Content Rules'   => $ruleCount,
            'Status'          => $plan['status'],
        ]];

        Utils\format_items($format, $items, ['Active Members', 'New (period)', 'Churned (period)', 'Content Rules', 'Status']);
    }

    /**
     * Resolve a user identifier (ID or email) to a WP_User object.
     */
    private function resolveUser(string $identifier): WP_User
    {
        if (empty($identifier)) {
            WP_CLI::error('Please provide a --member=<id|email>.');
        }

        if (is_numeric($identifier)) {
            $user = get_userdata((int) $identifier);
        } else {
            $user = get_user_by('email', $identifier);
        }

        if (!$user) {
            WP_CLI::error(sprintf('User "%s" not found.', $identifier));
        }

        return $user;
    }

    /**
     * Resolve a plan slug to a plan array.
     */
    private function resolvePlan(string $slug): array
    {
        if (empty($slug)) {
            WP_CLI::error('Please provide a --plan=<slug>.');
        }

        $plan = $this->planRepo->findBySlug($slug);
        if (!$plan) {
            WP_CLI::error(sprintf('Plan "%s" not found.', $slug));
        }

        return $plan;
    }

    /**
     * Parse a period string into from/to date strings.
     */
    private function parsePeriod(string $period): array
    {
        $to = current_time('mysql');
        $amount = (int) substr($period, 0, -1);
        $unit = substr($period, -1);

        if ($unit === 'm') {
            $from = gmdate('Y-m-d H:i:s', strtotime("-{$amount} months"));
        } else {
            $from = gmdate('Y-m-d H:i:s', strtotime("-{$amount} days"));
        }

        return ['from' => $from, 'to' => $to];
    }
}
