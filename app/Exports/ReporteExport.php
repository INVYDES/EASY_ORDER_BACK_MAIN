<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ReporteExport implements FromCollection, WithHeadings, WithMapping
{
    protected $data;
    protected $tipo;
    protected $fechaInicio;
    protected $fechaFin;

    public function __construct($data, $tipo, $fechaInicio, $fechaFin)
    {
        $this->data = $data;
        $this->tipo = $tipo;
        $this->fechaInicio = $fechaInicio;
        $this->fechaFin = $fechaFin;
    }

    public function collection()
    {
        return $this->data;
    }

    public function headings(): array
    {
        switch ($this->tipo) {
            case 'ventas':
                return ['ID', 'Fecha', 'Cliente', 'Total', 'Estado'];
            case 'productos':
                return ['ID', 'Nombre', 'Precio', 'Total Vendido', 'Total Ingresos'];
            case 'clientes':
                return ['ID', 'Nombre', 'Email', 'Teléfono', 'Total Compras'];
            case 'utilidad':
                return ['Descripción', 'Valor'];
            case 'roi':
                return ['KPI', 'Valor'];
            default:
                return [];
        }
    }

    public function map($row): array
    {
        switch ($this->tipo) {
            case 'ventas':
                return [
                    $row->id,
                    $row->created_at->format('d/m/Y H:i'),
                    $row->usuario->name ?? 'N/A',
                    $row->total,
                    $row->estado,
                ];
            case 'productos':
                return [
                    $row->id,
                    $row->nombre,
                    $row->precio,
                    $row->total_vendido ?? 0,
                    $row->total_ingresos ?? 0,
                ];
            case 'clientes':
                return [
                    $row->id,
                    $row->nombre,
                    $row->email ?? 'N/A',
                    $row->telefono ?? 'N/A',
                    $row->total_compras ?? 0,
                ];
            case 'utilidad':
            case 'roi':
                // En estos casos $row suele ser una pareja key => value
                return [
                    ucfirst(str_replace('_', ' ', $row['label'] ?? 'Dato')),
                    $row['value'] ?? '0'
                ];
            default:
                return [];
        }
    }
}