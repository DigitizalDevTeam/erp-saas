<?php

namespace Modules\HotelManagement\Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelAddon;
use Modules\HotelManagement\Entities\HotelBookingSite;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFolioLine;
use Modules\HotelManagement\Entities\HotelFolioPayment;
use Modules\HotelManagement\Entities\HotelGroup;
use Modules\HotelManagement\Entities\HotelMinibarItem;
use Modules\HotelManagement\Entities\HotelReservationAddon;
use Modules\HotelManagement\Entities\HotelGroupMember;
use Modules\HotelManagement\Entities\HotelGuest;
use Modules\HotelManagement\Entities\HotelHousekeepingTask;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Entities\HotelStay;

/**
 * HotelDummyDataSeeder — populates room types, rooms, group reservations and housekeeping tasks
 * for the first non-super-admin user (or the user id passed via the `--class` arguments env).
 *
 * Run:  php artisan db:seed --class="Modules\HotelManagement\Database\Seeders\HotelDummyDataSeeder"
 * Or:   php artisan module:seed HotelManagement
 */
class HotelDummyDataSeeder extends Seeder
{
    /**
     * Map reservation_no → desired payment state ('paid' | 'partial' | 'pending')
     * for use by seedStaysAndFolios.
     *
     * @var array<string, string>
     */
    protected array $paymentStateMap = [];

    public function run(): void
    {
        $userId = $this->resolveSeedUserId();
        if ($userId <= 0) {
            $this->command?->warn('No owner user found — skipping HotelDummyDataSeeder. Set HOTEL_SEED_USER_ID=<id>, HOTEL_SEED_EMAIL=<email>, or create a company user first.');
            return;
        }

        $this->command?->info("Seeding hotel dummy data for user_id={$userId}");

        DB::transaction(function () use ($userId) {
            $types = $this->seedRoomTypes($userId);
            $rooms = $this->seedRooms($userId, $types);
            $this->seedGroupReservations($userId, $types, $rooms);
            $this->seedSoloReservations($userId, $types, $rooms);
            $this->seedAddonsAndMinibar($userId);
            $this->seedStaysAndFolios($userId);
            $this->seedClientDemoFlow($userId, $types, $rooms);
            $this->seedHousekeepingTasks($userId, $rooms);
            $this->seedBookingSite($userId);
        });

        $this->command?->info('Hotel dummy data seeded.');
    }

    protected function resolveSeedUserId(): int
    {
        $fromEnv = (int) env('HOTEL_SEED_USER_ID', 0);
        if ($fromEnv > 0) {
            return $fromEnv;
        }

        $email = trim((string) env('HOTEL_SEED_EMAIL', ''));
        if ($email !== '') {
            $user = User::where('email', $email)->first();
            if ($user) {
                return (int) $user->creatorId();
            }
        }

        return $this->resolveOwnerId();
    }

    protected function resolveOwnerId(): int
    {
        $owner = User::where('type', 'company')->orderBy('id')->first()
            ?? User::orderBy('id')->first();

        return (int) ($owner?->id ?? 0);
    }

    /**
     * @return array<string, HotelRoomType>
     */
    protected function seedRoomTypes(int $userId): array
    {
        $defs = [
            ['name' => 'Standard',  'description' => 'Comfortable room with queen bed.',                'base_price' => 80,  'max_occupancy' => 2],
            ['name' => 'Deluxe',    'description' => 'Spacious room with king bed and city view.',      'base_price' => 130, 'max_occupancy' => 2],
            ['name' => 'Suite',     'description' => 'Two-room suite with living area & balcony.',      'base_price' => 220, 'max_occupancy' => 4],
            ['name' => 'Family',    'description' => 'Family room with one king + two single beds.',    'base_price' => 180, 'max_occupancy' => 4],
            ['name' => 'Executive', 'description' => 'Executive floor, lounge access, premium service.', 'base_price' => 300, 'max_occupancy' => 2],
        ];

        $map = [];
        foreach ($defs as $d) {
            $type = HotelRoomType::updateOrCreate(
                ['created_by' => $userId, 'name' => $d['name']],
                array_merge($d, [
                    'is_active' => true,
                    'created_by' => $userId,
                    'nightly_rate' => $d['base_price'],
                    'unit_category' => 'room',
                    'default_billing_mode' => 'nightly',
                ])
            );
            $map[$d['name']] = $type;
        }

        HotelRoomType::where('created_by', $userId)
            ->where('nightly_rate', '<=', 0)
            ->where('base_price', '>', 0)
            ->update(['nightly_rate' => DB::raw('base_price')]);

        return $map;
    }

