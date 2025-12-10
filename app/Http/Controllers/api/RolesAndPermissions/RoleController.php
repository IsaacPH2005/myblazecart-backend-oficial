<?php

namespace App\Http\Controllers\api\RolesAndPermissions;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    /**
     * Obtener todos los roles sin permisos
     */
    public function index(): JsonResponse
    {
        try {
            $roles = Role::where('guard_name', 'sanctum')
                ->select('id', 'name', 'created_at', 'updated_at')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Roles obtenidos exitosamente',
                'data' => $roles
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener todos los roles con sus permisos
     */
    public function rolesWithPermissions(): JsonResponse
    {
        try {
            $roles = Role::where('guard_name', 'sanctum')
                ->with(['permissions:id,name'])
                ->select('id', 'name', 'created_at', 'updated_at')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Roles con permisos obtenidos exitosamente',
                'data' => $roles
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los roles con permisos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener un rol específico sin permisos
     */
    public function show($id): JsonResponse
    {
        try {
            $role = Role::where('guard_name', 'sanctum')
                ->select('id', 'name', 'created_at', 'updated_at')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Rol obtenido exitosamente',
                'data' => $role
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el rol',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener un rol específico con sus permisos
     */
    public function showWithPermissions($id): JsonResponse
    {
        try {
            $role = Role::where('guard_name', 'sanctum')
                ->with(['permissions:id,name'])
                ->select('id', 'name', 'created_at', 'updated_at')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Rol con permisos obtenido exitosamente',
                'data' => $role
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el rol con permisos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener solo los nombres de los roles (para dropdowns)
     */
    public function roleNames(): JsonResponse
    {
        try {
            $roleNames = Role::where('guard_name', 'sanctum')
                ->pluck('name', 'id');

            return response()->json([
                'success' => true,
                'message' => 'Nombres de roles obtenidos exitosamente',
                'data' => $roleNames
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los nombres de roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener todos los permisos disponibles
     */
    public function permissions(): JsonResponse
    {
        try {
            $permissions = Permission::where('guard_name', 'sanctum')
                ->select('id', 'name', 'created_at', 'updated_at')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Permisos obtenidos exitosamente',
                'data' => $permissions
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los permisos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de roles
     */
    public function stats(): JsonResponse
    {
        try {
            $stats = [
                'total_roles' => Role::where('guard_name', 'sanctum')->count(),
                'total_permissions' => Permission::where('guard_name', 'sanctum')->count(),
                'roles_with_permissions' => Role::where('guard_name', 'sanctum')
                    ->has('permissions')
                    ->count(),
                'roles_without_permissions' => Role::where('guard_name', 'sanctum')
                    ->doesntHave('permissions')
                    ->count()
            ];

            return response()->json([
                'success' => true,
                'message' => 'Estadísticas obtenidas exitosamente',
                'data' => $stats
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
