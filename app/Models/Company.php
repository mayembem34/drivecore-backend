<?php

namespace App\Models;

use App\Actions\PostJournal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = ['require_2fa_for_privileged', 'require_inspection_photos', 'distance_unit', 'volume_unit', 'fuel_efficiency_unit', 'weight_unit', 'name', 'currency', 'vat_rate_pct', 'discount_approval_threshold_pct'];

    protected static function booted(): void
    {
        static::created(fn (Company $company) => PostJournal::seed($company->id));
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('branch_id');
    }

    protected function casts(): array
    {
        return ['require_2fa_for_privileged' => 'boolean', 'require_inspection_photos' => 'boolean', 'vat_rate_pct' => 'float', 'discount_approval_threshold_pct' => 'float'];
    }
}