    /**
     * @param array<string, HotelRoomType> $types
     * @return \Illuminate\Support\Collection<int, HotelRoom>
     */
    protected function seedRooms(int $userId, array $types)
    {
        $plan = [
            'Standard'  => ['floor' => 1, 'count' => 8,  'override' => null],
            'Deluxe'    => ['floor' => 2, 'count' => 6,  'override' => null],
            'Family'    => ['floor' => 3, 'count' => 4,  'override' => null],
            'Suite'     => ['floor' => 4, 'count' => 3,  'override' => null],
            'Executive' => ['floor' => 5, 'count' => 3,  'override' => 320],
        ];

        $statusCycle = [
            HotelRoom::STATUS_AVAILABLE,
            HotelRoom::STATUS_AVAILABLE,
            HotelRoom::STATUS_AVAILABLE,
            HotelRoom::STATUS_OCCUPIED,
            HotelRoom::STATUS_CLEANING,
            HotelRoom::STATUS_MAINTENANCE,
        ];

        $i = 0;
        foreach ($plan as $typeName => $cfg) {
            $type = $types[$typeName] ?? null;
            if (!$type) {
                continue;
            }
            for ($n = 1; $n <= $cfg['count']; $n++) {
                $roomNo = (string) ($cfg['floor'] * 100 + $n);
                HotelRoom::firstOrCreate(
                    ['created_by' => $userId, 'room_no' => $roomNo],
                    [
                        'hotel_room_type_id' => $type->id,
                        'override_price' => $cfg['override'],
                        'status' => $statusCycle[$i % count($statusCycle)],
                        'notes' => $i % 7 === 0 ? 'Recently renovated.' : null,
                        'created_by' => $userId,
                    ]
                );
                $i++;
            }
        }

        return HotelRoom::where('created_by', $userId)->orderBy('room_no')->get();
    }

