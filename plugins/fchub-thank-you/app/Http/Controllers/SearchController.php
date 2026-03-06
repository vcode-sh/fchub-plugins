<?php

declare(strict_types=1);

namespace FchubThankYou\Http\Controllers;

final class SearchController
{
    public function search(\WP_REST_Request $request): \WP_REST_Response
    {
        $postType = sanitize_key((string) ($request->get_param('post_type') ?? 'page'));
        $search   = sanitize_text_field((string) ($request->get_param('s') ?? ''));

        $args = [
            'post_type'      => $postType,
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        if ($search !== '') {
            $args['s'] = $search;
        }

        $query   = new \WP_Query($args);
        $results = [];

        /** @var \WP_Post $post */
        foreach ($query->posts as $post) {
            $results[] = [
                'id'        => $post->ID,
                'title'     => get_the_title($post->ID),
                'permalink' => get_permalink($post->ID) ?: '',
                'post_type' => $post->post_type,
            ];
        }

        return new \WP_REST_Response($results, 200);
    }

    public function postTypes(\WP_REST_Request $request): \WP_REST_Response
    {
        /** @var array<string, \WP_Post_Type> $types */
        $types  = get_post_types(['public' => true], 'objects');
        $result = [];

        foreach ($types as $slug => $type) {
            if ($slug === 'attachment') {
                continue;
            }
            $result[] = [
                'slug'  => $slug,
                'label' => $type->labels->name ?? $type->label,
            ];
        }

        return new \WP_REST_Response($result, 200);
    }
}
