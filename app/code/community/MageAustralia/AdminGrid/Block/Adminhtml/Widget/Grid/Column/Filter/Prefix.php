<?php

declare(strict_types=1);

/**
 * Prefix-match text filter for index-friendly grid searches.
 *
 * The default text filter builds LIKE '%value%', which cannot use a B-tree
 * index and forces a full table scan on large grids (e.g. sales_flat_order_grid).
 * This filter builds LIKE 'value%' by default, which the optimizer can satisfy
 * with an index range scan.
 *
 * A leading % or * in the user's input opts into the old broad LIKE '%value%'
 * behaviour (deliberately slower, e.g. searching by a trailing fragment).
 * User-supplied % and _ wildcards are always escaped.
 *
 * Wire via a column's "filter" config, e.g.:
 *   'filter' => 'mageaustralia_admingrid/adminhtml_widget_grid_column_filter_prefix'
 * or, for AdminGrid custom columns, set "prefix_search": true in source_config.
 */
class MageAustralia_AdminGrid_Block_Adminhtml_Widget_Grid_Column_Filter_Prefix extends Mage_Adminhtml_Block_Widget_Grid_Column_Filter_Text
{
    #[\Override]
    public function getCondition(): ?array
    {
        [$value, $broad] = $this->_parseValue();
        if ($value === null) {
            return null;
        }

        return $this->_likeCondition($value, $broad);
    }

    /**
     * Normalise the raw filter input.
     *
     * @return array{0: ?string, 1: bool} [value-or-null, broad-search-flag]
     */
    protected function _parseValue(): array
    {
        $value = $this->getValue();
        if ($value === null || trim((string) $value) === '') {
            return [null, false];
        }

        $value = trim((string) $value);

        // Leading % or * = deliberate broad "contains" search (slower, opt-in).
        $broad = ($value[0] === '%' || $value[0] === '*');
        if ($broad) {
            $value = ltrim($value, '%*');
            if ($value === '') {
                return [null, true];
            }
        }

        return [$value, $broad];
    }

    /**
     * Build a single LIKE condition, escaping the user's own wildcards.
     * Prefix (index range) by default; contains when $broad.
     *
     * @return array{like: string}
     */
    protected function _likeCondition(string $value, bool $broad): array
    {
        // Escape the user's own LIKE wildcards (% _ \) so they are matched
        // literally; then append our own trailing (or wrapping) wildcard.
        $escaped = addcslashes($value, '%_\\\\');

        return ['like' => $broad ? '%' . $escaped . '%' : $escaped . '%'];
    }
}
