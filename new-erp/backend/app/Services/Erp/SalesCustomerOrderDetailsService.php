<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesCustomerAddress;
use App\Models\Erp\SalesCustomerContact;

/** Called inside the order transaction when no customer detail was selected explicitly. */
class SalesCustomerOrderDetailsService
{
    public function contact(SalesCustomer $customer, string $name, ?string $phone): ?SalesCustomerContact
    {
        if ($name === '' && $phone === null) return null;
        $name = $name ?: $customer->customer_name;
        $this->lockCustomer($customer);
        $contact = $customer->contacts()->where('status', 'enabled')->where('contact_name', $name)
            ->orderByDesc('is_default')->orderBy('id')->lockForUpdate()->get()
            ->first(fn (SalesCustomerContact $row) => $row->contact_name === $name
                && ($row->mobile ?: $row->phone ?: null) === $phone);
        if ($contact) return $contact;

        $customer->contacts()->where('is_default', true)->update(['is_default' => false]);
        $contact = $customer->contacts()->create([
            'contact_name' => $name, 'mobile' => $phone, 'is_default' => true, 'status' => 'enabled',
        ]);
        $customer->update(['contact_name' => $name, 'contact_phone' => $phone]);
        return $contact;
    }

    public function address(
        SalesCustomer $customer, string $fullAddress, string $receiverName, ?string $receiverPhone, ?string $addressKey,
    ): ?SalesCustomerAddress {
        if ($fullAddress === '') return null;
        $receiverName = $receiverName ?: $customer->customer_name;
        $this->lockCustomer($customer);
        $address = $customer->addresses()->where('status', 'enabled')->where('full_address', $fullAddress)
            ->where('receiver_name', $receiverName)->orderByDesc('is_default')->orderBy('id')->lockForUpdate()->get()
            ->first(fn (SalesCustomerAddress $row) => $row->full_address === $fullAddress
                && $row->receiver_name === $receiverName && ($row->receiver_phone ?: null) === $receiverPhone);
        if ($address) return $address;

        $customer->addresses()->where('is_default', true)->update(['is_default' => false]);
        // 订单上的自由文本不能替换原地址的地区关联。
        $address = $customer->addresses()->create([
            'receiver_name' => $receiverName, 'receiver_phone' => $receiverPhone,
            'detail_address' => $fullAddress, 'full_address' => $fullAddress,
            'is_default' => true, 'status' => 'enabled',
        ]);
        $customer->update([
            'full_address' => $fullAddress, 'dedupe_address_key' => $addressKey,
            'province' => null, 'city' => null, 'district' => null,
        ]);
        return $address;
    }

    private function lockCustomer(SalesCustomer $customer): void
    {
        SalesCustomer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail(['id']);
    }
}
