<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The money ledger. Rows are only ever added, never changed.
 * A user's balance is the sum of amount_nanos.
 *
 *  - purchase: positive amount, the user bought credits
 *  - charge:   negative amount, the user paid for one image
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $generation_id
 * @property string $type
 * @property int $amount_nanos
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
