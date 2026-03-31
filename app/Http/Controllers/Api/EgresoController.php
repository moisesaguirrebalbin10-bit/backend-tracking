<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Egreso;
use App\Models\EgresoLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EgresoController extends Controller
{
    public function index(Request $request)
    {
        // Filtros: por fecha, mes, rango
        $query = Egreso::query();
        if ($request->filled('fecha')) {
            $query->whereDate('fecha', $request->input('fecha'));
        }
        if ($request->filled('desde') && $request->filled('hasta')) {
            $query->whereBetween('fecha', [$request->input('desde'), $request->input('hasta')]);
        }
        if ($request->filled('mes')) {
            $query->whereMonth('fecha', $request->input('mes'));
        }
        $egresos = $query->orderByDesc('fecha')->paginate(30);
        return response()->json($egresos);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'banco_metodo_pago' => 'required|string|max:255',
            'categoria' => 'required|string|max:255',
            'precio' => 'required|numeric|min:0',
            'fecha' => 'required|date|after_or_equal:' . now()->subWeek()->toDateString() . '|before_or_equal:' . now()->toDateString(),
        ]);
        $data['usuario_id'] = Auth::id();
        $egreso = Egreso::create($data);
        EgresoLog::create([
            'egreso_id' => $egreso->id,
            'usuario_id' => Auth::id(),
            'accion' => 'creado',
            'datos_anteriores' => null,
            'datos_nuevos' => $egreso->toArray(),
            'fecha' => now(),
        ]);
        return response()->json($egreso, 201);
    }

    public function show(Egreso $egreso)
    {
        return response()->json($egreso);
    }

    public function update(Request $request, Egreso $egreso)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'banco_metodo_pago' => 'required|string|max:255',
            'categoria' => 'required|string|max:255',
            'precio' => 'required|numeric|min:0',
            'fecha' => 'required|date|after_or_equal:' . now()->subWeek()->toDateString() . '|before_or_equal:' . now()->toDateString(),
        ]);
        $datos_anteriores = $egreso->toArray();
        $egreso->update($data);
        EgresoLog::create([
            'egreso_id' => $egreso->id,
            'usuario_id' => Auth::id(),
            'accion' => 'editado',
            'datos_anteriores' => $datos_anteriores,
            'datos_nuevos' => $egreso->toArray(),
            'fecha' => now(),
        ]);
        return response()->json($egreso);
    }

    public function destroy(Egreso $egreso)
    {
        $datos_anteriores = $egreso->toArray();
        $egreso->delete();
        EgresoLog::create([
            'egreso_id' => $egreso->id,
            'usuario_id' => Auth::id(),
            'accion' => 'eliminado',
            'datos_anteriores' => $datos_anteriores,
            'datos_nuevos' => null,
            'fecha' => now(),
        ]);
        return response()->json(['message' => 'Egreso eliminado']);
    }

    public function logs(Egreso $egreso)
    {
        $logs = $egreso->logs()->with('usuario')->orderByDesc('fecha')->get();
        return response()->json($logs);
    }
}
