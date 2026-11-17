<?php

namespace App\Modules\Scheduling\Controllers;

use App\Modules\Scheduling\Requests\CreateDoctorScheduleRequest;
use App\Modules\Scheduling\Requests\UpdateDoctorScheduleRequest;
use App\Modules\Scheduling\Services\DoctorScheduleService;
use App\Shared\Http\Requests\SpringParameters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DoctorScheduleController
{
    public function __construct(private DoctorScheduleService $doctorScheduleService) {}

    public function create(CreateDoctorScheduleRequest $request): JsonResponse
    {
        return response()->json($this->doctorScheduleService->create($request->validated()), 201);
    }

    public function importSchedules(Request $request): JsonResponse
    {
        if (! $request->hasFile('file')) {
            throw new \InvalidArgumentException("Required part 'file' is not present.");
        }

        return response()->json($this->doctorScheduleService->importSchedules($request->file('file')), 201);
    }

    public function getAll(Request $request): JsonResponse
    {
        return response()->json($this->doctorScheduleService->getAll(
            SpringParameters::queryDate($request, 'date'), SpringParameters::queryDate($request, 'startDate'), SpringParameters::queryDate($request, 'endDate'),
            SpringParameters::queryLong($request, 'doctorId'), SpringParameters::queryLong($request, 'roomId'),
        ));
    }

    public function getById(string $id): JsonResponse
    {
        return response()->json($this->doctorScheduleService->getById(SpringParameters::pathLong($id)));
    }

    public function update(string $id, UpdateDoctorScheduleRequest $request): JsonResponse
    {
        return response()->json($this->doctorScheduleService->update(SpringParameters::pathLong($id), $request->validated()));
    }

    public function delete(string $id): Response
    {
        $this->doctorScheduleService->delete(SpringParameters::pathLong($id));

        return response()->noContent();
    }
}
