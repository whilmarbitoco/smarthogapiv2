<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmSummaryResource extends JsonResource
{
 public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'location' => $this->location,
            'timezone' => $this->timezone,
        ];
    }
}
