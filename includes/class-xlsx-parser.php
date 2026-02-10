<?php
/**
 * Lightweight XLSX parser - reads .xlsx files without external dependencies.
 * XLSX files are ZIP archives containing XML files.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSC_XLSX_Parser {

    private $file_path;

    public function __construct($file_path) {
        $this->file_path = $file_path;
    }

    public function parse() {
        if (!class_exists('ZipArchive')) {
            throw new Exception('Расширение ZipArchive не установлено на сервере');
        }

        $zip = new ZipArchive();
        if ($zip->open($this->file_path) !== true) {
            throw new Exception('Не удалось открыть файл .xlsx');
        }

        // Read shared strings
        $shared_strings = $this->read_shared_strings($zip);

        // Read sheet1
        $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet_xml === false) {
            $zip->close();
            throw new Exception('Не удалось прочитать лист данных');
        }

        $zip->close();

        $sheet = simplexml_load_string($sheet_xml);
        if ($sheet === false) {
            throw new Exception('Ошибка разбора XML листа');
        }

        $rows_data = [];
        foreach ($sheet->sheetData->row as $row) {
            $row_cells = [];
            foreach ($row->c as $cell) {
                $ref = (string)$cell['r'];
                $col = preg_replace('/[0-9]/', '', $ref);
                $type = (string)$cell['t'];
                $value = (string)$cell->v;

                if ($type === 's' && isset($shared_strings[$value])) {
                    $row_cells[$col] = $shared_strings[(int)$value];
                } elseif ($type === 'inlineStr') {
                    $row_cells[$col] = (string)$cell->is->t;
                } else {
                    $row_cells[$col] = $value;
                }
            }
            $rows_data[] = $row_cells;
        }

        if (empty($rows_data)) {
            throw new Exception('Файл не содержит данных');
        }

        // First row is header - validate columns
        $header = $rows_data[0];
        $this->validate_header($header);

        // Map column letters to data fields
        $col_map = $this->map_columns($header);

        $result = [];
        for ($i = 1; $i < count($rows_data); $i++) {
            $row = $rows_data[$i];
            $parsed = $this->parse_row($row, $col_map);
            if ($parsed !== null) {
                $result[] = $parsed;
            }
        }

        return $result;
    }

    private function read_shared_strings($zip) {
        $strings = [];
        $xml_str = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml_str === false) {
            return $strings;
        }

        $xml = simplexml_load_string($xml_str);
        if ($xml === false) {
            return $strings;
        }

        $index = 0;
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $strings[$index] = (string)$si->t;
            } elseif (isset($si->r)) {
                $text = '';
                foreach ($si->r as $r) {
                    $text .= (string)$r->t;
                }
                $strings[$index] = $text;
            } else {
                $strings[$index] = '';
            }
            $index++;
        }

        return $strings;
    }

    private function validate_header($header) {
        $required = ['DATE', 'MCFTR', 'SC TOP 10'];
        $header_values = array_map('trim', array_values($header));

        foreach ($required as $col) {
            $found = false;
            foreach ($header_values as $h) {
                if (stripos($h, $col) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new Exception('Отсутствует обязательный столбец: ' . $col);
            }
        }
    }

    private function map_columns($header) {
        $map = [];
        foreach ($header as $col_letter => $name) {
            $name_trimmed = trim($name);
            $name_upper = mb_strtoupper($name_trimmed, 'UTF-8');

            if ($name_upper === 'DATE' || $name_upper === 'ДАТА') {
                $map['date'] = $col_letter;
            } elseif (preg_match('/MCFTR.*%/u', $name_upper) || preg_match('/MCFTR.*В\s*%/ui', $name_trimmed)) {
                $map['mcftr_pct'] = $col_letter;
            } elseif (stripos($name_trimmed, 'MCFTR') !== false) {
                $map['mcftr'] = $col_letter;
            } elseif (preg_match('/SC\s*TOP\s*10.*%/ui', $name_trimmed)) {
                $map['sc_top10_pct'] = $col_letter;
            } elseif (preg_match('/SC\s*TOP\s*10/ui', $name_trimmed)) {
                $map['sc_top10'] = $col_letter;
            }
        }

        if (!isset($map['date']) || !isset($map['mcftr']) || !isset($map['sc_top10'])) {
            throw new Exception('Не удалось определить все обязательные столбцы (DATE, MCFTR, SC TOP 10)');
        }

        return $map;
    }

    private function parse_row($row, $col_map) {
        $date_col = $col_map['date'];
        if (!isset($row[$date_col]) || empty(trim($row[$date_col]))) {
            return null;
        }

        $date_raw = trim($row[$date_col]);
        $date_info = $this->parse_date($date_raw);

        if ($date_info === null) {
            return null;
        }

        $mcftr = isset($row[$col_map['mcftr']]) ? $this->parse_number($row[$col_map['mcftr']]) : 0;
        $sc_top10 = isset($row[$col_map['sc_top10']]) ? $this->parse_number($row[$col_map['sc_top10']]) : 0;

        $mcftr_pct = null;
        if (isset($col_map['mcftr_pct']) && isset($row[$col_map['mcftr_pct']]) && $row[$col_map['mcftr_pct']] !== '') {
            $mcftr_pct = $this->parse_number($row[$col_map['mcftr_pct']]);
        }

        $sc_top10_pct = null;
        if (isset($col_map['sc_top10_pct']) && isset($row[$col_map['sc_top10_pct']]) && $row[$col_map['sc_top10_pct']] !== '') {
            $sc_top10_pct = $this->parse_number($row[$col_map['sc_top10_pct']]);
        }

        return [
            'date_value'  => $date_info['date'],
            'date_label'  => $date_info['label'],
            'mcftr'       => $mcftr,
            'mcftr_pct'   => $mcftr_pct,
            'sc_top10'    => $sc_top10,
            'sc_top10_pct'=> $sc_top10_pct,
        ];
    }

    private function parse_date($raw) {
        // Excel serial date number
        if (is_numeric($raw) && (int)$raw > 30000) {
            $unix = ($raw - 25569) * 86400;
            $date = gmdate('Y-m-d', (int)$unix);
            $label = gmdate('m/Y', (int)$unix);
            return ['date' => $date, 'label' => $label];
        }

        // Format: MM/YYYY or M/YYYY
        if (preg_match('/^(\d{1,2})\/(\d{4})$/', $raw, $m)) {
            $month = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $year = $m[2];
            return [
                'date'  => "{$year}-{$month}-01",
                'label' => "{$month}/{$year}",
            ];
        }

        // Format: DD.MM.YYYY
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m)) {
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $year = $m[3];
            return [
                'date'  => "{$year}-{$month}-{$day}",
                'label' => "{$month}/{$year}",
            ];
        }

        // Format: YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return [
                'date'  => $raw,
                'label' => "{$m[2]}/{$m[1]}",
            ];
        }

        return null;
    }

    private function parse_number($value) {
        if (is_numeric($value)) {
            return (float)$value;
        }
        // Handle Russian number format: spaces as thousands separator, comma as decimal
        $value = str_replace([' ', "\xC2\xA0"], '', $value); // regular and non-breaking spaces
        $value = str_replace(',', '.', $value);
        $value = preg_replace('/[^0-9.\-]/', '', $value);
        return $value !== '' ? (float)$value : 0;
    }
}
