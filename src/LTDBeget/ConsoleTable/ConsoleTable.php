<?php
/**
 * Fork of original ConsoleTable class, converted to PHP 5.x
 *
 * @see http://pear.php.net/package/ConsoleTable
 * @author Kolesnikov Vladislav
 * @author Jan Schneider <jan@horde.org>
 * @license  http://www.debian.org/misc/bsd.license  BSD License (3 Clause)
 * @date 19.02.15
 */

namespace LTDBeget\ConsoleTable;


/**
 * Class ConsoleTable
 *
 * @package LTDBeget\ConsoleTable
 */
class ConsoleTable
{
    const HORIZONTAL_RULE = 1;
    const ALIGN_LEFT      = -1;
    const ALIGN_CENTER    = 0;
    const ALIGN_RIGHT     = 1;
    const BORDER_ASCII    = -1;

    /**
     * The table headers.
     *
     * @var array
     */
    protected $_headers = [];

    /**
     * The data of the table.
     *
     * @var array
     */
    protected $_data = [];

    /**
     * The maximum number of columns in a row.
     *
     * @var integer
     */
    protected $_max_cols = 0;

    /**
     * The maximum number of rows in the table.
     *
     * @var integer
     */
    protected $_max_rows = 0;

    /**
     * Lengths of the columns, calculated when rows are added to the table.
     *
     * @var array
     */
    protected $_cell_lengths = [];

    /**
     * Heights of the rows.
     *
     * @var array
     */
    protected $_row_heights = [];

    /**
     * How many spaces to use to pad the table.
     *
     * @var integer
     */
    protected $_padding = 1;

    /**
     * Column filters.
     *
     * @var array
     */
    protected $_filters = [];

    /**
     * Columns to calculate totals for.
     *
     * @var array
     */
    protected $_calculateTotals;

    /**
     * Alignment of the columns.
     *
     * @var array
     */
    protected $_col_align = [];

    /**
     * Default alignment of columns.
     *
     * @var integer
     */
    protected $_defaultAlign;

    /**
     * Character set of the data.
     *
     * @var string
     */
    protected $_charset = 'utf-8';

    /**
     * Border character.
     *
     * @var string
     */
    protected $_border = self::BORDER_ASCII;


    /**
     * Constructor.
     *
     * @param int $align Default alignment. One of
     *                         self::ALIGN_LEFT,
     *                         self::ALIGN_CENTER or
     *                         self::ALIGN_RIGHT.
     * @param int|string $border The character used for table borders or
     *                         self::BORDER_ASCII.
     * @param integer $padding How many spaces to use to pad the table.
     * @param string $charset  A charset supported by the mbstring PHP
     *                         extension.
     */
    public function __construct($align = self::ALIGN_LEFT,
                           $border = self::BORDER_ASCII, $padding = 1,
                           $charset = null)
    {
        $this->_defaultAlign = $align;
        $this->_border       = $border;
        $this->_padding      = $padding;
        
        if (!empty($charset)) {
            $this->setCharset($charset);
        }
    }

