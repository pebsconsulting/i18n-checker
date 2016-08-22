<?php
/**
 * Contains and initializes the Checker class.
 * @package i18nChecker
 */
/**
 * 
 */
require_once('class.N11n.php');
require_once('class.Parser.php');
require_once('class.Information.php');
require_once('class.Report.php');
/**
 * The I18n Checker
 * 
 * This class holds the logic of the i18n checker.
 * 
 * @package i18nChecker
 * @author Richard Ishida <ishida@w3.org> & Thomas Gambet <tgambet@w3.org>
 * @copyright 2011 W3C ® (MIT, ERCIM, Keio)
 * @license http://www.w3.org/Consortium/Legal/copyright-software
 */
class Checker {

	private static $logger;
	private $curl_info;
	private $markup;
	private $doc;
	private $isUTF16;

	public static function _init() {
		self::$logger = Logger::getLogger('Checker');
	}
	
	public function __construct($curl_info, $markup) {
		$this->markup = $markup;
		$this->curl_info = $curl_info;
	}
	
	public function checkDocument($forcedMimeType = null) {
		// Convert encoding from UTF-16 to UTF-8. XXX What about other encodings ?
		$bom = $this->convertEncoding();
		
		// Instantiate parser
		try {
			$contentType = isset($this->curl_info['content_type']) ? $this->curl_info['content_type'] : null;
			if ($forcedMimeType != null)
				$contentType = $contentType == null ? $forcedMimeType.'; charset=utf-8' : preg_replace('/^.+;/', $forcedMimeType.';', $contentType);
			$this->doc = Parser::getParser($this->markup, $contentType);
		} catch (Exception $e) {
			//Message::addMessage(MSG_LEVEL_ERROR, 'Exception: '.$e->getMessage());
			Message::addMessage(MSG_LEVEL_ERROR, lang('message_parse_error_failed',isset($this->curl_info['url']) ? 'check?uri='.urlencode($this->curl_info['url']) : ''));
			self::$logger->error('Exception raised for URI: '.$this->curl_info['url'], $e);
			return false;
		}
		
		//print_r(htmlentities($this->doc->markup));
		
		// Gather information
		$this->addInfoDTDMimetype();
		$this->addInfoCharsetHTTP();
		$this->addInfoCharsetBom($bom);
		$this->addInfoCharsetXMLDeclaration();
		$this->addInfoCharsetMeta();
		$this->addInfoLangAttr();
		$this->addInfoLangHTTP();
		$this->addInfoLangMeta();
		$this->addInfoDirHTML();
		$this->addInfoDirControls();
		$this->addInfoClassId();
		$this->addInfoRequestHeaders();
		$this->isUTF16 = ($bom == 'UTF-16LE' || $bom == 'UTF-16BE') ? true : false;
		
		// Generate report
		$this->addReportCharsets();
		$this->addReportLanguages();
		$this->addReportDirValues();
		$this->addReportMisc();
		return true;
	}

	private function convertEncoding() {
		# this should be adapted to take into account HTTP headers set to UTF16/-LE/-BE
		$filestart = substr($this->markup,0,3);
		if (ord($filestart{0})== 239 && ord($filestart{1})== 187 && ord($filestart{2})== 191) 
			return 'UTF-8';
		else { 
			$filestart = substr($this->markup,0,2);
			if (ord($filestart{0})== 254 && ord($filestart{1})== 255) {
				$this->markup = mb_convert_encoding($this->markup, 'UTF-8', 'UTF-16BE');
				return 'UTF-16BE';
			} else if (ord($filestart{0})== 255 && ord($filestart{1})== 254) {
				$this->markup = mb_convert_encoding($this->markup, 'UTF-8', 'UTF-16LE');
				return 'UTF-16LE';
			}
		}
	}
	
	
	private function addInfoDTDMimetype() {
		if ($this->doc->isXHTML5)
			Message::addMessage(MSG_LEVEL_WARNING, lang("message_xhtml5_partial_support"));
		Information::addInfo(null, 'dtd', null, $this->doc->doctype2String());
		Information::addInfo(null, 'mimetype', null, $this->doc->mimetype);
	}
	
