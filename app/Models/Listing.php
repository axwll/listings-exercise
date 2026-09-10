<?php

namespace App\Models;

use App\Enums\ListingStatus;
use App\Enums\PropertyType;
use App\Enums\Tenure;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The casts() method tells Eloquent how to hydrate these attributes, but static
 * analysis can't infer that from a string map — these annotations do.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $reference
 * @property string $address_line_1
 * @property string $city
 * @property string $postcode
 * @property int $price
 * @property int $bedrooms
 * @property int $bathrooms
 * @property PropertyType $property_type
 * @property Tenure|null $tenure
 * @property ListingStatus $status
 * @property Carbon|null $listed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Branch $branch
 */
class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'reference',
        'address_line_1',
        'city',
        'postcode',
        'price',
        'bedrooms',
        'bathrooms',
        'property_type',
        'tenure',
        'status',
        'listed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'property_type' => PropertyType::class,
            'tenure' => Tenure::class,
            'status' => ListingStatus::class,
            'listed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Only listings that are currently on the market.
     *
     * @param  Builder<Listing>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', ListingStatus::Live);
    }

    /**
     * Listings satisfying every constraint on the given saved search. A null
     * field on the saved search means "no constraint" — the same semantics
     * ListingIndexRequest's optional filters already use.
     *
     * @param  Builder<Listing>  $query
     */
    public function scopeMatchingSavedSearch(Builder $query, SavedSearch $savedSearch): void
    {
        $query
            ->when($savedSearch->min_price !== null, fn (Builder $q) => $q->where('price', '>=', $savedSearch->min_price))
            ->when($savedSearch->max_price !== null, fn (Builder $q) => $q->where('price', '<=', $savedSearch->max_price))
            ->when($savedSearch->min_bedrooms !== null, fn (Builder $q) => $q->where('bedrooms', '>=', $savedSearch->min_bedrooms))
            ->when($savedSearch->max_bedrooms !== null, fn (Builder $q) => $q->where('bedrooms', '<=', $savedSearch->max_bedrooms))
            ->when($savedSearch->min_bathrooms !== null, fn (Builder $q) => $q->where('bathrooms', '>=', $savedSearch->min_bathrooms))
            ->when($savedSearch->max_bathrooms !== null, fn (Builder $q) => $q->where('bathrooms', '<=', $savedSearch->max_bathrooms))
            ->when($savedSearch->property_type !== null, fn (Builder $q) => $q->where('property_type', $savedSearch->property_type))
            ->when($savedSearch->tenure !== null, fn (Builder $q) => $q->where('tenure', $savedSearch->tenure))
            ->when($savedSearch->region !== null, fn (Builder $q) => $q->whereHas(
                'branch',
                fn (Builder $branchQuery) => $branchQuery->where('region', $savedSearch->region)
            ));
    }
}
