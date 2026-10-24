<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\System\AgentDomainResource\Pages;

use App\Filament\Admin\Resources\System\AgentDomainResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAgentDomain extends CreateRecord
{
    protected static string $resource = AgentDomainResource::class;
}