    /**
     * Converts an array to a table.
     *
     * @param array   $headers      Headers for the table.
     * @param array   $data         A two dimensional array with the table
     *                              data.
     * @param boolean $returnObject Whether to return the ConsoleTable object
     *                              instead of the rendered table.
     *
     * @static
     *
     * @return ConsoleTable|string  A ConsoleTable object or the generated
     *                               table.
     */
    public static function fromArray($headers, $data, $returnObject = false)
    {
        if (!is_array($headers) || !is_array($data)) {
            return false;
        }

        $table = new self;
        $table->setHeaders($headers);

        foreach ($data as $row) {
            $table->addRow($row);
        }

        return $returnObject ? $table : $table->getTable();
    }


    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getTable();
    }


    /**
     * Adds a filter to a column.
     *
     * Filters are standard PHP callbacks which are run on the data before
     * table generation is performed. Filters are applied in the order they
     * are added. The callback function must accept a single argument, which
     * is a single table cell.
     *
     * @param integer $col       Column to apply filter to.
     * @param mixed   &$callback PHP callback to apply.
     *
     * @return void
     */
    public function addFilter($col, &$callback)
    {
        $this->_filters[] = [$col, &$callback];
    }

    /**
     * Sets the charset of the provided table data.
     *
     * @param string $charset A charset supported by the mbstring PHP
     *                        extension.
     *
     * @return void
     */
    public function setCharset($charset)
    {
        $locale = setlocale(LC_CTYPE, 0);
        setlocale(LC_CTYPE, 'en_US');
        $this->_charset = strtolower($charset);
        setlocale(LC_CTYPE, $locale);
    }

    /**
     * Sets the alignment for the columns.
     *
     * @param integer $col_id The column number.
     * @param integer $align  Alignment to set for this column. One of
     *                        self::ALIGN_LEFT
     *                        self::ALIGN_CENTER
     *                        self::ALIGN_RIGHT.
     *
     * @return void
     */
    public function setAlign($col_id, $align = self::ALIGN_LEFT)
    {
        switch ($align) {
            case self::ALIGN_CENTER:
                $pad = STR_PAD_BOTH;
                break;
            case self::ALIGN_RIGHT:
                $pad = STR_PAD_LEFT;
                break;
            default:
                $pad = STR_PAD_RIGHT;
                break;
        }
        $this->_col_align[$col_id] = $pad;
    }

    /**
     * Specifies which columns are to have totals calculated for them and
     * added as a new row at the bottom.
     *
     * @param array $cols Array of column numbers (starting with 0).
     *
     * @return void
     */
    public function calculateTotalsFor($cols)
    {
        $this->_calculateTotals = $cols;
    }

    /**
     * Sets the headers for the columns.
     *
     * @param array $headers The column headers.
     *
     * @return void
     */
    public function setHeaders($headers)
    {
        $this->_headers = [array_values($headers)];
        $this->_updateRowsCols($headers);
    }

    /**
     * Adds a row to the table.
     *
     * @param array   $row    The row data to add.
     * @param boolean $append Whether to append or prepend the row.
     *
     * @return void
     */
    public function addRow($row, $append = true)
    {
        if ($append) {
            $this->_data[] = array_values($row);
        } else {
            array_unshift($this->_data, array_values($row));
        }

        $this->_updateRowsCols($row);
    }

    /**
     * Inserts a row after a given row number in the table.
     *
     * If $row_id is not given it will prepend the row.
     *
     * @param array   $row    The data to insert.
     * @param integer $row_id Row number to insert before.
     *
     * @return void
     */
    public function insertRow($row, $row_id = 0)
    {
        array_splice($this->_data, $row_id, 0, [$row]);

        $this->_updateRowsCols($row);
    }

    /**
     * Adds a column to the table.
     *
     * @param array   $col_data The data of the column.
     * @param integer $col_id   The column index to populate.
     * @param integer $row_id   If starting row is not zero, specify it here.
     *
     * @return void
     */
    public function addCol($col_data, $col_id = 0, $row_id = 0)
    {
        foreach ($col_data as $col_cell) {
            $this->_data[$row_id++][$col_id] = $col_cell;
        }

        $this->_updateRowsCols();
        $this->_max_cols = max($this->_max_cols, $col_id + 1);
    }

    /**
     * Adds data to the table.
     *
     * @param array   $data   A two dimensional array with the table data.
     * @param integer $col_id Starting column number.
     * @param integer $row_id Starting row number.
     *
     * @return void
     */
    public function addData($data, $col_id = 0, $row_id = 0)
    {
        foreach ($data as $row) {
            if ($row === self::HORIZONTAL_RULE) {
                $this->_data[$row_id] = self::HORIZONTAL_RULE;
                $row_id++;
                continue;
            }
            $starting_col = $col_id;
            foreach ($row as $cell) {
                $this->_data[$row_id][$starting_col++] = $cell;
            }
            $this->_updateRowsCols();
            $this->_max_cols = max($this->_max_cols, $starting_col);
            $row_id++;
        }
    }

    /**
     * Adds a horizontal seperator to the table.
     *
     * @return void
     */
    public function addSeparator()
    {
        $this->_data[] = self::HORIZONTAL_RULE;
    }

    /**
     * Returns the generated table.
     *
     * @return string  The generated table.
     */
    public function getTable()
    {
        $this->_applyFilters();
        $this->_calculateTotals();
        $this->_validateTable();

        return $this->_buildTable();
    }

    /**
     * Calculates totals for columns.
     *
     * @return void
     */
    protected function _calculateTotals()
    {
        if (empty($this->_calculateTotals)) {
            return;
        }

        $this->addSeparator();

        $totals = [];
        foreach ($this->_data as $row) {
            if (is_array($row)) {
                foreach ($this->_calculateTotals as $columnID) {
                    if (!isset($totals[$columnID]) {
                        $totals[$columnID] = 0;
                    }
                    $totals[$columnID] += $row[$columnID];
                }
            }
        }

        $this->_data[] = $totals;
        $this->_updateRowsCols();
    }

    /**
     * Applies any column filters to the data.
     *
     * @return void
     */
    protected function _applyFilters()
    {
        if (empty($this->_filters)) {
            return;
        }

        foreach ($this->_filters as $filter) {
            $column   = $filter[0];
            $callback = $filter[1];

            foreach ($this->_data as $row_id => $row_data) {
                if ($row_data !== self::HORIZONTAL_RULE) {
                    $this->_data[$row_id][$column] =
                        call_user_func($callback, $row_data[$column]);
                }
            }
        }
    }

    /**
     * Ensures that column and row counts are correct.
     *
     * @return void
     */
    protected function _validateTable()
    {
        if (!empty($this->_headers)) {
            $this->_calculateRowHeight(-1, $this->_headers[0]);
        }

        for ($i = 0; $i < $this->_max_rows; $i++) {
            for ($j = 0; $j < $this->_max_cols; $j++) {
                if (!isset($this->_data[$i][$j]) &&
                    (!isset($this->_data[$i]) ||
                        $this->_data[$i] !== self::HORIZONTAL_RULE)) {
                    $this->_data[$i][$j] = '';
                }

            }
            $this->_calculateRowHeight($i, $this->_data[$i]);

            if ($this->_data[$i] !== self::HORIZONTAL_RULE) {
                ksort($this->_data[$i]);
            }

        }

        $this->_splitMultilineRows();

        // Update cell lengths.
        for ($i = 0; $i < count($this->_headers); $i++) {
            $this->_calculateCellLengths($this->_headers[$i]);
        }
        for ($i = 0; $i < $this->_max_rows; $i++) {
            $this->_calculateCellLengths($this->_data[$i]);
        }

        ksort($this->_data);
    }

    /**
     * Splits multiline rows into many smaller one-line rows.
     *
     * @return void
     */
    protected function _splitMultilineRows()
    {
        ksort($this->_data);
        $sections          = [&$this->_headers, &$this->_data];
        $max_rows          = [count($this->_headers), $this->_max_rows];
        $row_height_offset = [-1, 0];

        for ($s = 0; $s <= 1; $s++) {
            $inserted = 0;
            $new_data = $sections[$s];

            for ($i = 0; $i < $max_rows[$s]; $i++) {
                // Process only rows that have many lines.
                $height = $this->_row_heights[$i + $row_height_offset[$s]];
                if ($height > 1) {
                    // Split column data into one-liners.
                    $split = [];
                    for ($j = 0; $j < $this->_max_cols; $j++) {
                        $split[$j] = preg_split('/\r?\n|\r/',
                            $sections[$s][$i][$j]);
                    }

                    $new_rows = [];
                    // Construct new 'virtual' rows - insert empty strings for
                    // columns that have less lines that the highest one.
                    for ($i2 = 0; $i2 < $height; $i2++) {
                        for ($j = 0; $j < $this->_max_cols; $j++) {
                            $new_rows[$i2][$j] = !isset($split[$j][$i2])
                                ? ''
                                : $split[$j][$i2];
                        }
                    }

                    // Replace current row with smaller rows.  $inserted is
                    // used to take account of bigger array because of already
                    // inserted rows.
                    array_splice($new_data, $i + $inserted, 1, $new_rows);
                    $inserted += count($new_rows) - 1;
                }
            }

            // Has the data been modified?
            if ($inserted > 0) {
                $sections[$s] = $new_data;
                $this->_updateRowsCols();
            }
        }
    }

    /**
     * Builds the table.
     *
     * @return string  The generated table string.
     */
    protected function _buildTable()
    {
        if (!count($this->_data)) {
            return '';
        }

        $rule      = $this->_border == self::BORDER_ASCII
            ? '|'
            : $this->_border;
        $separator = $this->_getSeparator();

        $return = [];
        for ($i = 0; $i < count($this->_data); $i++) {
            for ($j = 0; $j < count($this->_data[$i]); $j++) {
                if ($this->_data[$i] !== self::HORIZONTAL_RULE &&
                    $this->_strlen($this->_data[$i][$j]) <
                    $this->_cell_lengths[$j]) {
                    $this->_data[$i][$j] = $this->_strpad($this->_data[$i][$j],
                        $this->_cell_lengths[$j],
                        ' ',
                        $this->_col_align[$j]);
                }
            }

            if ($this->_data[$i] !== self::HORIZONTAL_RULE) {
                $row_begin    = $rule . str_repeat(' ', $this->_padding);
                $row_end      = str_repeat(' ', $this->_padding) . $rule;
                $implode_char = str_repeat(' ', $this->_padding) . $rule
                    . str_repeat(' ', $this->_padding);
                $return[]     = $row_begin
                    . implode($implode_char, $this->_data[$i]) . $row_end;
            } elseif (!empty($separator)) {
                $return[] = $separator;
            }

        }

        $return = implode("\r\n", $return);
        if (!empty($separator)) {
            $return = $separator . "\r\n" . $return . "\r\n" . $separator;
        }
        $return .= "\r\n";

        if (!empty($this->_headers)) {
            $return = $this->_getHeaderLine() .  "\r\n" . $return;
        }

        return $return;
    }

    /**
     * Creates a horizontal separator for header separation and table
     * start/end etc.
     *
     * @return string  The horizontal separator.
     */
    protected function _getSeparator()
    {
        if (!$this->_border) {
            return null;
        }

        if ($this->_border == self::BORDER_ASCII) {
            $rule = '-';
            $sect = '+';
        } else {
            $rule = $sect = $this->_border;
        }

        $return = [];
        foreach ($this->_cell_lengths as $cl) {
            $return[] = str_repeat($rule, $cl);
        }

        $row_begin    = $sect . str_repeat($rule, $this->_padding);
        $row_end      = str_repeat($rule, $this->_padding) . $sect;
        $implode_char = str_repeat($rule, $this->_padding) . $sect
            . str_repeat($rule, $this->_padding);

        return $row_begin . implode($implode_char, $return) . $row_end;
    }

    /**
     * Returns the header line for the table.
     *
     * @return string  The header line of the table.
     */
    protected function _getHeaderLine()
    {
        // Make sure column count is correct
        for ($j = 0; $j < count($this->_headers); $j++) {
            for ($i = 0; $i < $this->_max_cols; $i++) {
                if (!isset($this->_headers[$j][$i])) {
                    $this->_headers[$j][$i] = '';
                }
            }
        }

        for ($j = 0; $j < count($this->_headers); $j++) {
            for ($i = 0; $i < count($this->_headers[$j]); $i++) {
                if ($this->_strlen($this->_headers[$j][$i]) <
                    $this->_cell_lengths[$i]) {
                    $this->_headers[$j][$i] =
                        $this->_strpad($this->_headers[$j][$i],
                            $this->_cell_lengths[$i],
                            ' ',
                            $this->_col_align[$i]);
                }
            }
        }

        $rule         = $this->_border == self::BORDER_ASCII
            ? '|'
            : $this->_border;
        $row_begin    = $rule . str_repeat(' ', $this->_padding);
        $row_end      = str_repeat(' ', $this->_padding) . $rule;
        $implode_char = str_repeat(' ', $this->_padding) . $rule
            . str_repeat(' ', $this->_padding);

        $separator = $this->_getSeparator();
        $return = [];

        if (!empty($separator)) {
            $return[] = $separator;
        }
        for ($j = 0; $j < count($this->_headers); $j++) {
            $return[] = $row_begin
                . implode($implode_char, $this->_headers[$j]) . $row_end;
        }

        return implode("\r\n", $return);
    }

    /**
     * Updates values for maximum columns and rows.
     *
     * @param array $rowdata Data array of a single row.
     *
     * @return void
     */
    protected function _updateRowsCols($rowdata = null)
    {
        // Update maximum columns.
        $this->_max_cols = max($this->_max_cols, count($rowdata));

        // Update maximum rows.
        ksort($this->_data);
        $keys            = array_keys($this->_data);
        $this->_max_rows = end($keys) + 1;

        switch ($this->_defaultAlign) {
            case self::ALIGN_CENTER:
                $pad = STR_PAD_BOTH;
                break;
            case self::ALIGN_RIGHT:
                $pad = STR_PAD_LEFT;
                break;
            default:
                $pad = STR_PAD_RIGHT;
                break;
        }

        // Set default column alignments
        for ($i = count($this->_col_align); $i < $this->_max_cols; $i++) {
            $this->_col_align[$i] = $pad;
        }
    }

    /**
     * Calculates the maximum length for each column of a row.
     *
     * @param array $row The row data.
     *
     * @return void
     */
    protected function _calculateCellLengths($row)
    {
        for ($i = 0; $i < count($row); $i++) {
            if (!isset($this->_cell_lengths[$i])) {
                $this->_cell_lengths[$i] = 0;
            }
            $this->_cell_lengths[$i] = max($this->_cell_lengths[$i],
                $this->_strlen($row[$i]));
        }
    }

    /**
     * Calculates the maximum height for all columns of a row.
     *
     * @param integer $row_number The row number.
     * @param array   $row        The row data.
     *
     * @return void
     */
    protected function _calculateRowHeight($row_number, $row)
    {
        if (!isset($this->_row_heights[$row_number])) {
            $this->_row_heights[$row_number] = 1;
        }

        // Do not process horizontal rule rows.
        if ($row === self::HORIZONTAL_RULE) {
            return;
        }

        for ($i = 0, $c = count($row); $i < $c; ++$i) {
            $lines                           = preg_split('/\r?\n|\r/', $row[$i]);
            $this->_row_heights[$row_number] = max($this->_row_heights[$row_number],
                count($lines));
        }
    }

    /**
     * Returns the character length of a string.
     *
     * @param string $str A multibyte or singlebyte string.
     *
     * @return integer  The string length.
     */
    protected function _strlen($str)
    {
        static $mbstring, $utf8;

        // Strip ANSI color codes
        $str = preg_replace('/\033\[[\d;]+m/', '', $str);

        // Cache expensive function_exists() calls.
        if (!isset($mbstring)) {
            $mbstring = function_exists('mb_strlen');
        }
        if (!isset($utf8)) {
            $utf8 = function_exists('utf8_decode');
        }

        if ($utf8 &&
            ($this->_charset == strtolower('utf-8') ||
                $this->_charset == strtolower('utf8'))) {
            return strlen(utf8_decode($str));
        }
        if ($mbstring) {
            return mb_strlen($str, $this->_charset);
        }

        return strlen($str);
    }

    /**
     * Returns part of a string.
     *
     * @param string  $string The string to be converted.
     * @param integer $start  The part's start position, zero based.
     * @param integer $length The part's length.
     *
     * @return string  The string's part.
     */
    protected function _substr($string, $start, $length = null)
    {
        static $mbstring;

        // Cache expensive function_exists() calls.
        if (!isset($mbstring)) {
            $mbstring = function_exists('mb_substr');
        }

        if (is_null($length)) {
            $length = $this->_strlen($string);
        }
        if ($mbstring) {
            $ret = @mb_substr($string, $start, $length, $this->_charset);
            if (!empty($ret)) {
                return $ret;
            }
        }
        return substr($string, $start, $length);
    }

    /**
     * Returns a string padded to a certain length with another string.
     *
     * This method behaves exactly like str_pad but is multibyte safe.
     *
     * @param string  $input  The string to be padded.
     * @param integer $length The length of the resulting string.
     * @param string  $pad    The string to pad the input string with. Must
     *                        be in the same charset like the input string.
     * @param int   $type   The padding type. One of STR_PAD_LEFT,
     *                        STR_PAD_RIGHT, or STR_PAD_BOTH.
     *
     * @return string  The padded string.
     */
    protected function _strpad($input, $length, $pad = ' ', $type = STR_PAD_RIGHT)
    {
        $mb_length  = $this->_strlen($input);
        $sb_length  = strlen($input);
        $pad_length = $this->_strlen($pad);

        /* Return if we already have the length. */
        if ($mb_length >= $length) {
            return $input;
        }

        /* Shortcut for single byte strings. */
        if ($mb_length == $sb_length && $pad_length == strlen($pad)) {
            return str_pad($input, $length, $pad, $type);
        }

        switch ($type) {
            case STR_PAD_LEFT:
                $left   = $length - $mb_length;
                $output = $this->_substr(str_repeat($pad, ceil($left / $pad_length)),
                        0, $left, $this->_charset) . $input;
                break;
            case STR_PAD_BOTH:
                $left   = floor(($length - $mb_length) / 2);
                $right  = ceil(($length - $mb_length) / 2);
                $output = $this->_substr(str_repeat($pad, ceil($left / $pad_length)),
                        0, $left, $this->_charset) .
                    $input .
                    $this->_substr(str_repeat($pad, ceil($right / $pad_length)),
                        0, $right, $this->_charset);
                break;
            case STR_PAD_RIGHT:
                $right  = $length - $mb_length;
                $output = $input .
                    $this->_substr(str_repeat($pad, ceil($right / $pad_length)),
                        0, $right, $this->_charset);
                break;
            default:
                throw new \InvalidArgumentException("Invalid argument 'type' given!");
        }

        return $output;
    }

}
