<?php

namespace App\Http\Controllers\api\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\DriverDocument;
use App\Models\Driver;
use Illuminate\Support\Facades\File;

class DriverDocumentController extends Controller
{
    /**
     * Listar todos los documentos de conductores
     */
    public function index()
    {
        try {
            $documents = DriverDocument::with('driver.user.generalData')
                ->orderBy('created_at', 'desc')  // Orden descendente por fecha de creación
                ->get();

            // Iterar para modificar la URL del archivo y agregar campos adicionales
            foreach ($documents as $document) {
                if ($document->archivo) {
                    $document->archivo = asset($document->archivo);
                }
                $document->rutaArchivoCompleta = file_exists(public_path($document->archivo)) ? asset($document->archivo) : null;
            }

            return response()->json([
                'message' => 'Documentos de conductores obtenidos exitosamente',
                'datos' => $documents
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los documentos',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Mostrar documentos de un conductor específico
     */
    public function show($id)
    {
        try {
            $document = DriverDocument::with('driver.user.generalData')->findOrFail($id);

            // Generar URL completa del archivo usando asset()
            $document->archivo_url = $document->archivo ? asset($document->archivo) : null;

            return response()->json([
                'message' => 'Documento obtenido exitosamente',
                'datos' => $document
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Documento no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }



    /**
     * Subir un documento para un conductor
     */
    public function store(Request $request)
    {
        $request->validate([
            'driver_id' => 'required|exists:drivers,id',
            'tipo' => 'required|in:licencia,seguro,identificacion,certificado_medico,registro_vehicular,otros',
            'nombre' => 'required|string|max:255',
            'archivo' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240', // 10MB max
            'fecha_vencimiento' => 'nullable|date',
            'observaciones' => 'nullable|string'
        ]);
        try {
            DB::beginTransaction();
            // Obtener información del conductor
            $driver = Driver::with('user.generalData')->findOrFail($request->driver_id);
            // Manejo del archivo
            $archivo = $request->file('archivo');
            // Verificar el tipo MIME real del archivo
            $mimeType = $archivo->getMimeType();
            $allowedMimeTypes = [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            if (!in_array($mimeType, $allowedMimeTypes)) {
                throw new \Exception('El archivo no tiene un formato válido');
            }
            $nombre = $driver->user->generalData->nombre ?? 'sin_nombre';
            $apellido = $driver->user->generalData->apellido ?? 'sin_apellido';
            $nombreLimpio = $this->limpiarNombre($nombre . '_' . $apellido);
            $tipoDoc = $request->tipo;
            $extension = $archivo->getClientOriginalExtension();
            $nombreArchivo = "{$tipoDoc}_" . time() . ".{$extension}";
            // Crear la carpeta específica del conductor
            $rutaConductor = "drivers/{$nombreLimpio}";
            $rutaCarpetaCompleta = public_path($rutaConductor);
            if (!file_exists($rutaCarpetaCompleta)) {
                mkdir($rutaCarpetaCompleta, 0755, true);
            }
            // Mover el archivo a la carpeta del conductor
            $archivo->move($rutaCarpetaCompleta, $nombreArchivo);
            // Guardar la ruta relativa completa
            $rutaArchivoCompleta = "{$rutaConductor}/{$nombreArchivo}";
            // Crear el documento
            $document = DriverDocument::create([
                'driver_id' => $request->driver_id,
                'tipo' => $request->tipo,
                'nombre' => $request->nombre,
                'archivo' => $rutaArchivoCompleta,
                'fecha_vencimiento' => $request->fecha_vencimiento,
                'observaciones' => $request->observaciones,
                'aprobado' => false
            ]);
            DB::commit();
            return response()->json([
                'message' => 'Documento subido exitosamente',
                'datos' => $document->load('driver.user.generalData'),
                'archivo_url' => $document->archivo_url
            ], 201);
        } catch (\Exception $e) {
            DB::rollback();
            // Eliminar archivo si se subió pero falló la creación
            if (isset($rutaArchivoCompleta) && file_exists(public_path($rutaArchivoCompleta))) {
                unlink(public_path($rutaArchivoCompleta));
            }
            return response()->json([
                'message' => 'Error al subir el documento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un documento
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'tipo' => 'sometimes|in:licencia,seguro,identificacion,certificado_medico,registro_vehicular,otros',
            'nombre' => 'sometimes|string|max:255',
            'archivo' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
            'fecha_vencimiento' => 'nullable|date_format:Y/m/d',
            'observaciones' => 'nullable|string'
        ]);
        try {
            DB::beginTransaction();
            $document = DriverDocument::with('driver.user.generalData')->findOrFail($id);
            $archivoAnterior = $document->archivo;
            $nombreArchivo = $archivoAnterior;
            // Si se sube un nuevo archivo
            if ($request->hasFile('archivo')) {
                $archivo = $request->file('archivo');
                // Verificar el tipo MIME real del archivo
                $mimeType = $archivo->getMimeType();
                $allowedMimeTypes = [
                    'application/pdf',
                    'image/jpeg',
                    'image/png',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ];
                if (!in_array($mimeType, $allowedMimeTypes)) {
                    throw new \Exception('El archivo no tiene un formato válido');
                }
                $driver = $document->driver;
                $nombre = $driver->user->generalData->nombre ?? 'sin_nombre';
                $apellido = $driver->user->generalData->apellido ?? 'sin_apellido';
                $nombreLimpio = $this->limpiarNombre($nombre . '_' . $apellido);
                $tipoDoc = $request->get('tipo', $document->tipo);
                $extension = $archivo->getClientOriginalExtension();
                $nombreArchivo = "{$tipoDoc}_" . time() . ".{$extension}";
                // Crear carpeta específica del conductor
                $rutaConductor = "drivers/{$nombreLimpio}";
                $rutaCarpetaCompleta = public_path($rutaConductor);
                if (!file_exists($rutaCarpetaCompleta)) {
                    mkdir($rutaCarpetaCompleta, 0755, true);
                }
                // Mover nuevo archivo
                $archivo->move($rutaCarpetaCompleta, $nombreArchivo);
                // Ruta completa del nuevo archivo
                $nombreArchivo = "{$rutaConductor}/{$nombreArchivo}";
                // Eliminar archivo anterior
                if ($archivoAnterior && file_exists(public_path($archivoAnterior))) {
                    unlink(public_path($archivoAnterior));
                }
            }
            // Actualizar datos
            $datosActualizar = $request->only(['tipo', 'nombre', 'fecha_vencimiento', 'observaciones']);
            if ($request->hasFile('archivo')) {
                $datosActualizar['archivo'] = $nombreArchivo;
            }
            $document->update($datosActualizar);
            DB::commit();
            return response()->json([
                'message' => 'Documento actualizado exitosamente',
                'datos' => $document->load('driver.user.generalData'),
                'archivo_url' => $document->archivo_url
            ], 200);
        } catch (\Exception $e) {
            DB::rollback();
            // Eliminar nuevo archivo si falló
            if (isset($nombreArchivo) && $nombreArchivo !== $archivoAnterior && file_exists(public_path($nombreArchivo))) {
                unlink(public_path($nombreArchivo));
            }
            return response()->json([
                'message' => 'Error al actualizar el documento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un documento
     */
    public function destroy($id)
    {
        try {
            $document = DriverDocument::findOrFail($id);
            // Eliminar archivo físico
            if ($document->archivo && file_exists(public_path($document->archivo))) {
                unlink(public_path($document->archivo));
                // Intentar eliminar la carpeta del conductor si está vacía
                $rutaConductor = dirname(public_path($document->archivo));
                if (is_dir($rutaConductor) && count(scandir($rutaConductor)) == 2) {
                    rmdir($rutaConductor);
                }
            }
            // Eliminar registro
            $document->delete();
            return response()->json([
                'message' => 'Documento eliminado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar el documento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Descargar un documento
     */
    public function download($id)
    {
        try {
            // Buscar el documento por ID
            $document = DriverDocument::findOrFail($id);
            // Obtener la ruta completa del archivo
            $filePath = public_path($document->archivo);
            // Verificar si el archivo existe
            if (!File::exists($filePath)) {
                return response()->json([
                    'message' => 'Archivo no encontrado',
                    'error' => 'El archivo no existe en el servidor'
                ], 404);
            }
            // Obtener la extensión del archivo
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            // Mapear extensiones a tipos MIME
            $mimeTypes = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            // Determinar el tipo MIME
            $mimeType = isset($mimeTypes[$extension]) ? $mimeTypes[$extension] : File::mimeType($filePath);

            // Obtener el nombre original del archivo para la descarga
            $fileName = $document->nombre . '.' . $extension;
            // Definir headers
            $headers = [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ];
            // Retornar el archivo como respuesta descargable
            return response()->file($filePath, $headers);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al descargar el documento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Método auxiliar para limpiar nombres
     */
    private function limpiarNombre($nombre)
    {
        $nombre = iconv('UTF-8', 'ASCII//TRANSLIT', $nombre);
        $nombre = preg_replace('/[^a-zA-Z0-9]/', '_', $nombre);
        $nombre = preg_replace('/_+/', '_', $nombre);
        $nombre = trim($nombre, '_');
        return strtolower($nombre);
    }
}
