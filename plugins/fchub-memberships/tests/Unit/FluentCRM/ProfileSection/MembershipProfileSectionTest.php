<?php

declare(strict_types=1);

namespace FluentCrm\App\Models {
    final class Subscriber
    {
        public function __construct(private int $wpUserId)
        {
        }

        public function getWpUserId(): int
        {
            return $this->wpUserId;
        }
    }
}

namespace FluentCrm\App\Services\Html {
    final class TableBuilder
    {
        /** @var array<string, string> */
        private array $header = [];

        /** @var list<array<string, mixed>> */
        private array $rows = [];

        public function setHeader(array $header): void
        {
            $this->header = $header;
        }

        public function addRow(array $row): void
        {
            $this->rows[] = $row;
        }

        public function getHtml(): string
        {
            $html = '<table><thead><tr>';
            foreach ($this->header as $key => $label) {
                $html .= '<th data-column="' . $key . '">' . $label . '</th>';
            }
            $html .= '</tr></thead><tbody>';

            foreach ($this->rows as $row) {
                $html .= '<tr data-membership-row="1">';
                foreach ($this->header as $key => $label) {
                    $html .= '<td data-column="' . $key . '">' . ($row[$key] ?? '') . '</td>';
                }
                $html .= '</tr>';
            }

            return $html . '</tbody></table>';
        }
    }
}

namespace FChubMemberships\Tests\Unit\FluentCRM\ProfileSection {

    use FluentCrm\App\Models\Subscriber;
    use FChubMemberships\FluentCRM\ProfileSection\MembershipProfileSection;
    use FChubMemberships\Tests\Unit\PluginTestCase;

