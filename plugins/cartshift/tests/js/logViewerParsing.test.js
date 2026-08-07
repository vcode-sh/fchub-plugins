import { describe, it, expect } from 'vitest';
import {
  extractCode,
  humaniseCode,
  isRetryableRow,
  normaliseBreakdown,
} from '@/composables/useLogViewer.js';

describe('extractCode — top-level spellings', () => {
  it('reads the canonical error_code', () => {
    expect(extractCode({ error_code: 'customer_not_found' })).toBe('customer_not_found');
  });

  it.each([
    ['errorCode', 'sku_collision'],
    ['code', 'product_not_mapped'],
    ['reason_code', 'missing_email'],
    ['reasonCode', 'order_has_no_items'],
  ])('reads the %s alternate spelling', (key, value) => {
    expect(extractCode({ [key]: value })).toBe(value);
  });

  it('prefers error_code over every alternate', () => {
    const entry = {
      reasonCode: 'e',
      reason_code: 'd',
      code: 'c',
      errorCode: 'b',
      error_code: 'a',
    };

    expect(extractCode(entry)).toBe('a');
  });

  it('trims surrounding whitespace', () => {
    expect(extractCode({ error_code: '  sku_collision \n' })).toBe('sku_collision');
  });
});

describe('extractCode — nested payloads', () => {
  it('falls back to details', () => {
    expect(extractCode({ details: { code: 'coupon_code_collision' } })).toBe(
      'coupon_code_collision'
    );
  });

  it.each(['error', 'reason', 'context'])('descends one level into details.%s', (key) => {
    const entry = { details: { [key]: { error_code: 'term_creation_failed' } } };

    expect(extractCode(entry)).toBe('term_creation_failed');
  });

  it('prefers a top-level code over a nested one', () => {
    const entry = { code: 'top', details: { error_code: 'nested' } };

    expect(extractCode(entry)).toBe('top');
  });

  it('prefers details over details.error', () => {
    const entry = { details: { code: 'shallow', error: { code: 'deep' } } };

    expect(extractCode(entry)).toBe('shallow');
  });

  it('ignores an array details bag rather than indexing into it', () => {
    expect(extractCode({ details: ['error_code', 'nope'] })).toBeNull();
  });

  it('does not descend a second level', () => {
    const entry = { details: { error: { context: { code: 'too_deep' } } } };

    expect(extractCode(entry)).toBeNull();
  });
});

describe('extractCode — junk in, null out', () => {
  it.each([
    ['null', null],
    ['undefined', undefined],
    ['a string', 'error_code'],
    ['a number', 42],
    ['an empty object', {}],
    ['a boolean', true],
  ])('returns null for %s', (_label, input) => {
    expect(extractCode(input)).toBeNull();
  });

  it('treats a blank code as absent', () => {
    expect(extractCode({ error_code: '   ' })).toBeNull();
  });

  it('ignores non-string codes', () => {
    expect(extractCode({ error_code: 500, code: null })).toBeNull();
  });

  it('skips a non-string spelling and takes the next usable one', () => {
    expect(extractCode({ error_code: 0, code: 'missing_email' })).toBe('missing_email');
  });
});

describe('humaniseCode', () => {
  it('turns a slug into a sentence', () => {
    expect(humaniseCode('customer_not_found')).toBe('Customer not found');
  });

  it.each(['.', '-', '_'])('treats %s as a separator', (sep) => {
    expect(humaniseCode(['a', 'b'].join(sep))).toBe('A b');
  });

  it.each([
    ['null', null],
    ['undefined', undefined],
    ['empty string', ''],
    ['separators only', '___'],
  ])('falls back to Unclassified for %s', (_label, input) => {
    expect(humaniseCode(input)).toBe('Unclassified');
  });

  it('coerces a non-string code', () => {
    expect(humaniseCode(123)).toBe('123');
  });
});

