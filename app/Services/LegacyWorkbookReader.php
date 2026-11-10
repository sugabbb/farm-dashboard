<?php

namespace App\Services;

use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

final class LegacyWorkbookReader
{
    public const HASH = '5623c1d5dc1317ded02f140161e7e980ff84f017b653d38fcc85a513275cf62e';

    public const SHEETS = [
        'Cash Flow - Pengeluaran',
        'Cash Flow - Pemasukan & Telur K',
        'Record Lele #2',
        'Record Ayam',
        'Record Lele #1 - CLOSED PANEN',
    ];

    /** Workbook-specific last rows that contain source data relevant to the cutoff/report. */
    private const MAX_ROWS = [
        'Cash Flow - Pengeluaran' => 495,
        'Cash Flow - Pemasukan & Telur K' => 253,
        'Record Lele #2' => 29,
        'Record Ayam' => 135,
        'Record Lele #1 - CLOSED PANEN' => 124,
    ];

    /**
     * @return array{
     *   hash:string,
     *   sheets:array<string,array<int,array<string,string>>>,
     *   cells:array<string,array<int,array<string,array{value:string,formula:?string,type:string,has_cached_value:bool}>>>
     * }
     */
    public function read(string $path): array
    {
        $realPath = realpath($path);
        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            throw new RuntimeException("Workbook tidak dapat dibaca: {$path}");
        }

        $hash = hash_file('sha256', $realPath);
        if (! hash_equals(self::HASH, $hash)) {
            throw new RuntimeException("SHA-256 workbook tidak sesuai. Ditemukan {$hash}, diharapkan ".self::HASH.'.');
        }

