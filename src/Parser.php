<?php
/**
 * Class Parser
 *
 * @filesource   Parser.php
 * @created      18.09.2015
 * @package      chillerlan\bbcode
 * @author       Smiley <smiley@chillerlan.net>
 * @copyright    2015 Smiley
 * @license      MIT
 */

namespace chillerlan\bbcode;

use chillerlan\bbcode\BBCodeException;
use chillerlan\bbcode\ParserExtension;
use chillerlan\bbcode\ParserExtensionInterface;
use chillerlan\bbcode\Modules\ModuleInterface;
use chillerlan\bbcode\BBTemp;
use chillerlan\bbcode\Modules\BaseModule;
use chillerlan\bbcode\Modules\BaseModuleInterface;

/**
 * Regexp BBCode parser
 *
 * idea and regexes from developers-guide.net years ago
 * rewritten to reduce memory consumption and limit nesting ;)
 *
 * @link http://www.developers-guide.net/c/152-bbcode-parser-mit-noparse-tag-selbst-gemacht.html
 */
class Parser{

	/**
	 * @var \chillerlan\bbcode\ParserOptions
	 * @see \chillerlan\bbcode\Modules\Tagmap::$options
	 */
	protected $options;

	/**
	 * map of Tag -> Module
	 *
	 * @var array
	 */
	protected $tagmap = [];

	/**
	 * @var array
	 * @see \chillerlan\bbcode\Modules\Tagmap::$noparse_tags
	 */
	protected $noparse_tags = [];

	/**
	 * holds an array of allowed tags
	 *
	 * @todo: allowed tags -> options
	 *
	 * @var array
	 * @see \chillerlan\bbcode\Modules\Tagmap::$allowed_tags
	 */
	protected $allowed_tags = [];

	/**
	 * @var \chillerlan\bbcode\ParserExtensionInterface
	 */
	private $parser_extension;

	/**
	 * @var \chillerlan\bbcode\Modules\BaseModuleInterface
	 */
	private $base_module;

	/**
	 * holds the current encoder module
	 *
	 * @var \chillerlan\bbcode\Modules\ModuleInterface
	 */
	private $module;

	/**
	 * Holds an array of encoder module instances
	 *
	 * @var array
	 * @see \chillerlan\bbcode\Modules\ModuleInfo::$modules
	 */
	private $modules = [];

	/**
	 * @param \chillerlan\bbcode\ParserOptions $options
	 *
	 * @throws \chillerlan\bbcode\BBCodeException
	 */
	public function __construct(ParserOptions $options){
		$this->base_module = $this->_load($options->base_module, __NAMESPACE__.'\\Modules\\BaseModuleInterface');

		if($options->parser_extension){
			$this->parser_extension = $this->_load($options->parser_extension, __NAMESPACE__.'\\ParserExtensionInterface');
		}

		/** @var $module_info \chillerlan\bbcode\Modules\ModuleInfo */
		$module_info = $this->base_module->get_info();
		$singletags = [];
		foreach($module_info->modules as $module){
			$this->module = $this->_load($module, __NAMESPACE__.'\\Modules\\ModuleInterface');

			$tagmap = $this->module->get_tags();
			foreach($tagmap->tags as $tag){
				$this->tagmap[$tag] = $module;
			}

			$this->modules[$module] = $this->module;
			$this->noparse_tags = array_merge($this->noparse_tags, $tagmap->noparse_tags);
			$singletags = array_merge($singletags, $tagmap->singletags);
		}

		$this->options = $options;
		$this->options->eol_token = $module_info->eol_token;
		$this->options->singletags = implode('|', $singletags);
	}

	/**
	 * @param string $class
	 * @param string $type
	 *
	 * @return bool
	 * @throws \chillerlan\bbcode\BBCodeException
	 */
	private function _load($class, $type){
		if(class_exists($class)){
			$object = new $class;
			if(!is_a($object, $type)){
				throw new BBCodeException(get_class($object).' is not of type '.$type);
			}
			return $object;
		}
		else{
			throw new BBCodeException($class.' doesn\'t exist.');
		}
	}

	/**
	 * returns the current tagmap
	 *
	 * @return array
	 */
	public function get_tagmap(){
		ksort($this->tagmap);
		return $this->tagmap;
	}

	/**
	 * returns the noparse tags
	 *
	 * @return array
	 */
	public function get_noparse_tags(){
		return $this->noparse_tags;
	}

