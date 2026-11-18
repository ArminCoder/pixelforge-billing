<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;

/**
 * Reads and writes the credit ledger. All amounts are nanodollars.
 */
class Credits
{
    /** Everything bought minus everything charged. */
    public function balance(User $user): int
    {
        return (int) CreditTransaction::where('user_id', $user->id)->sum('amount_nanos');
    }

    /**
     * Money held back for images that are still being made. We hold the
     * highest possible price, so the balance can never go below zero.
     */
    public function reserved(User $user): int
    {
        return (int) Generation::where('user_id', $user->id)
            ->whereIn('status', GenerationStatus::pendingValues())
            ->sum('estimated_price_max_nanos');
    }

    /** What the user can still spend on new images. */
    public function available(User $user): int
    {
        return $this->balance($user) - $this->reserved($user);
    }

    public function purchase(User $user, int $amountNanos): void
    {
        CreditTransaction::create([
            'user_id' => $user->id,
            'type' => CreditTransaction::PURCHASE,
            'amount_nanos' => $amountNanos,
        ]);
    }

    /**
     * Charge the user for one finished image.
     * The unique index on (generation_id, type) makes a second charge
     * for the same image impossible.
     */
    public function charge(Generation $generation): void
    {
        CreditTransaction::create([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'type' => CreditTransaction::CHARGE,
            'amount_nanos' => -$generation->price_nanos,
        ]);
    }
}
