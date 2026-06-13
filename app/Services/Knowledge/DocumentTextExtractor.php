<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeResource;
use App\Services\MistralOcrService;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use RuntimeException;

/**
 * Turns an uploaded knowledge resource (документ/снимка) into plain
 * markdown-ish text for the chunker. Routing by mime (extension fallback):
 * PDF + images go through Mistral OCR, Office formats through phpoffice
 * readers, text formats raw.
 */
class DocumentTextExtractor
{
    public function __construct(private MistralOcrService $ocr) {}

    /**
     * @throws RuntimeException with a Bulgarian, user-facing message
     */
    public function extract(KnowledgeResource $document): string
    {
        if (! $document->storage_path || ! Storage::disk('local')->exists($document->storage_path)) {
            throw new RuntimeException('Файлът липсва в хранилището — качи документа отново.');
        }

        $path = Storage::disk('local')->path($document->storage_path);
        $kind = $this->kind((string) $document->mime, (string) $document->original_name);

        $text = match ($kind) {
            'ocr' => $this->viaOcr($path, (string) $document->mime),
            'docx' => $this->fromDocx($path),
            'xlsx' => $this->fromXlsx($path),
            'csv' => $this->fromCsv($path),
            'text' => (string) file_get_contents($path),
            default => throw new RuntimeException('Неподдържан файлов формат: '.($document->mime ?: $document->original_name)),
        };

        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('От документа не беше извлечен никакъв текст.');
        }

        $cap = (int) config('services.knowledge.max_extract_chars', 600000);
        if (mb_strlen($text) > $cap) {
            $text = mb_substr($text, 0, $cap)."\n\n… (документът е съкратен до лимита за обработка)";
        }

        return $text;
    }

    private function kind(string $mime, string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match (true) {
            $mime === 'application/pdf' || $ext === 'pdf',
            str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png'], true) => 'ocr',
            str_contains($mime, 'wordprocessingml') || $ext === 'docx' => 'docx',
            str_contains($mime, 'spreadsheetml') || $ext === 'xlsx' => 'xlsx',
            $mime === 'text/csv' || $ext === 'csv' => 'csv',
            str_starts_with($mime, 'text/') || in_array($ext, ['txt', 'md'], true) => 'text',
            default => 'unsupported',
        };
    }

    private function viaOcr(string $path, string $mime): string
    {
        if (empty(config('services.mistral.api_key'))) {
            throw new RuntimeException('PDF/изображения изискват Mistral OCR — задай MISTRAL_API_KEY.');
        }

        $text = $this->ocr->extractFile($path, $mime ?: 'application/pdf');
        if ($text === null || trim($text) === '') {
            throw new RuntimeException('OCR не върна текст (Mistral OCR недостъпен или празен документ).');
        }

        return $text;
    }

    private function fromDocx(string $path): string
    {
        $word = WordIOFactory::load($path);

        $lines = [];
        foreach ($word->getSections() as $section) {
            $this->walkWordContainer($section, $lines);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function walkWordContainer(AbstractContainer $container, array &$lines): void
    {
        foreach ($container->getElements() as $element) {
            if ($element instanceof TextRun) {
                $text = '';
                foreach ($element->getElements() as $child) {
                    if ($child instanceof Text) {
                        $text .= $child->getText();
                    }
                }
                $lines[] = $this->withHeadingPrefix($element, trim($text));
            } elseif ($element instanceof Text) {
                $lines[] = $this->withHeadingPrefix($element, trim($element->getText()));
            } elseif ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    $cells = [];
                    foreach ($row->getCells() as $cell) {
                        $cellLines = [];
                        $this->walkWordContainer($cell, $cellLines);
                        $cells[] = trim(implode(' ', $cellLines));
                    }
                    $lines[] = '| '.implode(' | ', $cells).' |';
                }
            } elseif ($element instanceof AbstractContainer) {
                $this->walkWordContainer($element, $lines);
            }
        }
    }

    private function withHeadingPrefix(object $element, string $text): string
    {
        if ($text === '' || ! method_exists($element, 'getParagraphStyle')) {
            return $text;
        }

        $style = $element->getParagraphStyle();
        $name = is_object($style) && method_exists($style, 'getStyleName') ? (string) $style->getStyleName() : (string) $style;

        if (preg_match('/Heading\s*([1-3])/i', $name, $m)) {
            return str_repeat('#', (int) $m[1]).' '.$text;
        }

        return $text;
    }

    private function fromXlsx(string $path): string
    {
        $reader = SpreadsheetIOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $maxRows = (int) config('services.knowledge.xlsx_max_rows', 2000);
        $blocks = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $lines = ['## Лист: '.$sheet->getTitle()];
            $count = 0;

            foreach ($sheet->getRowIterator() as $row) {
                if (++$count > $maxRows) {
                    $lines[] = '… (таблицата е съкратена до '.$maxRows.' реда)';
                    break;
                }

                $cells = [];
                foreach ($row->getCellIterator() as $cell) {
                    $cells[] = trim((string) $cell->getFormattedValue());
                }
                if (trim(implode('', $cells)) === '') {
                    continue;
                }
                $lines[] = '| '.implode(' | ', $cells).' |';
            }

            if (count($lines) > 1) {
                $blocks[] = implode("\n", $lines);
            }
        }

        $spreadsheet->disconnectWorksheets();

        return implode("\n\n", $blocks);
    }

    private function fromCsv(string $path): string
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('CSV файлът не може да бъде отворен.');
        }

        $maxRows = (int) config('services.knowledge.xlsx_max_rows', 2000);
        $lines = [];
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (++$count > $maxRows) {
                $lines[] = '… (таблицата е съкратена до '.$maxRows.' реда)';
                break;
            }
            $cells = array_map(fn ($cell) => trim((string) $cell), $row);
            if (trim(implode('', $cells)) === '') {
                continue;
            }
            $lines[] = '| '.implode(' | ', $cells).' |';
        }
        fclose($handle);

        return implode("\n", $lines);
    }
}
