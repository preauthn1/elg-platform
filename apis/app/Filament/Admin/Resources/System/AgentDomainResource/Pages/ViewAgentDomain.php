<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\System\AgentDomainResource\Pages;

use App\Filament\Admin\Resources\System\AgentDomainResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAgentDomain extends ViewRecord
{
    protected static string $resource = AgentDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
