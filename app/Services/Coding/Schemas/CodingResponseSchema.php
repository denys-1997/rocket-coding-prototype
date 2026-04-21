<?php

declare(strict_types=1);

namespace App\Services\Coding\Schemas;

/**
 * JSON Schema for the structured coding-suggestion output.
 *
 * This is the *source of truth* for what Claude must return. It is embedded
 * in the system prompt (so the model sees it explicitly) and used by
 * {@see \App\Services\Coding\CodingSuggestionService} for post-response
 * validation via opis/json-schema.
 *
 * Keeping the schema in one place means the prompt and the validator can
 * never drift apart.
 */
final class CodingResponseSchema
{
    public static function asArray(): array
    {
        return [
            '$schema'              => 'https://json-schema.org/draft/2020-12/schema',
            'title'                => 'CodingSuggestionResponse',
            'type'                 => 'object',
            'required'             => ['encounter_summary', 'cpt_codes', 'icd10_codes', 'confidence'],
            'additionalProperties' => false,
            'properties'           => [
                'encounter_summary' => [
                    'type'      => 'string',
                    'minLength' => 20,
                    'maxLength' => 2000,
                ],
                'cpt_codes' => [
                    'type'     => 'array',
                    'minItems' => 0,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['code', 'description', 'rationale', 'modifiers'],
                        'properties'           => [
                            'code' => [
                                'type'        => 'string',
                                'pattern'     => '^[0-9]{5}$',
                                'description' => '5-digit CPT code',
                            ],
                            'description' => ['type' => 'string'],
                            'rationale'   => ['type' => 'string', 'minLength' => 10],
                            'modifiers'   => [
                                'type'  => 'array',
                                'items' => [
                                    'type'    => 'string',
                                    'enum'    => ['25', '26', '59', 'XS', 'XE', 'XP', 'XU', 'LT', 'RT', '50', '51', '52', '53', '54', '55', '56', '57', '58', '76', '77', '78', '79', '80', '81', '82', 'TC', '91'],
                                ],
                            ],
                        ],
                    ],
                ],
                'icd10_codes' => [
                    'type'     => 'array',
                    'minItems' => 0,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['code', 'description', 'rationale'],
                        'properties'           => [
                            'code' => [
                                'type'        => 'string',
                                'pattern'     => '^[A-TV-Z][0-9][0-9AB](\.[0-9A-Z]{1,4})?$',
                                'description' => 'ICD-10-CM code, e.g. S52.501A',
                            ],
                            'description' => ['type' => 'string'],
                            'rationale'   => ['type' => 'string', 'minLength' => 10],
                        ],
                    ],
                ],
                'confidence' => [
                    'type'    => 'string',
                    'enum'    => ['high', 'medium', 'low'],
                ],
                'flags' => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['kind', 'message'],
                        'properties'           => [
                            'kind'    => ['type' => 'string', 'enum' => ['ncci_conflict', 'missing_modifier', 'documentation_gap', 'em_level_uncertain']],
                            'message' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function asJson(): string
    {
        return json_encode(
            self::asArray(),
            JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
    }
}
