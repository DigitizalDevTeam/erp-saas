<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class HotelBookingSite extends Model
{
    public const PAY_AT_HOTEL = 'pay_at_hotel';

    public const PAY_PREPAY = 'prepay';

    /** @var array<int, string> */
    public const RESERVED_SLUGS = [
        'admin', 'api', 'login', 'register', 'logout', 'password', 'hotel', 'hotel-dashboard',
        'dashboard', 'account-dashboard', 'hrm-dashboard', 'install', 'update', 'webhook',
        'storage', 'css', 'js', 'images', 'vendor', 'pages', 'livewire',
    ];

    protected $fillable = [
        'created_by',
        'slug',
        'custom_domain',
        'is_published',
        'display_name',
        'tagline',
        'description',
        'logo_path',
        'cover_image_path',
        'gallery_images',
        'amenities',
        'accent_color',
        'contact_email',
        'contact_phone',
        'address',
        'default_language',
        'check_in_time',
        'check_out_time',
        'cancellation_policy',
        'meta_title',
        'meta_description',
        'payment_mode',
        'prepayment_percent',
        'payment_config',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'gallery_images' => 'array',
        'amenities' => 'array',
        'payment_config' => 'array',
        'prepayment_percent' => 'integer',
    ];

    public function publicUrl(): string
    {
        return url('/' . $this->slug);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function coverUrl(): ?string
    {
        return $this->cover_image_path ? Storage::disk('public')->url($this->cover_image_path) : null;
    }

    /**
     * @return array<int, string>
     */
    public function galleryUrls(): array
    {
        return collect($this->gallery_images ?? [])
            ->filter()
            ->map(fn ($path) => Storage::disk('public')->url($path))
            ->values()
            ->all();
    }

    public static function isReservedSlug(string $slug): bool
    {
        return in_array(strtolower($slug), self::RESERVED_SLUGS, true);
    }
}
