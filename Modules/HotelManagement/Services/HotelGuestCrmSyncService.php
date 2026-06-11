<?php

namespace Modules\HotelManagement\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Modules\HotelManagement\Entities\HotelGuest;

class HotelGuestCrmSyncService
{
    public function syncGuest(HotelGuest $guest, int $creatorId): ?Customer
    {
        if ($guest->customer_id) {
            $existing = Customer::where('created_by', $creatorId)->find($guest->customer_id);
            if ($existing) {
                $this->updateCustomerFromGuest($existing, $guest);

                return $existing;
            }
        }

        $email = trim((string) ($guest->email ?? ''));
        $phone = trim((string) ($guest->phone ?? ''));

        if ($email === '' && $phone === '') {
            return null;
        }

        $customer = Customer::where('created_by', $creatorId)
            ->when($email !== '', fn ($q) => $q->where('email', $email))
            ->when($email === '' && $phone !== '', fn ($q) => $q->where('contact', $phone))
            ->first();

        if (! $customer) {
            $customer = $this->createCustomerFromGuest($guest, $creatorId);
        } else {
            $this->updateCustomerFromGuest($customer, $guest);
        }

        if (! $guest->customer_id) {
            $guest->update(['customer_id' => $customer->id]);
        }

        return $customer;
    }

    private function createCustomerFromGuest(HotelGuest $guest, int $creatorId): Customer
    {
        $latest = Customer::where('created_by', $creatorId)->latest()->first();
        $customerId = $latest ? ((int) $latest->customer_id + 1) : 1;

        $defaultLang = DB::table('settings')->where('name', 'default_language')->value('value');

        $customer = new Customer();
        $customer->customer_id = $customerId;
        $customer->name = trim($guest->first_name . ' ' . ($guest->last_name ?? ''));
        $customer->email = $guest->email;
        $customer->contact = $guest->phone;
        $customer->payment_type = 'cash';
        $customer->is_active = 1;
        $customer->created_by = $creatorId;
        $customer->lang = $defaultLang ?: '';
        $customer->bank_account = json_encode([]);
        $customer->save();

        return $customer;
    }

    private function updateCustomerFromGuest(Customer $customer, HotelGuest $guest): void
    {
        $name = trim($guest->first_name . ' ' . ($guest->last_name ?? ''));
        $updates = [];

        if ($name !== '' && blank($customer->name)) {
            $updates['name'] = $name;
        }
        if ($guest->email && blank($customer->email)) {
            $updates['email'] = $guest->email;
        }
        if ($guest->phone && blank($customer->contact)) {
            $updates['contact'] = $guest->phone;
        }

        if ($updates !== []) {
            $customer->update($updates);
        }
    }
}
