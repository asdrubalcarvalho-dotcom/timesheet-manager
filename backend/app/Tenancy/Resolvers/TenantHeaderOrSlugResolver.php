<?php

namespace App\Tenancy\Resolvers;

use App\Models\Tenant;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByRequestDataException;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;

class TenantHeaderOrSlugResolver extends RequestDataTenantResolver
{
    public function resolveWithoutCache(...$args): TenantContract
    {
        $payload = $args[0];

        if (tenancy()->initialized && tenancy()->tenant) {
            return tenancy()->tenant;
        }

        if ($payload && $tenant = tenancy()->find($payload)) {
            return $tenant;
        }

        if ($payload && $tenant = Tenant::where('slug', $payload)->first()) {
            return $tenant;
        }

        throw new TenantCouldNotBeIdentifiedByRequestDataException($payload);
    }
}