	// INFO: CHARSET FROM HTTP CONTENT-TYPE HEADER
	private function addInfoCharsetHTTP() { 
		$category = 'charset_category';
		$title = 'charset_http';
		$_code = $this->curl_info['content_type'] ? 'Content-Type: '.$this->curl_info['content_type'] : null;
		$_val = Utils::charsetFromContentType($this->curl_info['content_type']);
		$value = array('code' => $_code, 'values' => $_val);
		$display_value = null;
		if ($_code != null && $_val == null)
			$display_value = 'charset_none_found';
		if ($_code == null && $_val == null)
			$display_value = 'val_none_found';
		if (!$this->curl_info['url'])
			$display_value = 'charset_na_upload';
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: BYTE ORDER MARK.
	private function addInfoCharsetBom($bom = '') {
		$category = 'charset_category';
		$title = 'charset_bom';
		$value = null;
		$display_value = null;
		if ($bom != '')
			$value = array ('code' => "Byte order mark (BOM): $bom", 'values' => $bom);
		else
			$display_value = 'val_no';
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: CHARSET FROM XML DECLARATION
	private function addInfoCharsetXMLDeclaration() {
		$category = 'charset_category';
		$title = 'charset_xml';
		$_code = $this->doc->XMLDeclaration();
		$_val = Utils::charsetFromXMLDeclaration($this->doc->XMLDeclaration());
		$value = array('code' => $_code, 'values' => $_val);
		$display_value = null;
		if ($_code != null && $_val == null)
			$display_value = 'charset_none_found';
		if ($_code == null && $_val == null)
			$display_value = 'val_none_found';
		if ($this->doc->isXML || (!$this->doc->isXML && $_code != null) || ($this->doc->isXHTML5 && $_code != null))
			Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: CHARSET FROM META CONTENT-TYPE OR META CHARSET (HTML5)
	private function addInfoCharsetMeta() {
		$category = 'charset_category';
		$title = 'charset_meta';
		$value = array_merge($this->doc->getMetaCharset(), $this->doc->getMetaContentType());
		$display_value = null;
		$vals = Utils::valuesFromValArray($value);
		if (empty($vals)) {
			$codes = Utils::codesFromValArray($value);
			if (empty($codes))
				$display_value = 'val_none_found';
			else
				$display_value = 'charset_none_found';
		}
		if (empty($vals) && $this->doc->isXML && $this->doc->isServedAsXML) 
			return;
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: LANGUAGE FROM HTML LANG AND XML:LANG ATTRIBUTES
	private function addInfoLangAttr() {
		$category = 'lang_category';
		$title = 'lang_attr_lang';
		$_code = $this->doc->HTMLTag();
		$_val = array();
		if (($_langAttr = $this->doc->getHTMLTagAttr('lang')) != null)
			$_val[] = $_langAttr;
		if (($_xmlLangAttr = $this->doc->getHTMLTagAttr('lang', true)) != null)
			$_val[] = $_xmlLangAttr;
		$_val = empty($_val) ? null : array_unique($_val); // unify
		$value = array('code' => $_code, 'values' => $_val);
		$display_value = null;
		if ($_code != null && empty($_val))
			$display_value = 'val_none';
		if ($_code == null && empty($_val))
			$display_value = 'no_html_tag_found';
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: LANGUAGE FROM HTTP CONTENT-LANGUAGE
	private function addInfoLangHTTP() {
		$category = 'lang_category';
		$title = 'lang_http';
		$_code = isset($this->curl_info['content_language']) ? 'Content-Language: '.$this->curl_info['content_language'] : null;
		$_val = isset($this->curl_info['content_language']) ? Utils::getValuesFromCSString($this->curl_info['content_language']) : null;
		$value = array(
			'code' => $_code,
			'values' => $_val
		);		
		$display_value = null;
		if ($_val == null)
			$display_value = 'val_none_found';
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: LANGUAGE FROM META CONTENT-LANGUAGE
	private function addInfoLangMeta() {
		$category = 'lang_category';
		$title = 'lang_meta';
		$value = $this->doc->getMetaContentLanguage();
		$display_value = null;
		if ($value == null)
			$display_value = 'val_none_found';
		if (!empty($value))
			Information::addInfo($category, $title, $value, $display_value);		
	}
	
	// INFO: TEXT DIRECTION FROM HTML TAGS
	private function addInfoDirHTML() {
		$category = 'dir_category';
		$title = 'dir_default';
		$_code = $this->doc->HTMLTag();
		$_val = $this->doc->getHTMLTagAttr('dir');
		$value = array('code' => $_code, 'values' => $_val);
		$display_value = null;
		if ($_val == null) {
			$display_value = 'dir_default_ltr';
			$value['code'] = null;
		}
		Information::addInfo($category, $title, $value, $display_value);	
	}
	
	// INFO: DIRECTIONAL CONTROL CODES
	private function addInfoDirControls() {
		$resultStr = '';
		$totalCtrls = 0;
		if (preg_match_all('/(&rlm;)|(&lrm;)|(&#8206;)|(&#8207;)|(&#8234;)|(&#8235;)|(&#8236;)|(&#8237;)|(&#8238;)|(&#8294;)|(&#8295;)|(&#8296;)|(&#8297;)|(&#x200E;)|(&#x200F;)|(&#x202A;)|(&#x202B;)|(&#x202C;)|(&#x202D;)|(&#x202E;)|(&#x2066;)|(&#x2067;)|(&#x2068;)|(&#x2069;)|(‎)|(‏)|(‪)|(‫)|(‬)|(‭)|(‮)|(⁦)|(⁧)|(⁨)|(⁩)/', $this->markup, $foundEntities)) {
			$entityList = array_count_values($foundEntities[0]);
			$dirControls = array('rlm'=>0,'&rlm'=>0,'#rlm'=>0,'lrm'=>0,'&lrm'=>0,'#lrm'=>0,'lre'=>0,'#lre'=>0, 'rle'=>0,'#rle'=>0, 'pdf'=>0,'#pdf'=>0, 'rli'=>0,'#rli'=>0, 'lri'=>0,'#lri'=>0, 'fsi'=>0,'#fsi'=>0, 'pdi'=>0,'#pdi'=>0, 'rlo'=>0,'#rlo'=>0, 'lro'=>0,'#lro'=>0);
			// merge the results for hex and dec escapes
			foreach ($entityList as $key => $val) {
				switch ($key) {
					case '‏': $dirControls['rlm'] += $val; break;
					case '&#8207;';
					case '&#x200F;':$dirControls['#rlm'] += $val; break;
					case '&rlm;':$dirControls['&rlm'] += $val; break;
					case '‎': $dirControls['lrm'] += $val; break;
					case '&#8206;';
					case '&#x200E;':$dirControls['#lrm'] += $val; break;
					case '&lrm;':$dirControls['&lrm'] += $val; break;
					case '‪': $dirControls['lre'] += $val; break;
					case '&#8234;';
					case '&#x202A;':$dirControls['#lre'] += $val; break;
					case '‫': $dirControls['rle'] += $val; break;
					case '&#8235;';
					case '&#x202B;': $dirControls['#rle'] += $val; break;
					case '‬': $dirControls['pdf'] += $val; break;
					case '&#8236;';
					case '&#x202C;': $dirControls['#pdf'] += $val; break;

					case '‭': $dirControls['lro'] += $val; break;
					case '&#8237;';
					case '&#x202D;': $dirControls['#lro'] += $val; break;
					case '‮': $dirControls['rlo'] += $val; break;
					case '&#8238;';
					case '&#x202E;': $dirControls['#rlo'] += $val; break;

					case '⁦': $dirControls['lri'] += $val; break;
					case '&#8294;';
					case '&#x2066;': $dirControls['#lri'] += $val; break;
					case '⁧': $dirControls['rli'] += $val; break;
					case '&#8295;';
					case '&#x2067;': $dirControls['#rli'] += $val; break;

					case '⁨': $dirControls['fsi'] += $val; break;
					case '&#8296;';
					case '&#x2068;': $dirControls['#fsi'] += $val; break;
					case '⁩': $dirControls['pdi'] += $val; break;
					case '&#8297;';
					case '&#x2069;': $dirControls['#pdi'] += $val; break;
					}
				}
			foreach ($dirControls as $key => $val) {
				$totalCtrls += $val;
				if ($val > 0) { $resultStr .= $key.'('.$val.'), '; }
				}
			}
//print('totalCtrls:'.$totalCtrls."\n");
//print_r($dirControls);
		$category = 'dir_category';
		$title = 'dir_controls';
		$value = array(array('code'=>$resultStr,'values'=>null));
		$display_value = $totalCtrls == 0 ? 'val_none' : $totalCtrls;
		Information::addInfo($category, $title, $value, $display_value);	
	}
	
	// INFO: NON ASCII AND NFC CLASSES AND IDS
	private function addInfoClassId() {
		$classes = $this->doc->getNodesWithAttr('class');
		$ids = $this->doc->getNodesWithAttr('id');
		$nodes = array_merge((array) $classes,(array) $ids);
		
		// Remove nodes for which all class names are ASCII
		if (count($nodes) > 0)
			$nodes = array_filter($nodes, function (&$valArray) {
				$valArray['values'] = preg_filter('/[^\x20-\x7E]/', '$0', $valArray['values']);
				if (empty($valArray['values']))
					return false;
				return true;
			});	
		
		$category = 'classId_category';
		$title = 'classId_non_ascii';
		$value = array_values($nodes); // we use array_values() to reindex the array
		$display_value = count($value) == 0 ? 'val_none' : null;
		Information::addInfo($category, $title, $value, $display_value);
		
		// Remove nodes for which all class names are NFC
		if (count($nodes) > 0)
			// FIXME? case class="nonASCII nonNFC" may report two nonNFC names if $valArray[values] is not edited. the check should be value by value.
			$nodes = array_filter($nodes, function (&$valArray) {
				if (is_array($valArray['values'])) 
					$classStr = implode('', $valArray['values']);
				else
					$classStr = $valArray['values']; 
				if (N11n::nfc($classStr) == $classStr)
					return false;
				return true;
			});		
		
		$title = 'classId_non_nfc';
		$value = array_values($nodes);
		$display_value = count($value) == 0 ? 'val_none' : null;
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	// INFO: REQUEST HEADERS
	private function addInfoRequestHeaders() {
		$category = 'headers_category';
		$title = 'headers_accept_language';
		$_val = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? Utils::parseHeader($_SERVER['HTTP_ACCEPT_LANGUAGE']) : null;
		$_code = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? 'Accept-Language: '.$_SERVER['HTTP_ACCEPT_LANGUAGE'] : null;
		$value = array('code' => $_code, 'values' => $_val);
		$display_value = null;
		if ($_val == null)
			$display_value = 'val_none_found';
		Information::addInfo($category, $title, $value, $display_value);
		
		$title = 'headers_accept_charset';
		$_val = isset($_SERVER['HTTP_ACCEPT_CHARSET']) ? Utils::parseHeader($_SERVER['HTTP_ACCEPT_CHARSET']) : null;
		$_code = isset($_SERVER['HTTP_ACCEPT_CHARSET']) ? 'Accept-Charset: '.$_SERVER['HTTP_ACCEPT_CHARSET'] : null;
		$value = array('code' => $_code, 'values' => $_val);
		$display_value = null;
		if ($_val == null)
			$display_value = 'val_none_found';
		Information::addInfo($category, $title, $value, $display_value);
	}
	
	private function addReportCharsets() {
		$category = 'charset_category';
		
		// Get all the charsets found
		$charsets = (array) Information::getValuesStartingWith('charset_');
		$charsetVals = array_unique(array_map('strtoupper', Utils::valuesFromValArray($charsets)));
		$charsetVals = $charsetVals == null ? array() : $charsetVals;
		$charsetCodes = Utils::codesFromValArray(
			array_filter($charsets, function ($array) {
				if ($array['values'] != null && !empty($array['values']))
					return true;
				return false;
			})
		);
		
#print_r(Information::$infos);
#echo '<p>$charsets:</p>';
#echo '<pre>'; print_r($charsets); echo '</pre>';
#echo '<p>$charsetVals</p>';
#echo '<pre>'; print_r($charsetVals); echo '</pre>'; 
#echo '<p>$charsetCodes</p>';
#echo '<pre>';print_r($charsetCodes); echo '</pre>';
#echo 'Information::getValues("charset_xml")';
#print_r(Information::getValues('charset_xml'));
#echo 'Information::getFirstVal("charset_xml")';
#print_r(Information::getFirstVal('charset_xml'));
		
		// CHARSET REPORT: No character encoding information
		if (empty($charsetVals)) {
			self::$logger->debug('No charset information found for this document.');
			if (! $this->doc->isServedAsXML) {
				//if ($this->doc->isHTML5) { $rep_level = REPORT_LEVEL_ERROR; }
				//else { $rep_level = REPORT_LEVEL_WARNING; }
				Report::addReport(
					'rep_charset_none',
					$category, REPORT_LEVEL_ERROR,
					lang('rep_charset_none'),
					lang('rep_charset_none_expl'),
					lang('rep_charset_none_todo'),
					lang('rep_charset_none_link')
				);
			} else {
				Report::addReport(
					'rep_charset_no_encoding_xml',
					$category, REPORT_LEVEL_WARNING,
					lang('rep_charset_no_encoding_xml'),
					lang('rep_charset_no_encoding_xml_expl'),
					lang('rep_charset_no_encoding_xml_todo'),
					lang('rep_charset_no_encoding_xml_link')
				);
			}
			return;
		}
		
		// CHARSET REPORT: Non-UTF8 character encoding declared
		if (!in_array("UTF-8", $charsetVals) || count(array_unique($charsetVals)) > 1) {
			$nonUTF8CharsetCodes = Utils::codesFromValArray(
				array_filter($charsets, function ($array) {
					// XXX Review this
					if ($array['values'] != null 
						&& (!in_array("UTF-8", array_map('strtoupper', (array) $array['values']))))
						return true;
					return false;
				})
			);
			Report::addReport(
				'rep_charset_no_utf8',
				$category, REPORT_LEVEL_WARNING,
				lang('rep_charset_no_utf8'),
				lang('rep_charset_no_utf8_expl', Language::format($nonUTF8CharsetCodes, LANG_FORMAT_OL_CODE)),
				lang('rep_charset_no_utf8_todo'),
				lang('rep_charset_no_utf8_link')
			);
		}
		
		// INFO: Find out which legacy character encodings are used, and what labels
		$legacyEncodings = array();
		$legacyLabels = array();
		$unknownEncodings = array();
		$encLabels = array(
		"UTF-8"=>"UTF-8", "UNICODE-1-1-UTF-8"=>"UTF-8", "UTF8"=>"UTF-8", 
		"IBM866"=>"IBM866", "866"=>"IBM866", "CP866"=>"IBM866", "CSIBM866"=>"IBM866", 
		"ISO-8859-1"=>"WINDOWS-1252", 
		"ISO-8859-2"=>"ISO-8859-2", "CSISOLATIN2"=>"ISO-8859-2", "ISO-IR-101"=>"ISO-8859-2", "ISO8859-2"=>"ISO-8859-2", "ISO88592"=>"ISO-8859-2", "ISO_8859-2"=>"ISO-8859-2", "ISO_8859-2:1987"=>"ISO-8859-2", "L2"=>"ISO-8859-2", "LATIN2"=>"ISO-8859-2", 
		"ISO-8859-3"=>"ISO-8859-3", "CSISOLATIN3"=>"ISO-8859-3", "ISO-IR-109"=>"ISO-8859-3", "ISO8859-3"=>"ISO-8859-3", "ISO88593"=>"ISO-8859-3", "ISO_8859-3"=>"ISO-8859-3", "ISO_8859-3:1988"=>"ISO-8859-3", "L3"=>"ISO-8859-3", "LATIN3"=>"ISO-8859-3", 
		"ISO-8859-4"=>"ISO-8859-4", "CSISOLATIN4"=>"ISO-8859-4", "ISO-IR-110"=>"ISO-8859-4", "ISO8859-4"=>"ISO-8859-4", "ISO88594"=>"ISO-8859-4", "ISO_8859-4"=>"ISO-8859-4", "ISO_8859-4:1988"=>"ISO-8859-4", "L4"=>"ISO-8859-4", "LATIN4"=>"ISO-8859-4", 
		"ISO-8859-5"=>"ISO-8859-5", "CSISOLATINCYRILLIC"=>"ISO-8859-5", "CYRILLIC"=>"ISO-8859-5", "ISO-IR-144"=>"ISO-8859-5", "ISO8859-5"=>"ISO-8859-5", "ISO88595"=>"ISO-8859-5", "ISO_8859-5"=>"ISO-8859-5", "ISO_8859-5:1988"=>"ISO-8859-5", 
		"ISO-8859-6"=>"ISO-8859-6", "ARABIC"=>"ISO-8859-6", "ASMO-708"=>"ISO-8859-6", "CSISO88596E"=>"ISO-8859-6", "CSISO88596I"=>"ISO-8859-6", "CSISOLATINARABIC"=>"ISO-8859-6", "ECMA-114"=>"ISO-8859-6", "ISO-8859-6-E"=>"ISO-8859-6", "ISO-8859-6-I"=>"ISO-8859-6", "ISO-IR-127"=>"ISO-8859-6", "ISO8859-6"=>"ISO-8859-6", "ISO88596"=>"ISO-8859-6", "ISO_8859-6"=>"ISO-8859-6", "ISO_8859-6:1987"=>"ISO-8859-6",
		"ISO-8859-7"=>"ISO-8859-7", "CSISOLATINGREEK"=>"ISO-8859-7", "ECMA-118"=>"ISO-8859-7", "ELOT_928"=>"ISO-8859-7", "GREEK"=>"ISO-8859-7", "GREEK8"=>"ISO-8859-7", "ISO-IR-126"=>"ISO-8859-7", "SUN_EU_GREEK"=>"ISO-8859-7", "ISO8859-7"=>"ISO-8859-7", "ISO88597"=>"ISO-8859-7", "ISO_8859-7"=>"ISO-8859-7", "ISO_8859-7:1987"=>"ISO-8859-7", 
		"ISO-8859-8"=>"ISO-8859-8", "CSISO88598E"=>"ISO-8859-8", "CSISOLATINHEBREW"=>"ISO-8859-8", "HEBREW"=>"ISO-8859-8", "ISO-8859-8-E"=>"ISO-8859-8", "ISO-IR-138"=>"ISO-8859-8", "ISO8859-8"=>"ISO-8859-8", "ISO88598"=>"ISO-8859-8", "ISO_8859-8"=>"ISO-8859-8", "ISO_8859-8:1988"=>"ISO-8859-8", "VISUAL"=>"ISO-8859-8", 
		"ISO-8859-8-I"=>"ISO-8859-8-I", "CSISO88598I"=>"ISO-8859-8-1", "LOGICAL"=>"ISO-8859-8-I", 
		"ISO-8859-10"=>"ISO-8859-10", "CSISOLATIN6"=>"ISO-8859-10", "ISO-IR-157"=>"ISO-8859-10", "ISO8859-10"=>"ISO-8859-10", "ISO885910"=>"ISO-8859-10", "L6"=>"ISO-8859-10", "LATIN6"=>"ISO-8859-10", 
		"ISO-8859-13"=>"ISO-8859-13", "ISO8859-13"=>"ISO-8859-13", "ISO885913"=>"ISO-8859-13", 
		"ISO-8859-14"=>"ISO-8859-14", "ISO8859-14"=>"ISO-8859-14", "ISO885914"=>"ISO-8859-14", 
		"ISO-8859-15"=>"ISO-8859-15", "CSISOLATIN9"=>"ISO-8859-15", "ISO8859-15"=>"ISO-8859-15", "ISO885915"=>"ISO-8859-15", "ISO_8859-15"=>"ISO-8859-15", "L9"=>"ISO-8859-15", 
		"ISO-8859-16"=>"ISO-8859-16", 
		"KOI8-R"=>"KOI8-R", "CSKOI8R"=>"KOI8-R", "KOI"=>"KOI8-R", "KOI8"=>"KOI8-R", "KOI8_R"=>"KOI8-R", 
		"KOI8-U"=>"KOI8-U", "KOI8-RU"=>"KOI8-U", 
		"MACINTOSH"=>"MACINTOSH", "CSMACINTOSH"=>"MACINTOSH", "MAC"=>"MACINTOSH", "X-MAC-ROMAN"=>"MACINTOSH", 
		"WINDOWS-874"=>"WINDOWS-874", "DOS-874"=>"WINDOWS-874", "ISO-8859-11"=>"WINDOWS-874", "ISO8859-11"=>"WINDOWS-874", "ISO885911"=>"WINDOWS-874", "TIS-620"=>"WINDOWS-874", 
		"WINDOWS-1250"=>"WINDOWS-1250", "CP1250"=>"WINDOWS-1250", "X-CP1250"=>"WINDOWS-1250", 
		"WINDOWS-1251"=>"WINDOWS-1251", "CP1251"=>"WINDOWS-1251", "X-CP1251"=>"WINDOWS-1251", 
		"WINDOWS-1252"=>"WINDOWS-1252", "ANSI_X3.4-1968"=>"WINDOWS-1252", "ASCII"=>"WINDOWS-1252", "CP1252"=>"WINDOWS-1252", "CP819"=>"WINDOWS-1252", "CSISOLATIN1"=>"WINDOWS-1252", "IBM819"=>"WINDOWS-1252", "ISO-8859-1"=>"WINDOWS-1252", "ISO-IR-100"=>"WINDOWS-1252", "ISO8859-1"=>"WINDOWS-1252", "ISO88591"=>"WINDOWS-1252", "ISO_8859-1"=>"WINDOWS-1252", "ISO_8859-1:1987"=>"WINDOWS-1252", "L1"=>"WINDOWS-1252", "LATIN1"=>"WINDOWS-1252", "US-ASCII"=>"WINDOWS-1252", "X-CP1252"=>"WINDOWS-1252", 
		"WINDOWS-1253"=>"WINDOWS-1253", "CP1253"=>"WINDOWS-1253", "X-CP1253"=>"WINDOWS-1253", 
		"WINDOWS-1254"=>"WINDOWS-1254", "CP1254"=>"WINDOWS-1254", "X-CP1254"=>"WINDOWS-1254", "CSISOLATIN5"=>"WINDOWS-1254", "ISO-8859-9"=>"WINDOWS-1254", "ISO-IR-148"=>"WINDOWS-1254", "ISO8859-9"=>"WINDOWS-1254", "ISO88599"=>"WINDOWS-1254", "ISO_8859-9"=>"WINDOWS-1254", "ISO_8859-9:1989"=>"WINDOWS-1254", "L5"=>"WINDOWS-1254", "LATIN5"=>"WINDOWS-1254", 
		"WINDOWS-1255"=>"WINDOWS-1255", "CP1255"=>"WINDOWS-1255", "X-CP1255"=>"WINDOWS-1255", 
		"WINDOWS-1256"=>"WINDOWS-1256", "CP1256"=>"WINDOWS-1256", "X-CP1256"=>"WINDOWS-1256", 
		"WINDOWS-1257"=>"WINDOWS-1257", "CP1257"=>"WINDOWS-1257", "X-CP1257"=>"WINDOWS-1257", 
		"WINDOWS-1258"=>"WINDOWS-1258", "CP1258"=>"WINDOWS-1258", "X-CP1258"=>"WINDOWS-1258", 
		"X-MAC-CYRILLIC"=>"X-MAC-CYRILLIC", "X-MAC-UKRAINIAN"=>"X-MAC-CYRILLIC", 
		"GBK"=>"GBK", "CHINESE"=>"GBK", "CSGB2312"=>"GBK", "CSISO58GB231280"=>"GBK", "GB2312"=>"GBK", "GB_2312"=>"GBK", "GB_2312-80"=>"GBK", "ISO-IR-58"=>"GBK", "X-GBK"=>"GBK", "GB18030"=>"GB18030", 
		"BIG5"=>"BIG5", "BIG5-HKSCS"=>"BIG5", "CN-BIG5"=>"BIG5", "CSBIG5"=>"BIG5", "X-X-BIG5"=>"BIG5", 
		"EUC-JP"=>"EUC-JP", "CSEUCPKDFMTJAPANESE"=>"EUC-JP", "X-EUC-JP"=>"EUC-JP", 
		"ISO-2022-JP"=>"ISO-2022-JP", "CSISO2022JP"=>"ISO-2022-JP", 
		"SHIFT_JIS"=>"SHIFT_JIS", "CSSHIFTJIS"=>"SHIFT_JIS", "MS932"=>"SHIFT_JIS", "MS_KANJI"=>"SHIFT_JIS", "SHIFT-JIS"=>"SHIFT_JIS", "SJIS"=>"SHIFT_JIS", "WINDOWS-31J"=>"SHIFT_JIS", "X-SJIS"=>"SHIFT_JIS", 
		"EUC-KR"=>"EUC-KR", "CSEUCKR"=>"EUC-KR", "CSKSC56011987"=>"EUC-KR", "ISO-IR-149"=>"EUC-KR", "KOREAN"=>"EUC-KR", "KS_C_5601-1987"=>"EUC-KR", "KS_C_5601-1989"=>"EUC-KR", "KSC5601"=>"EUC-KR", "KSC_5601"=>"EUC-KR", "WINDOWS-949"=>"EUC-KR", 
		"UTF-16BE"=>"UTF-16BE", 
		"UTF-16LE"=>"UTF-16LE","UTF-16"=>"UTF-16", 
		);
		foreach($charsetVals as $encoding) {
			 if (isset($encLabels[$encoding])) {
				if ($encLabels[$encoding] != $encoding) { 
					$legacyEncodings[] = $encLabels[$encoding];
					$legacyLabels[] = $encoding; 
					}
			 	}
			else { $unknownEncodings[] = $encoding; }
			}
		//var_dump($legacyEncodings);
		//var_dump($legacyLabels);
		//var_dump($unknownEncodings);
		
		
		// CHARSET REPORT: Unknown character encoding declared
		if (count($unknownEncodings) > 0) {
			Report::addReport(
				'rep_charset_unknown',
				$category, REPORT_LEVEL_ERROR,
				lang('rep_charset_unknown'),
				lang('rep_charset_unknown_expl', Language::format($unknownEncodings, LANG_FORMAT_OL_CODE)),
				lang('rep_charset_unknown_todo'),
				lang('rep_charset_unknown_link')
			);
		}
		
		
		// CHARSET REPORT: Non-preferred legacy character encoding name used
		if (count($legacyLabels) > 0) {
			Report::addReport(
				'rep_charset_legacy',
				$category, REPORT_LEVEL_WARNING,
				lang('rep_charset_legacy'),
				lang('rep_charset_legacy_expl', Language::format($legacyLabels, LANG_FORMAT_OL_CODE), Language::format($legacyEncodings, LANG_FORMAT_OL_CODE)),
				lang('rep_charset_legacy_todo'),
				lang('rep_charset_legacy_link')
			);
		}
		
		// CHARSET REPORT: Conflicting character encoding declarations
		if (count(array_unique($charsetVals)) != 1) {
			$codes = $charsetCodes;
			Report::addReport(
				'rep_charset_conflict',
				$category, REPORT_LEVEL_ERROR,
				lang('rep_charset_conflict'),
				lang('rep_charset_conflict_expl', Language::format($codes, LANG_FORMAT_OL_CODE)),
				lang('rep_charset_conflict_todo'),
				lang('rep_charset_conflict_link')
			);
		}
		
		// CHARSET REPORT: XML Declaration used
		if (Information::getFirstVal('charset_xml') != null) {
			if ($this->doc->isHTML || $this->doc->isHTML5 && !$this->doc->isServedAsXML) {
				#if ($this->doc->isHTML) { $_expl = 'rep_charset_xml_decl_used_expl_html'; } else { $_expl = 'rep_charset_xml_decl_used_expl_html5'; }
				Report::addReport(
					'rep_charset_xml_decl_used',
					$category, REPORT_LEVEL_ERROR, 
					lang('rep_charset_xml_decl_used'),
					lang('rep_charset_xml_decl_used_expl_html5', Language::format(Utils::codesFromValArray(Information::getValues('charset_xml')), LANG_FORMAT_OL_CODE)),
					lang('rep_charset_xml_decl_used_todo_html'),
					lang('rep_charset_xml_decl_used_link')
				);
			}
			if ($this->doc->isXHTML10 && !$this->doc->isServedAsXML) {
				Report::addReport(
					'rep_charset_xml_decl_used',
					$category, REPORT_LEVEL_ERROR, 
					lang('rep_charset_xml_decl_used'),
					lang('rep_charset_xml_decl_used_expl_xhtml', Language::format(Utils::codesFromValArray(Information::getValues('charset_xml')), LANG_FORMAT_OL_CODE)),
					lang('rep_charset_xml_decl_used_todo_xhtml'),
					lang('rep_charset_xml_decl_used_link')
				);
			}
		}
		
		#if ($debug) { echo "<p>n".'Utils::codesFromValArray(Information::getValues("charset_meta"))'."</p>"; print_r(Utils::codesFromValArray(Information::getValues('charset_meta'))); }
		#if ($debug) { echo "<p>".'$charsetCodes'."</p>"; print_r($charsetCodes); }
		
		// CHARSET REPORT: Meta charset tag will cause validation to fail
		//if (Information::getFirstVal('charset_meta') != null && !Utils::_empty($this->doc->getMetaCharset())) {
		//	if (!$this->doc->isHTML5) {
		//		Report::addReport(
		//			'rep_charset_meta_charset_invalid',
		//			$category, REPORT_LEVEL_WARNING, 
		//			lang('rep_charset_meta_charset_invalid'),
		//			lang('rep_charset_meta_charset_invalid_expl', Language::format(Utils::codesFromValArray($this->doc->getMetaCharset()), LANG_FORMAT_OL_CODE)),
		//			lang('rep_charset_meta_charset_invalid_todo'),
		//			lang('rep_charset_meta_charset_invalid_link')
		//		);
		//	}
		//}  REMOVED because no longer a valid check - source left for now for reference

		// CHARSET REPORT: Meta charset declaration uses http-equiv
		if (! $this->doc->isServedAsXML) {
			if (Information::getFirstVal('charset_meta') != null && $this->metaType($this->getFirstCVP(Information::getValues('charset_meta'))) == 'http-equiv') {
				Report::addReport(
					'rep_charset_pragma',
					$category, REPORT_LEVEL_INFO, 
					lang('rep_charset_pragma'),
					lang('rep_charset_pragma_expl', Language::format(Utils::codesFromValArray($this->doc->getMetaContentType()), LANG_FORMAT_OL_CODE), Information::getFirstVal('charset_meta')),
					lang('rep_charset_pragma_todo'),
					lang('rep_charset_pragma_link')
				);
			}
		}
		
		#if ($debug) { echo "<p>n".'$inDocCharsets'."</p>"; print_r($inDocCharsets); }
		#if ($debug) { echo "<p>".'Information::getFirstVal("charset_meta")'."</p><pre>"; print_r(Information::getFirstVal('charset_meta')); echo "</pre>"; }
		
		// CHARSET REPORT: Meta encoding declarations don't work with XML
		if (Information::getFirstVal('charset_meta') != null) {
			#if ($this->doc->isServedAsXML && strtoupper(Information::getFirstVal('charset_meta')) == 'UTF-8' || strtoupper(Information::getFirstVal('charset_meta') == 'UTF-16')) {
			if ($this->doc->isServedAsXML) {
				Report::addReport(
					'rep_charset_meta_ineffective',
					$category, REPORT_LEVEL_INFO, 
					lang('rep_charset_meta_ineffective'),
					lang('rep_charset_meta_ineffective_expl', Language::format(Utils::codesFromValArray(Information::getValues('charset_meta')), LANG_FORMAT_OL_CODE)),
					lang('rep_charset_meta_ineffective_todo'),
					lang('rep_charset_meta_ineffective_link')
				);
			}
		}
		
		// CHARSET REPORT: Incorrect use of meta encoding declaration
		$inDocCharsets = array_merge(
			(array) Information::getValues('charset_http'),
			(array) Information::getValues('charset_bom'),
			(array) Information::getValues('charset_xml')
		);
		$inDocCharsets = 
			array_filter($inDocCharsets, function ($array) {
				if ($array['values'] != null && !empty($array['values']))
					return true;
				return false;
			});
		//echo "<p>n".'$inDocCharsets'."</p>"; var_dump($inDocCharsets); 
		//echo "<p>".'Information::getFirstVal("charset_meta")'."</p><pre>"; print_r(Information::getFirstVal('charset_meta')); echo "</pre>"; 
		if (Information::getFirstVal('charset_meta') != null && empty($inDocCharsets) && strtoupper(Information::getFirstVal('charset_meta')) != 'UTF-8' && strtoupper(Information::getFirstVal('charset_meta')) != 'UTF-16') {
			//if ($this->doc->isXHTML1x && ! $this->doc->isServedAsXML) {
			//	#if ($debug) { echo "<p>YES</p>"; }
			//	Report::addReport(
			//		'rep_charset_incorrect_use_meta',
			//		$category, REPORT_LEVEL_WARNING,
			//		lang('rep_charset_incorrect_use_meta'),
			//		lang('rep_charset_incorrect_use_meta_expl', Language::format(Utils::codesFromValArray(Information::getValues('charset_meta')), LANG_FORMAT_OL_CODE)),
			//		lang('rep_charset_incorrect_use_meta_todo_xhtml'),
			//		lang('rep_charset_incorrect_use_meta_link')
			//	);
			//}
			//if ($this->doc->isXHTML1x && $this->doc->isServedAsXML) {
			if ($this->doc->isServedAsXML) {
				Report::addReport(
					'rep_charset_incorrect_use_meta',
					$category, REPORT_LEVEL_ERROR,
					lang('rep_charset_incorrect_use_meta'),
					lang('rep_charset_incorrect_use_meta_expl', Language::format(Utils::codesFromValArray(Information::getValues('charset_meta')), LANG_FORMAT_OL_CODE)),
					lang('rep_charset_incorrect_use_meta_todo'),
					lang('rep_charset_incorrect_use_meta_link')
				);
			}
		}
		
		// CHARSET REPORT: Multiple encoding declarations using the meta tag
		if (count(Information::getValues('charset_meta')) > 1) {
			Report::addReport(
				'rep_charset_multiple_meta',
				$category, REPORT_LEVEL_ERROR, 
				lang('rep_charset_multiple_meta'),
				lang('rep_charset_multiple_meta_expl', Language::format(Utils::codesFromValArray(Information::getValues('charset_meta')), LANG_FORMAT_OL_CODE)),
				lang('rep_charset_multiple_meta_todo'),
				lang('rep_charset_multiple_meta_link')
			);
		}
		
		// CHARSET REPORT: UTF-8 BOM found at start of file
		if (($bom = Information::getFirstVal('charset_bom')) != null 
			&& strcasecmp($bom, "UTF-8") == 0) {
			Report::addReport(
				'rep_charset_bom_found',
				$category, REPORT_LEVEL_INFO, 
				lang('rep_charset_bom_found'),
				lang('rep_charset_bom_found_expl'),
				lang('rep_charset_bom_found_todo'),
				lang('rep_charset_bom_found_link')
			);
		}
		
		// CHARSET REPORT: UTF-8 BOM disagrees with another declaration
		if (($bom = Information::getFirstVal('charset_bom')) != null 
			&& strcasecmp($bom, "UTF-8") == 0) {
			if (count(array_unique($charsetVals)) != 1) {
				Report::addReport(
					'rep_charset_bom_diff_encoding',
					$category, REPORT_LEVEL_ERROR, 
					lang('rep_charset_bom_diff_encoding'),
					lang('rep_charset_bom_diff_encoding_expl', Language::format($codes, LANG_FORMAT_OL_CODE)),
					lang('rep_charset_bom_diff_encoding_todo'),
					lang('rep_charset_bom_diff_encoding_link')
				);
			}
		}
		
		// CHARSET REPORT: No charset declaration in the document
		$inDocCharsets = array_merge(
			(array) Information::getValues('charset_bom'),
			(array) Information::getValues('charset_xml'),
			(array) Information::getValues('charset_meta')
		);
		$inDocCharsets = 
			array_filter($inDocCharsets, function ($array) {
				if ($array['values'] != null && !empty($array['values']))
					return true;
				return false;
			});
		#if ($debug) { echo "\n".'$inDocCharsets'."\n"; print_r($inDocCharsets); }
		if (!empty($charsetVals) && empty($inDocCharsets)) {
			Report::addReport(
				'rep_charset_no_in_doc',
				$category, REPORT_LEVEL_WARNING,
				lang('rep_charset_no_in_doc'),
				lang('rep_charset_no_in_doc_expl', Information::get('charset_http')->values[0]['code']),
				lang('rep_charset_no_in_doc_todo'),
				lang('rep_charset_no_in_doc_link')
			);
		}
		
		// CHARSET REPORT: No visible in-document encoding specified
		$inDocCharsets = array_merge(
			(array) Information::getValues('charset_xml'),
			(array) Information::getValues('charset_meta')
			);
		$inDocCharsets = 
			array_filter($inDocCharsets, function ($array) {
				if ($array['values'] != null && !empty($array['values']))
					return true;
				return false;
				});
		#if ($debug) { echo "\n".'$inDocCharsets'."\n"; print_r($inDocCharsets); }
		#if ($debug) { echo "\n".'Information::getFirstVal("charset_bom")'."\n"; print_r(Information::getFirstVal('charset_bom')); }
		if (Information::getFirstVal('charset_bom') != null && empty($inDocCharsets)) {
//			if ((($this->doc->isHTML5 || $this->doc->isHTML5) && Information::getFirstVal('charset_bom') == 'UTF-8') || 
//				($this->doc->isHTML || $this->doc->isXHTML10 || $this->doc->isXHTML11)) {
			if ( Information::getFirstVal('charset_bom') == 'UTF-8' ) {
				Report::addReport(
					'rep_charset_no_visible_charset',
					$category, REPORT_LEVEL_WARNING,
					lang('rep_charset_no_visible_charset'),
					lang('rep_charset_no_visible_charset_expl', htmlspecialchars(Information::get('charset_http')->values[0]['code'])),
					lang('rep_charset_no_visible_charset_todo'),
					lang('rep_charset_no_visible_charset_link')
				);
			}
		}
		
		// CHARSET REPORT: No effective character encoding information
		$inDocCharsets = array_merge(
			(array) Information::getValues('charset_http'),
			(array) Information::getValues('charset_bom'),
			(array) Information::getValues('charset_meta')
		);
		$inDocCharsets = 
			array_filter($inDocCharsets, function ($array) {
				if ($array['values'] != null && !empty($array['values']))
					return true;
				return false;
			});
		#if ($debug) { echo "<p>n".'$inDocCharsets'."</p>"; print_r($inDocCharsets); }
		#if ($debug) { echo "<p>".'Information::getFirstVal("charset_bom")'."</p><pre>"; print_r(Information::getFirstVal('charset_bom')); echo "</pre>"; }
		if (Information::getFirstVal('charset_xml') != null && empty($inDocCharsets)) {
			if ($this->doc->isHTML5 || $this->doc->isHTML || ($this->doc->isXHTML10 && ! $this->doc->isServedAsXML)) {
				#if ($debug) { echo "<p>YES</p>"; }
				Report::addReport(
					'rep_charset_no_effective_charset',
					$category, REPORT_LEVEL_ERROR,
					lang('rep_charset_no_effective_charset'),
					lang('rep_charset_no_effective_charset_expl', Language::format(Utils::codesFromValArray(Information::getValues('charset_xml')), LANG_FORMAT_OL_CODE)),
					lang('rep_charset_no_effective_charset_todo'),
					lang('rep_charset_no_effective_charset_link')
				);
			}
		}
		
		// CHARSET REPORT: BOM in content
		// /!\ In the following line is the invisible BOM.
		if (preg_match('/﻿/', substr($this->markup,3))) {
			Report::addReport(
				'rep_charset_bom_in_content',
				$category, REPORT_LEVEL_WARNING, 
				lang('rep_charset_bom_in_content'),
				lang('rep_charset_bom_in_content_expl'),
				lang('rep_charset_bom_in_content_todo'),
				lang('rep_charset_bom_in_content_link')
			);
		}

		// CHARSET REPORT: Meta character encoding declaration used in UTF-16 page
		// CHARSET REPORT: UTF-16 encoding declaration in a non-UTF-16 document
		if (strtoupper(Information::getFirstVal('charset_meta')) == "UTF-16") {
			// check whether this is a UTF-16 encoded file
			if ($this->isUTF16) {
				if ($this->doc->isHTML5) { // disallow meta for html5
					Report::addReport(
						'rep_charset_utf16_meta',
						$category, REPORT_LEVEL_ERROR,
						lang('rep_charset_utf16_meta'),
						lang('rep_charset_utf16_meta_expl', Language::format(Utils::codesFromValArray(Information::getValues('charset_meta')), LANG_FORMAT_OL_CODE)),
						lang('rep_charset_utf16_meta_todo'),
						lang('rep_charset_utf16_meta_link')
						);
					}
				}
				
			else {
				Report::addReport(
					'rep_charset_bogus_utf16',
					$category, REPORT_LEVEL_ERROR,
					lang('rep_charset_bogus_utf16'),
					lang('rep_charset_bogus_utf16_expl', Language::format(Utils::codesFromValArray(Information::getValues('charset_meta')), LANG_FORMAT_OL_CODE)),
					lang('rep_charset_bogus_utf16_todo'),
					lang('rep_charset_bogus_utf16_link')
					);
				}
			}


		// CHARSET REPORT: UTF-16LE or UTF-16BE found in a character encoding declaration
		$nonBomCharsets = array_merge(
			(array) Information::getValues('charset_http'),
			(array) Information::getValues('charset_xml'),
			(array) Information::getValues('charset_meta')
			);
		$nonBomCharsets = array_filter($nonBomCharsets, array($this,"hasValue"));
		$found = false;
		foreach ($nonBomCharsets as $item) {
			if (strtoupper($item['values']) == 'UTF-16LE' || strtoupper($item['values']) == 'UTF-16BE') { $found = true; }
			}
		if ($found) {
			Report::addReport(
				'rep_charset_utf16lebe',
				$category, REPORT_LEVEL_ERROR,
				lang('rep_charset_utf16lebe'),
				lang('rep_charset_utf16lebe_expl', Language::format(Utils::codesFromValArray($nonBomCharsets), LANG_FORMAT_OL_CODE)),
				lang('rep_charset_utf16lebe_todo'),
				lang('rep_charset_utf16lebe_link')
				);
			}


		// CHARSET REPORT: Meta character encoding declaration not within 1024 byte of file start
		if (! $this->doc->isServedAsXML && Information::getFirstVal('charset_meta') != null) {
			if (!preg_match("/<meta\s[^>]*http-equiv=[\"\']?Content-Type[^>]*>/i", substr($this->markup,0,1024)) &&
				!preg_match("/<meta\s[^>]*charset=[^>]*>/i", substr($this->markup,0,1024))) { 
				Report::addReport(
					'rep_charset_1024_limit',
					$category, REPORT_LEVEL_ERROR,
					lang('rep_charset_1024_limit'),
					lang('rep_charset_1024_limit_expl', Language::format(Utils::codesFromValArray(Information::getValues('charset_meta')), LANG_FORMAT_OL_CODE)),
					lang('rep_charset_1024_limit_todo'),
					lang('rep_charset_1024_limit_link')
					);
				}
			}

		// CHARSET REPORT: charset attribute used on a or link elements
		$tagArray = array();
		$tags = $this->doc->getElementsByTagName('link');
		if ($tags->length > 0) {
			foreach ($tags as $tag) {
				if ($tag->attributes->getNamedItem('charset') != null) {
					$tagArray[] = $this->doc->dumpTag($tag);
					}
				}
			}
		$tags = $this->doc->getElementsByTagName('a');
		if ($tags->length > 0) {
			foreach ($tags as $tag) {
				if ($tag->attributes->getNamedItem('charset') != null) {
					$tagArray[] = $this->doc->dumpTag($tag);
					}
				}
			}
		//if ($this->doc->isHTML5) { $report_level = REPORT_LEVEL_ERROR; } else { $report_level = REPORT_LEVEL_WARNING; } 
		if (count($tagArray) > 0) {
			Report::addReport(
				'rep_charset_charset_attr',
				'markup_category', REPORT_LEVEL_ERROR, 
				lang('rep_charset_charset_attr'),
				lang('rep_charset_charset_attr_expl', Language::format($tagArray, LANG_FORMAT_OL_CODE)),
				lang('rep_charset_charset_attr_todo'),
				lang('rep_charset_charset_attr_link')
				);
			}
		

	} 
	
	
	private function hasValue ($array) {
		// used by array_filter to filter out value_code pairs from a list when there is no value
		// array: an array of items, each containing value and code items
		return ($array['values'] != null && !empty($array['values']));
		}
		
	private function getFirstCVP ($array) {
		// returns the first code-value pair from an array of code-value pairs
		if (count($array) > 0) { return $array[0]; }
		else return null;
		}
	
	private function metaType ($array) {
		// returns a string to indicate whether a meta declaration uses http-equiv or charset attribute
		// array: a single value+code item
		if (preg_match("/http-equiv=/i", $array['code'])) { return 'http-equiv'; }
		else { return 'charset'; }
		}
		
	
	private function addReportLanguages() {
		$category = 'lang_category';
		
		// Attributes on the html tag
		$langAttr = $this->doc->getHTMLTagAttr('lang');
		$xmlLangAttr = $this->doc->getHTMLTagAttr('lang', true);
		// Attributes on all nodes including html tag
		$htmlLangAttrs = $this->doc->getNodesWithAttr('lang');
		$xmlLangAttrs = $this->doc->getNodesWithAttr('lang', true);
		// Only the tag dumps of nodes containing (xml:)lang
		$htmlLangCodes = Utils::codesFromValArray($htmlLangAttrs);
		$xmlLangCodes = Utils::codesFromValArray($xmlLangAttrs);

//$debug = true;
/*
if ($debug) { 
	echo "<p>".'$langAttr'."</p>"; 
	echo "<pre>"; print_r($langAttr); echo "</pre>";
	echo "<p>".'$xmlLangAttr'."</p>"; 
	echo "<pre>"; print_r($xmlLangAttr);  echo "</pre>";
	echo "<p>".'$htmlLangAttrs'."</p>"; 
	echo "<pre>"; print_r($htmlLangAttrs);  echo "</pre>";
	echo "<p>".'$xmlLangAttrs'."</p>"; 
	echo "<pre>"; print_r($xmlLangAttrs);  echo "</pre>";
	echo "<p>".'$htmlLangCodes'."</p>"; 
	echo "<pre>"; print_r($htmlLangCodes);  echo "</pre>";
	echo "<p>".'$xmlLangCodes'."</p>"; 
	echo "<pre>"; print_r($xmlLangCodes);  echo "</pre>";
	}
*/
		
		// LANG REPORT: Content-Language meta element
		if (!Utils::_empty($this->doc->getMetaContentLanguage())) {
			Report::addReport(
				'rep_lang_content_lang_meta',
				$category, REPORT_LEVEL_ERROR,
				lang('rep_lang_content_lang_meta'),
				lang('rep_lang_content_lang_meta_expl', Language::format(Information::$infos['lang_meta']->values[0]['code'], LANG_FORMAT_OL_CODE)), // TODO review this after refactoring of Information
				lang('rep_lang_content_lang_meta_todo'),
				lang('rep_lang_content_lang_meta_link')
				);
			}

		// WARNING: The html tag has no language attribute
		if ($langAttr == null && $xmlLangAttr == null) {
			if ($this->doc->isHTML || $this->doc->isHTML5) { $_todo = 'rep_lang_no_lang_attr_todo_html'; }
			else if ($this->doc->isXHTML10 && ! $this->doc->isServedAsXML) { $_todo = 'rep_lang_no_lang_attr_todo_xhtml'; }
			else { $_todo = 'rep_lang_no_lang_attr_todo_xml'; }
			Report::addReport(
				'rep_lang_no_lang_attr',
				$category, REPORT_LEVEL_WARNING,
				lang('rep_lang_no_lang_attr'),
				lang('rep_lang_no_lang_attr_expl', htmlspecialchars($this->doc->HTMLTag())),
				lang($_todo),
				lang('rep_lang_no_lang_attr_link')
				);
			}

		// WARNING: The html tag has no effective language declaration
		if ($langAttr == null && $xmlLangAttr != null) {
			if ($this->doc->isHTML || $this->doc->isHTML5 || ($this->doc->isXHTML10 && !$this->doc->isServedAsXML)) {
				if ($this->doc->isXHTML10) { $_todo = 'rep_lang_html_no_effective_lang_todo_xhtml'; }
				else { $_todo = 'rep_lang_html_no_effective_lang_todo_html'; }
				Report::addReport(
					'rep_lang_html_no_effective_lang',
					$category, REPORT_LEVEL_WARNING,
					lang('rep_lang_html_no_effective_lang'),
					lang('rep_lang_html_no_effective_lang_expl', Language::format(Utils::codesFromValArray(Information::getValues('lang_attr_lang')), LANG_FORMAT_OL_CODE)),
					lang($_todo),
					lang('rep_lang_html_no_effective_lang_link')
					);
				}
			}
		if ($xmlLangAttr == null && $langAttr != null) {
			if ($this->doc->isServedAsXML) {
				Report::addReport(
					'rep_lang_html_no_effective_lang',
					$category, REPORT_LEVEL_WARNING,
					lang('rep_lang_html_no_effective_lang'),
					lang('rep_lang_html_no_effective_lang_expl', Language::format(Utils::codesFromValArray(Information::getValues('lang_attr_lang')), LANG_FORMAT_OL_CODE)),
					lang('rep_lang_html_no_effective_lang_todo_xml'),
					lang('rep_lang_html_no_effective_lang_link')
					);
				}
			}
		
		// WARNING: This HTML file contains xml:lang attributes
		// Removing this, since we are checking HTML4 files in the same way as HTML5 now
		// Haven't removed the messages yet
		//if ($this->doc->isHTML && $xmlLangAttrs != null) {
		//	Report::addReport(
		//		'rep_lang_xml_attr_in_html',
		//		$category, REPORT_LEVEL_ERROR, 
		//		lang('rep_lang_xml_attr_in_html'),
		//		lang('rep_lang_xml_attr_in_html_expl', Language::format($xmlLangCodes, LANG_FORMAT_OL_CODE)),
		//		lang('rep_lang_xml_attr_in_html_todo'),
		//		lang('rep_lang_xml_attr_in_html_link')
		//	);
		//}  

		// WARNING: A tag uses a lang attribute without an associated xml:lang attribute
		if ($this->doc->isXHTML10 || $this->doc->isXHTML11) {
			if (($diff = Utils::diffArray($htmlLangCodes, $xmlLangCodes)) != null) {
				if ($this->doc->isServedAsXML) { 
					$_reportlevel = REPORT_LEVEL_ERROR;
					$_expl = 'rep_lang_missing_xml_attr_expl_xml'; 
					$_todo = 'rep_lang_missing_xml_attr_todo_xml'; 
					}
				else { 
					$_reportlevel = REPORT_LEVEL_WARNING; 
					$_expl = 'rep_lang_missing_xml_attr_expl_xhtml'; 
					$_todo = 'rep_lang_missing_xml_attr_todo_xhtml'; 
					}
				Report::addReport(
					'rep_lang_missing_xml_attr',
					$category, $_reportlevel, 
					lang('rep_lang_missing_xml_attr'),
					lang($_expl, Language::format($diff, LANG_FORMAT_OL_CODE)),
					lang($_todo),
					lang('rep_lang_missing_attr_link')
				);
			}
		}

		// WARNING: A tag uses an xml:lang attribute without an associated lang attribute
		//if (($this->doc->isXHTML10 && !$this->doc->isServedAsXML) || $this->doc->isHTML5) {
		if (! $this->doc->isServedAsXML) {
			if (($diff = Utils::diffArray($xmlLangCodes, $htmlLangCodes)) != null) {
				if (! $this->doc->isXHTML10) { $_todostring = 'rep_lang_missing_html_attr_todo_html'; } 
				else { $_todostring = 'rep_lang_missing_html_attr_todo_xhtml'; } 
				if (! $this->doc->isXHTML10) { $_explstring = 'rep_lang_missing_html_attr_expl_html'; } 
				else { $_explstring = 'rep_lang_missing_html_attr_expl_xhtml'; } 
				Report::addReport(
					'rep_lang_missing_html_attr',
					$category, REPORT_LEVEL_ERROR, 
					lang('rep_lang_missing_html_attr'),
					lang($_explstring, Language::format($diff, LANG_FORMAT_OL_CODE)),
					lang($_todostring),
					lang('rep_lang_missing_attr_link')
				);
			}
		}
		
		// WARNING: A language attribute value was incorrectly formed.
		$malformedAttrs = array_filter(array_merge((array) $htmlLangAttrs, (array) $xmlLangAttrs), function ($element) {
			foreach ((array) $element['values'] as $val)
				if (preg_match("/^[a-zA-Z0-9]*[^a-zA-Z0-9\-]+[a-zA-Z0-9]*$/", $val))
					return true; // keep only those that do not match
				return false;
			});
		if ($malformedAttrs != null) {
			Report::addReport(
				'rep_lang_malformed_attr',
				$category, REPORT_LEVEL_ERROR, 
				lang('rep_lang_malformed_attr'),
				lang('rep_lang_malformed_attr_expl', Language::format(array_unique(Utils::codesFromValArray($malformedAttrs)), LANG_FORMAT_OL_CODE)),
				lang('rep_lang_malformed_attr_todo'),
				lang('rep_lang_malformed_attr_link')
			);
		}
		
		// ERROR: A lang attribute value did not match an xml:lang value when they appeared together on the same tag.
		$nonMatchingAttrs = array();
		if (count($htmlLangAttrs) > 0)
			array_walk($htmlLangAttrs, function (&$valArray, $key) use (&$xmlLangAttrs, &$nonMatchingAttrs) {
				$code = $valArray['code'];
				if (($el = Utils::findCodeIn($code, $xmlLangAttrs)) != null) {
					if ($el['values'] != $valArray['values']) {
						$nonMatchingAttrs[] = $code;
					}
				}
			});
		if (count($nonMatchingAttrs) > 0) {
			Report::addReport(
				'rep_lang_conflict',
				$category, REPORT_LEVEL_ERROR, 
				lang('rep_lang_conflict'),
				lang('rep_lang_conflict_expl', Language::format($nonMatchingAttrs, LANG_FORMAT_OL_CODE)),
				lang('rep_lang_conflict_todo'),
				lang('rep_lang_conflict_link')
			);
		}
	}
	
	private function addReportDirValues() {
		
		// ERROR: Incorrect values used for dir attribute
		$dirNodes = $this->doc->getNodesWithAttr('dir');
		$isXML = $this->doc->isServedAsXML;
		if (count($dirNodes) > 0) {
			$invalidDirNodes = array_filter($dirNodes, function ($array) use ($isXML) {
				if (is_array($array['values'])) { $array['values'] = implode(' ',$array['values']); }
				if (! $isXML) { $array['values'] = strtolower($array['values']); }
				if ($array['values']=='rtl' || $array['values']=='ltr' || $array['values']=='auto') { return false; }
				return true;
				});
			if (count($invalidDirNodes) > 0)
				Report::addReport(
					'rep_markup_dir_incorrect',
					'dir_category', REPORT_LEVEL_ERROR, 
					lang('rep_markup_dir_incorrect'),
					lang('rep_markup_dir_incorrect_expl_html', Language::format(Utils::codesFromValArray($invalidDirNodes), LANG_FORMAT_OL_CODE)),
					lang('rep_markup_dir_incorrect_todo'),
					lang('rep_markup_dir_incorrect_link')
				);
		}
		
	}
	
	private function addReportMisc() {
		// WARNING: are there non-NFC class or id names?
		$nonNFCs = Information::getValues('classId_non_nfc');
		if (count($nonNFCs) > 0) {
			Report::addReport(
				'rep_latin_non_nfc',
				'nonLatin_category', REPORT_LEVEL_WARNING, 
				lang('rep_latin_non_nfc'),
				lang('rep_latin_non_nfc_expl', count($nonNFCs), Language::format(Utils::codesFromValArray($nonNFCs), LANG_FORMAT_OL_CODE)),
				lang('rep_latin_non_nfc_todo'),
				lang('rep_latin_non_nfc_link')
			);
		}
		
		// INFO: <b> tags found in source
		$bTags = $this->doc->getElementsByTagName('b');
		$count = 0;
		if ($bTags->length > 0) {
			foreach ($bTags as $bTag) {
				if (! $bTag->hasAttributes() || $bTag->attributes->getNamedItem('class') == null) {
					$count++;
				}
			}
			if ($count > 0)
				Report::addReport(
					'rep_markup_tags_no_class_b',
					'markup_category', REPORT_LEVEL_INFO, 
					lang('rep_markup_tags_no_class_b', 'b'),
					lang('rep_markup_tags_no_class_b_expl', 'b', $bTags->length, $count),
					lang('rep_markup_tags_no_class_b_todo', 'b'),
					lang('rep_markup_tags_no_class_b_link')
				);
		}
		
		// INFO: <i> tags found in source
		$iTags = $this->doc->getElementsByTagName('i');
		$count = 0;
		if ($iTags->length > 0) {
			foreach ($iTags as $iTag) {
				if (! $iTag->hasAttributes() || $iTag->attributes->getNamedItem('class') == null) {
					$count++;
				}
			}
			if ($count > 0)
				Report::addReport(
					'rep_markup_tags_no_class_i',
					'markup_category', REPORT_LEVEL_INFO, 
					lang('rep_markup_tags_no_class_i', 'i'),
					lang('rep_markup_tags_no_class_i_expl', 'i', $iTags->length, $count),
					lang('rep_markup_tags_no_class_i_todo', 'i'),
					lang('rep_markup_tags_no_class_i_link')
				);
		}

		// ERROR: <bdo> tag without dir
		$foundTags = $this->doc->getElementsByTagName('bdo');
		$count = 0;
		if ($foundTags->length > 0) {
			foreach ($foundTags as $tag) {
				if (! $tag->hasAttributes() || $tag->attributes->getNamedItem('dir') == null) {
					$count++;
				}
			}
			if ($count > 0)
				Report::addReport(
					'rep_markup_bdo_no_dir',
					'markup_category', REPORT_LEVEL_ERROR, 
					lang('rep_markup_bdo_no_dir', 'b'),
					lang('rep_markup_bdo_no_dir_expl', 'b', $foundTags->length, $count),
					lang('rep_markup_bdo_no_dir_todo', 'b'),
					lang('rep_markup_bdo_no_dir_link')
				);
		}

		// ERROR: <bdo> tag with dir=auto
		$foundTags = $this->doc->getElementsByTagName('bdo'); 
		$count = 0;
		if ($foundTags->length > 0) {
			foreach ($foundTags as $tag) { 
				if ($tag->hasAttributes() && $tag->attributes->getNamedItem('dir')->value == "auto") {
					$count++;
				}
			}
			if ($count > 0)
				Report::addReport(
					'rep_markup_bdo_auto',
					'markup_category', REPORT_LEVEL_ERROR, 
					lang('rep_markup_bdo_auto', 'b'),
					lang('rep_markup_bdo_auto_expl', 'b', $foundTags->length, $count),
					lang('rep_markup_bdo_auto_todo', 'b'),
					lang('rep_markup_bdo_auto_link')
				);
		}


		//if (preg_match_all('/\u200E|\u200F|\u202A|\u202B|\u202C|\u202D|\u202E|\u2066|\u2067|\u2068|\u2069/', $this->markup, $foundEntities)) {
		//	print_r($foundEntities);
		//	}
		
		//if (preg_match_all('/‎|‏|‪|‫|‬|‭|‮|⁦|⁧|⁨|⁩/', $this->markup, $foundEntities)) {
		//	print_r($foundEntities);
		//	}
		
		
		// ERROR: Invalid named character references for directional controls
		if (preg_match_all('/(&lre;)|(&rle;)|(&pdf;)|(&rli;)|(&lri;)|(&fsi;)|(&pdi;)/i', $this->markup, $foundEntities)) {
			$resultStr = '';
			$entityList = array_count_values($foundEntities[0]);
			foreach ($entityList as $key => $val) {
				$resultStr .= '&amp;'.substr($key,1,3).'; ('.$val.') &#xA0; ';
				}
			if ($resultStr)
				Report::addReport(
					'rep_markup_bogus_dir_entities',
					'markup_category', REPORT_LEVEL_ERROR, 
					lang('rep_markup_bogus_dir_entities'),
					lang('rep_markup_bogus_dir_entities_expl', $resultStr),
					lang('rep_markup_bogus_dir_entities_todo'),
					lang('rep_markup_bogus_dir_entities_link')
				);
			}
		
	
		
	}
}

Checker::_init();