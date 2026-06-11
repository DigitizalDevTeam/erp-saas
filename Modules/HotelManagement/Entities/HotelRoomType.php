<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class HotelRoomType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'cover_image_path',
        'gallery_images',
        'unit_category',
        'base_price',
        'nightly_rate',
        'monthly_rate',
        'default_billing_mode',
        'max_occupancy',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'gallery_images' => 'array',
        'is_active' => 'boolean',
        'base_price' => 'float',
        'nightly_rate' => 'float',
        'monthly_rate' => 'float',
    ];

    public function rooms()
    {
        return $this->hasMany(HotelRoom::class);
    }

    public function ratePlans()
    {
        return $this->hasMany(HotelRatePlan::class);
    }

    public function effectiveNightlyRate(): float
    {
        $nightly = (float) ($this->nightly_rate ?? 0);
        $base = (float) ($this->base_price ?? 0);

        return $nightly > 0 ? $nightly : $base;
    }

    public function coverImageUrl(): ?string
    {
        if (! $this->cover_image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->cover_image_path);
    }

    /**
     * @return array<int, string>
     */
    public function galleryImageUrls(): array
    {
        return collect($this->gallery_images ?? [])
            ->filter()
            ->map(fn ($path) => Storage::disk('public')->url($path))
            ->values()
            ->all();
    }

    public function displayImageUrl(): ?string
    {
        return $this->coverImageUrl() ?: ($this->galleryImageUrls()[0] ?? null);
    }
}
