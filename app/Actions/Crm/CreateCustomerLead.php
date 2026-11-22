<?php

namespace App\Actions\Crm;

use App\Models\CustomerLead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CreateCustomerLead
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validate(array $data): array
    {
        return Validator::make($data, [
            'first_name' => ['required', 'string', 'min:1', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'min:1', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('customer_leads', 'email')->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'min:1', 'max:50'],
            'source' => ['required', 'string', 'min:1', 'max:100'],
            'source_category' => ['required', 'string', Rule::in(array_keys(CustomerLead::sourceCategories()))],
            'campaign_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'referral_partner' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source_captured_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\\TH:i:sP'],
            'topic_interest' => ['required', 'string', 'min:1', 'max:255'],
            'product_interest' => ['required', 'string', 'min:1', 'max:255'],
            'last_contacted_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
        ])->validate();
    }

    /** @param array<string, mixed> $data */
    public function handle(array $data): CustomerLead
    {
        $values = $this->validate($data);
        $values['middle_name'] = $values['middle_name'] ?? '';

        return DB::transaction(fn (): CustomerLead => CustomerLead::query()->create([
            ...$values,
            'status' => 'new',
        ]));
    }
}
