<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class UnauthorizedException extends Exception
{
    protected $message;
    protected $statusCode;

    public function __construct(string $message = 'This action is unauthorized.', int $statusCode = 403)
    {
        $this->message = $message;
        $this->statusCode = $statusCode;
        parent::__construct($message, $statusCode);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->message,
            'error' => 'unauthorized'
        ], $this->statusCode);
    }
}
