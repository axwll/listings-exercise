<?php

namespace App\Models;

use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $listing_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read Listing $listing
 * @property-read Collection<int, SavedSearch> $savedSearches
 */
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'listing_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsToMany<SavedSearch, $this>
     */
    public function savedSearches(): BelongsToMany
    {
        return $this->belongsToMany(SavedSearch::class, 'alert_saved_search');
    }
}
