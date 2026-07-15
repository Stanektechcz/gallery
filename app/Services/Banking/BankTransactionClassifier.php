<?php

namespace App\Services\Banking;

use App\Models\BankCategoryRule;
use App\Models\GallerySpace;
use Illuminate\Support\Str;

class BankTransactionClassifier
{
    private const DEFAULTS = [
        'transport' => ['regiojet', 'flixbus', 'ceske drahy', 'cd.cz', 'leo express', 'ryanair', 'wizz air', 'easyjet', 'uber', 'bolt', 'shell', 'omv', 'mol ', 'benzin', 'parking', 'dalnice', 'vignette', 'mhd', 'dpp ', 'dpmb', 'trainline'],
        'accommodation' => ['booking.com', 'airbnb', 'hotel', 'hostel', 'pension', 'penzion', 'apartman', 'apartment'],
        'food' => ['restaurant', 'restaurace', 'bistro', 'cafe', 'coffee', 'kavarna', 'pizzeria', 'mcdonald', 'kfc', 'burger king', 'lidl', 'albert', 'tesco', 'billa', 'kaufland', 'rohlik', 'wolt', 'foodora'],
        'activities' => ['cinema', 'kino', 'museum', 'muzeum', 'gallery', 'galerie', 'zoo', 'aquapark', 'ticket', 'vstupne', 'goout', 'ticketportal', 'infinit'],
        'insurance' => ['insurance', 'pojisteni', 'allianz', 'axa assistance', 'erv ', 'koop poj'],
    ];

    /** @return array{category:string,trip_action:string,is_internal_transfer:bool,is_refund:bool,is_fee:bool,is_cash_withdrawal:bool,rule:?string} */
    public function classify(GallerySpace $space, array $input): array
    {
        $values = [
            'merchant' => $this->normalize($input['merchant_name'] ?? ''),
            'counterparty' => $this->normalize($input['counterparty_name'] ?? ''),
            'description' => $this->normalize($input['description'] ?? ''),
            'type' => $this->normalize($input['transaction_type'] ?? '').' '.$this->normalize($input['bank_transaction_code'] ?? ''),
        ];
        $haystack = implode(' ', $values);
        $isCash = Str::contains($haystack, ['cash withdrawal', 'vyber hotovosti', 'atm ', 'cashpoint']);
        $isFee = Str::contains($haystack, [' fee', 'fee ', 'poplatek', 'charge']);
        $isRefund = (float) ($input['amount'] ?? 0) > 0 && Str::contains($haystack, ['refund', 'vratka', 'reversal', 'chargeback']);
        $isInternal = Str::contains($haystack, ['internal transfer', 'vlastni ucet', 'between your accounts', 'card to card', 'exchanged to', 'exchange ']);

        $rules = BankCategoryRule::where('gallery_space_id', $space->id)->where('is_enabled', true)->orderByDesc('priority')->get();
        foreach ($rules as $rule) {
            $subject = $values[$rule->field] ?? $haystack;
            $matches = $rule->operator === 'equals' ? $subject === $this->normalize($rule->pattern)
                : ($rule->operator === 'starts_with' ? Str::startsWith($subject, $this->normalize($rule->pattern)) : Str::contains($subject, $this->normalize($rule->pattern)));
            if ($matches) {
                return ['category' => $rule->category, 'trip_action' => $rule->trip_action, 'is_internal_transfer' => $isInternal,
                    'is_refund' => $isRefund, 'is_fee' => $isFee, 'is_cash_withdrawal' => $isCash, 'rule' => $rule->uuid];
            }
        }

        $category = 'other';
        foreach (self::DEFAULTS as $candidate => $needles) {
            if (Str::contains($haystack, $needles)) {
                $category = $candidate;
                break;
            }
        }

        return ['category' => $category, 'trip_action' => 'suggest', 'is_internal_transfer' => $isInternal, 'is_refund' => $isRefund,
            'is_fee' => $isFee, 'is_cash_withdrawal' => $isCash, 'rule' => null];
    }

    public function normalize(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return Str::of(Str::ascii((string) $value))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }
}
