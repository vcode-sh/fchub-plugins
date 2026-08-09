<?php

declare(strict_types=1);

/**
 * Make `CartShift\Support` believe it is running on a host without mbstring.
 *
 * `ext-mbstring` is optional in PHP and this plugin does not require it, so
 * "the mb_ functions are simply not there" is a real production configuration —
 * and the only way an unguarded mb_strlen() call fails is loudly, at runtime,
 * on the first record that reaches it. A test cannot unload an extension, but
 * PHP resolves an unqualified function call inside a namespace against that
 * namespace first and only then against the global one, so declaring these
 * three here is enough for the code under test to see the world it would see
 * on such a host.
 *
 * Off by default and switched per test through
 * `$GLOBALS['_cartshift_test_no_mbstring']`, because these declarations are
 * process-wide and permanent: `CartShift\Support` also holds Migrations and
 * WooStorage, both of which call function_exists() for reasons that have
 * nothing to do with this. With the flag down every call falls straight through
 * to the global function and nothing behaves differently.
 *
 * @see \CartShift\Tests\Unit\Support\SkuAllocatorWithoutMbstringTest
 */

namespace CartShift\Support;

function cartshift_test_mbstring_hidden(): bool
{
    return (bool) ($GLOBALS['_cartshift_test_no_mbstring'] ?? false);
}

function function_exists(string $function): bool
{
    if (cartshift_test_mbstring_hidden() && str_starts_with($function, 'mb_')) {
        return false;
    }

    return \function_exists($function);
}

/**
 * What PHP itself raises for a call to a function that is not defined. A guard
 * that checks function_exists() never reaches this; one that does not, does.
 */
function mb_strlen(string $string, ?string $encoding = null): int
{
    if (cartshift_test_mbstring_hidden()) {
        throw new \Error('Call to undefined function CartShift\Support\mb_strlen()');
    }

    return \mb_strlen($string, $encoding);
}

function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
{
    if (cartshift_test_mbstring_hidden()) {
        throw new \Error('Call to undefined function CartShift\Support\mb_substr()');
    }

    return \mb_substr($string, $start, $length, $encoding);
}