	/**
	 * Encodes a BBCode string to HTML (or whatevs)
	 *
	 * @param string $bbcode
	 *
	 * @return string
	 * @throws \chillerlan\bbcode\BBCodeException
	 */
	public function parse($bbcode){
		if($this->options->sanitize){
			$bbcode = $this->base_module->sanitize($bbcode);
		}

		$bbcode = $this->parser_extension->pre($bbcode);
		$bbcode = preg_replace('#\[('.$this->options->singletags.')((?:\s|=)[^]]*)?]#is', '[$1$2]*[/$1]', $bbcode);
		$bbcode = str_replace(["\r", "\n"], ['', $this->options->eol_placeholder], $bbcode);
		$bbcode = $this->_parse($bbcode);
		$bbcode = $this->parser_extension->post($bbcode);
		$bbcode = str_replace($this->options->eol_placeholder, $this->options->eol_token, $bbcode);

		return $bbcode;
	}

	/**
	 * strng regexp bbcode killer
	 *
	 * @param string|array $bbcode BBCode as string or matches as array - callback from preg_replace_callback()
	 *
	 * @return array|string
	 * @throws \chillerlan\bbcode\BBCodeException
	 */
	private function _parse($bbcode){
		static $callback_count = 0;
		$callback = false;
		$preg_error = PREG_NO_ERROR;

		if(is_array($bbcode) && isset($bbcode['tag'], $bbcode['attributes'], $bbcode['content'])){
			$tag = strtolower($bbcode['tag']);
			$attributes = $this->get_attributes($bbcode['attributes']);
			$content = $bbcode['content'];

			$callback = true;
			$callback_count++;
		}
		else if(is_string($bbcode) && !empty($bbcode)){
			$tag = null;
			$attributes = [];
			$content = $bbcode;
		}
		else{
			return '';
		}

		if(!empty($content) && $callback_count < (int)$this->options->nesting_limit && !in_array($tag, $this->noparse_tags)){
			$pattern = '#\[(?P<tag>\w+)(?P<attributes>(?:\s|=)[^]]*)?](?P<content>(?:[^[]|\[(?!/?\1((?:\s|=)[^]]*)?])|(?R))*)\[/\1]#';
			$content = preg_replace_callback($pattern, __METHOD__, $content);
			$preg_error = preg_last_error();
		}

		if($preg_error !== PREG_NO_ERROR){
			// still testing...
			$err = [
				PREG_INTERNAL_ERROR        => 'PREG_INTERNAL_ERROR',
				PREG_BACKTRACK_LIMIT_ERROR => 'PREG_BACKTRACK_LIMIT_ERROR',
				PREG_RECURSION_LIMIT_ERROR => 'PREG_RECURSION_LIMIT_ERROR',
				PREG_BAD_UTF8_ERROR        => 'PREG_BAD_UTF8_ERROR',
				PREG_BAD_UTF8_OFFSET_ERROR => 'PREG_BAD_UTF8_OFFSET_ERROR',
				6                          => 'PREG_JIT_STACKLIMIT_ERROR', // int to prevent a notice in php 5
			][$preg_error];

			throw new BBCodeException('preg_replace_callback() died due to a '.$err.' ('.$preg_error.')'.PHP_EOL.$content);
		}

		if($callback && isset($this->tagmap[$tag])){
			$bbtemp = new BBTemp;
			$bbtemp->tag = $tag;
			$bbtemp->attributes = $attributes;
			$bbtemp->content = $content;
			$bbtemp->options = $this->options;

			$this->module = $this->modules[$this->tagmap[$tag]];
			$this->module->set_bbtemp($bbtemp);
			$content = $this->module->transform();
		}

		$callback_count = 0;

		return $content;
	}

	/**
	 * The attributes parser
	 *
	 * @todo recognize attributes without value
	 * @param string $attributes
	 *
	 * @return array
	 */
	private function get_attributes($attributes){
		$attr = [];
		$pattern = '#(?P<name>^|\w+)\=(\'?)(?P<value>[^\']*?)\2(?: |$)#';

		if(preg_match_all($pattern, $attributes, $matches, PREG_SET_ORDER) > 0){
			foreach($matches as $attribute){
				$name = empty($attribute['name']) ? $this->options->bbtag_placeholder : strtolower(trim($attribute['name']));

				$value = trim($attribute['value']);
				$value = $this->base_module->sanitize($value);

				$attr[$name] = $value;
			}
		}

		return $attr;
	}

}
