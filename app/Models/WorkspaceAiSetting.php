<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string|null $gemini_api_key
 * @property string|null $groq_api_key
 * @property Carbon|null $configured_at
 */
#[Fillable([
    'gemini_api_key',
    'groq_api_key',
    'configured_at',
])]
#[Hidden([
    'gemini_api_key',
    'groq_api_key',
])]
class WorkspaceAiSetting extends Model
{
    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function hasGemini(): bool
    {
        return $this->filled($this->gemini_api_key);
    }

    public function hasGroq(): bool
    {
        return $this->filled($this->groq_api_key);
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gemini_api_key' => 'encrypted',
            'groq_api_key' => 'encrypted',
            'configured_at' => 'datetime',
        ];
    }
}
