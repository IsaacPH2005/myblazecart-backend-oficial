<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Listar todas las categorías
     */
    public function index()
    {
        $categories = Category::select('id', 'nombre', 'descripcion', 'estado')
            ->get();
        return response()->json([
            'mensaje' => 'Categorías cargadas correctamente',
            'data' => $categories
        ]);
    }

    /**
     * Listar solo categorías activas
     */
    public function activas()
    {
        $categories = Category::where('estado', true)
            ->select('id', 'nombre', 'descripcion', 'estado')  // Eliminado caja_operativa_id
            ->get();

        return response()->json([
            'mensaje' => 'Categorías activas cargadas',
            'data' => $categories
        ]);
    }

    /**
     * Mostrar una categoría
     */
    public function show($id)
    {
        $category = Category::find($id);  // Eliminado with('cajaOperativa')

        if (!$category) {
            return response()->json(['mensaje' => 'Categoría no encontrada.'], 404);
        }

        return response()->json([
            'mensaje' => 'Categoría encontrada.',
            'data' => $category
        ]);
    }

    /**
     * Crear una categoría
     */
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255|unique:categories,nombre',
            'descripcion' => 'nullable|string|max:1000',
            // Eliminado: 'caja_operativa_id' => 'required|exists:operating_boxes,id',
        ]);

        $category = Category::create([
            'nombre' => $request->nombre,
            'descripcion' => $request->descripcion,
            // Eliminado: 'caja_operativa_id' => $request->caja_operativa_id,
            'estado' => true,
        ]);

        return response()->json([
            'mensaje' => 'Categoría creada exitosamente.',
            'data' => $category
        ], 201);
    }

    /**
     * Actualizar una categoría
     */
    public function update(Request $request, $id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json(['mensaje' => 'Categoría no encontrada.'], 404);
        }

        $request->validate([
            'nombre' => 'required|string|max:255|unique:categories,nombre,' . $id,
            'descripcion' => 'nullable|string|max:1000',
            // Eliminado: 'caja_operativa_id' => 'required|exists:operating_boxes,id',
        ]);

        $category->update([
            'nombre' => $request->nombre,
            'descripcion' => $request->descripcion,
            // Eliminado: 'caja_operativa_id' => $request->caja_operativa_id,
        ]);

        return response()->json([
            'mensaje' => 'Categoría actualizada exitosamente.',
            'data' => $category
        ]);
    }

    /**
     * Desactivar una categoría
     */
    public function destroy($id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json(['mensaje' => 'Categoría no encontrada.'], 404);
        }

        $category->update(['estado' => false]);

        return response()->json([
            'mensaje' => 'Categoría desactivada exitosamente.'
        ]);
    }

    /**
     * Reactivar una categoría
     */
    public function activate($id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json(['mensaje' => 'Categoría no encontrada.'], 404);
        }

        $category->update(['estado' => true]);

        return response()->json([
            'mensaje' => 'Categoría reactivada exitosamente.'
        ]);
    }

    public function categoryActives()
    {
        $categories = Category::where('estado', true)
            ->select('id', 'nombre', 'descripcion', 'estado')
            ->orderByRaw('LOWER(nombre) asc')
            ->get();

        return response()->json([
            "mensaje" => "Categorias Cargadas",
            "datos" => $categories
        ]);
    }
}
