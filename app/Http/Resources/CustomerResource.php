<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type ?: '6',
            'ruc' => $this->ruc,
            'name' => $this->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
