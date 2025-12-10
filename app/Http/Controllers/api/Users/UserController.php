<?php

namespace App\Http\Controllers\api\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\GeneralData;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Controlador para la gestión completa de usuarios
 * Incluye operaciones CRUD con datos generales y asignación de roles
 */
class UserController extends Controller
{
    /**
     * Listar todos los usuarios con sus datos generales y roles
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            // Obtener parámetros de solicitud
            $estado = request()->input('estado');
            $rol = request()->input('rol');
            $search = request()->input('search');
            $perPage = request()->input('per_page', 10); // Número de elementos por página, por defecto 10

            // Iniciar consulta con relaciones optimizadas
            $query = User::with(['generalData', 'roles:id,name']);

            // Aplicar filtros
            if ($estado) {
                $query->where('estado', $estado);
            }

            if ($rol) {
                $query->whereHas('roles', function ($q) use ($rol) {
                    $q->where('name', $rol);
                });
            }

            // Aplicar búsqueda
            if ($search) {
                $query->where(function ($q) use ($search) {
                    // Búsqueda en tabla users
                    $q->where('email', 'like', "%{$search}%");

                    // Búsqueda en tabla general_data
                    $q->orWhereHas('generalData', function ($q2) use ($search) {
                        $q2->where('nombre', 'like', "%{$search}%")
                            ->orWhere('apellido', 'like', "%{$search}%")
                            ->orWhere('documento_identidad', 'like', "%{$search}%")
                            ->orWhere('celular', 'like', "%{$search}%");
                    });
                });
            }

            // Ordenar resultados en orden descendente (más recientes primero)
            $query->orderBy('created_at', 'desc');

            // Obtener resultados con paginación
            $users = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Lista de usuarios obtenida exitosamente',
                'data' => $users->items(),
                'pagination' => [
                    'total' => $users->total(),
                    'count' => $users->count(),
                    'per_page' => $users->perPage(),
                    'current_page' => $users->currentPage(),
                    'total_pages' => $users->lastPage(),
                    'has_more_pages' => $users->hasMorePages(),
                    'next_page_url' => $users->nextPageUrl(),
                    'prev_page_url' => $users->previousPageUrl(),
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor al obtener usuarios',
                'error' => config('app.debug') ? $th->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Crear nuevo usuario con datos generales y asignación de roles
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        // Validar los datos de entrada
        $validated = $request->validate([
            // Datos del usuario
            'email' => 'required|email|unique:users,email|max:255',
            'password' => 'required|string|min:8|confirmed',
            'estado' => 'sometimes|boolean',

            // Datos generales (requeridos)
            'general_data.nombre' => 'required|string|max:255',
            'general_data.apellido' => 'required|string|max:255',
            'general_data.documento_identidad' => 'required|string|max:50|unique:general_data,documento_identidad',
            'general_data.celular' => 'required|string|max:20',
            'general_data.direccion' => 'required|string|max:255',
            'general_data.ciudad' => 'required|string|max:100',
            'general_data.departamento' => 'required|string|max:100',

            // Datos generales (opcionales)
            'general_data.nacimiento' => 'nullable|date|before:today',
            'general_data.genero' => 'nullable|in:masculino,femenino,otro',
            'general_data.codigo_postal' => 'nullable|string|max:20',
            'general_data.contacto_emergencia_nombre' => 'nullable|string|max:255',
            'general_data.contacto_emergencia_telefono' => 'nullable|string|max:20',
            'general_data.notas' => 'nullable|string|max:1000',

            // Roles
            'roles' => 'sometimes|array',
            'roles.*' => 'exists:roles,name'
        ], [
            // Mensajes de error personalizados
            'email.required' => 'El correo electrónico es obligatorio',
            'email.email' => 'Debe ingresar un correo electrónico válido',
            'email.unique' => 'Este correo electrónico ya está registrado',
            'email.max' => 'El correo electrónico no puede superar los 255 caracteres',

            'password.required' => 'La contraseña es obligatoria',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
            'password.confirmed' => 'La confirmación de contraseña no coincide',

            'estado.boolean' => 'El estado debe ser verdadero o falso',

            'general_data.nombre.required' => 'El nombre es obligatorio',
            'general_data.nombre.max' => 'El nombre no puede superar los 255 caracteres',

            'general_data.apellido.required' => 'El apellido es obligatorio',
            'general_data.apellido.max' => 'El apellido no puede superar los 255 caracteres',

            'general_data.documento_identidad.required' => 'El documento de identidad es obligatorio',
            'general_data.documento_identidad.unique' => 'Este documento de identidad ya está registrado',
            'general_data.documento_identidad.max' => 'El documento de identidad no puede superar los 50 caracteres',

            'general_data.celular.required' => 'El número de celular es obligatorio',
            'general_data.celular.max' => 'El número de celular no puede superar los 20 caracteres',

            'general_data.direccion.required' => 'La dirección es obligatoria',
            'general_data.direccion.max' => 'La dirección no puede superar los 255 caracteres',

            'general_data.ciudad.required' => 'La ciudad es obligatoria',
            'general_data.ciudad.max' => 'La ciudad no puede superar los 100 caracteres',

            'general_data.departamento.required' => 'El departamento es obligatorio',
            'general_data.departamento.max' => 'El departamento no puede superar los 100 caracteres',

            'general_data.nacimiento.date' => 'La fecha de nacimiento debe ser una fecha válida',
            'general_data.nacimiento.before' => 'La fecha de nacimiento debe ser anterior a hoy',

            'general_data.genero.in' => 'El género debe ser masculino, femenino u otro',

            'general_data.codigo_postal.max' => 'El código postal no puede superar los 20 caracteres',

            'general_data.contacto_emergencia_nombre.max' => 'El nombre de contacto de emergencia no puede superar los 255 caracteres',

            'general_data.contacto_emergencia_telefono.max' => 'El teléfono de contacto de emergencia no puede superar los 20 caracteres',

            'general_data.notas.max' => 'Las notas no pueden superar los 1000 caracteres',

            'roles.array' => 'Los roles deben ser un array',
            'roles.*.exists' => 'Uno o más roles seleccionados no son válidos'
        ]);

        DB::beginTransaction();
        try {
            // Preparar datos con valores por defecto
            $userData = [
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'email_verified_at' => now(),
                'estado' => $validated['estado'] ?? 1,
            ];

            // 1. Crear el usuario principal
            $user = User::create($userData);

            // Preparar datos generales
            $generalData = [
                'user_id' => $user->id,
                'nombre' => $validated['general_data']['nombre'],
                'apellido' => $validated['general_data']['apellido'],
                'documento_identidad' => $validated['general_data']['documento_identidad'],
                'celular' => $validated['general_data']['celular'],
                'direccion' => $validated['general_data']['direccion'],
                'ciudad' => $validated['general_data']['ciudad'],
                'departamento' => $validated['general_data']['departamento'],
                'nacimiento' => $validated['general_data']['nacimiento'] ?? null,
                'genero' => $validated['general_data']['genero'] ?? null,
                'codigo_postal' => $validated['general_data']['codigo_postal'] ?? null,
                'contacto_emergencia_nombre' => $validated['general_data']['contacto_emergencia_nombre'] ?? null,
                'contacto_emergencia_telefono' => $validated['general_data']['contacto_emergencia_telefono'] ?? null,
                'notas' => $validated['general_data']['notas'] ?? null,
            ];

            // 2. Crear datos generales asociados
            GeneralData::create($generalData);

            // 3. Asignar roles si se proporcionan
            if (isset($validated['roles'])) {
                $user->syncRoles($validated['roles']);
            }

            DB::commit();

            // Cargar relaciones para respuesta
            $user->load(['generalData', 'roles:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Usuario creado exitosamente',
                'data' => $user
            ], 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el usuario',
                'error' => config('app.debug') ? $th->getMessage() : 'Error interno'
            ], 500);
        }
    }
    /**
     * Mostrar un usuario específico con todos sus datos
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        try {
            // Buscar usuario con todas las relaciones
            $user = User::with([
                'generalData',
                'roles:id,name',
                'permissions:id,name'
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Usuario encontrado exitosamente',
                'data' => $user
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado'
            ], 404);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno al obtener el usuario',
                'error' => config('app.debug') ? $th->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Actualizar usuario, datos generales y roles
     *
     * @param string $id
     * @return JsonResponse
     */
    public function update(Request $request, string $id): JsonResponse
    {
        // Validar los datos de entrada
        $validated = $request->validate([
            // Datos del usuario
            'email' => 'required|email|unique:users,email,' . $id . '|max:255',
            'estado' => 'sometimes|boolean',

            // Datos generales (requeridos)
            'general_data.nombre' => 'required|string|max:255',
            'general_data.apellido' => 'required|string|max:255',
            'general_data.documento_identidad' => 'required|string|max:50|unique:general_data,documento_identidad,' . $id . ',user_id',
            'general_data.celular' => 'required|string|max:20',
            'general_data.direccion' => 'required|string|max:255',
            'general_data.ciudad' => 'required|string|max:100',
            'general_data.departamento' => 'required|string|max:100',

            // Datos generales (opcionales)
            'general_data.nacimiento' => 'nullable|date|before:today',
            'general_data.genero' => 'nullable|in:masculino,femenino,otro',
            'general_data.codigo_postal' => 'nullable|string|max:20',
            'general_data.contacto_emergencia_nombre' => 'nullable|string|max:255',
            'general_data.contacto_emergencia_telefono' => 'nullable|string|max:20',
            'general_data.notas' => 'nullable|string|max:1000',

            // Roles
            'roles' => 'sometimes|array',
            'roles.*' => 'exists:roles,name'
        ], [
            // Mensajes de error personalizados
            'email.required' => 'El correo electrónico es obligatorio',
            'email.email' => 'Debe ingresar un correo electrónico válido',
            'email.unique' => 'Este correo electrónico ya está registrado',
            'email.max' => 'El correo electrónico no puede superar los 255 caracteres',

            'estado.boolean' => 'El estado debe ser verdadero o falso',

            'general_data.nombre.required' => 'El nombre es obligatorio',
            'general_data.nombre.max' => 'El nombre no puede superar los 255 caracteres',

            'general_data.apellido.required' => 'El apellido es obligatorio',
            'general_data.apellido.max' => 'El apellido no puede superar los 255 caracteres',

            'general_data.documento_identidad.required' => 'El documento de identidad es obligatorio',
            'general_data.documento_identidad.unique' => 'Este documento de identidad ya está registrado',
            'general_data.documento_identidad.max' => 'El documento de identidad no puede superar los 50 caracteres',

            'general_data.celular.required' => 'El número de celular es obligatorio',
            'general_data.celular.max' => 'El número de celular no puede superar los 20 caracteres',

            'general_data.direccion.required' => 'La dirección es obligatoria',
            'general_data.direccion.max' => 'La dirección no puede superar los 255 caracteres',

            'general_data.ciudad.required' => 'La ciudad es obligatoria',
            'general_data.ciudad.max' => 'La ciudad no puede superar los 100 caracteres',

            'general_data.departamento.required' => 'El departamento es obligatorio',
            'general_data.departamento.max' => 'El departamento no puede superar los 100 caracteres',

            'general_data.nacimiento.date' => 'La fecha de nacimiento debe ser una fecha válida',
            'general_data.nacimiento.before' => 'La fecha de nacimiento debe ser anterior a hoy',

            'general_data.genero.in' => 'El género debe ser masculino, femenino u otro',

            'general_data.codigo_postal.max' => 'El código postal no puede superar los 20 caracteres',

            'general_data.contacto_emergencia_nombre.max' => 'El nombre de contacto de emergencia no puede superar los 255 caracteres',

            'general_data.contacto_emergencia_telefono.max' => 'El teléfono de contacto de emergencia no puede superar los 20 caracteres',

            'general_data.notas.max' => 'Las notas no pueden superar los 1000 caracteres',

            'roles.array' => 'Los roles deben ser un array',
            'roles.*.exists' => 'Uno o más roles seleccionados no son válidos'
        ]);

        DB::beginTransaction();
        try {
            // Buscar usuario
            $user = User::findOrFail($id);

            // 1. Actualizar datos del usuario principal
            $userData = [
                'email' => $validated['email'],
                'estado' => $validated['estado'] ?? $user->estado,
            ];

            $user->update($userData);

            // 2. Actualizar o crear datos generales
            $generalData = [
                'nombre' => $validated['general_data']['nombre'],
                'apellido' => $validated['general_data']['apellido'],
                'documento_identidad' => $validated['general_data']['documento_identidad'],
                'celular' => $validated['general_data']['celular'],
                'direccion' => $validated['general_data']['direccion'],
                'ciudad' => $validated['general_data']['ciudad'],
                'departamento' => $validated['general_data']['departamento'],
                'nacimiento' => $validated['general_data']['nacimiento'] ?? null,
                'genero' => $validated['general_data']['genero'] ?? null,
                'codigo_postal' => $validated['general_data']['codigo_postal'] ?? null,
                'contacto_emergencia_nombre' => $validated['general_data']['contacto_emergencia_nombre'] ?? null,
                'contacto_emergencia_telefono' => $validated['general_data']['contacto_emergencia_telefono'] ?? null,
                'notas' => $validated['general_data']['notas'] ?? null,
            ];

            if ($user->generalData) {
                // Actualizar datos existentes
                $user->generalData->update($generalData);
            } else {
                // Crear nuevos datos
                $generalData['user_id'] = $user->id;
                GeneralData::create($generalData);
            }

            // 3. Actualizar roles si se proporcionan
            if (isset($validated['roles'])) {
                $user->syncRoles($validated['roles']);
            }

            DB::commit();

            // Cargar relaciones actualizadas
            $user->load(['generalData', 'roles:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Usuario actualizado exitosamente',
                'data' => $user
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado'
            ], 404);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el usuario',
                'error' => config('app.debug') ? $th->getMessage() : 'Error interno'
            ], 500);
        }
    }
    /**
     * Eliminar usuario y todos sus datos asociados
     *
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(string $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            // Buscar usuario
            $user = User::findOrFail($id);

            // Verificar que no sea el último admin
            if ($this->isLastAdmin($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el último usuario administrador'
                ], 422);
            }

            // 1. Eliminar datos generales
            if ($user->generalData) {
                $user->generalData->delete();
            }

            // 2. Remover roles y permisos
            $user->roles()->detach();
            $user->permissions()->detach();

            // 3. Eliminar usuario
            $user->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Usuario eliminado exitosamente'
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado'
            ], 404);
        } catch (\Throwable $th) {
            DB::rollBack();

            Log::error('Error eliminando usuario', [
                'user_id' => $id,
                'error' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el usuario',
                'error' => config('app.debug') ? $th->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Verificar si el usuario es el último administrador
     *
     * @param User $user
     * @return bool
     */
    private function isLastAdmin(User $user): bool
    {
        // Verificar si el usuario tiene rol de admin
        if (!$user->hasRole('admin')) {
            return false;
        }

        // Contar cuántos admins hay en total
        $adminCount = User::role('admin')->count();

        return $adminCount <= 1;
    }
    public function getActiveUsers()
    {
        try {
            // Obtener solo usuarios activos sin driver asociado con relaciones optimizadas
            $activeUsers = User::with([
                'generalData:id,user_id,nombre,apellido,documento_identidad,celular,ciudad,departamento',
                'roles:id,name'
            ])
                ->select('id', 'email', 'estado')
                ->where('estado', true) // Solo usuarios activos
                /* ->whereDoesntHave('driver') // Solo usuarios que NO tienen driver */
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Lista de usuarios activos sin driver obtenida exitosamente',
                'data' => $activeUsers,
                'total' => $activeUsers->count()
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor al obtener usuarios activos',
                'error' => config('app.debug') ? $th->getMessage() : 'Error interno'
            ], 500);
        }
    }

    public function updatePassword(Request $request, string $id)
    {
        try {
            // Validar la request
            $request->validate([
                'password' => [
                    'required',
                    'string',
                    'min:8',
                    'confirmed' // Requiere password_confirmation
                ]
            ]);

            // Buscar el usuario
            $user = User::findOrFail($id);

            // Actualizar solo la contraseña
            $user->update([
                'password' => Hash::make($request->input('password'))
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada exitosamente'
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Throwable $th) {

            return response()->json([
                'success' => false,
                'message' => 'Error interno al actualizar la contraseña',
                'error' => config('app.debug') ? $th->getMessage() : 'Error interno'
            ], 500);
        }
    }
    /**
     * Mostrar todos los datos completos de un usuario con todas sus relaciones
     *
     * @param string $id
     * @return JsonResponse
     */
    public function getCompleteUserData(string $id): JsonResponse
    {
        try {
            // Buscar usuario con todas sus relaciones
            $user = User::with([
                'generalData',
                'driver' => function ($query) {
                    $query->with(['documents' => function ($docQuery) {
                        $docQuery->select('id', 'driver_id', 'tipo', 'nombre', 'archivo', 'fecha_vencimiento', 'aprobado', 'observaciones');
                    }]);
                },
                'roles:id,name,guard_name',
                'permissions:id,name,guard_name',
                'vehicles' => function ($query) {
                    $query->with([
                        'negocio:id,nombre,estado',
                        'documents' => function ($docQuery) {
                            $docQuery->select('id', 'vehicle_id', 'tipo', 'nombre', 'archivo', 'fecha_vencimiento');
                        }
                    ])->select('id', 'user_id', 'negocio_id', 'numero_vin', 'marca', 'modelo', 'año', 'numero_placa', 'numero_dot', 'tipo_vehiculo', 'tipo_propiedad', 'precio_compra', 'fecha_compra', 'valor_actual', 'millaje', 'vencimiento_registro', 'vencimiento_inspeccion', 'color', 'combustible', 'transmision', 'capacidad_carga', 'estado', 'observaciones', 'foto');
                }
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Datos completos del usuario obtenidos exitosamente',
                'data' => $user
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado'
            ], 404);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno al obtener los datos completos del usuario',
                'error' => config('app.debug') ? $th->getMessage() : 'Error interno'
            ], 500);
        }
    }

    /**
     * Obtener todos los datos del usuario incluyendo contraseña hasheada
     *
     * @param string $id
     * @return JsonResponse
     */
    public function getUserWithPassword(string $id): JsonResponse
    {
        try {
            // Buscar usuario con todos sus datos y relaciones
            $user = User::with([
                'generalData',
                'roles:id,name',
                'permissions:id,name',
                'driver' => function ($query) {
                    $query->select('id', 'user_id', 'numero_licencia', 'vencimiento_licencia', 'estado_licencia', 'categoria');
                },
                'vehicles' => function ($query) {
                    $query->select('id', 'user_id', 'numero_placa', 'marca', 'modelo', 'año');
                }
            ])->select('id', 'email', 'password', 'estado', 'email_verified_at', 'created_at', 'updated_at')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Datos completos del usuario obtenidos exitosamente',
                'data' => $user
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado'
            ], 404);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Error interno al obtener los datos del usuario',
                'error' => config('app.debug') ? $th->getMessage() : 'Error interno'
            ], 500);
        }
    }
}
