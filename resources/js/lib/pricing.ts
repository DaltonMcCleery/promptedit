/**
 * The browser copy of App\Billing\CostCalculator::estimate, kept in the same
 * order so the two are easy to compare. It only drives the live display: the
 * server recomputes and stores the estimate when the form is submitted.
 *
 * All amounts are integer nanodollars, computed with BigInt.
 */

export type Size = '1024x1024' | '1536x1024' | '1024x1536';
export type Quality = 'low' | 'medium' | 'high';
export type TokenType =
    | 'input_text_tokens'
    | 'input_image_tokens'
    | 'output_tokens'
    | 'reasoning_tokens';

/** PriceSheet::toArray(): rates are nanodollars per 1M tokens. */
export type PriceSheet = {
    rates: Record<TokenType, number>;
    characters_per_text_token: number;
    text_token_variance_basis_points: number;
    tokens_per_reference_image: number;
    output_tokens: Record<Quality, Record<Size, number>>;
    reasoning_tokens: Record<Quality, { min: number; max: number }>;
};

export type EstimateLine = {
    type: TokenType;
    minTokens: number;
    maxTokens: number;
    minNanos: bigint;
    maxNanos: bigint;
};

export type Estimate = {
    minNanos: bigint;
    maxNanos: bigint;
    providerMinNanos: bigint;
    providerMaxNanos: bigint;
    lines: EstimateLine[];
};

export const TOKEN_TYPES: TokenType[] = [
    'input_text_tokens',
    'input_image_tokens',
    'output_tokens',
    'reasoning_tokens',
];

const TOKENS_PER_RATE_UNIT = 1_000_000n;
const BASIS_POINTS = 10_000n;

/**
 * Pixelforge counts Unicode characters (code points), and so does PHP's
 * mb_strlen on the server. Spreading a string yields code points, which is
 * exactly the count wanted here, not grapheme clusters.
 */
export function characterCount(prompt: string): number {
    // oxlint-disable-next-line typescript/no-misused-spread
    return [...prompt].length;
}

/** Provider cost × 1.25, rounded up to a whole nanodollar. */
export function withMarkup(providerCost: bigint): bigint {
    return (providerCost * 5n + 3n) / 4n;
}

export function estimate(
    sheet: PriceSheet,
    prompt: string,
    size: Size,
    quality: Quality,
    referenceCount: number,
): Estimate {
    const baseTextTokens = ceilDiv(
        BigInt(characterCount(prompt)),
        BigInt(sheet.characters_per_text_token),
    );
    const variance = BigInt(sheet.text_token_variance_basis_points);
    const reasoning = sheet.reasoning_tokens[quality];
    const imageTokens = sheet.tokens_per_reference_image * referenceCount;
    const outputTokens = sheet.output_tokens[quality][size];

    const min: Record<TokenType, number> = {
        input_text_tokens: Number(
            (baseTextTokens * (BASIS_POINTS - variance)) / BASIS_POINTS,
        ),
        input_image_tokens: imageTokens,
        output_tokens: outputTokens,
        reasoning_tokens: reasoning.min,
    };

    const max: Record<TokenType, number> = {
        input_text_tokens: Number(
            ceilDiv(baseTextTokens * (BASIS_POINTS + variance), BASIS_POINTS),
        ),
        input_image_tokens: imageTokens,
        output_tokens: outputTokens,
        reasoning_tokens: reasoning.max,
    };

    const lines = TOKEN_TYPES.map((type) => ({
        type,
        minTokens: min[type],
        maxTokens: max[type],
        minNanos: costOf(sheet.rates, { [type]: min[type] }),
        maxNanos: costOf(sheet.rates, { [type]: max[type] }),
    }));

    const providerMinNanos = costOf(sheet.rates, min);
    const providerMaxNanos = costOf(sheet.rates, max);

    return {
        minNanos: withMarkup(providerMinNanos),
        maxNanos: withMarkup(providerMaxNanos),
        providerMinNanos,
        providerMaxNanos,
        lines,
    };
}

function costOf(
    rates: Record<TokenType, number>,
    tokens: Partial<Record<TokenType, number>>,
): bigint {
    let numerator = 0n;

    for (const [type, count] of Object.entries(tokens) as [
        TokenType,
        number,
    ][]) {
        numerator += BigInt(count) * BigInt(rates[type]);
    }

    return ceilDiv(numerator, TOKENS_PER_RATE_UNIT);
}

function ceilDiv(numerator: bigint, denominator: bigint): bigint {
    return (numerator + denominator - 1n) / denominator;
}
