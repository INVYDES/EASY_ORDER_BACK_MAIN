<?php

namespace App\Helpers;

/**
 * Generador de .xlsx mínimo y sin dependencias.
 *
 * El servidor puede no tener la extensión `zip` (ZipArchive) —en este proyecto
 * no está disponible—, así que el archivo se arma a mano: un XLSX es un ZIP con
 * unos XML dentro. Se usan entradas *stored* (sin compresión) y CRC-32, que
 * Excel y el resto de hojas de cálculo abren sin problema.
 *
 * Está pensado para catálogos grandes: las filas se van escribiendo al sheet en
 * un stream temporal (php://temp, que desborda a disco), y el ZIP se vuelca al
 * final al stream de salida. Así la memoria se mantiene acotada.
 *
 * Uso:
 *   $xlsx = new XlsxWriter('Productos');
 *   $xlsx->addRow(['nombre', 'precio']);
 *   foreach ($rows as $row) { $xlsx->addRow([$row->nombre, (float) $row->precio]); }
 *   $xlsx->writeTo(fopen('php://output', 'w'));
 */
class XlsxWriter
{
    /** @var resource Stream temporal donde se acumula el XML del sheet. */
    private $sheet;

    /** @var \HashContext CRC-32 del sheet (se calcula a medida que se escribe). */
    private $crc;

    private int $sheetSize = 0;
    private int $rowNumber = 0;
    private string $sheetName;

    public function __construct(string $sheetName = 'Hoja1')
    {
        $this->sheetName = self::sanitizeSheetName($sheetName);

        $this->sheet = fopen('php://temp', 'r+');
        $this->crc   = hash_init('crc32b');

        $this->put('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
        $this->put('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
    }

    /**
     * Añade una fila. Los tipos se preservan: los números van como números y el
     * texto como string en línea (inlineStr, sin tabla de strings compartidos).
     *
     * @param array<int, string|int|float|bool|null> $values
     */
    public function addRow(array $values): void
    {
        $this->rowNumber++;

        $xml = '<row r="' . $this->rowNumber . '">';

        foreach (array_values($values) as $i => $value) {
            // null / cadena vacía: se omite la celda (queda en blanco).
            if ($value === null || $value === '') {
                continue;
            }

            $ref = self::columnName($i + 1) . $this->rowNumber;

            if (is_bool($value)) {
                $xml .= '<c r="' . $ref . '" t="b"><v>' . ($value ? '1' : '0') . '</v></c>';
            } elseif (is_int($value) || is_float($value)) {
                $xml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                      . self::escape((string) $value) . '</t></is></c>';
            }
        }

        $xml .= '</row>';

        $this->put($xml);
    }

    /**
     * Vuelca el .xlsx completo al stream indicado.
     *
     * @param resource $out
     */
    public function writeTo($out): void
    {
        $this->put('</sheetData></worksheet>');

        $sheetCrc = hexdec(hash_final($this->crc));
        [$dosTime, $dosDate] = self::dosTimestamp();

        $entries = [
            ['[Content_Types].xml', self::CONTENT_TYPES],
            ['_rels/.rels', self::ROOT_RELS],
            ['xl/workbook.xml', $this->workbookXml()],
            ['xl/_rels/workbook.xml.rels', self::WORKBOOK_RELS],
        ];

        $offset  = 0;
        $central = [];
        $write   = function (string $data) use ($out, &$offset): void {
            fwrite($out, $data);
            $offset += strlen($data);
        };

        foreach ($entries as [$name, $data]) {
            $crc  = crc32($data);
            $size = strlen($data);
            $central[] = [$name, $crc, $size, $offset];

            $write(self::localHeader($name, $crc, $size, $dosTime, $dosDate));
            $write($name);
            $write($data);
        }

        // El sheet puede ser enorme: la cabecera ya lleva crc/tamaño calculados
        // y luego se copia el stream temporal en bloques (sin cargarlo en memoria).
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $central[] = [$sheetPath, $sheetCrc, $this->sheetSize, $offset];

        $write(self::localHeader($sheetPath, $sheetCrc, $this->sheetSize, $dosTime, $dosDate));
        $write($sheetPath);

        rewind($this->sheet);
        while (!feof($this->sheet)) {
            $chunk = fread($this->sheet, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $write($chunk);
        }
        fclose($this->sheet);

        // Directorio central + fin del directorio central.
        $cdOffset = $offset;
        $cdSize   = 0;

        foreach ($central as [$name, $crc, $size, $localOffset]) {
            $record = self::centralHeader($name, $crc, $size, $localOffset, $dosTime, $dosDate);
            $write($record);
            $write($name);
            $cdSize += strlen($record) + strlen($name);
        }

        $write(pack(
            'VvvvvVVv',
            0x06054b50,          // firma fin de directorio central
            0, 0,                // disco / disco del directorio central
            count($central),     // entradas en este disco
            count($central),     // entradas totales
            $cdSize,             // tamaño del directorio central
            $cdOffset,           // offset del directorio central
            0                    // longitud del comentario
        ));
    }

    /** Escribe en el sheet y actualiza CRC/tamaño. */
    private function put(string $data): void
    {
        fwrite($this->sheet, $data);
        hash_update($this->crc, $data);
        $this->sheetSize += strlen($data);
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function localHeader(string $name, int $crc, int $size, int $dosTime, int $dosDate): string
    {
        return pack(
            'VvvvvvVVVvv',
            0x04034b50,          // firma de cabecera local
            20,                  // versión necesaria (2.0)
            0x0800,              // flag: nombres en UTF-8
            0,                   // compresión: stored
            $dosTime,
            $dosDate,
            $crc,
            $size,               // tamaño comprimido
            $size,               // tamaño sin comprimir
            strlen($name),
            0                    // longitud del campo extra
        );
    }

    private static function centralHeader(string $name, int $crc, int $size, int $offset, int $dosTime, int $dosDate): string
    {
        return pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,          // firma de cabecera del directorio central
            20,                  // versión con la que se creó
            20,                  // versión necesaria
            0x0800,              // flag: nombres en UTF-8
            0,                   // compresión: stored
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            strlen($name),
            0,                   // extra
            0,                   // comentario
            0,                   // disco de inicio
            0,                   // atributos internos
            0,                   // atributos externos
            $offset              // offset de la cabecera local
        );
    }

    /** Índice 1-based → nombre de columna de Excel (1→A, 27→AA). */
    private static function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name  = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** @return array{0:int,1:int} [hora DOS, fecha DOS] */
    private static function dosTimestamp(): array
    {
        $t = getdate();

        $time = ($t['hours'] << 11) | ($t['minutes'] << 5) | ($t['seconds'] >> 1);
        $date = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];

        return [$time, $date];
    }

    private static function sanitizeSheetName(string $name): string
    {
        // Excel no permite: \ / ? * [ ] : y limita el nombre a 31 caracteres.
        $name = str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name);
        $name = trim(substr($name, 0, 31));

        return $name === '' ? 'Hoja1' : $name;
    }

    private const CONTENT_TYPES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    private const ROOT_RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    private const WORKBOOK_RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';
}
