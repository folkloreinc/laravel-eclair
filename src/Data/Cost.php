<?php

namespace Folklore\Eclair\Data;

/** An estimate of a provider's cost, when its price is configured. The provider's bill is the reference. */
final readonly class Cost
{
    public function __construct(
        public float $amount,
        /** ISO 4217: `USD`. */
        public string $currency,
    ) {}

    /**
     * The cost, or null when it lacks its amount or currency.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;
        if (! is_numeric($amount) || ! is_string($currency) || $currency === '') {
            return null;
        }

        return new self((float) $amount, $currency);
    }

    /**
     * @return array{amount: float, currency: string}
     */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }
}
