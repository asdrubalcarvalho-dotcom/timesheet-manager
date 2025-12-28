<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\Location;
use App\Http\Controllers\Concerns\HandlesConstraintExceptions;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;

class LocationController extends Controller
{
    use HandlesConstraintExceptions;
    /**
     * Display a listing of locations.
     */
    public function index(): JsonResponse
    {
        $locations = Location::orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $locations
        ]);
    }

    /**
     * Store a newly created location.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'country_id' => 'nullable|integer|exists:countries,id',
            'country' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'address' => 'nullable|string',
            'postal_code' => 'nullable|string|max:50',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'asset_id' => 'nullable|integer',
            'oem_id' => 'nullable|integer',
            'is_active' => 'boolean'
        ]);

        if (empty($validated['country_id'])) {
            $legacyCountry = isset($validated['country']) ? trim((string) $validated['country']) : '';
            if ($legacyCountry !== '') {
                $iso2Candidate = strtoupper($legacyCountry);
                $country = Country::query()
                    ->where('iso2', $iso2Candidate)
                    ->orWhere('name', $legacyCountry)
                    ->first();

                if ($country) {
                    $validated['country_id'] = $country->id;
                    $validated['country'] = $country->iso2;
                }
            }
        }

        if (empty($validated['country_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Country is required. Please select a valid country.',
                'errors' => [
                    'country_id' => ['The country_id field is required.'],
                ],
            ], 422);
        }

        $location = Location::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Location created successfully',
            'data' => $location
        ], 201);
    }

    /**
     * Display the specified location.
     */
    public function show(Location $location): JsonResponse
    {
        $location->load('timesheets');

        return response()->json([
            'success' => true,
            'data' => $location
        ]);
    }

    /**
     * Update the specified location.
     */
    public function update(Request $request, Location $location): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'country_id' => 'nullable|integer|exists:countries,id',
            'country' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'address' => 'nullable|string',
            'postal_code' => 'nullable|string|max:50',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'asset_id' => 'nullable|integer',
            'oem_id' => 'nullable|integer',
            'is_active' => 'boolean'
        ]);

        if (empty($validated['country_id'])) {
            $legacyCountry = isset($validated['country']) ? trim((string) $validated['country']) : '';
            if ($legacyCountry !== '') {
                $iso2Candidate = strtoupper($legacyCountry);
                $country = Country::query()
                    ->where('iso2', $iso2Candidate)
                    ->orWhere('name', $legacyCountry)
                    ->first();

                if ($country) {
                    $validated['country_id'] = $country->id;
                    $validated['country'] = $country->iso2;
                }
            }
        }

        if (empty($validated['country_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Country is required. Please select a valid country.',
                'errors' => [
                    'country_id' => ['The country_id field is required.'],
                ],
            ], 422);
        }

        $location->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Location updated successfully',
            'data' => $location
        ]);
    }

    /**
     * Remove the specified location.
     */
    public function destroy(Location $location): JsonResponse
    {
        // Check if location has associated timesheets
        if ($location->timesheets()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete location with associated timesheets'
            ], 422);
        }

        try {
            $location->delete();

            return response()->json([
                'success' => true,
                'message' => 'Location deleted successfully'
            ]);
        } catch (QueryException $e) {
            if ($this->isForeignKeyConstraint($e)) {
                return $this->constraintConflictResponse(
                    'This location cannot be deleted because it is referenced by related records (timesheets or travel segments).'
                );
            }

            throw $e;
        }
    }

    /**
     * Get active locations for dropdown.
     */
    public function active(): JsonResponse
    {
        $locations = Location::where('is_active', true)
            ->select('id', 'name', 'address', 'city', 'country', 'country_id', 'latitude', 'longitude')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $locations
        ]);
    }
}
