<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\Residence;

class TenantContext
{
    public function __construct(public ?Organization $organization = null, public ?Residence $residence = null) {}

    public function organization(): Organization
    {
        abort_unless($this->organization, 409, 'Aucune organisation active.');

        return $this->organization;
    }

    public function residence(): Residence
    {
        abort_unless($this->residence, 409, 'Aucune résidence active.');

        return $this->residence;
    }
}
