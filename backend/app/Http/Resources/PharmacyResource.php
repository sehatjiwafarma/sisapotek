<?php

namespace App\Http\Resources;

use App\Services\PharmacyMaster;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PharmacyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $member = $this->memberships()->with('roles')->where('user_id', $request->user()->id)->where('active', true)->first();
        $full = $request->user()->is_system_admin || $member?->allows('pharmacy.view_sensitive');
        $data = $this->resource->only(['id', 'code', 'name', 'status', 'address', 'phone', 'email', 'website', 'province_code', 'city_code', 'district_code', 'village_code', 'postal_code', 'latitude', 'longitude', 'maps_url']);
        $data['hours'] = $this->hours()->get()->makeHidden(['id', 'pharmacy_id', 'created_at', 'updated_at', 'start_minute', 'end_minute']);
        if ($full) {
            $data['owners'] = $this->owners;
            $data += $this->resource->only(['ownership_type', 'business_name', 'legal_form', 'owner_name', 'owner_phone', 'owner_email', 'activated_at', 'activated_by', 'deactivated_at', 'deactivation_reason']);
            $data['license'] = $this->license?->makeHidden(['id', 'pharmacy_id', 'created_at', 'updated_at']);
            $data['settings'] = $this->settings?->makeHidden(['id', 'pharmacy_id', 'created_at', 'updated_at']);
            $data['missing_requirements'] = app(PharmacyMaster::class)->missing($this->resource);
        }

        return $data;
    }
}