        $zip = new ZipArchive;
        if ($zip->open($realPath) !== true) {
            throw new RuntimeException('Workbook bukan arsip XLSX yang valid.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $sheetPaths = $this->sheetPaths($zip);
            if (array_keys($sheetPaths) !== self::SHEETS) {
                throw new RuntimeException('Nama atau urutan sheet tidak sesuai kontrak: '.implode(', ', array_keys($sheetPaths)).'.');
            }

            $sheets = [];
            $metadata = [];
            foreach ($sheetPaths as $name => $sheetPath) {
                [$sheets[$name], $metadata[$name]] = $this->rows($zip, $sheetPath, $sharedStrings, self::MAX_ROWS[$name]);
            }

            $this->validateHeaders($sheets);

            return ['hash' => $hash, 'sheets' => $sheets, 'cells' => $metadata];
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $root = $this->xml($xml, 'sharedStrings.xml');
        $root->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $values = [];
        foreach ($root->xpath('//m:si') ?: [] as $item) {
            $parts = [];
            $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($item->xpath('.//m:t') ?: [] as $text) {
                $parts[] = (string) $text;
            }
            $values[] = implode('', $parts);
        }

        return $values;
    }

    /** @return array<string,string> */
    private function sheetPaths(ZipArchive $zip): array
    {
        $workbook = $this->xml($this->entry($zip, 'xl/workbook.xml'), 'workbook.xml');
        $rels = $this->xml($this->entry($zip, 'xl/_rels/workbook.xml.rels'), 'workbook relationships');
        $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');

        $targets = [];
        foreach ($rels->xpath('//p:Relationship') ?: [] as $relationship) {
            $targets[(string) $relationship['Id']] = (string) $relationship['Target'];
        }

        $paths = [];
        foreach ($workbook->xpath('//m:sheets/m:sheet') ?: [] as $sheet) {
            $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationshipId = (string) $attributes['id'];
            if (! isset($targets[$relationshipId])) {
                throw new RuntimeException("Relationship sheet {$relationshipId} tidak ditemukan.");
            }
            $target = ltrim($targets[$relationshipId], '/');
            $paths[(string) $sheet['name']] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        return $paths;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return array{array<int,array<string,string>>,array<int,array<string,array{value:string,formula:?string,type:string,has_cached_value:bool}>>}
     */
    private function rows(ZipArchive $zip, string $path, array $sharedStrings, int $maxRow): array
    {
        $reader = new XMLReader;
        if (! $reader->XML($this->entry($zip, $path), null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException("XML worksheet {$path} tidak valid.");
        }

        $rows = [];
        $metadata = [];
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }
            $rowNumber = (int) $reader->getAttribute('r');
            if ($rowNumber > $maxRow) {
                break;
            }
            $row = $this->xml($reader->readOuterXml(), "row {$rowNumber} {$path}");
            $row->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($row->xpath('./m:c') ?: [] as $cell) {
                $reference = (string) $cell['r'];
                if (! preg_match('/^([A-Z]+)[0-9]+$/', $reference, $matches)) {
                    throw new RuntimeException("Referensi cell tidak valid: {$reference}.");
                }

                $column = $matches[1];
                $type = (string) $cell['t'];
                $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $valueNodes = $cell->xpath('./m:v') ?: [];
                $formulaNodes = $cell->xpath('./m:f') ?: [];
                $hasCachedValue = isset($valueNodes[0]);
                $value = $hasCachedValue ? (string) $valueNodes[0] : '';

                if ($type === 's') {
                    if ($value === '' || ! array_key_exists((int) $value, $sharedStrings)) {
                        throw new RuntimeException("Index shared string tidak valid pada {$reference}.");
                    }
                    $value = $sharedStrings[(int) $value];
                } elseif ($type === 'inlineStr') {
                    $parts = [];
                    foreach ($cell->xpath('.//m:t') ?: [] as $text) {
                        $parts[] = (string) $text;
                    }
                    $value = implode('', $parts);
                    $hasCachedValue = true;
                } elseif (! in_array($type, ['', 'n', 'str', 'b'], true)) {
                    throw new RuntimeException("Tipe cell {$type} pada {$reference} tidak didukung.");
                }

                $rows[$rowNumber][$column] = $value;
                $metadata[$rowNumber][$column] = [
                    'value' => $value,
                    'formula' => isset($formulaNodes[0]) ? (string) $formulaNodes[0] : null,
                    'type' => $type,
                    'has_cached_value' => $hasCachedValue,
                ];
            }
        }
        $reader->close();

        return [$rows, $metadata];
    }

    /** @param array<string,array<int,array<string,string>>> $sheets */
    private function validateHeaders(array $sheets): void
    {
        $expected = [
            'Cash Flow - Pengeluaran' => [
                3 => ['A' => 'Date', 'B' => 'In', 'C' => 'Out', 'D' => 'Cash on hand', 'E' => 'Qty', 'F' => 'Mapping', 'G' => 'Note', 'H' => 'Invoice'],
            ],
            'Cash Flow - Pemasukan & Telur K' => [
                3 => ['A' => 'Date', 'B' => 'Lele / Telur', 'C' => 'Lunas', 'D' => 'Jenis Telur', 'E' => 'Pembeli', 'F' => 'Harga per Kg', 'G' => 'Total Butir', 'H' => 'Berat Real (kg)', 'I' => 'Berat Round (kg)', 'J' => 'Total Cash in', 'K' => 'Total Cash Out', 'L' => 'Cash on Hand', 'M' => 'Note'],
            ],
            'Record Lele #2' => [
                1 => ['A' => 'Tanggal', 'C' => 'Populasi Lele (C1)', 'G' => 'Populasi Lele (C1) - Keramba', 'K' => 'Populasi Lele (C2)', 'O' => 'Konsumsi Pakan Pelet (C1)', 'S' => 'Konsumsi Pakan Pelet (C2)', 'W' => 'Stok Awal (Kg)', 'X' => 'Stok Akhir (Kg)', 'Y' => 'Catatan Harian', 'Z' => 'Sampling Berat (10 Ekor)'],
                2 => ['C' => 'Mati', 'G' => 'Mati', 'K' => 'Mati', 'O' => 'Pagi (Kg)', 'P' => 'Siang (Kg)', 'Q' => 'Sore (Kg)', 'S' => 'Pagi (Kg)', 'T' => 'Siang (Kg)', 'U' => 'Sore (Kg)'],
            ],
            'Record Ayam' => [
                1 => ['A' => 'Tanggal', 'C' => 'Populasi Ayam', 'H' => 'Konsumsi Pakan', 'N' => 'Produksi Telur', 'AH' => 'Telur Keluar (DONT EDIT THESE CELL BELOW PLEASE!)'],
                2 => ['O' => 'Telur Rusak / Retak', 'Q' => 'Telur Reject', 'U' => 'Telur Utuh', 'AF' => 'Catatan'],
            ],
            'Record Lele #1 - CLOSED PANEN' => [
                1 => ['A' => 'Tanggal', 'C' => 'Populasi Lele (C1)', 'G' => 'Populasi Lele (C2)', 'K' => 'Konsumsi Pakan Pelet (C1)', 'N' => 'Konsumsi Pakan Pelet (C2)', 'Q' => 'Stok Awal (Kg)', 'R' => 'Stok Akhir (Kg)', 'S' => 'Sampling Berat (8 Ekor)', 'U' => 'Hasil Panen', 'X' => 'Catatan Harian'],
                2 => ['C' => 'Mati', 'G' => 'Mati', 'K' => 'Pagi (Kg)', 'L' => 'Sore (Kg)', 'N' => 'Pagi (Kg)', 'O' => 'Sore (Kg)'],
            ],
        ];

        foreach ($expected as $sheet => $checks) {
            foreach ($checks as $row => $columns) {
                foreach ($columns as $column => $value) {
                    $actual = trim($sheets[$sheet][$row][$column] ?? '');
                    if ($actual !== $value) {
                        throw new RuntimeException("Header {$sheet}!{$column}{$row} tidak sesuai: '{$actual}', seharusnya '{$value}'.");
                    }
                }
            }
        }
    }

    private function entry(ZipArchive $zip, string $name): string
    {
        $value = $zip->getFromName($name);
        if ($value === false) {
            throw new RuntimeException("Entry XLSX tidak ditemukan: {$name}.");
        }

        return $value;
    }

    private function xml(string $value, string $label): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($value, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            if ($xml === false) {
                throw new RuntimeException("XML {$label} tidak valid.");
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
