<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_booking_sites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->index();
            $table->string('slug', 64)->unique();
            $table->string('custom_domain', 191)->nullable()->unique();
            $table->boolean('is_published')->default(false);
            $table->string('display_name', 191);
            $table->string('tagline', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('logo_path', 500)->nullable();
            $table->string('cover_image_path', 500)->nullable();
            $table->json('gallery_images')->nullable();
            $table->json('amenities')->nullable();
            $table->string('accent_color', 16)->default('#0d9488');
            $table->string('contact_email', 191)->nullable();
            $table->string('contact_phone', 64)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('default_language', 8)->default('en');
            $table->string('check_in_time', 8)->default('14:00');
            $table->string('check_out_time', 8)->default('11:00');
            $table->text('cancellation_policy')->nullable();
            $table->string('meta_title', 191)->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('payment_mode', 32)->default('pay_at_hotel');
            $table->unsignedTinyInteger('prepayment_percent')->default(0);
            $table->json('payment_config')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('hotel_reservations')) {
            Schema::table('hotel_reservations', function (Blueprint $table) {
                if (! Schema::hasColumn('hotel_reservations', 'hotel_booking_site_id')) {
                    $table->unsignedBigInteger('hotel_booking_site_id')->nullable()->after('source');
                }
                if (! Schema::hasColumn('hotel_reservations', 'online_payment_ref')) {
                    $table->string('online_payment_ref', 191)->nullable()->after('hotel_booking_site_id');
                }
                if (! Schema::hasColumn('hotel_reservations', 'confirmation_token')) {
                    $table->string('confirmation_token', 64)->nullable()->unique()->after('online_payment_ref');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hotel_reservations')) {
            Schema::table('hotel_reservations', function (Blueprint $table) {
                foreach (['confirmation_token', 'online_payment_ref', 'hotel_booking_site_id'] as $col) {
                    if (Schema::hasColumn('hotel_reservations', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('hotel_booking_sites');
    }
};
