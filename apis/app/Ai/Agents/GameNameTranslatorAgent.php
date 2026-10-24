<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[UseCheapestModel]
class GameNameTranslatorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You translate casino game titles (slots, table games, fishing games) for an online gaming platform\'s game lobby into the target language named in the request. '
            . 'Use the name a local player would recognize: translate descriptive words, and keep brand names, character names and other proper nouns as they are when no established translation exists. '
            . 'Return one entry per input name, and copy each input name into "original" exactly as given, character for character, because entries are matched back to the database by that exact string.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'translations' => $schema->array()
                ->items(
                    $schema->object([
                        'original' => $schema->string()->required(),
                        'translated' => $schema->string()->required(),
                    ])
                )
                ->required(),
        ];
    }
}
