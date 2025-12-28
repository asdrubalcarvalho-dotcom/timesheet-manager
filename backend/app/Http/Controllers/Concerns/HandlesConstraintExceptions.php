<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

trait HandlesConstraintExceptions
{
    /**
     * Return a user-facing response when a foreign key constraint blocks deletion.
     */
    protected function constraintConflictResponse(string $message = 'This record cannot be deleted because it has related data.'): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }

    /**
     * Determine if the given exception is a foreign key constraint violation.
     */
    protected function isForeignKeyConstraint(QueryException $exception): bool
    {
        // SQLSTATE[23000] with MySQL error code 1451 (cannot delete or update a parent row)
        return $exception->getCode() === '23000'
            && isset($exception->errorInfo[1])
            && (int) $exception->errorInfo[1] === 1451;
    }
}
