<?php

declare(strict_types=1);

namespace App\Filament\Admin\Forms;

use Filament\Forms\Components\Field;

class RadioWithImageTag extends Field
{
    protected string $view = 'forms.components.radio-with-image-tag';

    protected array $templates = [];

    public function templates(array $templates): static
    {
        $this->templates = $templates;

        return $this;
    }

    public function getTemplates(): array
    {
        return $this->templates;
    }
}
