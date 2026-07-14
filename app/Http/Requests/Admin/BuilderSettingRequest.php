<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BuilderSettingRequest extends FormRequest
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
            'key'   => ['required', 'string'],
            'value' => ['present'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $key = $this->input('key');
            if (!array_key_exists($key, self::SETTING_TYPES)) {
                $validator->errors()->add('key', "Unknown setting key: {$key}");
                return;
            }

            $expected = self::SETTING_TYPES[$key];
            $actual = $expected === 'array' ? is_array($this->input('value')) : is_bool($this->input('value'));
            if (!$actual) {
                $validator->errors()->add('value', "Setting '{$key}' must be {$expected}");
            }
        });
    }
}
