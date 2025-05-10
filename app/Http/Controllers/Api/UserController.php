<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index()
    {
        $users = User::with('permissions')->orderBy('id', 'desc')->paginate(10);
        return UserResource::collection($users);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \App\Http\Requests\StoreUserRequest $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();
        $data['password'] = bcrypt($data['password']);
        $user = User::create($data);

        // Sync permissions if provided
        if (isset($data['permissions'])) {
            $user->permissions()->sync($data['permissions']);
        }

        return response(new UserResource($user->load('permissions')), 201);
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\User $user
     * @return \Illuminate\Http\Response
     */
    public function show(User $user)
    {
        return new UserResource($user->load('permissions'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \App\Http\Requests\UpdateUserRequest $request
     * @param \App\Models\User                     $user
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->validated();
        if (isset($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        }
        $user->update($data);

        // Sync permissions if provided
        if (isset($data['permissions'])) {
            $user->permissions()->sync($data['permissions']);
        }

        return new UserResource($user->load('permissions'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\User $user
     * @return \Illuminate\Http\Response
     */
    public function destroy(User $user)
    {
        $user->delete();

        return response("", 204);
    }

    /**
     * Fetch all permissions for the form.
     *
     * @return \Illuminate\Http\Response
     */
    public function permissions()
    {
        try {
            $permissions = Permission::all();
            return response()->json($permissions);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch permissions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new permission.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function createPermission(Request $request)
    {
        // Validate the request
        $request->validate([
            'name' => 'required|string|unique:permissions,name',
        ]);

        // Generate a slug from the name
        $slug = Str::slug($request->name);

        // Create the permission
        $permission = Permission::create([
            'name' => $request->name,
            'slug' => $slug, // Add the slug field
        ]);

        // Return the created permission
        return response()->json($permission, 201);
    }

    /**
     * Delete a permission.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function deletePermission($id)
    {
        $permission = Permission::findOrFail($id);
        $permission->delete();

        return response()->noContent();
    }
}