<?php

namespace App\Http\Requests;

use App\Models\Region;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePharmacyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
    }

    public function rules(): array
    {
        $phone = ['required', 'string', 'max:20', 'regex:/^\+?[0-9][0-9 ()\-]{6,19}$/'];
        $rules = [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/'], 'name' => ['required', 'string', 'min:3', 'max:150'],
            'ownership_type' => ['required', Rule::in(['Individual', 'Legal Entity'])],
            'business_name' => ['nullable', 'required_if:ownership_type,Legal Entity', 'string', 'max:200'],
            'legal_form' => ['nullable', 'required_if:ownership_type,Legal Entity', Rule::in(['PT', 'CV', 'Koperasi', 'Yayasan', 'Lainnya'])],
            'phone' => $phone, 'email' => ['nullable', 'email', 'max:254'], 'website' => ['nullable', 'url:http,https', 'max:500'],
            'address' => ['required', 'string', 'max:500'], 'postal_code' => ['nullable', 'string', 'max:10'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'maps_url' => ['nullable', 'url:http,https', 'max:500'], 'owner_name' => ['required', 'string', 'max:150'], 'owner_phone' => $phone, 'owner_email' => ['nullable', 'email', 'max:254'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
        foreach (['province', 'city', 'district', 'village'] as $index => $level) {
            $rules[$level.'_code'] = ['required', 'string', Rule::exists('regions', 'code')->where('level', $index + 1)];
        }

        $rules['owners'] = ['sometimes', 'required', 'array', 'list', 'min:1', 'max:100'];
        $rules['owners.*'] = ['required', 'array:name,phone,email,share_percentage'];
        $rules['owners.*.name'] = ['required', 'string', 'max:150'];
        $rules['owners.*.phone'] = $phone;
        $rules['owners.*.email'] = ['nullable', 'email', 'max:254'];
        $rules['owners.*.share_percentage'] = ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'];
        if ($this->exists('owners')) {
            unset($rules['owner_name'], $rules['owner_phone'], $rules['owner_email']);
        }

        return $rules;
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($this->exists('owners') && ! $validator->errors()->hasAny(['owners', 'owners.*'])) {
                $total = 0;
                foreach ($this->input('owners', []) as $owner) {
                    $total += (int) round((float) $owner['share_percentage'] * 100);
                }
                if ($total !== 10000) {
                    $validator->errors()->add('owners', 'Total persentase saham seluruh pemilik harus 100%.');
                }
            }
            foreach (['city' => 'province', 'district' => 'city', 'village' => 'district'] as $child => $parent) {
                if ($this->filled($child.'_code') && ! Region::where('code', $this->input($child.'_code'))->where('parent_code', $this->input($parent.'_code'))->exists()) {
                    $validator->errors()->add($child.'_code', 'Pilihan wilayah tidak sesuai dengan wilayah induknya.');
                }
            }
        }];
    }
}
