<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\System\AgentDomainResource\Pages;

use App\Filament\Admin\Resources\System\AgentDomainResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAgentDomain extends EditRecord
{
    protected static string $resource = AgentDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