    final class MembershipProfileSectionTest extends PluginTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['_fchub_test_options']['date_format'] = 'Y-m-d';
            $GLOBALS['_fchub_test_options']['time_format'] = 'H:i';
        }

        public function test_three_resource_grants_render_one_plan_row_and_one_drip_progress_block(): void
        {
            $html = $this->render(
                [
                    $this->grant(1, 5, 'post', '101', renewalCount: 2, createdAt: '2026-03-03 10:00:00'),
                    $this->grant(2, 5, 'page', '102', renewalCount: 4, createdAt: '2026-03-01 10:00:00'),
                    $this->grant(3, 5, 'category', '103', renewalCount: 3, createdAt: '2026-03-02 10:00:00'),
                ],
                [5 => $this->plan(5, '<script>Gold & Plus</script>')],
                [5 => [
                    $this->rule(51, 5, 'immediate'),
                    $this->rule(52, 5, 'delayed'),
                    $this->rule(53, 5, 'fixed_date'),
                ]],
                [
                    1 => [$this->drip(501, 1, 52, 'sent')],
                    2 => [$this->drip(502, 2, 53, 'pending')],
                ]
            );

            self::assertSame(1, substr_count($html, 'data-membership-row="1"'));
            self::assertSame(1, substr_count($html, 'items unlocked'));
            self::assertStringContainsString('2 of 3 items unlocked', $html);
            self::assertStringContainsString('data-column="renewals">4 (varies)</td>', $html);
            self::assertStringContainsString('data-column="granted">2026-03-01 10:00</td>', $html);
            self::assertStringNotContainsString('<script>Gold & Plus</script>', $html);
            self::assertStringContainsString('&lt;script&gt;Gold &amp; Plus&lt;/script&gt;', $html);
        }

        public function test_stacked_plans_render_as_separate_rows_in_plan_order(): void
        {
            $html = $this->render(
                [
                    $this->grant(1, 8, 'post', '801'),
                    $this->grant(2, 5, 'post', '501'),
                    $this->grant(3, 8, 'page', '802'),
                ],
                [
                    5 => $this->plan(5, 'Gold Plan'),
                    8 => $this->plan(8, 'Silver Plan'),
                ]
            );

            self::assertSame(2, substr_count($html, 'data-membership-row="1"'));
            self::assertSame(1, substr_count($html, '>Gold Plan</td>'));
            self::assertSame(1, substr_count($html, '>Silver Plan</td>'));
            self::assertLessThan(strpos($html, 'Silver Plan'), strpos($html, 'Gold Plan'));
        }

        public function test_planless_grants_remain_separate_memberships(): void
        {
            $html = $this->render([
                $this->grant(1, null, 'post', '101'),
                $this->grant(2, null, 'page', '202'),
            ]);

            self::assertSame(2, substr_count($html, 'data-membership-row="1"'));
            self::assertSame(2, substr_count($html, '>(No Plan)</td>'));
        }

        public function test_history_group_exposes_mixed_status_expiry_and_renewal_values(): void
        {
            $html = $this->render(
                [
                    $this->grant(
                        1,
                        5,
                        'post',
                        '101',
                        status: 'revoked',
                        renewalCount: 2,
                        expiresAt: null,
                        createdAt: '2026-02-03 10:00:00'
                    ),
                    $this->grant(
                        2,
                        5,
                        'page',
                        '102',
                        status: 'expired',
                        renewalCount: 4,
                        expiresAt: '2026-04-01 00:00:00',
                        createdAt: '2026-02-01 10:00:00'
                    ),
                ],
                [5 => $this->plan(5, 'Archive Plan')]
            );

            self::assertSame(1, substr_count($html, 'data-membership-row="1"'));
            self::assertStringContainsString('Grant History', $html);
            self::assertStringContainsString('Expired (mixed)', $html);
            self::assertStringContainsString('data-column="renewals">4 (varies)</td>', $html);
            self::assertStringContainsString('data-column="expires">Varies</td>', $html);
            self::assertStringContainsString('data-column="granted">2026-02-01 10:00</td>', $html);
        }

        public function test_active_plan_with_mixed_dated_expiries_uses_the_latest_current_expiry(): void
        {
            $html = $this->render(
                [
                    $this->grant(1, 5, 'post', '101', expiresAt: '2026-05-01 00:00:00'),
                    $this->grant(2, 5, 'page', '102', expiresAt: '2026-06-15 00:00:00'),
                ],
                [5 => $this->plan(5, 'Gold Plan')]
            );

            self::assertStringContainsString('data-column="expires">2026-06-15 00:00</td>', $html);
            self::assertStringNotContainsString('data-column="expires">Varies</td>', $html);
        }

        public function test_active_plan_with_lifetime_and_dated_grants_renders_never(): void
        {
            $html = $this->render(
                [
                    $this->grant(1, 5, 'post', '101', expiresAt: null),
                    $this->grant(2, 5, 'page', '102', expiresAt: '2026-06-15 00:00:00'),
                ],
                [5 => $this->plan(5, 'Gold Plan')]
            );

            self::assertStringContainsString('data-column="expires">Never</td>', $html);
            self::assertStringNotContainsString('data-column="expires">Varies</td>', $html);
        }

        public function test_paused_lifetime_grant_does_not_override_active_dated_expiry(): void
        {
            $html = $this->render(
                [
                    $this->grant(
                        1,
                        5,
                        'post',
                        '101',
                        status: 'active',
                        expiresAt: '2026-06-15 00:00:00'
                    ),
                    $this->grant(
                        2,
                        5,
                        'page',
                        '102',
                        status: 'paused',
                        expiresAt: null
                    ),
                ],
                [5 => $this->plan(5, 'Gold Plan')]
            );

            self::assertStringContainsString('data-column="expires">2026-06-15 00:00</td>', $html);
            self::assertStringNotContainsString('data-column="expires">Never</td>', $html);
        }

        public function test_active_grant_with_any_recorded_trial_end_uses_trial_projection_status(): void
        {
            $html = $this->render(
                [
                    $this->grant(
                        1,
                        5,
                        'post',
                        '101',
                        status: 'active',
                        trialEndsAt: '2026-04-15 00:00:00'
                    ),
                ],
                [5 => $this->plan(5, 'Trial Plan')]
            );

            self::assertStringContainsString('>Trial</span>', $html);
            self::assertStringNotContainsString('>Active</span>', $html);
        }

        public function test_drip_progress_counts_every_immediate_rule_as_unlocked(): void
        {
            $html = $this->render(
                [$this->grant(1, 5, 'post', '101')],
                [5 => $this->plan(5, 'Gold Plan')],
                [5 => [
                    $this->rule(51, 5, 'immediate'),
                    $this->rule(52, 5, 'immediate'),
                    $this->rule(53, 5, 'delayed'),
                ]]
            );

            self::assertStringContainsString('2 of 3 items unlocked', $html);
        }

        public function test_drip_progress_ignores_sent_notifications_for_stale_plan_rules(): void
        {
            $html = $this->render(
                [$this->grant(1, 5, 'post', '101')],
                [5 => $this->plan(5, 'Gold Plan')],
                [5 => [
                    $this->rule(51, 5, 'immediate'),
                    $this->rule(52, 5, 'delayed'),
                ]],
                [1 => [$this->drip(501, 1, 99, 'sent')]]
            );

            self::assertStringContainsString('1 of 2 items unlocked', $html);
            self::assertStringNotContainsString('2 of 2 items unlocked', $html);
        }

        /**
         * @param list<array<string, mixed>> $grants
         * @param array<int, array<string, mixed>> $plans
         * @param array<int, list<array<string, mixed>>> $rules
         * @param array<int, list<array<string, mixed>>> $drips
         */
        private function render(array $grants, array $plans = [], array $rules = [], array $drips = []): string
        {
            $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use ($grants, $rules, $drips): array {
                if (str_contains($query, 'fchub_membership_grants') && str_contains($query, 'WHERE user_id')) {
                    return $grants;
                }

                if (str_contains($query, 'fchub_membership_plan_rules')) {
                    preg_match('/plan_id = (\d+)/', $query, $matches);
                    return $rules[(int) ($matches[1] ?? 0)] ?? [];
                }

                if (str_contains($query, 'fchub_membership_drip_notifications')) {
                    preg_match('/grant_id = (\d+)/', $query, $matches);
                    return $drips[(int) ($matches[1] ?? 0)] ?? [];
                }

                return [];
            };
            $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use ($plans): ?array {
                if (!str_contains($query, 'fchub_membership_plans')) {
                    return null;
                }

                preg_match('/id = (\d+)/', $query, $matches);
                return $plans[(int) ($matches[1] ?? 0)] ?? null;
            };

            $section = (new MembershipProfileSection())->getSection([], new Subscriber(21));

            return (string) $section['content_html'];
        }

        /** @return array<string, mixed> */
        private function grant(
            int $id,
            ?int $planId,
            string $resourceType,
            string $resourceId,
            string $status = 'active',
            int $renewalCount = 1,
            ?string $expiresAt = '2026-06-01 00:00:00',
            string $createdAt = '2026-03-01 10:00:00',
            ?string $trialEndsAt = null
        ): array {
            return [
                'id' => $id,
                'user_id' => 21,
                'plan_id' => $planId,
                'provider' => 'wordpress_core',
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'source_id' => 0,
                'feed_id' => null,
                'status' => $status,
                'expires_at' => $expiresAt,
                'trial_ends_at' => $trialEndsAt,
                'renewal_count' => $renewalCount,
                'source_ids' => '[]',
                'meta' => '{}',
                'created_at' => $createdAt,
                'updated_at' => '2026-04-01 12:00:00',
            ];
        }

        /** @return array<string, mixed> */
        private function plan(int $id, string $title): array
        {
            return [
                'id' => $id,
                'title' => $title,
                'slug' => 'plan-' . $id,
                'level' => 0,
                'status' => 'active',
            ];
        }

        /** @return array<string, mixed> */
        private function rule(int $id, int $planId, string $dripType = 'immediate'): array
        {
            return [
                'id' => $id,
                'plan_id' => $planId,
                'drip_type' => $dripType,
                'drip_delay_days' => 0,
                'sort_order' => $id,
                'meta' => '{}',
            ];
        }

        /** @return array<string, mixed> */
        private function drip(int $id, int $grantId, int $ruleId, string $status): array
        {
            return [
                'id' => $id,
                'grant_id' => $grantId,
                'plan_rule_id' => $ruleId,
                'user_id' => 21,
                'status' => $status,
            ];
        }
    }
}
