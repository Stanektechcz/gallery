<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $space  = $request->user()->gallerySpaces()->first();
        $people = Person::where('gallery_space_id', $space->id)->orderBy('name')->get();
        return response()->json($people);
    }

    public function store(Request $request): JsonResponse
    {
        $data  = $request->validate(['name' => 'required|string|max:100', 'nickname' => 'nullable|string|max:100', 'birth_date' => 'nullable|date']);
        $space = $request->user()->gallerySpaces()->first();
        $person = Person::create(array_merge($data, ['gallery_space_id' => $space->id, 'created_by' => $request->user()->id]));
        return response()->json($person, 201);
    }

    public function show(Person $person): JsonResponse
    {
        return response()->json($person);
    }

    public function update(Request $request, Person $person): JsonResponse
    {
        $data = $request->validate(['name' => 'sometimes|string|max:100', 'nickname' => 'nullable|string|max:100', 'description' => 'nullable|string', 'birth_date' => 'nullable|date']);
        $person->update($data);
        return response()->json($person);
    }

    public function destroy(int $id): JsonResponse
    {
        Person::findOrFail($id)->delete();
        return response()->json(['status' => 'deleted']);
    }
}
