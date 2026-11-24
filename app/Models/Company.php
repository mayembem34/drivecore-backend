<?php

namespace App\Models;

use App\Actions\PostJournal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'currency', 'vat_rate_pct', 'discount_approval_threshold_pct'];

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
        return ['vat_rate_pct' => 'float', 'discount_approval_threshold_pct' => 'float'];
    }
}