    /**
     * Seed two demo group reservations: a family stay and a corporate group.
     *
     * @param array<string, HotelRoomType>                      $types
     * @param \Illuminate\Support\Collection<int, HotelRoom>    $rooms
     */
    protected function seedGroupReservations(int $userId, array $types, $rooms): void
    {
        $today = Carbon::today();

        $groups = [
            [
                'group' => [
                    'name' => 'Silva Family Holiday',
                    'company_name' => null,
                    'contact_name' => 'Carlos Silva',
                    'notes' => 'Family of 4 — connecting rooms requested.',
                ],
                'check_in' => $today->copy()->addDays(2)->setTime(14, 0),
                'check_out' => $today->copy()->addDays(6)->setTime(11, 0),
                'preferred_type' => 'Family',
                'members' => [
                    ['first_name' => 'Carlos',  'last_name' => 'Silva',  'phone' => '+258840000101', 'email' => 'carlos.silva@example.com', 'role' => 'adult', 'nationality' => 'Mozambican', 'is_vip' => false],
                    ['first_name' => 'Marta',   'last_name' => 'Silva',  'phone' => '+258840000102', 'email' => 'marta.silva@example.com',  'role' => 'adult', 'nationality' => 'Mozambican', 'is_vip' => false],
                    ['first_name' => 'Tiago',   'last_name' => 'Silva',  'phone' => null,           'email' => null,                       'role' => 'child', 'nationality' => 'Mozambican', 'is_vip' => false],
                    ['first_name' => 'Sofia',   'last_name' => 'Silva',  'phone' => null,           'email' => null,                       'role' => 'child', 'nationality' => 'Mozambican', 'is_vip' => false],
                ],
            ],
            [
                'group' => [
                    'name' => 'Acme Corp — Q2 Offsite',
                    'company_name' => 'Acme Mozambique LDA',
                    'contact_name' => 'Helena Costa',
                    'notes' => 'Corporate offsite, late check-out, billing under company.',
                ],
                'check_in' => $today->copy()->addDays(5)->setTime(15, 0),
                'check_out' => $today->copy()->addDays(8)->setTime(12, 0),
                'preferred_type' => 'Executive',
                'members' => [
                    ['first_name' => 'Helena',  'last_name' => 'Costa',    'phone' => '+258840001001', 'email' => 'helena.costa@acme.example',    'role' => 'adult', 'nationality' => 'Portuguese', 'is_vip' => true],
                    ['first_name' => 'Bruno',   'last_name' => 'Macamo',   'phone' => '+258840001002', 'email' => 'bruno.macamo@acme.example',    'role' => 'adult', 'nationality' => 'Mozambican',  'is_vip' => false],
                    ['first_name' => 'Ines',    'last_name' => 'Pereira',  'phone' => '+258840001003', 'email' => 'ines.pereira@acme.example',    'role' => 'adult', 'nationality' => 'Portuguese', 'is_vip' => false],
                ],
            ],
            [
                'group' => [
                    'name' => 'Maputo City Tour Bus',
                    'company_name' => 'Sunrise Tours',
                    'contact_name' => 'Pedro Manjate',
                    'notes' => 'Tour group, 2 nights, Standard rooms.',
                ],
                'check_in' => $today->copy()->addDays(1)->setTime(16, 0),
                'check_out' => $today->copy()->addDays(3)->setTime(10, 0),
                'preferred_type' => 'Standard',
                'members' => [
                    ['first_name' => 'Pedro',   'last_name' => 'Manjate', 'phone' => '+258840002001', 'email' => 'pedro@sunrise.example', 'role' => 'adult', 'nationality' => 'Mozambican', 'is_vip' => false],
                    ['first_name' => 'Anita',   'last_name' => 'Cossa',   'phone' => '+258840002002', 'email' => 'anita@sunrise.example', 'role' => 'adult', 'nationality' => 'South African', 'is_vip' => false],
                    ['first_name' => 'Joao',    'last_name' => 'Mucache', 'phone' => '+258840002003', 'email' => 'joao@sunrise.example',  'role' => 'adult', 'nationality' => 'Mozambican', 'is_vip' => false],
                    ['first_name' => 'Luisa',   'last_name' => 'Bila',    'phone' => '+258840002004', 'email' => 'luisa@sunrise.example', 'role' => 'adult', 'nationality' => 'Mozambican', 'is_vip' => false],
                ],
            ],
        ];

        foreach ($groups as $g) {
            if (HotelGroup::where('created_by', $userId)->where('name', $g['group']['name'])->exists()) {
                continue;
            }

            $type = $types[$g['preferred_type']] ?? null;
            if (!$type) {
                continue;
            }
            $rate = $type->base_price;

            $availableRooms = $rooms
                ->where('hotel_room_type_id', $type->id)
                ->where('status', HotelRoom::STATUS_AVAILABLE)
                ->values();

            $group = HotelGroup::create(array_merge($g['group'], ['created_by' => $userId]));
            $sort = 0;

            foreach ($g['members'] as $idx => $m) {
                $guest = HotelGuest::create([
                    'first_name' => $m['first_name'],
                    'last_name' => $m['last_name'],
                    'phone' => $m['phone'],
                    'email' => $m['email'],
                    'nationality' => $m['nationality'] ?? null,
                    'is_vip' => $m['is_vip'] ?? false,
                    'preferred_room_type_id' => $type->id,
                    'created_by' => $userId,
                ]);

                HotelGroupMember::create([
                    'hotel_group_id' => $group->id,
                    'hotel_guest_id' => $guest->id,
                    'role' => $m['role'],
                    'sort_order' => $sort++,
                ]);

                $room = $availableRooms->get($idx);
                $isChild = ($m['role'] ?? 'adult') === 'child';

                HotelReservation::create([
                    'reservation_no' => 'RSV-DEMO-' . strtoupper(substr(md5(uniqid('', true)), 0, 8)),
                    'hotel_guest_id' => $guest->id,
                    'hotel_group_id' => $group->id,
                    'hotel_room_type_id' => $type->id,
                    'hotel_room_id' => $room?->id,
                    'check_in_at' => $g['check_in']->format('Y-m-d H:i:s'),
                    'check_out_at' => $g['check_out']->format('Y-m-d H:i:s'),
                    'check_in_date' => $g['check_in']->toDateString(),
                    'check_out_date' => $g['check_out']->toDateString(),
                    'adults' => $isChild ? 0 : 1,
                    'children' => $isChild ? 1 : 0,
                    'source' => 'manual',
                    'status' => HotelReservation::STATUS_CONFIRMED,
                    'rate_per_night' => $rate,
                    'discount_amount' => $isChild ? 0 : 0,
                    'notes' => null,
                    'created_by' => $userId,
                ]);
            }
        }
    }

