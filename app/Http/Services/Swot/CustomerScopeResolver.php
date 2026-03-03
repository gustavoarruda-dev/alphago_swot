<?php

namespace App\Http\Services\Swot;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerScopeResolver
{
    public function resolve(Request $request): string
    {
        $headerCustomerUuid = trim((string) $request->header('X-CUSTOMER-UUID', ''));

        $queryCustomerUuid = trim((string) $request->query('customer_uuid', ''));
        $bodyCustomerUuid = trim((string) $request->input('customer_uuid', ''));

        $requestedCustomerUuid = $queryCustomerUuid !== ''
            ? $queryCustomerUuid
            : $bodyCustomerUuid;

        if (
            $headerCustomerUuid !== '' &&
            $requestedCustomerUuid !== '' &&
            ! hash_equals($headerCustomerUuid, $requestedCustomerUuid)
        ) {
            throw new AuthorizationException('customer_uuid does not match gateway scope.');
        }

        $customerUuid = $headerCustomerUuid !== ''
            ? $headerCustomerUuid
            : $requestedCustomerUuid;

        if ($customerUuid === '') {
            throw ValidationException::withMessages([
                'customer_uuid' => ['customer_uuid is required.'],
            ]);
        }

        return $customerUuid;
    }
}
