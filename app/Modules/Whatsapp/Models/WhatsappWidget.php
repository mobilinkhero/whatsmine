<?php

namespace App\Modules\Whatsapp\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $widget_key
 * @property string|null $name
 * @property string|null $phone_number_id
 * @property string|null $display_phone
 * @property string|null $prefilled_message
 * @property string|null $greeting_message
 * @property string|null $agent_name
 * @property string|null $agent_avatar_color
 * @property string|null $button_color
 * @property string $position
 * @property array<int, string>|null $allowed_domains
 * @property array<string, mixed>|null $working_hours_json
 * @property-read string|null $embed_url
 */
class WhatsappWidget extends Model
{
    protected $table = 'whatsapp_widgets';

    protected $fillable = [
        'workspace_id', 'widget_key', 'phone_number_id', 'display_phone',
        'name', 'prefilled_message', 'greeting_message', 'agent_name', 'agent_avatar_color',
        'button_color', 'position', 'allowed_domains', 'working_hours_json',
    ];

    /**
     * The public script URL is generated here, not in the browser, so it
     * carries the app's real base path. Installs that serve the app from a
     * sub-directory (e.g. https://example.com/public/) would otherwise get a
     * snippet built from window.location.origin that drops that prefix and
     * 404s on the customer's website.
     */
    protected $appends = ['embed_url'];

    /** @return Attribute<string|null, never> */
    protected function embedUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->widget_key ? route('whatsapp.widget.embed', ['key' => $this->widget_key]) : null,
        );
    }

    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'working_hours_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->widget_key)) {
                $model->widget_key = Str::random(32);
            }
        });
    }
}