    /**
     * Seed standalone reservations across statuses, sources and date ranges
     * so reports / calendar / list / dashboard all have realistic data.
     *
     * @param array<string, HotelRoomType>                   $types
     * @param \Illuminate\Support\Collection<int, HotelRoom> $rooms
     */
    protected function seedSoloReservations(int $userId, array $types, $rooms): void
    {
        $today = Carbon::today();

        $guestPool = [
            ['first_name' => 'Amina',    'last_name' => 'Mahomed',  'phone' => '+258840100001', 'email' => 'amina.mahomed@example.com',  'nationality' => 'Mozambican',   'is_vip' => false, 'id_type' => 'id',       'id_number' => 'M-100001'],
            ['first_name' => 'Diogo',    'last_name' => 'Rocha',    'phone' => '+258840100002', 'email' => 'diogo.rocha@example.com',    'nationality' => 'Portuguese',   'is_vip' => true,  'id_type' => 'passport', 'id_number' => 'PT-200002'],
            ['first_name' => 'Susana',   'last_name' => 'Khan',     'phone' => '+258840100003', 'email' => 'susana.khan@example.com',    'nationality' => 'South African','is_vip' => false, 'id_type' => 'passport', 'id_number' => 'ZA-300003'],
            ['first_name' => 'Lucas',    'last_name' => 'Ferreira', 'phone' => '+258840100004', 'email' => 'lucas.f@example.com',         'nationality' => 'Brazilian',    'is_vip' => false, 'id_type' => 'passport', 'id_number' => 'BR-400004'],
            ['first_name' => 'Mariana',  'last_name' => 'Sitoe',    'phone' => '+258840100005', 'email' => 'mariana.sitoe@example.com',   'nationality' => 'Mozambican',   'is_vip' => false, 'id_type' => 'id',       'id_number' => 'M-100005'],
            ['first_name' => 'Tomás',    'last_name' => 'Lopez',    'phone' => '+258840100006', 'email' => 'tomas.lopez@example.com',     'nationality' => 'Spanish',      'is_vip' => false, 'id_type' => 'passport', 'id_number' => 'ES-500006'],
            ['first_name' => 'Beatriz',  'last_name' => 'Macuacua', 'phone' => '+258840100007', 'email' => 'beatriz.m@example.com',       'nationality' => 'Mozambican',   'is_vip' => true,  'id_type' => 'id',       'id_number' => 'M-100007'],
            ['first_name' => 'Rafael',   'last_name' => 'Da Silva', 'phone' => '+258840100008', 'email' => 'rafael.ds@example.com',       'nationality' => 'Portuguese',   'is_vip' => false, 'id_type' => 'passport', 'id_number' => 'PT-200008'],
            ['first_name' => 'Eunice',   'last_name' => 'Chissano', 'phone' => '+258840100009', 'email' => 'eunice.c@example.com',        'nationality' => 'Mozambican',   'is_vip' => false, 'id_type' => 'id',       'id_number' => 'M-100009'],
            ['first_name' => 'Fatima',   'last_name' => 'Ali',      'phone' => '+258840100010', 'email' => 'fatima.ali@example.com',      'nationality' => 'Indian',       'is_vip' => false, 'id_type' => 'passport', 'id_number' => 'IN-600010'],
            ['first_name' => 'Hugo',     'last_name' => 'Mondlane', 'phone' => '+258840100011', 'email' => 'hugo.m@example.com',          'nationality' => 'Mozambican',   'is_vip' => false, 'id_type' => 'id',       'id_number' => 'M-100011'],
            ['first_name' => 'Camila',   'last_name' => 'Nguyen',   'phone' => '+258840100012', 'email' => 'camila.n@example.com',        'nationality' => 'Vietnamese',   'is_vip' => false, 'id_type' => 'passport', 'id_number' => 'VN-700012'],
        ];

        // Each entry: [days_offset_from_today_for_check_in, length_in_nights, status, source, type_name, payment_state]
        // payment_state: paid|partial|pending  (used later when seeding folios)
        $plan = [
            // Past — checked out (drives revenue / customer / occupancy reports)
            [-25, 2, HotelReservation::STATUS_CHECKED_OUT, 'manual',  'Standard',  'paid'],
            [-22, 3, HotelReservation::STATUS_CHECKED_OUT, 'walkin',  'Deluxe',    'paid'],
            [-18, 1, HotelReservation::STATUS_CHECKED_OUT, 'online',  'Standard',  'paid'],
            [-15, 4, HotelReservation::STATUS_CHECKED_OUT, 'ota',     'Suite',     'paid'],
            [-12, 2, HotelReservation::STATUS_CHECKED_OUT, 'manual',  'Family',    'partial'],
            [-9,  1, HotelReservation::STATUS_CANCELLED,   'online',  'Standard',  'pending'],
            [-7,  3, HotelReservation::STATUS_CHECKED_OUT, 'walkin',  'Deluxe',    'paid'],
            [-5,  2, HotelReservation::STATUS_CHECKED_OUT, 'manual',  'Executive', 'paid'],

            // Currently in-house (today)
            [-2,  4, HotelReservation::STATUS_CHECKED_IN,  'manual',  'Deluxe',    'partial'],
            [-1,  3, HotelReservation::STATUS_CHECKED_IN,  'online',  'Standard',  'paid'],
            [0,   2, HotelReservation::STATUS_CHECKED_IN,  'walkin',  'Family',    'pending'],

            // Upcoming — confirmed / pending
            [1,   2, HotelReservation::STATUS_CONFIRMED,   'manual',  'Standard',  'pending'],
            [2,   1, HotelReservation::STATUS_PENDING,     'online',  'Deluxe',    'pending'],
            [3,   3, HotelReservation::STATUS_CONFIRMED,   'ota',     'Suite',     'partial'],
            [4,   2, HotelReservation::STATUS_PENDING,     'manual',  'Standard',  'pending'],
            [6,   4, HotelReservation::STATUS_CONFIRMED,   'online',  'Executive', 'pending'],
            [10,  2, HotelReservation::STATUS_CONFIRMED,   'manual',  'Family',    'pending'],
            [14,  1, HotelReservation::STATUS_PENDING,     'walkin',  'Standard',  'pending'],
        ];

        $alreadySeeded = HotelReservation::where('created_by', $userId)
            ->where('reservation_no', 'like', 'RSV-SOLO-%')
            ->exists();
        if ($alreadySeeded) {
            return;
        }

        foreach ($plan as $i => [$dayOffset, $nights, $status, $source, $typeName, $payState]) {
            $type = $types[$typeName] ?? null;
            if (!$type) {
                continue;
            }
            $g = $guestPool[$i % count($guestPool)];
            $checkInAt = $today->copy()->addDays((int) $dayOffset)->setTime(14, 0);
            $checkOutAt = $checkInAt->copy()->addDays((int) $nights)->setTime(11, 0);

            $guest = HotelGuest::create([
                'first_name' => $g['first_name'],
                'last_name' => $g['last_name'],
                'phone' => $g['phone'],
                'email' => $g['email'],
                'nationality' => $g['nationality'],
                'id_type' => $g['id_type'],
                'id_number' => $g['id_number'],
                'is_vip' => $g['is_vip'],
                'preferred_room_type_id' => $type->id,
                'created_by' => $userId,
            ]);

            $room = $this->pickRoomForReservation($userId, $type->id, $checkInAt, $checkOutAt, $status);

            $rate = $type->base_price + ($i % 3 === 0 ? 10 : 0);
            $discount = $i % 5 === 0 ? 20 : 0;

            $reservationNo = 'RSV-SOLO-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
            $this->paymentStateMap[$reservationNo] = $payState;

            HotelReservation::create([
                'reservation_no' => $reservationNo,
                'hotel_guest_id' => $guest->id,
                'hotel_group_id' => null,
                'hotel_room_type_id' => $type->id,
                'hotel_room_id' => $room?->id,
                'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
                'check_out_at' => $checkOutAt->format('Y-m-d H:i:s'),
                'check_in_date' => $checkInAt->toDateString(),
                'check_out_date' => $checkOutAt->toDateString(),
                'adults' => 1 + ($i % 2),
                'children' => $i % 4 === 0 ? 1 : 0,
                'source' => $source,
                'status' => $status,
                'rate_per_night' => $rate,
                'discount_amount' => $discount,
                'notes' => $i % 3 === 0 ? 'Late arrival expected.' : null,
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * Find a room for the type that does not collide with the requested period.
     * For checked_in we prefer occupied rooms (will be flipped to occupied below),
     * for past stays we pick any room of the type.
     */
    protected function pickRoomForReservation(int $userId, int $typeId, Carbon $checkIn, Carbon $checkOut, string $status): ?HotelRoom
    {
        $base = HotelRoom::where('created_by', $userId)
            ->where('hotel_room_type_id', $typeId);

        if ($status === HotelReservation::STATUS_CHECKED_IN) {
            $busyRoomIds = HotelReservation::where('created_by', $userId)
                ->whereNotNull('hotel_room_id')
                ->where('status', HotelReservation::STATUS_CHECKED_IN)
                ->pluck('hotel_room_id')->all();

            $room = (clone $base)->whereNotIn('id', $busyRoomIds)->orderBy('room_no')->first();
            if ($room) {
                $room->update(['status' => HotelRoom::STATUS_OCCUPIED]);
            }

            return $room;
        }

        $busyIds = HotelReservation::where('created_by', $userId)
            ->whereNotNull('hotel_room_id')
            ->whereIn('status', [HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CHECKED_IN])
            ->where(function ($q) use ($checkIn, $checkOut) {
                $q->whereBetween('check_in_at', [$checkIn, $checkOut])
                    ->orWhereBetween('check_out_at', [$checkIn, $checkOut])
                    ->orWhere(function ($q2) use ($checkIn, $checkOut) {
                        $q2->where('check_in_at', '<', $checkIn)->where('check_out_at', '>', $checkOut);
                    });
            })
            ->pluck('hotel_room_id')->all();

        return (clone $base)->whereNotIn('id', $busyIds)->orderBy('room_no')->first()
            ?? (clone $base)->orderBy('room_no')->first();
    }

    protected function seedAddonsAndMinibar(int $userId): void
    {
        if (Schema::hasTable('hotel_addons')) {
            $addons = [
                ['name' => 'Extra bed', 'type' => HotelAddon::TYPE_EXTRA_BED, 'price' => 25, 'billing_mode' => HotelAddon::BILLING_PER_NIGHT],
                ['name' => 'Breakfast buffet', 'type' => HotelAddon::TYPE_BREAKFAST, 'price' => 15, 'billing_mode' => HotelAddon::BILLING_PER_STAY],
                ['name' => 'Parking', 'type' => HotelAddon::TYPE_PARKING, 'price' => 10, 'billing_mode' => HotelAddon::BILLING_PER_STAY],
            ];
            foreach ($addons as $row) {
                HotelAddon::firstOrCreate(
                    ['created_by' => $userId, 'name' => $row['name']],
                    array_merge($row, ['is_active' => true, 'taxable' => true, 'created_by' => $userId])
                );
            }
        }

        if (Schema::hasTable('hotel_minibar_items')) {
            foreach ([
                ['name' => 'Water (500ml)', 'unit_price' => 3.5, 'default_quantity' => 2],
                ['name' => 'Soft drink', 'unit_price' => 4.0, 'default_quantity' => 2],
                ['name' => 'Snacks', 'unit_price' => 6.5, 'default_quantity' => 1],
            ] as $item) {
                HotelMinibarItem::firstOrCreate(
                    ['created_by' => $userId, 'name' => $item['name']],
                    array_merge($item, ['is_active' => true, 'created_by' => $userId])
                );
            }
        }
    }

    /**
     * Single reservation for client demo / e2e: in-house guest with addon + minibar + partial payment.
     *
     * @param array<string, HotelRoomType> $types
     * @param \Illuminate\Support\Collection<int, HotelRoom> $rooms
     */
    protected function seedClientDemoFlow(int $userId, array $types, $rooms): void
    {
        if (HotelReservation::where('created_by', $userId)->where('reservation_no', 'RSV-DEMO-FLOW')->exists()) {
            return;
        }

        $type = $types['Standard'] ?? reset($types);
        if (! $type) {
            return;
        }

        $room = $rooms->firstWhere('room_no', '101')
            ?? $rooms->where('hotel_room_type_id', $type->id)->where('status', HotelRoom::STATUS_AVAILABLE)->first();

        if (! $room) {
            return;
        }

        $today = Carbon::today();
        $checkIn = $today->copy()->setTime(14, 0);
        $checkOut = $today->copy()->addDay()->setTime(11, 0);

        $guest = HotelGuest::create([
            'first_name' => 'Demo',
            'last_name' => 'Flow',
            'email' => 'demo.flow@hotel.example',
            'phone' => '+258840199999',
            'nationality' => 'Mozambican',
            'is_vip' => true,
            'preferred_room_type_id' => $type->id,
            'created_by' => $userId,
        ]);

        $extraBed = Schema::hasTable('hotel_addons')
            ? HotelAddon::where('created_by', $userId)->where('type', HotelAddon::TYPE_EXTRA_BED)->first()
            : null;

        $reservation = HotelReservation::create([
            'reservation_no' => 'RSV-DEMO-FLOW',
            'hotel_guest_id' => $guest->id,
            'hotel_room_type_id' => $type->id,
            'hotel_room_id' => $room->id,
            'check_in_at' => $checkIn->format('Y-m-d H:i:s'),
            'check_out_at' => $checkOut->format('Y-m-d H:i:s'),
            'check_in_date' => $checkIn->toDateString(),
            'check_out_date' => $checkOut->toDateString(),
            'adults' => 2,
            'children' => 1,
            'source' => 'manual',
            'status' => HotelReservation::STATUS_CHECKED_IN,
            'rate_per_night' => (float) $type->base_price,
            'discount_amount' => 0,
            'notes' => 'Client demo — use for E2E walkthrough or hotelmanagement:e2e-demo --checkout',
            'created_by' => $userId,
        ]);

        $room->update(['status' => HotelRoom::STATUS_OCCUPIED]);

        $stay = HotelStay::create([
            'hotel_reservation_id' => $reservation->id,
            'hotel_guest_id' => $guest->id,
            'hotel_room_id' => $room->id,
            'check_in_at' => $checkIn->format('Y-m-d H:i:s'),
            'expected_check_out_at' => $checkOut->format('Y-m-d H:i:s'),
            'status' => 'in_house',
            'minibar_verified_at' => Schema::hasColumn('hotel_stays', 'minibar_verified_at') ? now() : null,
            'created_by' => $userId,
        ]);

        $nights = 1;
        $rate = (float) $reservation->rate_per_night;
        $roomCharge = $rate * $nights;
        $addonTotal = 0.0;

        $folio = HotelFolio::create([
            'hotel_stay_id' => $stay->id,
            'folio_no' => 'FOL-DEMO-FLOW',
            'sub_total' => $roomCharge,
            'grand_total' => $roomCharge,
            'paid_total' => 0,
            'status' => 'open',
            'created_by' => $userId,
        ]);

        HotelFolioLine::create([
            'hotel_folio_id' => $folio->id,
            'line_type' => 'room_charge',
            'description' => 'Room charge (1 night)',
            'quantity' => $nights,
            'unit_price' => $rate,
            'line_total' => $roomCharge,
            'created_by' => $userId,
        ]);

        if ($extraBed && Schema::hasTable('hotel_reservation_addons')) {
            $addonQty = 1;
            $addonLineTotal = (float) $extraBed->price * $nights * $addonQty;
            $addonTotal += $addonLineTotal;

            $addonLine = HotelFolioLine::create([
                'hotel_folio_id' => $folio->id,
                'line_type' => 'addon_charge',
                'description' => $extraBed->name . ' (1 night)',
                'quantity' => $nights,
                'unit_price' => (float) $extraBed->price,
                'line_total' => $addonLineTotal,
                'hotel_addon_id' => $extraBed->id,
                'created_by' => $userId,
            ]);

            HotelReservationAddon::create([
                'hotel_reservation_id' => $reservation->id,
                'hotel_addon_id' => $extraBed->id,
                'qty' => $addonQty,
                'unit_price' => (float) $extraBed->price,
                'billing_mode' => $extraBed->billing_mode,
                'hotel_folio_line_id' => $addonLine->id,
                'created_by' => $userId,
            ]);
        }

        $minibarTotal = 8.5;
        HotelFolioLine::create([
            'hotel_folio_id' => $folio->id,
            'line_type' => 'misc_charge',
            'description' => 'Minibar — Soft drink × 2',
            'quantity' => 2,
            'unit_price' => 4.25,
            'line_total' => $minibarTotal,
            'created_by' => $userId,
        ]);

        $grand = $roomCharge + $addonTotal + $minibarTotal;
        $partial = round($grand * 0.4, 2);

        $folio->update([
            'sub_total' => $grand,
            'grand_total' => $grand,
        ]);

        HotelFolioPayment::create([
            'hotel_folio_id' => $folio->id,
            'amount' => $partial,
            'method' => 'mpesa',
            'reference' => 'DEMO-FLOW-ADV',
            'notes' => 'Advance payment — demo',
            'created_by' => $userId,
        ]);

        $folio->update(['paid_total' => $partial]);
    }

    /**
     * Build hotel_stays + hotel_folios + hotel_folio_lines + hotel_folio_payments for
     * checked_in / checked_out reservations seeded above.
     */
    protected function seedStaysAndFolios(int $userId): void
    {
        $reservations = HotelReservation::where('created_by', $userId)
            ->whereIn('status', [HotelReservation::STATUS_CHECKED_IN, HotelReservation::STATUS_CHECKED_OUT])
            ->whereNotNull('hotel_room_id')
            ->where('reservation_no', '!=', 'RSV-DEMO-FLOW')
            ->get();

        $extraOptions = [
            ['line_type' => 'pos_charge', 'description' => 'Restaurant — dinner', 'qty' => 1, 'price' => 35.00],
            ['line_type' => 'pos_charge', 'description' => 'Mini-bar — beverages', 'qty' => 2, 'price' => 8.50],
            ['line_type' => 'pos_charge', 'description' => 'Spa & wellness', 'qty' => 1, 'price' => 65.00],
            ['line_type' => 'pos_charge', 'description' => 'Laundry service', 'qty' => 1, 'price' => 20.00],
        ];

        $methods = ['cash', 'mpesa', 'card', 'bank_transfer'];

        foreach ($reservations as $i => $reservation) {
            if (HotelStay::where('hotel_reservation_id', $reservation->id)->exists()) {
                continue;
            }

            $checkInAt = Carbon::parse($reservation->check_in_at);
            $expectedOut = Carbon::parse($reservation->check_out_at);
            $isCheckedOut = $reservation->status === HotelReservation::STATUS_CHECKED_OUT;

            $stay = HotelStay::create([
                'hotel_reservation_id' => $reservation->id,
                'hotel_guest_id' => $reservation->hotel_guest_id,
                'hotel_room_id' => $reservation->hotel_room_id,
                'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
                'expected_check_out_at' => $expectedOut->format('Y-m-d H:i:s'),
                'checked_out_at' => $isCheckedOut ? $expectedOut->format('Y-m-d H:i:s') : null,
                'status' => $isCheckedOut ? 'checked_out' : 'in_house',
                'extra_charge' => 0,
                'notes' => null,
                'created_by' => $userId,
            ]);

            $nights = max(1, (int) ceil($checkInAt->diffInHours($expectedOut) / 24));
            $rate = (float) $reservation->rate_per_night;
            $roomCharge = $rate * $nights;
            $discount = (float) $reservation->discount_amount;

            $folio = HotelFolio::create([
                'hotel_stay_id' => $stay->id,
                'folio_no' => 'FOL-DEMO-' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                'sub_total' => $roomCharge,
                'tax_total' => 0,
                'discount_total' => $discount,
                'grand_total' => max(0, $roomCharge - $discount),
                'paid_total' => 0,
                'status' => $isCheckedOut ? 'settled' : 'open',
                'created_by' => $userId,
            ]);

            HotelFolioLine::create([
                'hotel_folio_id' => $folio->id,
                'line_type' => 'room_charge',
                'description' => 'Room charge (' . $nights . ' night' . ($nights === 1 ? '' : 's') . ')',
                'quantity' => $nights,
                'unit_price' => $rate,
                'tax_amount' => 0,
                'discount_amount' => $discount,
                'line_total' => max(0, $roomCharge - $discount),
                'created_by' => $userId,
            ]);

            $extraTotal = 0;
            $extraCount = $i % 3;
            for ($e = 0; $e < $extraCount; $e++) {
                $opt = $extraOptions[($i + $e) % count($extraOptions)];
                $lineTotal = $opt['qty'] * $opt['price'];
                HotelFolioLine::create([
                    'hotel_folio_id' => $folio->id,
                    'line_type' => $opt['line_type'],
                    'description' => $opt['description'],
                    'quantity' => $opt['qty'],
                    'unit_price' => $opt['price'],
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'line_total' => $lineTotal,
                    'created_by' => $userId,
                ]);
                $extraTotal += $lineTotal;
            }

            $grand = max(0, $roomCharge - $discount) + $extraTotal;
            $folio->update([
                'sub_total' => $roomCharge + $extraTotal,
                'grand_total' => $grand,
            ]);

            $payState = $this->paymentStateMap[$reservation->reservation_no] ?? ($isCheckedOut ? 'paid' : 'pending');
            $paid = match ($payState) {
                'paid' => $grand,
                'partial' => round($grand * 0.5, 2),
                default => 0.0,
            };

            if ($paid > 0) {
                HotelFolioPayment::create([
                    'hotel_folio_id' => $folio->id,
                    'amount' => $paid,
                    'method' => $methods[$i % count($methods)],
                    'reference' => 'DEMO-' . strtoupper(substr(md5($folio->id . '-' . $i), 0, 8)),
                    'notes' => null,
                    'created_by' => $userId,
                ]);
            }

            $folio->update([
                'paid_total' => $paid,
                'status' => $paid >= $grand - 0.01 ? 'settled' : 'open',
            ]);
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int, HotelRoom> $rooms
     */
    protected function seedHousekeepingTasks(int $userId, $rooms): void
    {
        if ($rooms->isEmpty()) {
            return;
        }

        $assignee = User::where('created_by', $userId)
            ->orWhere('id', $userId)
            ->orderBy('id')
            ->first();
        $assigneeId = $assignee?->id ?? $userId;

        $samples = [
            ['status' => 'pending',     'notes' => 'Standard turn-down service.'],
            ['status' => 'in_progress', 'notes' => 'Deep clean after extended stay.'],
            ['status' => 'pending',     'notes' => 'Replace towels and linens.'],
            ['status' => 'done',        'notes' => 'Completed; ready for next guest.'],
            ['status' => 'pending',     'notes' => 'Air conditioning maintenance check.'],
            ['status' => 'in_progress', 'notes' => 'Restock minibar and amenities.'],
        ];

        $targetRooms = $rooms->whereIn('status', [
            HotelRoom::STATUS_CLEANING,
            HotelRoom::STATUS_OCCUPIED,
            HotelRoom::STATUS_AVAILABLE,
        ])->take(count($samples))->values();

        foreach ($targetRooms as $idx => $room) {
            $exists = HotelHousekeepingTask::where('created_by', $userId)
                ->where('hotel_room_id', $room->id)
                ->whereDate('created_at', Carbon::today())
                ->exists();
            if ($exists) {
                continue;
            }
            $sample = $samples[$idx % count($samples)];
            HotelHousekeepingTask::create([
                'hotel_room_id' => $room->id,
                'assigned_to' => $assigneeId,
                'status' => $sample['status'],
                'notes' => $sample['notes'],
                'created_by' => $userId,
            ]);
        }
    }

    protected function seedBookingSite(int $userId): void
    {
        if (! Schema::hasTable('hotel_booking_sites')) {
            return;
        }

        if (HotelBookingSite::where('created_by', $userId)->exists()) {
            return;
        }

        $company = User::find($userId);

        HotelBookingSite::create([
            'created_by' => $userId,
            'slug' => 'demo-hotel',
            'is_published' => true,
            'display_name' => $company?->name ?? 'Demo Hotel',
            'tagline' => __('Your perfect stay awaits'),
            'description' => __('Book directly with us for the best rates and personalized service.'),
            'accent_color' => '#0d9488',
            'contact_email' => $company?->email,
            'contact_phone' => '+258840000000',
            'address' => 'Maputo, Mozambique',
            'default_language' => 'en',
            'check_in_time' => '14:00',
            'check_out_time' => '11:00',
            'cancellation_policy' => __('Free cancellation up to 24 hours before check-in.'),
            'payment_mode' => HotelBookingSite::PAY_AT_HOTEL,
            'amenities' => ['Wi-Fi', 'Parking', 'Restaurant', 'Air conditioning'],
        ]);

        $this->command?->info('Demo booking site: ' . url('/demo-hotel'));
    }
}
