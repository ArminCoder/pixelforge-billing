<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The money ledger. Rows are only ever added, never changed.
 * A user's balance is the sum of amount_nanos.
 *
 *  - purchase: positive amount, the user bought credits
 *  - charge:   negative amount, the user paid for one image
 */
class CreditTransaction extends Model
{
    public const PURCHASE = 'purchase';

    public const CHARGE = 'charge';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_nanos' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
