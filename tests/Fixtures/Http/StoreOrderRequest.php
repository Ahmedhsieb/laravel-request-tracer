<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'coupon' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'in:card,paypal'],
            'card_number' => ['nullable', 'string'],
        ];
    }
}