describe('normaliseBreakdown — array form', () => {
  it('maps rows and sorts by count descending', () => {
    const rows = normaliseBreakdown([
      { error_code: 'a', count: 2 },
      { error_code: 'b', count: 9 },
      { error_code: 'c', count: 5 },
    ]);

    expect(rows.map((r) => r.code)).toEqual(['b', 'c', 'a']);
  });

  it('carries label, hint, severity, category and retryable through', () => {
    const [row] = normaliseBreakdown([
      {
        error_code: 'sku_collision',
        count: 3,
        label: 'SKU already taken',
        hint: 'Rename the duplicate SKU in WooCommerce',
        severity: 'error',
        category: 'product',
        retryable: false,
      },
    ]);

    expect(row).toEqual({
      code: 'sku_collision',
      count: 3,
      label: 'SKU already taken',
      hint: 'Rename the duplicate SKU in WooCommerce',
      severity: 'error',
      category: 'product',
      retryable: false,
    });
  });

  it.each(['count', 'total', 'n'])('accepts %s as the count key', (key) => {
    const [row] = normaliseBreakdown([{ code: 'x', [key]: 4 }]);

    expect(row.count).toBe(4);
  });

  it('humanises the code when the server sends no label', () => {
    const [row] = normaliseBreakdown([{ code: 'coupon_code_missing', count: 1 }]);

    expect(row.label).toBe('Coupon code missing');
    expect(row.hint).toBeNull();
  });

  it.each([
    ['title', 'A title'],
    ['summary', 'A summary'],
    ['reason_label', 'A reason label'],
    ['description', 'A description'],
  ])('accepts %s as an alternate label key', (key, value) => {
    const [row] = normaliseBreakdown([{ code: 'x', count: 1, [key]: value }]);

    expect(row.label).toBe(value);
  });

  it.each(['hint', 'remedy', 'fix', 'resolution', 'suggestion', 'help', 'advice'])(
    'accepts %s as a hint key',
    (key) => {
      const [row] = normaliseBreakdown([{ code: 'x', count: 1, [key]: 'do the thing' }]);

      expect(row.hint).toBe('do the thing');
    }
  );

  it('drops rows with no code, no usable count, or a non-positive count', () => {
    const rows = normaliseBreakdown([
      { count: 5 },
      { code: '   ', count: 5 },
      { code: 'a', count: 0 },
      { code: 'b', count: -3 },
      { code: 'c', count: 'not a number' },
      { code: 'd' },
      { code: 'keeper', count: 1 },
    ]);

    expect(rows.map((r) => r.code)).toEqual(['keeper']);
  });

  it('skips non-object members instead of throwing', () => {
    const rows = normaliseBreakdown([null, 'nope', 7, { code: 'a', count: 1 }]);

    expect(rows.map((r) => r.code)).toEqual(['a']);
  });

  it('coerces a numeric string count', () => {
    const [row] = normaliseBreakdown([{ code: 'a', count: '12' }]);

    expect(row.count).toBe(12);
  });
});

describe('normaliseBreakdown — map forms', () => {
  it('accepts the bare {code: count} map', () => {
    const rows = normaliseBreakdown({ sku_collision: 4, missing_email: 7 });

    expect(rows).toEqual([
      {
        code: 'missing_email',
        count: 7,
        label: 'Missing email',
        hint: null,
        severity: null,
        category: null,
        retryable: null,
      },
      {
        code: 'sku_collision',
        count: 4,
        label: 'Sku collision',
        hint: null,
        severity: null,
        category: null,
        retryable: null,
      },
    ]);
  });

  it('accepts the {code: {count, label, hint}} map', () => {
    const rows = normaliseBreakdown({
      sku_collision: { count: 2, label: 'SKU clash', hint: 'Rename it', severity: 'error' },
    });

    expect(rows[0].label).toBe('SKU clash');
    expect(rows[0].hint).toBe('Rename it');
    expect(rows[0].severity).toBe('error');
  });

  it('accepts a string count in map form', () => {
    expect(normaliseBreakdown({ a: '3' })[0].count).toBe(3);
  });

  it('drops map entries whose value is unusable', () => {
    const rows = normaliseBreakdown({
      a: 0,
      b: null,
      c: true,
      d: [],
      e: { label: 'no count' },
      f: 2,
    });

    expect(rows.map((r) => r.code)).toEqual(['f']);
  });
});

