<?php

namespace App\Modules\Appointment\Controllers;

use App\Enums\AppointmentStatus;
use App\Modules\Appointment\Requests\ConfirmManualPaymentRequest;
use App\Modules\Appointment\Requests\CreateAppointmentRequest;
use App\Modules\Appointment\Requests\CreateWalkInAppointmentRequest;
use App\Modules\Appointment\Requests\SepayWebhookRequest;
use App\Modules\Appointment\Services\AppointmentService;
use App\Shared\Http\Requests\SpringParameters;
use App\Shared\Support\Sepay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController
{
    public function __construct(private AppointmentService $appointmentService) {}

    public function create(CreateAppointmentRequest $request): JsonResponse
    {
        return response()->json($this->appointmentService->create($request->validated()), 201);
    }

    public function createWalkInPaidAppointment(CreateWalkInAppointmentRequest $request): JsonResponse
    {
        return response()->json($this->appointmentService->createWalkInPaidAppointment($request->validated()), 201);
    }

    public function getById(string $id): JsonResponse
    {
        return response()->json($this->appointmentService->getById(SpringParameters::pathLong($id)));
    }

    public function getAll(Request $request): JsonResponse
    {
        return response()->json($this->appointmentService->getAll(
            SpringParameters::queryLong($request, 'patientId'), SpringParameters::queryLong($request, 'doctorId'),
            SpringParameters::queryLong($request, 'doctorScheduleId'),
            SpringParameters::queryEnum($request, 'status', 'com.healthcare.backend.entity.enums.AppointmentStatus', array_column(AppointmentStatus::cases(), 'value')),
        ));
    }

    public function checkIn(string $id): JsonResponse
    {
        return response()->json($this->appointmentService->checkIn(SpringParameters::pathLong($id)));
    }

    public function start(string $id): JsonResponse
    {
        return response()->json($this->appointmentService->start(SpringParameters::pathLong($id)));
    }

    public function cancel(string $id): JsonResponse
    {
        return response()->json($this->appointmentService->cancel(SpringParameters::pathLong($id)));
    }

    public function confirmManualPayment(string $id, ConfirmManualPaymentRequest $request): JsonResponse
    {
        return response()->json($this->appointmentService->confirmManualPayment(SpringParameters::pathLong($id), $request->validated()));
    }

    public function handleSepayWebhook(SepayWebhookRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $secret = Sepay::resolveSecret($request->header('X-Secret-Key'), $request->header('Authorization'));
        $code = $this->resolveWebhookCode($payload);
        if ($code !== null && (str_starts_with($code, 'MR-') || str_starts_with($code, 'PR-'))) {
            // These two branches require PaymentRecordService from the Payment module.
            $service = app()->make('App\\Modules\\Payment\\Services\\PaymentRecordService');
            if (str_starts_with($code, 'MR-')) {
                $response = $service->confirmMedicalRecordPaymentFromSepayWebhook($payload, $secret);

                return response()->json(['success' => true, 'medicalRecordId' => $response['medicalRecordId'] ?? 0, 'requestCode' => $response['requestCode']]);
            }
            $response = $service->confirmPrescriptionPaymentFromSepayWebhook($payload, $secret);

            return response()->json(['success' => true, 'prescriptionId' => $response['prescriptionId'] ?? 0, 'requestCode' => $response['requestCode']]);
        }
        $response = $this->appointmentService->confirmPaymentFromSepayWebhook($payload, $secret);

        return response()->json(['success' => true, 'appointmentId' => $response['appointmentId'], 'appointmentCode' => $response['appointmentCode']]);
    }

    private function resolveWebhookCode(array $request): ?string
    {
        foreach (['content', 'description', 'code'] as $field) {
            if (! Sepay::isBlank($request[$field])) {
                $text = mb_strtoupper($request[$field]);
                foreach (['/MR-[0-9]+/', '/PR-[0-9]+/', '/APT-[A-Z0-9]+/', '/DK[0-9]+/'] as $pattern) {
                    if (preg_match($pattern, $text, $match)) {
                        return $match[0];
                    }
                }
                if ($field === 'code') {
                    return Sepay::trim($text);
                }
            }
        }

        return null;
    }
}
