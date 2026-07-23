<?php

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

final class AccessCheckRestArguments
{
    public static function all(): array
    {
        $validate = static fn(mixed $value, \WP_REST_Request $request): bool =>
            self::validateRequest($request) === true;

        return [
            'user_id' => [
                'type' => 'integer',
                'minimum' => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => $validate,
            ],
            'email' => [
                'type' => 'string',
                'format' => 'email',
                'maxLength' => 254,
                'sanitize_callback' => 'sanitize_email',
                'validate_callback' => $validate,
            ],
            'plan' => [
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 100,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => $validate,
            ],
            'resource_type' => [
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 50,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => $validate,
            ],
            'resource_id' => [
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 100,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => $validate,
            ],
            'provider' => [
                'type' => 'string',
                'enum' => ['wordpress_core'],
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => $validate,
            ],
        ];
    }

    public static function validateRequest(\WP_REST_Request $request): true|\WP_Error
    {
        $userId = (int) $request->get_param('user_id');
        $email = trim((string) $request->get_param('email'));
        $hasUserId = $userId > 0;
        $hasEmail = $email !== '';

        if (($hasUserId && $hasEmail) || (!$hasUserId && !$hasEmail && !is_user_logged_in())) {
            return self::error('Identify a user by exactly one of user_id or email.');
        }

        $plan = trim((string) $request->get_param('plan'));
        $resourceType = trim((string) $request->get_param('resource_type'));
        $resourceId = trim((string) $request->get_param('resource_id'));
        $provider = trim((string) $request->get_param('provider'));
        $hasPlan = $plan !== '';
        $hasResourceType = $resourceType !== '';
        $hasResourceId = $resourceId !== '';
        $hasProvider = $provider !== '';
        $hasCompleteResource = $hasResourceType && $hasResourceId;

        if (($hasPlan && ($hasResourceType || $hasResourceId || $hasProvider))
            || (!$hasPlan && !$hasCompleteResource)
            || ($hasResourceType xor $hasResourceId)
        ) {
            return self::error('Specify exactly one selector: plan or resource_type with resource_id.');
        }

        return true;
    }

    private static function error(string $message): \WP_Error
    {
        return new \WP_Error('fchub_invalid_access_request', __($message, 'fchub-memberships'), ['status' => 422]);
    }
}