describe('normaliseBreakdown — severity gate', () => {
  it.each(['info', 'warning', 'error'])('keeps the known severity %s', (severity) => {
    expect(normaliseBreakdown([{ code: 'a', count: 1, severity }])[0].severity).toBe(severity);
  });

  it('lowercases before matching', () => {
    expect(normaliseBreakdown([{ code: 'a', count: 1, severity: 'WARNING' }])[0].severity).toBe(
      'warning'
    );
  });

  it('nulls an unrecognised severity rather than passing it to the UI', () => {
    expect(normaliseBreakdown([{ code: 'a', count: 1, severity: 'catastrophic' }])[0].severity).toBeNull();
    expect(normaliseBreakdown([{ code: 'a', count: 1, severity: 3 }])[0].severity).toBeNull();
  });
});

describe('normaliseBreakdown — retryable is tri-state', () => {
  it('keeps a server boolean either way', () => {
    expect(normaliseBreakdown([{ code: 'a', count: 1, retryable: true }])[0].retryable).toBe(true);
    expect(normaliseBreakdown([{ code: 'a', count: 1, retryable: false }])[0].retryable).toBe(false);
  });

  it('nulls a non-boolean rather than coercing it', () => {
    expect(normaliseBreakdown([{ code: 'a', count: 1, retryable: 'yes' }])[0].retryable).toBeNull();
    expect(normaliseBreakdown([{ code: 'a', count: 1, retryable: 1 }])[0].retryable).toBeNull();
  });
});

describe('normaliseBreakdown — junk in, empty out', () => {
  it.each([
    ['null', null],
    ['undefined', undefined],
    ['false', false],
    ['zero', 0],
    ['empty string', ''],
    ['empty array', []],
    ['empty object', {}],
  ])('returns [] for %s', (_label, input) => {
    expect(normaliseBreakdown(input)).toEqual([]);
  });

  it('returns [] for a bare string without throwing', () => {
    expect(normaliseBreakdown('sku_collision')).toEqual([]);
  });

  it('returns [] for a number without throwing', () => {
    expect(normaliseBreakdown(17)).toEqual([]);
  });
});

describe('isRetryableRow', () => {
  const NON_RETRYABLE = [
    'unsupported_product_type',
    'sku_collision',
    'user_not_found',
    'already_migrated',
    'already_exists_in_fluentcart',
  ];

  const RETRYABLE = [
    'customer_not_found',
    'product_not_mapped',
    'variation_not_mapped',
    'coupon_code_missing',
    'coupon_code_too_long',
    'coupon_code_collision',
    'order_has_no_items',
    'empty_product_name',
    'missing_email',
    'term_creation_failed',
    'product_creation_failed',
    'dry_run_validation_failed',
    'unexpected_exception',
    'migration_aborted',
  ];

  it.each(NON_RETRYABLE)('refuses to offer a retry for %s', (code) => {
    expect(isRetryableRow({ code, count: 1 })).toBe(false);
  });

  it.each(RETRYABLE)('offers a retry for %s', (code) => {
    expect(isRetryableRow({ code, count: 1 })).toBe(true);
  });

  it('says no to an unknown code — silence beats a button that can only fail', () => {
    expect(isRetryableRow({ code: 'something_nobody_has_seen', count: 1 })).toBe(false);
  });

  it('lets the server overrule the local list in both directions', () => {
    expect(isRetryableRow({ code: 'sku_collision', retryable: true })).toBe(true);
    expect(isRetryableRow({ code: 'missing_email', retryable: false })).toBe(false);
  });

  it('ignores a non-boolean retryable and falls back to the list', () => {
    expect(isRetryableRow({ code: 'missing_email', retryable: 'no' })).toBe(true);
    expect(isRetryableRow({ code: 'sku_collision', retryable: 'yes' })).toBe(false);
    expect(isRetryableRow({ code: 'missing_email', retryable: null })).toBe(true);
  });

  it.each([
    ['null', null],
    ['undefined', undefined],
    ['a string', 'missing_email'],
    ['a number', 1],
    ['a row with no code', { count: 4 }],
  ])('returns false for %s', (_label, input) => {
    expect(isRetryableRow(input)).toBe(false);
  });
});
