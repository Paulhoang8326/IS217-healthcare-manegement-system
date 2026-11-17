<?php

namespace App\Shared\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ExceptionRenderer
{
    public function __invoke(Throwable $exception, Request $request): ?Response
    {
        if (! $request->is('api', 'api/*')) {
            return null;
        }

        if ($exception instanceof ValidationException) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $field = preg_replace('/\.(\d+)(?=\.|$)/', '[$1]', $field);
                // QUIRK-NEW: Java keeps the last validation message for each field.
                foreach ($messages as $message) {
                    $errors[$field] = $message;
                }
            }

            return response()->json((object) $errors, 400);
        }

        $isAuthorizationFailure = $exception instanceof AccessDeniedException
            || ($exception instanceof HttpExceptionInterface
                && $exception->getStatusCode() === 403
                && $exception->getPrevious() instanceof AuthorizationException);

        $status = match (true) {
            $exception instanceof ResourceNotFoundException => 404,
            $exception instanceof DuplicateResourceException,
            $exception instanceof OptimisticLockException => 409,
            $exception instanceof BusinessException,
            $exception instanceof DataIntegrityViolationException,
            $exception instanceof HttpMessageNotReadableException => 400,
            $isAuthorizationFailure => 403,
            default => null,
        };

        if ($exception instanceof QueryException && $this->isDataIntegrityViolation($exception)) {
            $status = 400;
        }

        if ($status === null && ($exception instanceof HttpExceptionInterface
            || $exception instanceof HttpResponseException
            || $exception instanceof AuthenticationException)) {
            return null;
        }

        $message = match (true) {
            $exception instanceof DataIntegrityViolationException,
            $exception instanceof QueryException && $status === 400 => 'Request violates database constraints. Please check unique fields, enum values and data types',
            $isAuthorizationFailure => 'You do not have permission to perform this action',
            $exception instanceof OptimisticLockException => 'Data was changed by another transaction, please try again',
            $exception instanceof HttpMessageNotReadableException => 'Invalid request body. Please check JSON syntax and date format yyyy-MM-dd',
            $status === null => 'An unexpected error occurred: '.$exception->getMessage(),
            default => $exception->getMessage(),
        };

        return response($message, $status ?? 500, ['Content-Type' => 'text/plain;charset=UTF-8']);
    }

    private function isDataIntegrityViolation(QueryException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array(substr($state, 0, 2), ['22', '23'], true)
            || ($state === '01000' && $driverCode === 1265)
            || ($state === 'HY000' && in_array($driverCode, [1364, 1366, 3819], true));
    }
}
