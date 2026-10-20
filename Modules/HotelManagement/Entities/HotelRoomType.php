<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class HotelRoomType extends Model
{
    use HasFactory;

    public const MAX_SLIDER_IMAGES = 3;

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
        'included_adults',
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
        $slides = $this->sliderImageUrls();

        return $slides[0] ?? null;
    }

    /**
     * Up to 3 images for the public booking slider: feature + 2 extras.
     *
     * @return array<int, string>
     */
    public function sliderImageUrls(): array
    {
        $urls = [];

        if ($cover = $this->coverImageUrl()) {
            $urls[] = $cover;
        }

        foreach ($this->galleryImageUrls() as $url) {
            if (in_array($url, $urls, true)) {
                continue;
            }
            $urls[] = $url;
            if (count($urls) >= self::MAX_SLIDER_IMAGES) {
                break;
            }
        }

        return array_slice($urls, 0, self::MAX_SLIDER_IMAGES);
    }

    public function slideTwoPath(): ?string
    {
        return ($this->gallery_images ?? [])[0] ?? null;
    }

    public function slideThreePath(): ?string
    {
        return ($this->gallery_images ?? [])[1] ?? null;
    }

    public function slideTwoUrl(): ?string
    {
        $path = $this->slideTwoPath();

        return $path ? Storage::disk('public')->url($path) : null;
    }

    public function slideThreeUrl(): ?string
    {
        $path = $this->slideThreePath();

        return $path ? Storage::disk('public')->url($path) : null;
    }
}
