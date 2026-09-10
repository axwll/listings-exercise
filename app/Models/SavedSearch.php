<?php

namespace App\Models;

use App\Enums\PropertyType;
use App\Enums\Tenure;
use Database\Factories\SavedSearchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int|null $min_price
 * @property int|null $max_price
 * @property int|null $min_bedrooms
 * @property int|null $max_bedrooms
 * @property int|null $min_bathrooms
 * @property int|null $max_bathrooms
 * @property PropertyType|null $property_type
 * @property string|null $region
 * @property Tenure|null $tenure
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class SavedSearch extends Model
{
    /** @use HasFactory<SavedSearchFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'min_price',
        'max_price',
        'min_bedrooms',
        'max_bedrooms',
        'min_bathrooms',
        'max_bathrooms',
        'property_type',
        'region',
        'tenure',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_price' => 'integer',
            'max_price' => 'integer',
            'min_bedrooms' => 'integer',
            'max_bedrooms' => 'integer',
            'min_bathrooms' => 'integer',
            'max_bathrooms' => 'integer',
            'property_type' => PropertyType::class,
            'tenure' => Tenure::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Alert, $this>
     */
    public function alerts(): BelongsToMany
    {
        return $this->belongsToMany(Alert::class, 'alert_saved_search');
    }
}
