<?php

namespace App\Modules\Automation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Automation extends Model
{
    protected $table = 'automations';

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = ['workspace_id', 'name', 'status', 'trigger_type', 'trigger_config', 'trigger_token', 'nodes', 'edges', 'run_count'];

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'nodes' => 'array',
            'edges' => 'array',
        ];
    }

    public function runs()
    {
        return $this->hasMany(AutomationRun::class, 'automation_id');
    }

    public function isActive()
    {
        return $this->status === 'active';
    }

    /**
     * Canonicalise a node list posted by the builder so storage and runtime agree:
     * the trigger is always type 'trigger', and each step's type is its real node
     * type (the builder keeps that in data.nodeType and uses type for React Flow).
     *
     * @param  array<int, mixed>  $nodes
     * @return array<int, mixed>
     */
    public static function normalizeNodes(array $nodes): array
    {
        return array_values(array_map(function ($node) {
            if (! is_array($node)) {
                return $node;
            }

            $data = is_array($node['data'] ?? null) ? $node['data'] : [];

            if (in_array($node['type'] ?? '', ['trigger', 'triggerNode'], true) || array_key_exists('triggerType', $data)) {
                $node['type'] = 'trigger';

                return $node;
            }

            if (is_string($data['nodeType'] ?? null) && $data['nodeType'] !== '') {
                $node['type'] = $data['nodeType'];
            }

            return $node;
        }, $nodes));
    }
}
