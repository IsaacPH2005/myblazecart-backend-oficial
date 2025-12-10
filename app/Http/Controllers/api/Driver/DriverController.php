<?php

namespace App\Http\Controllers\api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\GeneralData;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DriverController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Driver::with(['user.generalData', 'documents']);

            // Filtro por estado del conductor (1=activo, 0=inactivo)
            if ($request->has('estado') && $request->estado !== '') {
                // Aceptamos tanto 'activo'/'inactivo' como 1/0
                $status = $request->estado;
                if (is_string($status)) {
                    $status = $status === 'activo' ? 1 : 0;
                }
                $query->where('drivers.estado', $status);
            }

            // Filtro por estado de la licencia
            if ($request->has('estado_licencia') && $request->estado_licencia !== '') {
                $query->where('drivers.estado_licencia', $request->estado_licencia);
            }

            // Búsqueda por nombre, apellido, email, documento de identidad o número de licencia
            if ($request->has('search') && $request->search !== '') {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('drivers.numero_licencia', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('users.email', 'like', "%{$search}%")
                                ->orWhereHas('generalData', function ($generalDataQuery) use ($search) {
                                    $generalDataQuery->where('general_data.nombre', 'like', "%{$search}%")
                                        ->orWhere('general_data.apellido', 'like', "%{$search}%")
                                        ->orWhere('general_data.documento_identidad', 'like', "%{$search}%");
                                });
                        });
                });
            }

            // Ordenamiento descendente por fecha de creación (más recientes primero)
            $query->orderBy('drivers.created_at', 'desc');

            // Obtener resultados
            $drivers = $query->get();

            // Agregar URL de foto a cada conductor
            $drivers->transform(function ($driver) {
                $driver->foto_url = $driver->foto ? asset('img_drivers/' . $driver->foto) : null;
                return $driver;
            });

            return response()->json([
                'message' => 'Lista de conductores',
                'datos' => $drivers
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener la lista de conductores',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getDriverInfo($id)
    {
        try {
            $driver = Driver::with(['user.generalData', 'documents'])
                ->findOrFail($id);
            // Agregar URL de foto
            $driver->foto_url = $driver->foto ? asset('img_drivers/' . $driver->foto) : null;
            // Agregar URL de archivos de documentos
            if ($driver->documents) {
                $driver->documents->transform(function ($document) {
                    $document->archivo_url = $document->archivo ? asset($document->archivo) : null;
                    return $document;
                });
            }
            return response()->json([
                'message' => 'Información completa del conductor',
                'datos' => $driver
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener la información del conductor',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function show($id)
    {
        try {
            $driver = Driver::with('user.generalData', 'documents')->findOrFail($id);
            return response()->json([
                'message' => 'Conductor encontrado',
                'datos' => $driver,
                'ruta_foto' => $driver->foto ? asset('img_drivers/' . $driver->foto) : null
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Conductor no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function store(Request $request)
    {
        // Validación de los datos de entrada
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'numero_licencia' => 'required|string|max:255|unique:drivers,numero_licencia',
            'vencimiento_licencia' => 'required|date',
            'estado_licencia' => 'required|string|max:50|in:vigente,suspendida,vencida',
            'clase_cdl' => 'required|string|max:50|in:A,B,C',
            'tipo_licencia' => 'required|string|max:50|in:particular,profesional',
            'restricciones' => 'nullable|string|max:255',
            'categoria' => 'required|string|max:50|in:primera,segunda,tercera',
            'observaciones' => 'nullable|string|max:255',
            'foto' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);
        // Obtener información del usuario con sus datos generales
        $user = User::with('generalData')->find($request->user_id);
        // Verificar si el usuario existe
        if (!$user) {
            return response()->json([
                'message' => 'Usuario no encontrado',
                'error' => 'El usuario especificado no existe'
            ], 404);
        }
        // Asignar rol 'carrier' al usuario si no lo tiene ya
        if (!$user->hasRole('carrier')) {
            $user->assignRole('carrier');
        }
        // Inicializar variable para el nombre de la foto
        $nombreFoto = null;
        // Manejo de la foto si se proporciona
        if ($request->hasFile('foto')) {
            $foto = $request->file('foto');
            // Crear nombre del archivo usando nombre y apellido del usuario desde general_data
            $nombre = $user->generalData->nombre ?? 'sin_nombre';
            $apellido = $user->generalData->apellido ?? 'sin_apellido';
            $nombreLimpio = $this->limpiarNombre($nombre . '_' . $apellido);
            $extension = $foto->getClientOriginalExtension();
            $nombreFoto = $nombreLimpio . '_' . time() . '.' . $extension;
            // Crear la carpeta si no existe
            $rutaCarpeta = public_path('img_drivers');
            if (!file_exists($rutaCarpeta)) {
                mkdir($rutaCarpeta, 0755, true);
            }
            // Mover el archivo a la carpeta img_drivers
            $foto->move($rutaCarpeta, $nombreFoto);
        }
        try {
            // Creación del conductor
            $driver = Driver::create([
                'user_id' => $request->user_id,
                'numero_licencia' => $request->numero_licencia,
                'vencimiento_licencia' => $request->vencimiento_licencia,
                'estado_licencia' => $request->estado_licencia,
                'clase_cdl' => $request->clase_cdl,
                'tipo_licencia' => $request->tipo_licencia,
                'restricciones' => $request->restricciones,
                'categoria' => $request->categoria,
                'observaciones' => $request->observaciones,
                'foto' => $nombreFoto // Guardar solo el nombre del archivo
            ]);
            // Cargar las relaciones después de crear el registro
            $driver->load('user.generalData', 'documents');
            // Obtener los roles actualizados del usuario
            $userRoles = $user->fresh()->getRoleNames();
            return response()->json([
                'message' => 'Conductor creado exitosamente y rol carrier asignado',
                'datos' => $driver,
                'ruta_foto' => $nombreFoto ? asset('img_drivers/' . $nombreFoto) : null,
                'user_roles' => $userRoles, // Mostrar todos los roles del usuario
                'rol_carrier_asignado' => $user->hasRole('carrier')
            ], 201);
        } catch (\Exception $e) {
            // Si hay error en la creación, eliminar la foto si fue subida
            if ($nombreFoto && file_exists(public_path('img_drivers/' . $nombreFoto))) {
                unlink(public_path('img_drivers/' . $nombreFoto));
            }
            return response()->json([
                'message' => 'Error al crear el conductor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function createUserWithDriver(Request $request)
    {
        // Validación completa de todos los datos
        $request->validate([
            // Datos del usuario
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string|min:8',
            // Datos generales (general_data)
            'nombre' => 'required|string|max:255',
            'apellido' => 'required|string|max:255',
            'documento_identidad' => 'required|string|unique:general_data,documento_identidad',
            'celular' => 'required|string|max:20',
            'nacimiento' => 'nullable|date_format:Y/m/d',
            'genero' => 'nullable|in:masculino,femenino,otro',
            'direccion' => 'required|string|max:255',
            'ciudad' => 'required|string|max:255',
            'departamento' => 'required|string|max:255',
            'codigo_postal' => 'nullable|string|max:20',
            'contacto_emergencia_nombre' => 'nullable|string|max:255',
            'contacto_emergencia_telefono' => 'nullable|string|max:20',
            'notas' => 'nullable|string',
            // Datos del conductor (driver)
            'numero_licencia' => 'required|string|max:255|unique:drivers,numero_licencia',
            'vencimiento_licencia' => 'required|date_format:Y/m/d',
            'estado_licencia' => 'required|string|max:50|in:vigente,suspendida,vencida',
            'clase_cdl' => 'required|string|max:50|in:A,B,C',
            'tipo_licencia' => 'required|string|max:50|in:particular,profesional',
            'restricciones' => 'nullable|string|max:255',
            'categoria' => 'required|string|max:50|in:primera,segunda,tercera',
            'observaciones' => 'nullable|string|max:255',
            'foto' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
        ]);
        try {
            // Usar transacción para asegurar que todo se cree correctamente
            DB::beginTransaction();
            // 1. Crear el usuario
            $user = User::create([
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'estado' => true
            ]);
            // 2. Crear los datos generales
            $generalData = GeneralData::create([
                'user_id' => $user->id,
                'nombre' => $request->nombre,
                'apellido' => $request->apellido,
                'documento_identidad' => $request->documento_identidad,
                'celular' => $request->celular,
                'nacimiento' => $request->nacimiento,
                'genero' => $request->genero,
                'direccion' => $request->direccion,
                'ciudad' => $request->ciudad,
                'departamento' => $request->departamento,
                'codigo_postal' => $request->codigo_postal,
                'contacto_emergencia_nombre' => $request->contacto_emergencia_nombre,
                'contacto_emergencia_telefono' => $request->contacto_emergencia_telefono,
                'notas' => $request->notas
            ]);
            // 3. Manejo de la foto del conductor
            $nombreFoto = null;
            if ($request->hasFile('foto')) {
                $foto = $request->file('foto');
                // Crear nombre del archivo usando nombre y apellido
                $nombreLimpio = $this->limpiarNombre($request->nombre . '_' . $request->apellido);
                $extension = $foto->getClientOriginalExtension();
                $nombreFoto = 'foto_perfil.' . $extension;
                // Crear la carpeta específica del conductor
                $rutaConductor = "drivers/{$nombreLimpio}";
                $rutaCarpetaCompleta = public_path($rutaConductor);
                if (!file_exists($rutaCarpetaCompleta)) {
                    mkdir($rutaCarpetaCompleta, 0755, true);
                }
                // Mover el archivo a la carpeta del conductor
                $foto->move($rutaCarpetaCompleta, $nombreFoto);
                // Guardar la ruta relativa completa
                $nombreFoto = "{$rutaConductor}/{$nombreFoto}";
            }
            // 4. Crear el conductor
            $driver = Driver::create([
                'user_id' => $user->id,
                'numero_licencia' => $request->numero_licencia,
                'vencimiento_licencia' => $request->vencimiento_licencia,
                'estado_licencia' => $request->estado_licencia,
                'clase_cdl' => $request->clase_cdl,
                'tipo_licencia' => $request->tipo_licencia,
                'restricciones' => $request->restricciones,
                'categoria' => $request->categoria,
                'observaciones' => $request->observaciones,
                'foto' => $nombreFoto
            ]);
            // 5. Asignar el rol 'carrier' al usuario
            $user->assignRole('carrier');
            // Confirmar la transacción
            DB::commit();
            // Cargar las relaciones para la respuesta
            $user->load(['generalData', 'driver']);
            return response()->json([
                'message' => 'Usuario y conductor creados exitosamente',
                'datos' => [
                    'user' => $user,
                    'general_data' => $user->generalData,
                    'driver' => $user->driver,
                    'foto_url' => $nombreFoto ? asset($nombreFoto) : null
                ]
            ], 201);
        } catch (\Exception $e) {
            // Revertir la transacción en caso de error
            DB::rollback();
            // Eliminar la foto si se subió pero falló la creación
            if (isset($nombreFoto) && $nombreFoto && file_exists(public_path($nombreFoto))) {
                unlink(public_path($nombreFoto));
            }
            return response()->json([
                'message' => 'Error al crear el usuario y conductor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            // Buscar el conductor
            $driver = Driver::with('user.generalData')->findOrFail($id);
            // Validación de los datos de entrada
            $request->validate([
                'numero_licencia' => 'sometimes|required|string|max:255|unique:drivers,numero_licencia,' . $driver->id,
                'vencimiento_licencia' => 'sometimes|required',
                'estado_licencia' => 'sometimes|required|string|max:50|in:vigente,suspendida,vencida',
                'clase_cdl' => 'sometimes|required|string|max:50|in:A,B,C',
                'tipo_licencia' => 'sometimes|required|string|max:50|in:particular,profesional',
                'restricciones' => 'nullable|string|max:255',
                'categoria' => 'sometimes|required|string|max:50|in:primera,segunda,tercera',
                'observaciones' => 'nullable|string|max:255',
                'foto' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048'
            ]);
            DB::beginTransaction();
            // Guardar la foto anterior para eliminarla si se actualiza
            $fotoAnterior = $driver->foto;
            $nombreFoto = $fotoAnterior;
            // Manejo de la nueva foto si se proporciona
            if ($request->hasFile('foto')) {
                $foto = $request->file('foto');
                // Crear nombre del archivo usando nombre y apellido del usuario desde general_data
                $nombre = $driver->user->generalData->nombre ?? 'sin_nombre';
                $apellido = $driver->user->generalData->apellido ?? 'sin_apellido';
                $nombreLimpio = $this->limpiarNombre($nombre . '_' . $apellido);
                $extension = $foto->getClientOriginalExtension();
                $nombreFoto = $nombreLimpio . '_' . time() . '.' . $extension;
                // Crear la carpeta si no existe
                $rutaCarpeta = public_path('img_drivers');
                if (!file_exists($rutaCarpeta)) {
                    mkdir($rutaCarpeta, 0755, true);
                }
                // Mover el archivo a la carpeta img_drivers
                $foto->move($rutaCarpeta, $nombreFoto);
                // Eliminar la foto anterior si existía
                if ($fotoAnterior && file_exists(public_path('img_drivers/' . $fotoAnterior))) {
                    unlink(public_path('img_drivers/' . $fotoAnterior));
                }
            }
            // Actualizar solo los campos enviados en la request
            $datosActualizar = $request->only([
                'numero_licencia',
                'vencimiento_licencia',
                'estado_licencia',
                'clase_cdl',
                'tipo_licencia',
                'restricciones',
                'categoria',
                'observaciones'
            ]);
            // Solo actualizar la foto si se subió una nueva
            if ($request->hasFile('foto')) {
                $datosActualizar['foto'] = $nombreFoto;
            }
            // Actualizar el conductor
            $driver->update($datosActualizar);
            DB::commit();
            // Recargar las relaciones
            $driver->load('user.generalData', 'documents');
            return response()->json([
                'message' => 'Conductor actualizado exitosamente',
                'datos' => $driver,
                'ruta_foto' => $driver->foto ? asset('img_drivers/' . $driver->foto) : null
            ], 200);
        } catch (\Exception $e) {
            DB::rollback();
            // Eliminar la nueva foto si se subió pero falló la actualización
            if (isset($nombreFoto) && $nombreFoto !== $fotoAnterior && file_exists(public_path('img_drivers/' . $nombreFoto))) {
                unlink(public_path('img_drivers/' . $nombreFoto));
            }
            return response()->json([
                'message' => 'Error al actualizar el conductor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            // Buscar el conductor
            $driver = Driver::with('user')->findOrFail($id);
            DB::beginTransaction();
            // Eliminar la foto del conductor si existe
            if ($driver->foto && file_exists(public_path('img_drivers/' . $driver->foto))) {
                unlink(public_path('img_drivers/' . $driver->foto));
            }
            // Remover el rol 'carrier' del usuario
            if ($driver->user) {
                $driver->user->removeRole('carrier');
            }
            // Eliminar el conductor
            $driver->delete();
            DB::commit();
            return response()->json([
                'message' => 'Conductor eliminado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Error al eliminar el conductor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Método corregido para obtener conductores activos de forma simple
    public function getSimpleActiveDrivers()
    {
        try {
            $drivers = Driver::with(['user.generalData', 'documents'])
                ->where('estado', true)
                ->whereHas('user', function ($q) {
                    $q->where('estado', true);
                })
                ->get()
                ->map(function ($driver) {
                    return [
                        'id' => $driver->id,
                        'numero_licencia' => $driver->numero_licencia,
                        'vencimiento_licencia' => $driver->vencimiento_licencia,
                        'estado_licencia' => $driver->estado_licencia,
                        'categoria' => $driver->categoria,
                        'foto_url' => $driver->foto ? asset('img_drivers/' . $driver->foto) : null,
                        'user' => [
                            'id' => $driver->user->id,
                            'email' => $driver->user->email,
                            'general_data' => $driver->user->generalData ? [
                                'nombre' => $driver->user->generalData->nombre,
                                'apellido' => $driver->user->generalData->apellido,
                                'documento_identidad' => $driver->user->generalData->documento_identidad,
                                'celular' => $driver->user->generalData->celular,
                                'direccion' => $driver->user->generalData->direccion,
                                'ciudad' => $driver->user->generalData->ciudad,
                                'departamento' => $driver->user->generalData->departamento,
                            ] : null
                        ],
                        'documents' => $driver->documents ? $driver->documents->map(function ($doc) {
                            return [
                                'id' => $doc->id,
                                'tipo' => $doc->tipo,
                                'nombre' => $doc->nombre,
                                'archivo_url' => $doc->archivo ? asset($doc->archivo) : null,
                                'fecha_vencimiento' => $doc->fecha_vencimiento,
                                'aprobado' => $doc->aprobado,
                                'observaciones' => $doc->observaciones
                            ];
                        }) : []
                    ];
                });
            return response()->json([
                'message' => 'Conductores activos obtenidos exitosamente',
                'datos' => $drivers,
                'total' => $drivers->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los conductores activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getActiveDriversBasic()
    {
        try {
            $drivers = Driver::with(['user.generalData'])
                ->where('estado', true)
                ->whereHas('user', function ($q) {
                    $q->where('estado', true);
                })
                ->get()
                ->map(function ($driver) {
                    return [
                        'id' => $driver->id,
                        'numero_licencia' => $driver->numero_licencia,
                        'vencimiento_licencia' => $driver->vencimiento_licencia,
                        'estado_licencia' => $driver->estado_licencia,
                        'categoria' => $driver->categoria,
                        'foto_url' => $driver->foto ? asset('img_drivers/' . $driver->foto) : null,
                        'nombre_completo' => $driver->user->generalData
                            ? $driver->user->generalData->nombre . ' ' . $driver->user->generalData->apellido
                            : 'Sin nombre',
                        'email' => $driver->user->email,
                        'documento_identidad' => $driver->user->generalData
                            ? $driver->user->generalData->documento_identidad
                            : null,
                        'celular' => $driver->user->generalData
                            ? $driver->user->generalData->celular
                            : null,
                    ];
                });

            return response()->json([
                'message' => 'Conductores activos obtenidos exitosamente',
                'datos' => $drivers,
                'total' => $drivers->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los conductores activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Método auxiliar para limpiar el nombre del archivo
     */
    private function limpiarNombre($nombre)
    {
        // Remover acentos y caracteres especiales
        $nombre = iconv('UTF-8', 'ASCII//TRANSLIT', $nombre);
        // Reemplazar espacios y caracteres no alfanuméricos con guiones bajos
        $nombre = preg_replace('/[^a-zA-Z0-9]/', '_', $nombre);
        // Remover guiones bajos duplicados
        $nombre = preg_replace('/_+/', '_', $nombre);
        // Remover guiones bajos del inicio y final
        $nombre = trim($nombre, '_');
        return strtolower($nombre);
    }
    public function getUsersNotDrivers()
    {
        try {
            // Obtenemos usuarios que no tienen un registro en la tabla drivers
            $users = User::with('generalData')
                ->whereDoesntHave('driver')
                ->orderBy('users.created_at', 'desc')
                ->get();

            return response()->json([
                'message' => 'Usuarios que no son conductores obtenidos exitosamente',
                'datos' => $users,
                'total' => $users->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los usuarios que no son conductores',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Activar un conductor
     */
    public function activate($id)
    {
        try {
            $driver = Driver::findOrFail($id);
            $driver->estado = true;
            $driver->save();

            return response()->json([
                'message' => 'Conductor activado exitosamente',
                'datos' => $driver
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al activar el conductor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Desactivar un conductor
     */
    public function desactivate($id)
    {
        try {
            $driver = Driver::findOrFail($id);
            $driver->estado = false;
            $driver->save();

            return response()->json([
                'message' => 'Conductor desactivado exitosamente',
                'datos' => $driver
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al desactivar el conductor',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
