<?php

namespace App\Actions\Crm;

use App\Models\CustomerInteraction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class CreateCustomerInteraction
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validate(array $data): array
    {
        return Validator::make($data, [
            'customer_id' => ['required', 'integer', 'min:1', 'exists:customers,id'],
            'initiator' => ['required', 'string', Rule::in(['Customer', 'Coordinator'])],
            'reason' => ['required', 'string', Rule::in(['Product Inquiry', 'Billing Question', 'Complaint', 'Feedback'])],
            'description' => ['required', 'string', 'min:1', 'max:10000'],
            'timestamp' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
            'interaction_rating' => ['required', 'integer', 'min:1', 'max:5'],
        ])->validate();
    }

    /** @param array<string, mixed> $data */
    public function handle(array $data): CustomerInteraction
    {
        $values = $this->validate($data);

        return DB::transaction(fn (): CustomerInteraction => CustomerInteraction::query()->create($values));
    }
}
