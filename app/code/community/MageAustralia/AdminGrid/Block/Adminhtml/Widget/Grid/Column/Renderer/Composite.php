<?php

declare(strict_types=1);

/**
 * Composite column renderer — displays multiple field values in a single cell.
 *
 * Supports a 'template' config that controls layout:
 *   - Array of arrays: each inner array = one line, fields joined by separator
 *   - Example: [["firstname","lastname"], ["street"], ["city","region","postcode"]]
 *     → "John Smith\n123 Main St\nSydney NSW 2000"
 *
 * If no template, falls back to all fields on separate lines.
 *
 * For multi_row composites (e.g. ordered items), renders each row as a block.
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Renderer_Composite
    extends Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Abstract
{
    public function render(Varien_Object $row): string
    {
        $colIndex = $this->getColumn()->getIndex();
        $data = $row->getData($colIndex);

        if (!$data || !is_array($data)) {
            return '';
        }

        $sourceConfig = $this->getColumn()->getData('admingrid_source_config') ?: [];
        $template = $sourceConfig['template'] ?? null;
        $separator = $sourceConfig['separator'] ?? ' ';
        $multiRow = $sourceConfig['multi_row'] ?? false;

        if ($multiRow) {
            return $this->renderMultiRow($data, $template, $separator);
        }

        return $this->renderSingleRow($data, $template, $separator);
    }

    /**
     * Render a single-row composite (e.g. address).
     * $data is an associative or indexed array of field values.
     */
    private function renderSingleRow(array $data, ?array $template, string $separator): string
    {
        if ($template) {
            return $this->renderTemplate($data, $template, $separator);
        }

        // Default: each value on its own line
        $lines = array_filter($data, fn($v) => $v !== null && $v !== '');
        if (empty($lines)) {
            return '';
        }

        return '<span class="admingrid-composite">'
            . implode('<br>', array_map('htmlspecialchars', $lines))
            . '</span>';
    }

    /**
     * Render using a template layout.
     * Template: [["firstname","lastname"], ["street"], ["city","region","postcode"]]
     */
    private function renderTemplate(array $data, array $template, string $separator): string
    {
        // If data is indexed (from hydration), convert to assoc using field order
        // Data from hydration is indexed array of values
        $lines = [];

        foreach ($template as $lineFields) {
            if (!is_array($lineFields)) {
                $lineFields = [$lineFields];
            }

            $parts = [];
            foreach ($lineFields as $field) {
                $value = null;
                // Try direct key lookup (assoc)
                if (isset($data[$field]) && $data[$field] !== '') {
                    $value = $data[$field];
                } elseif (is_int(array_key_first($data))) {
                    // Indexed array — skip, field won't be found
                }

                if ($value !== null) {
                    $parts[] = htmlspecialchars((string) $value);
                }
            }

            if (!empty($parts)) {
                $lines[] = implode($separator, $parts);
            }
        }

        if (empty($lines)) {
            return '';
        }

        return '<span class="admingrid-composite">'
            . implode('<br>', $lines)
            . '</span>';
    }

    /**
     * Render multi-row composite (e.g. ordered items).
     * $data is array of arrays (one per row).
     */
    private function renderMultiRow(array $data, ?array $template, string $separator): string
    {
        $blocks = [];

        foreach ($data as $rowData) {
            if (is_string($rowData)) {
                // Already formatted by hydration
                $blocks[] = htmlspecialchars($rowData);
            } elseif (is_array($rowData)) {
                if ($template) {
                    $rendered = $this->renderTemplate($rowData, $template, $separator);
                } else {
                    $parts = array_filter($rowData, fn($v) => $v !== null && $v !== '');
                    $rendered = implode($separator, array_map('htmlspecialchars', $parts));
                }
                if ($rendered) {
                    $blocks[] = $rendered;
                }
            }
        }

        if (empty($blocks)) {
            return '';
        }

        return '<span class="admingrid-composite admingrid-composite-multi">'
            . implode('<hr class="admingrid-composite-sep">', $blocks)
            . '</span>';
    }
}
