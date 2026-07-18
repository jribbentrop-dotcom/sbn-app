<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBuilderSettingRequest extends FormRequest
{
    /**
     * Known setting keys and the value type each must hold. A bad key or a
     * wrong-typed value persists to sbn_builder_settings and then breaks
     * every builder call site-wide, so reject anything unexpected here.
     */
    public const SETTING_TYPES = [
        'category_pools'           => 'array',
        'blues_advanced_pool'      => 'array',
        'register_targets'         => 'array',
        'cost_weights'             => 'array',
        'pass2_eligible'           => 'array',
        'pass1_extensions_allowed' => 'array',
        'root_only_default'        => 'array',
        'tonic_widen_default'      => 'array',
        'default_voicing_style'    => 'array',
        'repeated_chord_reuse'     => 'boolean',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->isInstructor();
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', Rule::in(array_keys(self::SETTING_TYPES))],
            'value' => ['required', function ($attribute, $value, $fail) {
                $expected = self::SETTING_TYPES[$this->input('key')] ?? null;
                if ($expected === null) {
                    return; // the 'key' rule above already rejects unknown keys
                }
                $actual = $expected === 'array' ? is_array($value) : is_bool($value);
                if (!$actual) {
                    $fail("Setting '{$this->input('key')}' must be {$expected}.");
                }
            }],
        ];
    }
}
