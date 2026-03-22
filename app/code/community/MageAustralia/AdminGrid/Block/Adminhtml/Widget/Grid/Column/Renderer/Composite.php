<?php

declare(strict_types=1);

/**
 * Composite column renderer — displays multiple field values in a single cell.
 *
 * Supports:
 * - 'template': array of arrays defining line grouping
 *     e.g. [["firstname","lastname"], ["street"], ["city","region","postcode"]]
 * - 'separator': string between fields on the same line (default: ' ')
 * - 'style': preset name (plain/card/bordered/compact) or 'custom'
 * - 'custom_css': raw CSS string for custom styling
 * - 'multi_row': boolean — if true, data is array of arrays (e.g. order items)
 * - 'item_separator': 'hr' (default) or 'br' or 'none'
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

        $config = $this->getColumn()->getData('admingrid_source_config') ?: [];
        $template = $config['template'] ?? null;
        $separator = $config['separator'] ?? ' ';
        $multiRow = $config['multi_row'] ?? false;
        $style = $config['style'] ?? 'plain';
        $customCss = $config['custom_css'] ?? '';

        // Build CSS class + inline style
        $cssClass = 'admingrid-composite';
        if ($style !== 'custom' && $style !== 'plain') {
            $cssClass .= ' admingrid-composite-' . htmlspecialchars($style);
        }
        $inlineStyle = ($style === 'custom' && $customCss)
            ? ' style="' . htmlspecialchars($customCss) . '"'
            : '';

        $content = $multiRow
            ? $this->renderMultiRow($data, $template, $separator, $config)
            : $this->renderSingleRow($data, $template, $separator);

        if (!$content) {
            return '';
        }

        return "<span class=\"{$cssClass}\"{$inlineStyle}>{$content}</span>";
    }

    private function renderSingleRow(array $data, ?array $template, string $separator): string
    {
        if ($template) {
            return $this->applyTemplate($data, $template, $separator);
        }

        // Default: filter empties, join with <br>
        $values = array_filter($data, fn($v) => $v !== null && $v !== '');
        return implode('<br>', array_map('htmlspecialchars', $values));
    }

    private function renderMultiRow(array $data, ?array $template, string $separator, array $config): string
    {
        $itemSep = $config['item_separator'] ?? 'hr';
        $blocks = [];

        foreach ($data as $rowData) {
            if (!is_array($rowData)) {
                if ((string) $rowData !== '') {
                    $blocks[] = htmlspecialchars((string) $rowData);
                }
                continue;
            }

            $rendered = $template
                ? $this->applyTemplate($rowData, $template, $separator)
                : implode($separator, array_map('htmlspecialchars',
                    array_filter($rowData, fn($v) => $v !== null && $v !== '')));

            if ($rendered) {
                $blocks[] = $rendered;
            }
        }

        if (empty($blocks)) {
            return '';
        }

        $sep = match ($itemSep) {
            'hr' => '<hr class="admingrid-item-sep">',
            'br' => '<br>',
            'none' => '',
            default => '<hr class="admingrid-item-sep">',
        };

        return implode($sep, $blocks);
    }

    /**
     * Apply a template layout to associative data.
     * Template: [["firstname","lastname"], ["street"], ["city","region","postcode"]]
     */
    private function applyTemplate(array $data, array $template, string $separator): string
    {
        $lines = [];

        foreach ($template as $lineFields) {
            if (!is_array($lineFields)) {
                $lineFields = [$lineFields];
            }

            $parts = [];
            foreach ($lineFields as $field) {
                if (isset($data[$field]) && (string) $data[$field] !== '') {
                    $value = (string) $data[$field];
                    // Clean up decimal quantities (1.0000 → 1, 2.5000 → 2.5)
                    if (is_numeric($value) && str_contains($value, '.')) {
                        $value = rtrim(rtrim($value, '0'), '.');
                    }
                    $parts[] = htmlspecialchars($value);
                }
            }

            if (!empty($parts)) {
                $lines[] = implode($separator, $parts);
            }
        }

        return implode('<br>', $lines);
    }
}
