<?php

namespace TablePress\PhpOffice\PhpSpreadsheet\Reader;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use TablePress\PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use TablePress\PhpOffice\PhpSpreadsheet\Cell\DataType;
use TablePress\PhpOffice\PhpSpreadsheet\Comment;
use TablePress\PhpOffice\PhpSpreadsheet\Document\Properties;
use TablePress\PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use TablePress\PhpOffice\PhpSpreadsheet\Helper\Dimension as CssDimension;
use TablePress\PhpOffice\PhpSpreadsheet\Helper\Html as HelperHtml;
use TablePress\PhpOffice\PhpSpreadsheet\Reader\Security\XmlScanner;
use TablePress\PhpOffice\PhpSpreadsheet\Spreadsheet;
use TablePress\PhpOffice\PhpSpreadsheet\Style\Border;
use TablePress\PhpOffice\PhpSpreadsheet\Style\Color;
use TablePress\PhpOffice\PhpSpreadsheet\Style\Fill;
use TablePress\PhpOffice\PhpSpreadsheet\Style\Font;
use TablePress\PhpOffice\PhpSpreadsheet\Style\Style;
use TablePress\PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use TablePress\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class Html extends BaseReader
{
	/**
	 * Sample size to read to determine if it's HTML or not.
	 */
	const TEST_SAMPLE_SIZE = 2048;

	private const STARTS_WITH_BOM = '/^(?:\xfe\xff|\xff\xfe|\xEF\xBB\xBF)/';

	private const DECLARES_CHARSET = '/ charset=/i';

	/**
	 * Input encoding.
	 */
	protected string $inputEncoding = 'ANSI';

	/**
	 * Sheet index to read.
	 */
	protected int $sheetIndex = 0;

	/**
	 * Formats.
	 */
	protected array $formats = [
		'h1' => [
			'font' => [
				'bold' => true,
				'size' => 24,
			],
		], //    Bold, 24pt
		'h2' => [
			'font' => [
				'bold' => true,
				'size' => 18,
			],
		], //    Bold, 18pt
		'h3' => [
			'font' => [
				'bold' => true,
				'size' => 13.5,
			],
		], //    Bold, 13.5pt
		'h4' => [
			'font' => [
				'bold' => true,
				'size' => 12,
			],
		], //    Bold, 12pt
		'h5' => [
			'font' => [
				'bold' => true,
				'size' => 10,
			],
		], //    Bold, 10pt
		'h6' => [
			'font' => [
				'bold' => true,
				'size' => 7.5,
			],
		], //    Bold, 7.5pt
		'a' => [
			'font' => [
				'underline' => true,
				'color' => [
					'argb' => Color::COLOR_BLUE,
				],
			],
		], //    Blue underlined
		'hr' => [
			'borders' => [
				'bottom' => [
					'borderStyle' => Border::BORDER_THIN,
					'color' => [
						Color::COLOR_BLACK,
					],
				],
			],
		], //    Bottom border
		'strong' => [
			'font' => [
				'bold' => true,
			],
		], //    Bold
		'b' => [
			'font' => [
				'bold' => true,
			],
		], //    Bold
		'i' => [
			'font' => [
				'italic' => true,
			],
		], //    Italic
		'em' => [
			'font' => [
				'italic' => true,
			],
		], //    Italic
	];

	protected array $rowspan = [];

	/**
	 * Create a new HTML Reader instance.
	 */
	public function __construct()
	{
		parent::__construct();
		$this->securityScanner = XmlScanner::getInstance($this);
	}

	/**
	 * Validate that the current file is an HTML file.
	 */
	public function canRead(string $filename): bool
	{
		// Check if file exists
		try {
			$this->openFile($filename);
		} catch (Exception $exception) {
			return false;
		}

		$beginning = preg_replace(self::STARTS_WITH_BOM, '', $this->readBeginning()) ?? '';

		$startWithTag = self::startsWithTag($beginning);
		$containsTags = self::containsTags($beginning);
		$endsWithTag = self::endsWithTag($this->readEnding());

		fclose($this->fileHandle);

		return $startWithTag && $containsTags && $endsWithTag;
	}

	private function readBeginning(): string
	{
		fseek($this->fileHandle, 0);

		return (string) fread($this->fileHandle, self::TEST_SAMPLE_SIZE);
	}

	private function readEnding(): string
	{
		$meta = stream_get_meta_data($this->fileHandle);
		// Phpstan incorrectly flags following line for Php8.2-, corrected in 8.3
		$filename = $meta['uri']; //@phpstan-ignore-line

		clearstatcache(true, $filename);
		$size = (int) filesize($filename);
		if ($size === 0) {
			return '';
		}

		$blockSize = self::TEST_SAMPLE_SIZE;
		if ($size < $blockSize) {
			$blockSize = $size;
		}

		fseek($this->fileHandle, $size - $blockSize);

		return (string) fread($this->fileHandle, $blockSize);
	}

	private static function startsWithTag(string $data): bool
	{
		return str_starts_with(trim($data), '<');
	}

	private static function endsWithTag(string $data): bool
	{
		return str_ends_with(trim($data), '>');
	}

	private static function containsTags(string $data): bool
	{
		return strlen($data) !== strlen(strip_tags($data));
	}

	/**
	 * Loads Spreadsheet from file.
	 */
	public function loadSpreadsheetFromFile(string $filename): Spreadsheet
	{
		// Create new Spreadsheet
		$spreadsheet = new Spreadsheet();
		$spreadsheet->setValueBinder($this->valueBinder);

		// Load into this instance
		return $this->loadIntoExisting($filename, $spreadsheet);
	}

	//    Data Array used for testing only, should write to Spreadsheet object on completion of tests

	protected array $dataArray = [];

	protected int $tableLevel = 0;

	protected array $nestedColumn = ['A'];

	protected function setTableStartColumn(string $column): string
	{
		if ($this->tableLevel == 0) {
			$column = 'A';
		}
		++$this->tableLevel;
		$this->nestedColumn[$this->tableLevel] = $column;

		return $this->nestedColumn[$this->tableLevel];
	}

	protected function getTableStartColumn(): string
	{
		return $this->nestedColumn[$this->tableLevel];
	}

	protected function releaseTableStartColumn(): string
	{
		--$this->tableLevel;

		return array_pop($this->nestedColumn);
	}

	/**
	 * Flush cell.
	 * @param int|string $row
	 * @param mixed $cellContent
	 */
	protected function flushCell(Worksheet $sheet, string $column, $row, &$cellContent, array $attributeArray): void
	{
		if (is_string($cellContent)) {
			//    Simple String content
			if (trim($cellContent) > '') {
				//    Only actually write it if there's content in the string
				//    Write to worksheet to be done here...
				//    ... we return the cell, so we can mess about with styles more easily

				// Set cell value explicitly if there is data-type attribute
				if (isset($attributeArray['data-type'])) {
					$datatype = $attributeArray['data-type'];
					if (in_array($datatype, [DataType::TYPE_STRING, DataType::TYPE_STRING2, DataType::TYPE_INLINE])) {
						//Prevent to Excel treat string with beginning equal sign or convert big numbers to scientific number
						if (str_starts_with($cellContent, '=')) {
							$sheet->getCell($column . $row)
								->getStyle()
								->setQuotePrefix(true);
						}
					}

					//catching the Exception and ignoring the invalid data types
					try {
						$sheet->setCellValueExplicit($column . $row, $cellContent, $attributeArray['data-type']);
					} catch (SpreadsheetException $exception) {
						$sheet->setCellValue($column . $row, $cellContent);
					}
				} else {
					$sheet->setCellValue($column . $row, $cellContent);
				}
				$this->dataArray[$row][$column] = $cellContent;
			}
		} else {
			//    We have a Rich Text run
			//    TODO
			$this->dataArray[$row][$column] = 'RICH TEXT: ' . $cellContent;
		}
		$cellContent = (string) '';
	}

	private function processDomElementBody(Worksheet $sheet, int &$row, string &$column, string &$cellContent, DOMElement $child): void
	{
		$attributeArray = [];
		/** @var DOMAttr $attribute */
		foreach ($child->attributes as $attribute) {
			$attributeArray[$attribute->name] = $attribute->value;
		}

		if ($child->nodeName === 'body') {
			$row = 1;
			$column = 'A';
			$cellContent = '';
			$this->tableLevel = 0;
			$this->processDomElement($child, $sheet, $row, $column, $cellContent);
		} else {
			$this->processDomElementTitle($sheet, $row, $column, $cellContent, $child, $attributeArray);
		}
	}

	private function processDomElementTitle(Worksheet $sheet, int &$row, string &$column, string &$cellContent, DOMElement $child, array &$attributeArray): void
	{
		if ($child->nodeName === 'title') {
			$this->processDomElement($child, $sheet, $row, $column, $cellContent);

			try {
				$sheet->setTitle($cellContent, true, true);
				($nullsafeVariable1 = ($nullsafeVariable2 = $sheet->getParent()) ? $nullsafeVariable2->getProperties() : null) ? $nullsafeVariable1->setTitle($cellContent) : null;
			} catch (SpreadsheetException $exception) {
				// leave default title if too long or illegal chars
			}
			$cellContent = '';
		} else {
			$this->processDomElementSpanEtc($sheet, $row, $column, $cellContent, $child, $attributeArray);
		}
	}

	private const SPAN_ETC = ['span', 'div', 'font', 'i', 'em', 'strong', 'b'];

	private function processDomElementSpanEtc(Worksheet $sheet, int &$row, string &$column, string &$cellContent, DOMElement $child, array &$attributeArray): void
	{
		if (in_array((string) $child->nodeName, self::SPAN_ETC, true)) {
			if (isset($attributeArray['class']) && $attributeArray['class'] === 'comment') {
				$sheet->getComment($column . $row)
					->getText()
					->createTextRun($child->textContent);
				if (isset($attributeArray['dir']) && $attributeArray['dir'] === 'rtl') {
					$sheet->getComment($column . $row)->setTextboxDirection(Comment::TEXTBOX_DIRECTION_RTL);
				}
				if (isset($attributeArray['style'])) {
					$alignStyle = $attributeArray['style'];
					if (preg_match('/\\btext-align:\\s*(left|right|center|justify)\\b/', $alignStyle, $matches) === 1) {
						$sheet->getComment($column . $row)->setAlignment($matches[1]);
					}
				}
			} else {
				$this->processDomElement($child, $sheet, $row, $column, $cellContent);
			}

			if (isset($this->formats[$child->nodeName])) {
				$sheet->getStyle($column . $row)->applyFromArray($this->formats[$child->nodeName]);
			}
		} else {
			$this->processDomElementHr($sheet, $row, $column, $cellContent, $child, $attributeArray);
		}
	}

	private function processDomElementHr(Worksheet $sheet, int &$row, string &$column, string &$cellContent, DOMElement $child, array &$attributeArray): void
	{
		if ($child->nodeName === 'hr') {
			$this->flushCell($sheet, $column, $row, $cellContent, $attributeArray);
			++$row;
			if (isset($this->formats[$child->nodeName])) {
				$sheet->getStyle($column . $row)->applyFromArray($this->formats[$child->nodeName]);
			}
			++$row;
		}
		// fall through to br
		$this->processDomElementBr($sheet, $row, $column, $cellContent, $child, $attributeArray);
	}

	private function processDomElementBr(Worksheet $sheet, int &$row, string &$column, string &$cellContent, DOMElement $child, array &$attributeArray): void
	{
		if ($child->nodeName === 'br' || $child->nodeName === 'hr') {
			if ($this->tableLevel > 0) {
				//    If we're inside a table, replace with a newline and set the cell to wrap
				$cellContent .= "\n";
				$sheet->getStyle($column . $row)->getAlignment()->setWrapText(true);
			} else {
				//    Otherwise flush our existing content and move the row cursor on
				$this->flushCell($sheet, $column, $row, $cellContent, $attributeArray);
				++$row;
			}
		} else {
			$this->processDomElementA($sheet, $row, $column, $cellContent, $child, $attributeArray);
		}
	}

	private function processDomElementA(Worksheet $sheet, int &$row, string &$column, string &$cellContent, DOMElement $child, array &$attributeArray): void
	{
		if ($child->nodeName === 'a') {
			foreach ($attributeArray as $attributeName => $attributeValue) {
				switch ($attributeName) {
					case 'href':
						$sheet->getCell($column . $row)->getHyperlink()->setUrl($attributeValue);
						if (isset($this->formats[$child->nodeName])) {
							$sheet->getStyle($column . $row)->applyFromArray($this->formats[$child->nodeName]);
						}

						break;
					case 'class':
						if ($attributeValue === 'comment-indicator') {
							break; // Ignore - it's just a red square.
						}
				}
			}
			// no idea why this should be needed
			//$cellContent .= ' ';
			$this->processDomElement($child, $sheet, $row, $column, $cellContent);
		} else {
			$this->processDomElementH1Etc($sheet, $row, $column, $cellContent, $child, $attributeArray);
		}
	}

	private const H1_ETC = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ol', 'ul', 'p'];

	private function processDomElementH1Etc(Worksheet $sheet, int &$row, string &$column, string &$cellContent, DOMElement $child, array &$attributeArray): void
	{
		if (in_array((string) $child->nodeName, self::H1_ETC, true)) {
			if ($this->tableLevel > 0) {
				//    If we're inside a table, replace with a newline
				$cellContent .= $cellContent ? "\n" : '';
				$sheet->getStyle($column . $row)->getAlignment()->setWrapText(true);
				$this->processDomElement($child, $sheet, $row, $column, $cellContent);
			} else {
				if ($cellContent > '') {
					$this->flushCell($sheet, $column, $row, $cellContent, $attributeArray);
					++$row;
				}
				$this->processDomElement($child, $sheet, $row, $column, $cellContent);
				$this->flushCell($sheet, $column, $row, $cellContent, $attributeArray);

				if (isset($this->formats[$child->nodeName])) {
					$sheet->getStyle($column . $row)->applyFromArray($this->formats[$child->nodeName]);
				}

				++$row;
				$column = 'A';
			}
		} else {
			$this->processDomElementLi($sheet, $row, $column, $cellContent, $child, $attributeArray);
		}
	}

	private function processDomElementLi(Worksheet $sheet, int &$row, string &$column, string &$cellContent, DOMElement $child, array &$attributeArray): void
	{
		if ($child->nodeName === 'li') {
			if ($this->tableLevel > 0) {
				//    If we're inside a table, replace with a newline
				$cellContent .= $cellContent ? "\n" : '';
				$this->processDomElement($child, $sheet, $row, $column, $cellContent);
			} else {
				if ($cellContent > '') {
					$this->flushCell($sheet, $column, $row, $cellContent, $attributeArray);
				}
				++$row;
				$this->processDomElement($child, $sheet, $row, $column, $cellContent);
				$this->flushCell($sheet, $column, $row, $cellContent, $attributeArray);
				$column = 'A';
			}
		} else {
			$this->processDomElementImg($sheet, $row, $column, $cellContent, $child, $attributeArray);
		}
	}

	private function processDomElementImg(Worksheet $sheet, int &$row, string &$column, string &$cellContent, DOMElement $child, array &$attributeArray): void
	{
		if ($child->nodeName === 'img') {
			$this->insertImage($sheet, $column, $row, $attributeArray);
		} else {
			$this->processDomElementTable($sheet, $row, $column, $cellContent, $child, $attributeArray);
		}
	}

	private string $currentColumn = 'A';

	private function processDomElementTable(Worksheet $sheet, int &$row, string &$column, string &$cellContent, DOMElement $child, array &$attributeArray): void
	{
		if ($child->nodeName === 'table') {
			if (isset($attributeArray['class'])) {
				$classes = explode(' ', $attributeArray['class']);
				$sheet->setShowGridlines(in_array('gridlines', $classes, true));
				$sheet->setPrintGridlines(in_array('gridlinesp', $classes, true));
			}
			$this->currentColumn = 'A';
			$this->flushCell($sheet, $column, $row, $cellContent, $attributeArray);
			$column = $this->setTableStartColumn($column);
			if ($this->tableLevel > 1 && $row > 1) {
				--$row;
			}
			$this->processDomElement($child, $sheet, $row, $column, $cellContent);
			$column = $this->releaseTableStartColumn();
			if ($this->tableLevel > 1) {
				++$column;
			} else {
				++$row;
			}
		} else {
			$this->processDomElementTr($sheet, $row, $column, $cellContent, $child, $attributeArray);
		}
	}

	private function processDomElementTr(Worksheet $sheet, int &$row, string &$column, string &$cellContent, DOMElement $child, array &$attributeArray): void
	{
		if ($child->nodeName === 'col') {
			$this->applyInlineStyle($sheet, -1, $this->currentColumn, $attributeArray);
			++$this->currentColumn;
		} elseif ($child->nodeName === 'tr') {
			$column = $this->getTableStartColumn();
			$cellContent = '';
			$this->processDomElement($child, $sheet, $row, $column, $cellContent);

			if (isset($attributeArray['height'])) {
				$sheet->getRowDimension($row)->setRowHeight($attributeArray['height']);
			}

			++$row;
		} else {
			$this->processDomElementThTdOther($sheet, $row, $column, $cellContent, $child, $attributeArray);
		}
	}

	private function processDomElementThTdOther(Worksheet $sheet, int &$row, string &$column, string &$cellContent, DOMElement $child, array &$attributeArray): void
	{
		if ($child->nodeName !== 'td' && $child->nodeName !== 'th') {
			$this->processDomElement($child, $sheet, $row, $column, $cellContent);
		} else {
			$this->processDomElementThTd($sheet, $row, $column, $cellContent, $child, $attributeArray);
		}
	}

	private function processDomElementBgcolor(Worksheet $sheet, int $row, string $column, array $attributeArray): void
	{
		if (isset($attributeArray['bgcolor'])) {
			$sheet->getStyle("$column$row")->applyFromArray(
				[
					'fill' => [
						'fillType' => Fill::FILL_SOLID,
						'color' => ['rgb' => $this->getStyleColor($attributeArray['bgcolor'])],
					],
				]
			);
		}
	}

	private function processDomElementWidth(Worksheet $sheet, string $column, array $attributeArray): void
	{
		if (isset($attributeArray['width'])) {
			$sheet->getColumnDimension($column)->setWidth((new CssDimension($attributeArray['width']))->width());
		}
	}

	private function processDomElementHeight(Worksheet $sheet, int $row, array $attributeArray): void
	{
		if (isset($attributeArray['height'])) {
			$sheet->getRowDimension($row)->setRowHeight((new CssDimension($attributeArray['height']))->height());
		}
	}

	private function processDomElementAlign(Worksheet $sheet, int $row, string $column, array $attributeArray): void
	{
		if (isset($attributeArray['align'])) {
			$sheet->getStyle($column . $row)->getAlignment()->setHorizontal($attributeArray['align']);
		}
	}

	private function processDomElementVAlign(Worksheet $sheet, int $row, string $column, array $attributeArray): void
	{
		if (isset($attributeArray['valign'])) {
			$sheet->getStyle($column . $row)->getAlignment()->setVertical($attributeArray['valign']);
		}
	}

	private function processDomElementDataFormat(Worksheet $sheet, int $row, string $column, array $attributeArray): void
	{
		if (isset($attributeArray['data-format'])) {
			$sheet->getStyle($column . $row)->getNumberFormat()->setFormatCode($attributeArray['data-format']);
		}
	}

	private function processDomElementThTd(Worksheet $sheet, int &$row, string &$column, string &$cellContent, DOMElement $child, array &$attributeArray): void
	{
		while (isset($this->rowspan[$column . $row])) {
			++$column;
		}
		$this->processDomElement($child, $sheet, $row, $column, $cellContent);

		// apply inline style
		$this->applyInlineStyle($sheet, $row, $column, $attributeArray);

		$this->flushCell($sheet, $column, $row, $cellContent, $attributeArray);

		$this->processDomElementBgcolor($sheet, $row, $column, $attributeArray);
		$this->processDomElementWidth($sheet, $column, $attributeArray);
		$this->processDomElementHeight($sheet, $row, $attributeArray);
		$this->processDomElementAlign($sheet, $row, $column, $attributeArray);
		$this->processDomElementVAlign($sheet, $row, $column, $attributeArray);
		$this->processDomElementDataFormat($sheet, $row, $column, $attributeArray);

		if (isset($attributeArray['rowspan'], $attributeArray['colspan'])) {
			//create merging rowspan and colspan
			$columnTo = $column;
			for ($i = 0; $i < (int) $attributeArray['colspan'] - 1; ++$i) {
				++$columnTo;
			}
			$range = $column . $row . ':' . $columnTo . ($row + (int) $attributeArray['rowspan'] - 1);
			foreach (Coordinate::extractAllCellReferencesInRange($range) as $value) {
				$this->rowspan[$value] = true;
			}
			$sheet->mergeCells($range);
			$column = $columnTo;
		} elseif (isset($attributeArray['rowspan'])) {
			//create merging rowspan
			$range = $column . $row . ':' . $column . ($row + (int) $attributeArray['rowspan'] - 1);
			foreach (Coordinate::extractAllCellReferencesInRange($range) as $value) {
				$this->rowspan[$value] = true;
			}
			$sheet->mergeCells($range);
		} elseif (isset($attributeArray['colspan'])) {
			//create merging colspan
			$columnTo = $column;
			for ($i = 0; $i < (int) $attributeArray['colspan'] - 1; ++$i) {
				++$columnTo;
			}
			$sheet->mergeCells($column . $row . ':' . $columnTo . $row);
			$column = $columnTo;
		}

		++$column;
	}

	protected function processDomElement(DOMNode $element, Worksheet $sheet, int &$row, string &$column, string &$cellContent): void
	{
		foreach ($element->childNodes as $child) {
			if ($child instanceof DOMText) {
				$domText = (string) preg_replace('/\s+/', ' ', trim($child->nodeValue ?? ''));
				if ($domText === "\u{a0}") {
					$domText = '';
				}
				if (is_string($cellContent)) {
					//    simply append the text if the cell content is a plain text string
					$cellContent .= $domText;
				}
				//    but if we have a rich text run instead, we need to append it correctly
				//    TODO
			} elseif ($child instanceof DOMElement) {
				$this->processDomElementBody($sheet, $row, $column, $cellContent, $child);
			}
		}
	}

	/**
	 * Loads PhpSpreadsheet from file into PhpSpreadsheet instance.
	 */
	public function loadIntoExisting(string $filename, Spreadsheet $spreadsheet): Spreadsheet
	{
		// Validate
		if (!$this->canRead($filename)) {
			throw new Exception($filename . ' is an Invalid HTML file.');
		}

		// Create a new DOM object
		$dom = new DOMDocument();

		// Reload the HTML file into the DOM object
		try {
			$convert = $this->getSecurityScannerOrThrow()->scanFile($filename);
			$convert = self::replaceNonAsciiIfNeeded($convert);
			$loaded = ($convert === null) ? false : $dom->loadHTML($convert);
		} catch (Throwable $e) {
			$loaded = false;
		}
		if ($loaded === false) {
			throw new Exception('Failed to load ' . $filename . ' as a DOM Document', 0, $e ?? null);
		}
		self::loadProperties($dom, $spreadsheet);

		return $this->loadDocument($dom, $spreadsheet);
	}

	private static function loadProperties(DOMDocument $dom, Spreadsheet $spreadsheet): void
	{
		$properties = $spreadsheet->getProperties();
		foreach ($dom->getElementsByTagName('meta') as $meta) {
			$metaContent = (string) $meta->getAttribute('content');
			if ($metaContent !== '') {
				$metaName = (string) $meta->getAttribute('name');
				switch ($metaName) {
					case 'author':
						$properties->setCreator($metaContent);

						break;
					case 'category':
						$properties->setCategory($metaContent);

						break;
					case 'company':
						$properties->setCompany($metaContent);

						break;
					case 'created':
						$properties->setCreated($metaContent);

						break;
					case 'description':
						$properties->setDescription($metaContent);

						break;
					case 'keywords':
						$properties->setKeywords($metaContent);

						break;
					case 'lastModifiedBy':
						$properties->setLastModifiedBy($metaContent);

						break;
					case 'manager':
						$properties->setManager($metaContent);

						break;
					case 'modified':
						$properties->setModified($metaContent);

						break;
					case 'subject':
						$properties->setSubject($metaContent);

						break;
					case 'title':
						$properties->setTitle($metaContent);

						break;
					case 'viewport':
						$properties->setViewport($metaContent);

						break;
					default:
						if (preg_match('/^custom[.](bool|date|float|int|string)[.](.+)$/', $metaName, $matches) === 1) {
							switch ($matches[1]) {
								case 'bool':
									$properties->setCustomProperty($matches[2], (bool) $metaContent, Properties::PROPERTY_TYPE_BOOLEAN);
									break;
								case 'float':
									$properties->setCustomProperty($matches[2], (float) $metaContent, Properties::PROPERTY_TYPE_FLOAT);
									break;
								case 'int':
									$properties->setCustomProperty($matches[2], (int) $metaContent, Properties::PROPERTY_TYPE_INTEGER);
									break;
								case 'date':
									$properties->setCustomProperty($matches[2], $metaContent, Properties::PROPERTY_TYPE_DATE);
									break;
								default:
									$properties->setCustomProperty($matches[2], $metaContent, Properties::PROPERTY_TYPE_STRING);
									break;
							}
						}
				}
			}
		}
		if (!empty($dom->baseURI)) {
			$properties->setHyperlinkBase($dom->baseURI);
		}
	}

	private static function replaceNonAscii(array $matches): string
	{
		return '&#' . mb_ord($matches[0], 'UTF-8') . ';';
	}

	private static function replaceNonAsciiIfNeeded(string $convert): ?string
	{
		if (preg_match(self::STARTS_WITH_BOM, $convert) !== 1 && preg_match(self::DECLARES_CHARSET, $convert) !== 1) {
			$lowend = "\u{80}";
			$highend = "\u{10ffff}";
			$regexp = "/[$lowend-$highend]/u";
			/** @var callable $callback */
			$callback = [self::class, 'replaceNonAscii'];
			$convert = preg_replace_callback($regexp, $callback, $convert);
		}

		return $convert;
	}

	/**
	 * Spreadsheet from content.
	 */
	public function loadFromString(string $content, ?Spreadsheet $spreadsheet = null): Spreadsheet
	{
		//    Create a new DOM object
		$dom = new DOMDocument();

		//    Reload the HTML file into the DOM object
		try {
			$convert = $this->getSecurityScannerOrThrow()->scan($content);
			$convert = self::replaceNonAsciiIfNeeded($convert);
			$loaded = ($convert === null) ? false : $dom->loadHTML($convert);
		} catch (Throwable $e) {
			$loaded = false;
		}
		if ($loaded === false) {
			throw new Exception('Failed to load content as a DOM Document', 0, $e ?? null);
		}
		$spreadsheet = $spreadsheet ?? new Spreadsheet();
		$spreadsheet->setValueBinder($this->valueBinder);
		self::loadProperties($dom, $spreadsheet);

		return $this->loadDocument($dom, $spreadsheet);
	}

	/**
	 * Loads PhpSpreadsheet from DOMDocument into PhpSpreadsheet instance.
	 */
	private function loadDocument(DOMDocument $document, Spreadsheet $spreadsheet): Spreadsheet
	{
		while ($spreadsheet->getSheetCount() <= $this->sheetIndex) {
			$spreadsheet->createSheet();
		}
		$spreadsheet->setActiveSheetIndex($this->sheetIndex);

		// Discard white space
		$document->preserveWhiteSpace = false;

		$row = 0;
		$column = 'A';
		$content = '';
		$this->rowspan = [];
		$this->processDomElement($document, $spreadsheet->getActiveSheet(), $row, $column, $content);

		// Return
		return $spreadsheet;
	}

	/**
	 * Get sheet index.
	 */
	public function getSheetIndex(): int
	{
		return $this->sheetIndex;
	}

	/**
	 * Set sheet index.
	 *
	 * @param int $sheetIndex Sheet index
	 *
	 * @return $this
	 */
	public function setSheetIndex(int $sheetIndex)
	{
		$this->sheetIndex = $sheetIndex;

		return $this;
	}

	/**
	 * Apply inline css inline style.
	 *
	 * NOTES :
	 * Currently only intended for td & th element,
	 * and only takes 'background-color' and 'color'; property with HEX color
	 *
	 * TODO :
	 * - Implement to other propertie, such as border
	 */
	private function applyInlineStyle(Worksheet &$sheet, int $row, string $column, array $attributeArray): void
	{
		if (!isset($attributeArray['style'])) {
			return;
		}

		if ($row <= 0 || $column === '') {
			$cellStyle = new Style();
		} elseif (isset($attributeArray['rowspan'], $attributeArray['colspan'])) {
			$columnTo = $column;
			for ($i = 0; $i < (int) $attributeArray['colspan'] - 1; ++$i) {
				++$columnTo;
			}
			$range = $column . $row . ':' . $columnTo . ($row + (int) $attributeArray['rowspan'] - 1);
			$cellStyle = $sheet->getStyle($range);
		} elseif (isset($attributeArray['rowspan'])) {
			$range = $column . $row . ':' . $column . ($row + (int) $attributeArray['rowspan'] - 1);
			$cellStyle = $sheet->getStyle($range);
		} elseif (isset($attributeArray['colspan'])) {
			$columnTo = $column;
			for ($i = 0; $i < (int) $attributeArray['colspan'] - 1; ++$i) {
				++$columnTo;
			}
			$range = $column . $row . ':' . $columnTo . $row;
			$cellStyle = $sheet->getStyle($range);
		} else {
			$cellStyle = $sheet->getStyle($column . $row);
		}

		// add color styles (background & text) from dom element,currently support : td & th, using ONLY inline css style with RGB color
		$styles = explode(';', $attributeArray['style']);
		foreach ($styles as $st) {
			$value = explode(':', $st);
			$styleName = isset($value[0]) ? trim($value[0]) : null;
			$styleValue = isset($value[1]) ? trim($value[1]) : null;
			$styleValueString = (string) $styleValue;

			if (!$styleName) {
				continue;
			}

			switch ($styleName) {
				case 'background':
				case 'background-color':
					$styleColor = $this->getStyleColor($styleValueString);

					if (!$styleColor) {
						continue 2;
					}

					$cellStyle->applyFromArray(['fill' => ['fillType' => Fill::FILL_SOLID, 'color' => ['rgb' => $styleColor]]]);

					break;
				case 'color':
					$styleColor = $this->getStyleColor($styleValueString);

					if (!$styleColor) {
						continue 2;
					}

					$cellStyle->applyFromArray(['font' => ['color' => ['rgb' => $styleColor]]]);

					break;

				case 'border':
					$this->setBorderStyle($cellStyle, $styleValueString, 'allBorders');

					break;

				case 'border-top':
					$this->setBorderStyle($cellStyle, $styleValueString, 'top');

					break;

				case 'border-bottom':
					$this->setBorderStyle($cellStyle, $styleValueString, 'bottom');

					break;

				case 'border-left':
					$this->setBorderStyle($cellStyle, $styleValueString, 'left');

					break;

				case 'border-right':
					$this->setBorderStyle($cellStyle, $styleValueString, 'right');

					break;

				case 'font-size':
					$cellStyle->getFont()->setSize(
						(float) $styleValue
					);

					break;

				case 'font-weight':
					if ($styleValue === 'bold' || $styleValue >= 500) {
						$cellStyle->getFont()->setBold(true);
					}

					break;

				case 'font-style':
					if ($styleValue === 'italic') {
						$cellStyle->getFont()->setItalic(true);
					}

					break;

				case 'font-family':
					$cellStyle->getFont()->setName(str_replace('\'', '', $styleValueString));

					break;

				case 'text-decoration':
					switch ($styleValue) {
						case 'underline':
							$cellStyle->getFont()->setUnderline(Font::UNDERLINE_SINGLE);

							break;
						case 'line-through':
							$cellStyle->getFont()->setStrikethrough(true);

							break;
					}

					break;

				case 'text-align':
					$cellStyle->getAlignment()->setHorizontal($styleValueString);

					break;

				case 'vertical-align':
					$cellStyle->getAlignment()->setVertical($styleValueString);

					break;

				case 'width':
					if ($column !== '') {
						$sheet->getColumnDimension($column)->setWidth(
							(new CssDimension($styleValue ?? ''))->width()
						);
					}

					break;

				case 'height':
					if ($row > 0) {
						$sheet->getRowDimension($row)->setRowHeight(
							(new CssDimension($styleValue ?? ''))->height()
						);
					}

					break;

				case 'word-wrap':
					$cellStyle->getAlignment()->setWrapText(
						$styleValue === 'break-word'
					);

					break;

				case 'text-indent':
					$cellStyle->getAlignment()->setIndent(
						(int) str_replace(['px'], '', $styleValueString)
					);

					break;
			}
		}
	}

	/**
	 * Check if has #, so we can get clean hex.
	 */
	public function getStyleColor(?string $value): string
	{
		$value = (string) $value;
		if (str_starts_with($value, '#')) {
			return substr($value, 1);
		}

		return HelperHtml::colourNameLookup($value);
	}

	private function insertImage(Worksheet $sheet, string $column, int $row, array $attributes): void
	{
		if (!isset($attributes['src'])) {
			return;
		}
		$styleArray = self::getStyleArray($attributes);

		$src = $attributes['src'];
		if (substr($src, 0, 5) !== 'data:') {
			$src = urldecode($src);
		}
		$width = isset($attributes['width']) ? (float) $attributes['width'] : ($styleArray['width'] ?? null);
		$height = isset($attributes['height']) ? (float) $attributes['height'] : ($styleArray['height'] ?? null);
		$name = $attributes['alt'] ?? null;

		$drawing = new Drawing();
		$drawing->setPath($src, false);
		if ($drawing->getPath() === '') {
			return;
		}
		$drawing->setWorksheet($sheet);
		$drawing->setCoordinates($column . $row);
		$drawing->setOffsetX(0);
		$drawing->setOffsetY(10);
		$drawing->setResizeProportional(true);

		if ($name) {
			$drawing->setName($name);
		}

		if ($width) {
			if ($height) {
				$drawing->setWidthAndHeight((int) $width, (int) $height);
			} else {
				$drawing->setWidth((int) $width);
			}
		} elseif ($height) {
			$drawing->setHeight((int) $height);
		}

		$sheet->getColumnDimension($column)->setWidth(
			$drawing->getWidth() / 6
		);

		$sheet->getRowDimension($row)->setRowHeight(
			$drawing->getHeight() * 0.9
		);

		if (isset($styleArray['opacity'])) {
			$opacity = $styleArray['opacity'];
			if (is_numeric($opacity)) {
				$drawing->setOpacity((int) ($opacity * 100000));
			}
		}
	}

	private static function getStyleArray(array $attributes): array
	{
		$styleArray = [];
		if (isset($attributes['style'])) {
			$styles = explode(';', $attributes['style']);
			foreach ($styles as $style) {
				$value = explode(':', $style);
				if (count($value) === 2) {
					$arrayKey = trim($value[0]);
					$arrayValue = trim($value[1]);
					if ($arrayKey === 'width') {
						if (substr($arrayValue, -2) === 'px') {
							$arrayValue = (string) (((float) substr($arrayValue, 0, -2)));
						} else {
							$arrayValue = (new CssDimension($arrayValue))->width();
						}
					} elseif ($arrayKey === 'height') {
						if (substr($arrayValue, -2) === 'px') {
							$arrayValue = substr($arrayValue, 0, -2);
						} else {
							$arrayValue = (new CssDimension($arrayValue))->height();
						}
					}
					$styleArray[$arrayKey] = $arrayValue;
				}
			}
		}

		return $styleArray;
	}

	private const BORDER_MAPPINGS = [
		'dash-dot' => Border::BORDER_DASHDOT,
		'dash-dot-dot' => Border::BORDER_DASHDOTDOT,
		'dashed' => Border::BORDER_DASHED,
		'dotted' => Border::BORDER_DOTTED,
		'double' => Border::BORDER_DOUBLE,
		'hair' => Border::BORDER_HAIR,
		'medium' => Border::BORDER_MEDIUM,
		'medium-dashed' => Border::BORDER_MEDIUMDASHED,
		'medium-dash-dot' => Border::BORDER_MEDIUMDASHDOT,
		'medium-dash-dot-dot' => Border::BORDER_MEDIUMDASHDOTDOT,
		'none' => Border::BORDER_NONE,
		'slant-dash-dot' => Border::BORDER_SLANTDASHDOT,
		'solid' => Border::BORDER_THIN,
		'thick' => Border::BORDER_THICK,
	];

	public static function getBorderMappings(): array
	{
		return self::BORDER_MAPPINGS;
	}

	/**
	 * Map html border style to PhpSpreadsheet border style.
	 */
	public function getBorderStyle(string $style): ?string
	{
		return self::BORDER_MAPPINGS[$style] ?? null;
	}

	private function setBorderStyle(Style $cellStyle, string $styleValue, string $type): void
	{
		if (trim($styleValue) === Border::BORDER_NONE) {
			$borderStyle = Border::BORDER_NONE;
			$color = null;
		} else {
			$borderArray = explode(' ', $styleValue);
			$borderCount = count($borderArray);
			if ($borderCount >= 3) {
				$borderStyle = $borderArray[1];
				$color = $borderArray[2];
			} else {
				$borderStyle = $borderArray[0];
				$color = $borderArray[1] ?? null;
			}
		}

		$cellStyle->applyFromArray([
			'borders' => [
				$type => [
					'borderStyle' => $this->getBorderStyle($borderStyle),
					'color' => ['rgb' => $this->getStyleColor($color)],
				],
			],
		]);
	}

	/**
	 * Return worksheet info (Name, Last Column Letter, Last Column Index, Total Rows, Total Columns).
	 */
	public function listWorksheetInfo(string $filename): array
	{
		$info = [];
		$spreadsheet = new Spreadsheet();
		$this->loadIntoExisting($filename, $spreadsheet);
		foreach ($spreadsheet->getAllSheets() as $sheet) {
			$newEntry = ['worksheetName' => $sheet->getTitle()];
			$newEntry['lastColumnLetter'] = $sheet->getHighestDataColumn();
			$newEntry['lastColumnIndex'] = Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) - 1;
			$newEntry['totalRows'] = $sheet->getHighestDataRow();
			$newEntry['totalColumns'] = $newEntry['lastColumnIndex'] + 1;
			$info[] = $newEntry;
		}
		$spreadsheet->disconnectWorksheets();

		return $info;
	}
}
