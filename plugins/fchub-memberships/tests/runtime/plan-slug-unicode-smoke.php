<?php

use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Domain\Plan\PlanSlug;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "WordPress must be loaded before this runtime smoke test.\n");
    exit(1);
}

$failures = [];
$cases = [
    'Polish' => ['Klub Przyjaciół Psów', 'klub-przyjaciol-psow'],
    'Czech' => ['Přátelé žluťoučkého koně', 'pratele-zlutouckeho-kone'],
    'French' => ['Crème brûlée', 'creme-brulee'],
    'Turkish' => ['İstanbul Üyeliği', 'istanbul-uyeligi'],
    'Vietnamese' => ['Hội viên đặc biệt', 'hoi-vien-dac-biet'],
];

foreach ($cases as $language => [$input, $expected]) {
    $actual = PlanSlug::canonicalize($input);
    if ($actual !== $expected) {
        $failures[] = sprintf('%s: expected %s, got %s', $language, $expected, $actual);
    }
}

$nativeCases = [
    'Greek' => 'Λέσχη φίλων',
    'Cyrillic' => 'Клуб друзей',
    'Arabic' => 'نادي الأصدقاء',
    'Hebrew' => 'מועדון חברים',
    'Hindi' => 'मित्र क्लब',
    'Japanese' => '友達クラブ',
    'Chinese' => '朋友俱乐部',
];

foreach ($nativeCases as $language => $input) {
    $actual = PlanSlug::canonicalize($input);
    if ($actual === '' || !preg_match('/^(?:%[0-9a-f]{2}|[a-z0-9-])+$/', $actual)) {
        $failures[] = sprintf('%s: expected a non-empty encoded WordPress slug, got %s', $language, $actual);
    }
}

$nfc = PlanSlug::canonicalize('Café');
$nfd = PlanSlug::canonicalize("Cafe\u{0301}");
if ($nfc !== 'cafe' || $nfd !== $nfc) {
    $failures[] = sprintf('Unicode normalisation: expected cafe parity, got %s and %s', $nfc, $nfd);
}

$bounded = PlanSlug::canonicalize(str_repeat('朋友', 20));
$suffixed = PlanSlug::appendSuffix($bounded, 12);
if (strlen($bounded) > PlanSlug::MAX_LENGTH || strlen($suffixed) > PlanSlug::MAX_LENGTH || !str_ends_with($suffixed, '-12')) {
    $failures[] = sprintf('Length boundary: got %d-byte base and %d-byte suffixed slug', strlen($bounded), strlen($suffixed));
}

$preview = (new PlanService())->previewSlug('Klub Przyjaciół Psów');
if (($preview['slug'] ?? '') !== 'klub-przyjaciol-psow' || ($preview['mode'] ?? '') !== 'automatic') {
    $failures[] = 'REST service preview did not match the canonical Polish slug.';
}

wp_set_current_user(1);
$request = new WP_REST_Request('GET', '/fchub-memberships/v1/admin/plans/slug-preview');
$request->set_query_params(['title' => 'Klub Przyjaciół Psów']);
$response = rest_do_request($request);
$responseData = $response->get_data();
if ($response->get_status() !== 200 || ($responseData['data']['slug'] ?? '') !== 'klub-przyjaciol-psow') {
    $failures[] = sprintf('REST route: expected canonical Polish preview, got HTTP %d', $response->get_status());
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "PASS: WordPress produced canonical, bounded slugs for 12 scripts and normalisation forms.\n";
