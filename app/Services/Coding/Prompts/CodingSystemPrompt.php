<?php

declare(strict_types=1);

namespace App\Services\Coding\Prompts;

use App\Services\Coding\Schemas\CodingResponseSchema;

final class CodingSystemPrompt
{
    public static function base(): string
    {
        $schema = CodingResponseSchema::asJson();

        $intro = <<<'EOT'
You are a certified medical coding assistant operating under OIG-compliant audit
controls. You analyze clinical documentation (chart notes, operative reports,
progress notes) and return structured suggestions for CPT, HCPCS, and ICD-10-CM
codes along with applicable modifiers.

Your output MUST be a single JSON object -- no prose, no markdown, no apologies,
no code fences -- conforming exactly to this JSON Schema:
EOT;

        $rules = <<<'EOT'
Rules:
1. Every CPT code MUST be exactly 5 digits. Do not invent codes you are unsure
   of; prefer omitting a suggestion over hallucinating a code.
2. Every modifier MUST come from the enum in the schema. Do not invent
   modifiers.
3. Every suggestion MUST include a "rationale" field grounded in the
   documentation. If the documentation does not support a code, do NOT suggest
   it.
4. If the documentation is ambiguous (e.g. E/M level uncertain between 99213
   and 99214), add a "flags" entry with kind "em_level_uncertain" and explain.
5. If NCCI bundling is likely (e.g. a column-1 / column-2 pair), add a "flags"
   entry with kind "ncci_conflict".
6. Do NOT provide patient-identifying information back in the response. Strip
   names, DOB, MRN, and any obvious PHI.
7. If you cannot comply with the schema for any reason, return a valid JSON
   object with "confidence" set to "low" and empty code arrays -- NEVER respond
   in natural language.

Certified human coders will review every suggestion. Your role is first-pass
augmentation, not final billing. Accuracy and auditability outweigh coverage.
EOT;

        return $intro . "\n\n" . $schema . "\n\n" . $rules;
    }

    /**
     * Corrective system prompt for retry after a schema validation failure.
     *
     * We append the original malformed output and the schema violations so the
     * model knows exactly what went wrong.
     *
     * @param  list<string>  $schemaErrors
     */
    public static function correction(string $malformedResponse, array $schemaErrors): string
    {
        $errorList = '  - ' . implode("\n  - ", $schemaErrors);

        $header = <<<'EOT'


---
The previous response did not conform to the required JSON Schema.

Previous response (verbatim):
EOT;

        $footer = <<<'EOT'


Respond now with ONLY a corrected JSON object conforming to the schema above.
No prose, no apologies, no markdown. Just the JSON object.
EOT;

        return self::base()
            . $header
            . "\n" . $malformedResponse
            . "\n\nSchema violations:\n" . $errorList
            . $footer;
    }
}
