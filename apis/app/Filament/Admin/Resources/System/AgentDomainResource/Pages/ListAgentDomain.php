<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\System\AgentDomainResource\Pages;

use App\Filament\Admin\Resources\System\AgentDomainResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAgentDomain extends ListRecords
{
    protected static string $resource = AgentDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
