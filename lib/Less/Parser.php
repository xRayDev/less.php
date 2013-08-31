<?php


class Less_Parser{


    private $input;		// LeSS input string
    private $pos;		// current index in `input`
    private $memo;		// temporarily holds `i`, when backtracking


	/**
	 * @var string
	 *
	 */
    private $current;

    /**
     * @var string
     */
    private $path;

	/**
	 * @var array
	 */
	static $import_dirs = array();

    /**
     * @var string
     */
    private $filename;

    /**
     * @var string
     */
    private $css;

    /**
     *
     */
    static public $version = '1.4.2';
    static public $less_version = '1.4.2';

    /**
     * @var Less_Environment
     */
    private $env;

	private static $imports = array();

    /**
     * @param Environment|null $env
     */
    public function __construct( $env = null ){

		// Top parser on an import tree must be sure there is one "env"
		// which will then be passed around by reference.
		if( $env instanceof Less_Environment ){
			$this->env = $env;
		}else{
			$this->env = new Less_Environment( $env );
			self::$imports = array();
			self::$import_dirs = array();
		}

		$this->css = '';
		$this->pos = 0;
    }



    /**
     * Get the current parser environment
     *
     * @return Environment
     */
    public function getEnvironment()
    {
        return $this->env;
    }

    /**
     * Set the current parser environment
     *
     * @param Less_Envronment $env
     * @return void
     */
    public function setEnvironment(Less_Envronment $env)
    {
        $this->env = $env;
    }

    /**
     * Get the current css buffer
     *
     * @return string
     */
    public function getCss()
    {
        return $this->css;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->css;
    }

    /**
     * Clear the css buffer
     *
     * @return void
     */
    public function clearCss()
    {
        $this->css = '';
    }

    /**
     * Parse a Less string into css
     *
     * @param string $str The string to convert
     * @param bool $returnRoot Indicates whether the return value should be a css string a root node
     * @return Less_Tree_Ruleset|Less_Parser
     */
    public function parse($str, $returnRoot = false){
        $this->pos = 0;
        $this->input = preg_replace('/\r\n/', "\n", $str);

        // Remove potential UTF Byte Order Mark
        //$this->input = preg_replace('/^\uFEFF/', '', $this->input);
        $this->input = preg_replace('/^\\357\\273\\277/um', '', $this->input);

        $root = new Less_Tree_Ruleset(null, $this->match('parsePrimary'));
        $root->root = true;

		//$importVisitor = new Less_importVisitor();
		//$importVisitor->run($root);

        if ($returnRoot) {
            return $root;
        } else {

			$evaldRoot = $root->compile($this->env);

			$joinSelector = new Less_joinSelectorVisitor();
			$joinSelector->run($evaldRoot);

			$extendsVisitor = new Less_processExtendsVisitor();
			$extendsVisitor->run($evaldRoot);

            $this->css = $evaldRoot->toCSS($this->env);

			if( $this->env->compress ){
				$this->css = preg_replace('/(\s)+/',"$1", $this->css);
			}

            return $this;
        }
    }

    /**
     * Parse a Less string from a given file
     *
     * @throws Less_ParserException
     * @param $filename The file to parse
     * @param $uri_root The url of the file
     * @param bool $returnRoot Indicates whether the return value should be a css string a root node
     * @return Less_Tree_Ruleset|Less_Parser
     */
	public function parseFile($filename, $uri_root = '', $returnRoot = false){

		if( !file_exists($filename) ){
			throw new Less_ParserException(sprintf('File `%s` not found.', $filename));
		}

		$previousFileInfo = $this->env->currentFileInfo;
		$this->SetFileInfo($filename, $uri_root);
		$previousImportDirs = self::$import_dirs;
		self::AddParsedFile($filename);

		$return = $this->parse(file_get_contents($filename), $returnRoot);

		$this->env->currentFileInfo = $previousFileInfo;
		self::$import_dirs = $previousImportDirs;

		return $return;
	}

	public function SetFileInfo( $filename, $uri_root = ''){

		$this->path = pathinfo($filename, PATHINFO_DIRNAME);
		$this->filename = realpath($filename);

		$dirname = preg_replace('/[^\/\\\\]*$/','',$this->filename);


		$currentFileInfo = array();
		$currentFileInfo['currentDirectory'] = $dirname;
		$currentFileInfo['filename'] = $filename;
		$currentFileInfo['rootpath'] = $dirname;
		$currentFileInfo['entryPath'] = $dirname;

		if( empty($uri_root) ){
			$currentFileInfo['uri_root'] = $uri_root;
		}else{
			$currentFileInfo['uri_root'] = rtrim($uri_root,'/').'/';
		}

		$this->env->currentFileInfo = $currentFileInfo;

		self::$import_dirs = array_merge( array( $dirname => $currentFileInfo['uri_root'] ), self::$import_dirs );
	}

	public function SetImportDirs( $dirs ){
		foreach($dirs as $path => $uri_root){
			if( !empty($path) ){
				$path = rtrim($path,'/').'/';
			}
			if( !empty($uri_root) ){
				$uri_root = rtrim($uri_root,'/').'/';
			}
			self::$import_dirs[$path] = $uri_root;
		}
	}

	static function AddParsedFile($file){
		self::$imports[] = $file;
	}

	static function AllParsedFiles(){
		return self::$imports;
	}

	static function FileParsed($file){
		return in_array($file,self::$imports);
	}


    function save() {
        $this->memo = $this->pos;
	}

    function restore() {
        $this->pos = $this->memo;
	}

    /**
     * Update $this->current to reflect $this->input from the $this->pos
     *
     * @return void
     */
    public function sync(){
        $this->current = substr($this->input, $this->pos);
    }

    function isWhitespace($offset = 0) {
		return ctype_space($this->input[ $this->pos + $offset]);
    }

    /**
     * Parse from a token, regexp or string, and move forward if match
     *
     * @param string $tok
     * @return null|bool|object
     */
    public function match($tok){

		//handle multiple tokens
		if( func_num_args() > 1 ){
			$toks = func_get_args();
			while( $tok = array_shift($toks) ){
				$v = $this->match($tok);
				if( $v ){
					return $v;
				}
			}
			return null;
		}


		$match = null;
		if( strlen($tok) == 1 ){
			return $this->MatchChar($tok);

		}else if (is_callable(array($this, $tok))) {
			// Non-terminal, match using a function call
			return $this->$tok();

		} else {
			// Match a regexp from the current start point
			$this->sync();

			$result = preg_match($tok, $this->current, $match);
			if ($result) {
				$length = strlen($match[0]);
			} else {
				return null;
			}
		}


        // The match is confirmed, add the match length to `this::pos`,
        // and consume any extra white-space characters (' ' || '\n')
        // which come after that. The reason for this is that LeSS's
        // grammar is mostly white-space insensitive.
        //
        if( $match ){
			$this->skipWhitespace($length);
            $this->sync();

            if (is_string($match)) {
                return $match;
            } else {
                return count($match) === 1 ? $match[0] : $match;
            }
        }
    }

	// Match a single character in the input,
    function MatchChar($tok){
		if( substr($this->input,$this->pos,1) === $tok ){
			$this->skipWhitespace(1);
			$this->sync();
			return $tok;
		}
	}

	/**
	 * Same as match(), but don't change the state of the parser,
	 * just return the match.
	 *
	 * @param $tok
	 * @param int $offset
	 * @return bool
	 */
	public function peek($tok, $offset = 0){
		if (strlen($tok) == 1) {
			return substr($this->input,$this->pos + $offset,1) === $tok;
		} else if (preg_match($tok, $this->current, $matches)) {
			return true;
		} else {
			return false;
		}
	}


    public function skipWhitespace($length) {
		$this->pos += $length;
		while( $this->pos < strlen($this->input) && $this->isWhitespace() ){
			$this->pos++;
		}
    }

	public function expect($tok, $msg = NULL) {
		$result = $this->match($tok);
		if (!$result) {
			throw new Less_ParserException(
				$msg === NULL
					? "Expected '" . $tok . "' got '" . $this->input[$this->pos] . "'"
					: $msg
			);
		} else {
			return $result;
		}
	}

    //
    // Here in, the parsing rules/functions
    //
    // The basic structure of the syntax tree generated is as follows:
    //
    //   Ruleset ->  Rule -> Value -> Expression -> Entity
    //
    // Here's some LESS code:
    //
    //    .class {
    //      color: #fff;
    //      border: 1px solid #000;
    //      width: @w + 4px;
    //      > .child {...}
    //    }
    //
    // And here's what the parse tree might look like:
    //
    //     Ruleset (Selector '.class', [
    //         Rule ("color",  Value ([Expression [Color #fff]]))
    //         Rule ("border", Value ([Expression [Dimension 1px][Keyword "solid"][Color #000]]))
    //         Rule ("width",  Value ([Expression [Operation "+" [Variable "@w"][Dimension 4px]]]))
    //         Ruleset (Selector [Element '>', '.child'], [...])
    //     ])
    //
    //  In general, most rules will try to parse a token with the `$()` function, and if the return
    //  value is truly, will return a new node, of the relevant type. Sometimes, we need to check
    //  first, before parsing, that's when we use `peek()`.
    //

    //
    // The `primary` rule is the *entry* and *exit* point of the parser.
    // The rules here can appear at any level of the parse tree.
    //
    // The recursive nature of the grammar is an interplay between the `block`
    // rule, which represents `{ ... }`, the `ruleset` rule, and this `primary` rule,
    // as represented by this simplified grammar:
    //
    //     primary  →  (ruleset | rule)+
    //     ruleset  →  selector+ block
    //     block    →  '{' primary '}'
    //
    // Only at one point is the primary rule not called from the
    // block rule: at the root level.
    //
    private function parsePrimary(){
        $root = array();

        while( ($node = $this->match('parseExtendRule', 'parseMixinDefinition', 'parseRule', 'parseRuleset',
							'parseMixinCall', 'parseComment', 'parseDirective' ))
							|| $this->match("/^[\s\n]+/") || $this->match('/^;+/')
        ){
			//not the same as less.js
			if( is_array($node) ){
				$root[] = $node[0];
			}elseif( $node ){
				$root[] = $node;
			}
        }

        return $root;
    }

    // We create a Comment node for CSS comments `/* */`,
    // but keep the LeSS comments `//` silent, by just skipping
    // over them.
    private function parseComment(){

        if ( ! $this->peek('/')) {
            return;
        }

		if ($this->peek('/', 1)) {
			return new Less_Tree_Comment($this->match('/^\/\/.*/'), true);
		}elseif( $comment = $this->match('/^\/\*(?:[^*]|\*+[^\/*])*\*+\/\n?/')) {
			return new Less_Tree_Comment($comment, false);
		}
    }

    //
    // A string, which supports escaping " and '
    //
    //     "milky way" 'he\'s the one!'
    //
    private function parseEntitiesQuoted() {
		$j = 0;
		$e = false;
		$index = $this->pos;

        if ($this->peek('~')) {
			$j++;
            $e = true; // Escaped strings
        }

        if ( ! $this->peek('"', $j) && ! $this->peek("'", $j)) {
            return;
        }

        if ($e) {
            $this->MatchChar('~');
        }

        if ($str = $this->match('/^"((?:[^"\\\\\r\n]|\\\\.)*)"|\'((?:[^\'\\\\\r\n]|\\\\.)*)\'/')) {
			$result = $str[0][0] == '"' ? $str[1] : $str[2];
			return new Less_Tree_Quoted($str[0], $result, $e, $index, $this->env->currentFileInfo );
        }
        return;
    }

    //
    // A catch-all word, such as:
    //
    //     black border-collapse
    //
    private function parseEntitiesKeyword()
    {
        if ($k = $this->match('/^[_A-Za-z-][_A-Za-z0-9-]*/')) {
			if (Less_Colors::hasOwnProperty($k))
				// detected named color
				return new Less_Tree_Color(substr(Less_Colors::color($k), 1));
			else
				return new Less_Tree_Keyword($k);
        }
        return;
    }

    //
    // A function call
    //
    //     rgb(255, 0, 255)
    //
    // We also try to catch IE's `alpha()`, but let the `alpha` parser
    // deal with the details.
    //
    // The arguments are parsed with the `entities.arguments` parser.
    //
    private function parseEntitiesCall()
    {
        $index = $this->pos;
        if ( ! preg_match('/^([\w-]+|%|progid:[\w\.]+)\(/', $this->current, $name)) {
            return;
        }
        $name = $name[1];
        $nameLC = strtolower($name);

        if ($nameLC === 'url') {
            return null;
        } else {
            $this->pos += strlen($name);
        }

        if ($nameLC === 'alpha') {
			$alpha_ret = $this->match('parseAlpha');
			if( $alpha_ret ){
				return $alpha_ret;
			}
		}

        $this->MatchChar('('); // Parse the '(' and consume whitespace.

        $args = $this->match('parseEntitiesArguments');

        if( !$this->MatchChar(')') ){
            return;
        }

        if ($name) {
            return new Less_Tree_Call($name, $args, $index, $this->env->currentFileInfo );
        }
    }

    /**
     * Parse a list of arguments
     *
     * @return array
     */
	private function parseEntitiesArguments(){
		$args = array();
		while( $arg = $this->match('parseEntitiesAssignment','parseExpression') ){
			$args[] = $arg;
			if (! $this->MatchChar(',')) {
				break;
			}
		}
		return $args;
	}

    private function parseEntitiesLiteral(){
		return $this->match('parseEntitiesDimension','parseEntitiesColor','parseEntitiesQuoted','parseUnicodeDescriptor');
    }

	// Assignments are argument entities for calls.
	// They are present in ie filter properties as shown below.
	//
	//     filter: progid:DXImageTransform.Microsoft.Alpha( *opacity=50* )
	//
	private function parseEntitiesAssignment() {
		if (($key = $this->match('/^\w+(?=\s?=)/i')) && $this->MatchChar('=') && ($value = $this->match('parseEntity'))) {
			return new Less_Tree_Assignment($key, $value);
		}
	}

	//
	// Parse url() tokens
	//
	// We use a specific rule for urls, because they don't really behave like
	// standard function calls. The difference is that the argument doesn't have
	// to be enclosed within a string, so it can't be parsed as an Expression.
	//
	private function parseEntitiesUrl(){
		if (! $this->peek('u') || ! $this->match('/^url\(/')) {
			return;
		}

		$value = $this->match('parseEntitiesQuoted','parseEntitiesVariable','/^(?:(?:\\\\[\(\)\'"])|[^\(\)\'"])+/');
		if( !$value ){
			$value = '';
		}


		$this->expect(')');


		return new Less_Tree_Url((isset($value->value) || $value instanceof Less_Tree_Variable)
							? $value : new Less_Tree_Anonymous($value), $this->env->currentFileInfo );
	}


	//
	// A Variable entity, such as `@fink`, in
	//
	//     width: @fink + 2px
	//
	// We use a different parser for variable definitions,
	// see `parsers.variable`.
	//
	private function parseEntitiesVariable(){
		$index = $this->pos;
		if ($this->peek('@') && ($name = $this->match('/^@@?[\w-]+/'))) {
			return new Less_Tree_Variable($name, $index, $this->env->currentFileInfo);
		}
	}


	// A variable entity useing the protective {} e.g. @{var}
	private function parseEntitiesVariableCurly() {
		$index = $this->pos;

		if( strlen($this->input) > ($this->pos+1) && $this->input[$this->pos] === '@' && ($curly = $this->match('/^@\{([\w-]+)\}/')) ){
			return new Less_Tree_Variable('@'.$curly[1], $index, $this->env->currentFileInfo);
		}
	}

    //
    // A Hexadecimal color
    //
    //     #4F3C2F
    //
    // `rgb` and `hsl` colors are parsed through the `entities.call` parser.
    //
    private function parseEntitiesColor()
    {
        if ($this->peek('#') && ($rgb = $this->match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})/'))) {
            return new Less_Tree_Color($rgb[1]);
        }
    }

    //
    // A Dimension, that is, a number and a unit
    //
    //     0.5em 95%
    //
    private function parseEntitiesDimension(){
        $c = ord($this->input[$this->pos]);

		//Is the first char of the dimension 0-9, '.', '+' or '-'
		if (($c > 57 || $c < 43) || $c === 47 || $c == 44){
			return;
        }

        if ($value = $this->match('/^([+-]?\d*\.?\d+)(%|[a-z]+)?/')) {
            return new Less_Tree_Dimension($value[1], isset($value[2]) ? $value[2] : null);
        }
    }


	//
	// A unicode descriptor, as is used in unicode-range
	//
	// U+0?? or U+00A1-00A9
	//
	function parseUnicodeDescriptor() {

		if ($ud = $this->match('/^(U\+[0-9a-fA-F?]+)(\-[0-9a-fA-F?]+)?/')) {
			return new Less_Tree_UnicodeDescriptor($ud[0]);
		}
	}


    //
    // JavaScript code to be evaluated
    //
    //     `window.location.href`
    //
    private function parseEntitiesJavascript()
    {
        $e = false;
        if ($this->peek('~')) {
            $e = true;
        }
        if (! $this->peek('`', $e)) {
            return;
        }
        if ($e) {
            $this->MatchChar('~');
        }
        if ($str = $this->match('/^`([^`]*)`/')) {
            return new Less_Tree_Javascript($str[1], $this->pos, $e);
        }
    }


	//
	// The variable part of a variable definition. Used in the `rule` parser
	//
	//     @fink:
	//
	private function parseVariable(){
		if ($this->peek('@') && ($name = $this->match('/^(@[\w-]+)\s*:/'))) {
			return $name[1];
		}
	}

    //
	// extend syntax - used to extend selectors
	//
	function parseExtend($isRule = false){

		$index = $this->pos;
		$extendList = array();

		if( !$this->match( $isRule ? '/^&:extend\(/' : '/^:extend\(/' ) ){ return; }

		do{
			$option = null;
			$elements = array();
			while( true ){
				$option = $this->match('/^(all)(?=\s*(\)|,))/');
				if( $option ){ break; }
				$e = $this->match('parseElement');
				if( !$e ){ break; }
				$elements[] = $e;
			}

			if( $option ){
				$option = $option[1];
			}

			$extendList[] = new Less_Tree_Extend( new Less_Tree_Selector($elements), $option, $index );

		}while( $this->MatchChar(",") );

		$this->expect('/^\)/');

		if( $isRule ){
			$this->expect('/^;/');
		}

		return $extendList;
	}

	function parseExtendRule(){
		return $this->parseExtend(true);
	}


	//
	// A Mixin call, with an optional argument list
	//
	//     #mixins > .square(#fff);
	//     .rounded(4px, black);
	//     .button;
	//
	// The `while` loop is there because mixins can be
	// namespaced, but we only support the child and descendant
	// selector for now.
	//
    private function parseMixinCall(){
		$elements = array();
		$index = $this->pos;
		$important = false;
		$args = null;
		$c = null;

        if( !$this->peek('.') && !$this->peek('#') ){
            return;
        }

		$this->save(); // stop us absorbing part of an invalid selector

		while( $e = $this->match('/^[#.](?:[\w-]|\\\\(?:[A-Fa-f0-9]{1,6} ?|[^A-Fa-f0-9]))+/') ){
			$elements[] = new Less_Tree_Element($c, $e, $this->pos);
			$c = $this->MatchChar('>');
		}

		if( $this->MatchChar('(') ){
			$returned = $this->parseMixinArgs(true);
			$args = $returned['args'];
			$this->expect(')');
		}

		if( !$args ){
			$args = array();
		}

		if( $this->match('parseImportant') ){
			$important = true;
		}

		if( count($elements) > 0 && ($this->MatchChar(';') || $this->peek('}')) ){
			return new Less_Tree_Mixin_Call($elements, $args, $index, $this->env->currentFileInfo, $important);
		}

		$this->restore();
	}


	private function parseMixinArgs( $isCall ){
		$expressions = array();
		$argsSemiColon = array();
		$isSemiColonSeperated = null;
		$argsComma = array();
		$expressionContainsNamed = null;
		$name = null;
		$nameLoop = null;
		$returner = array('args'=>null, 'variadic'=> false);

		while( true ){
			if( $isCall ){
				$arg = $this->match('parseExpression');
			} else {
				$this->match('parseComment');
				if( $this->input[ $this->pos ] === '.' && $this->match('/^\.{3}/') ){
					$returner['variadic'] = true;
					if( $this->MatchChar(";") && !$isSemiColonSeperated ){
						$isSemiColonSeperated = true;
					}

					if( $isSemiColonSeperated ){
						$argsSemiColon[] = array('variadic'=>true);
					}else{
						$argsComma[] = array('variadic'=>true);
					}
					break;
				}
				$arg = $this->match('parseEntitiesVariable','parseEntitiesLiteral','parseEntitiesKeyword');
			}


			if( !$arg ){
				break;
			}


			$nameLoop = null;
			if( method_exists($arg,'throwAwayComments') ){
				$arg->throwAwayComments();
			}
			$value = $arg;
			$val = null;

			if( $isCall ){
				// Variable
				if( count($arg->value) == 1) {
					$val = $arg->value[0];
				}
			} else {
				$val = $arg;
			}


			if( $val && $val instanceof Less_Tree_Variable ){

				if( $this->MatchChar(':') ){
					if( count($expressions) > 0 ){
						if( $isSemiColonSeperated ){
							throw new Less_ParserException('Cannot mix ; and , as delimiter types');
						}
						$expressionContainsNamed = true;
					}
					$value = $this->expect('parseExpression');
					$nameLoop = ($name = $val->name);
				}elseif( !$isCall && $this->match('/^\.{3}/') ){
					$returner['variadic'] = true;
					if( $this->MatchChar(";") && !$isSemiColonSeperated ){
						$isSemiColonSeperated = true;
					}
					if( $isSemiColonSeperated ){
						$argsSemiColon[] = array('name'=> $arg->name, 'variadic' => true);
					}else{
						$argsComma[] = array('name'=> $arg->name, 'variadic' => true);
					}
					break;
				}elseif( !$isCall ){
					$name = $nameLoop = $val->name;
					$value = null;
				}
			}

			if( $value ){
				$expressions[] = $value;
			}

			$argsComma[] = array('name'=>$nameLoop, 'value'=>$value );

			if( $this->MatchChar(',') ){
				continue;
			}

			if( $this->MatchChar(';') || $isSemiColonSeperated ){

				if( $expressionContainsNamed ){
					throw new Less_ParserException('Cannot mix ; and , as delimiter types');
				}

				$isSemiColonSeperated = true;

				if( count($expressions) > 1 ){
					$value = new Less_Tree_Value($expressions);
				}
				$argsSemiColon[] = array('name'=>$name, 'value'=>$value );

				$name = null;
				$expressions = array();
				$expressionContainsNamed = false;
			}
		}

		$returner['args'] = ($isSemiColonSeperated ? $argsSemiColon : $argsComma);
		return $returner;
	}


    //
    // A Mixin definition, with a list of parameters
    //
    //     .rounded (@radius: 2px, @color) {
    //        ...
    //     }
    //
    // Until we have a finer grained state-machine, we have to
    // do a look-ahead, to make sure we don't have a mixin call.
    // See the `rule` function for more information.
    //
    // We start by matching `.rounded (`, and then proceed on to
    // the argument list, which has optional default values.
    // We store the parameters in `params`, with a `value` key,
    // if there is a value, such as in the case of `@radius`.
    //
    // Once we've got our params list, and a closing `)`, we parse
    // the `{...}` block.
    //
    private function parseMixinDefinition(){
        $params = array();
		$variadic = false;
		$cond = null;

        if ((! $this->peek('.') && ! $this->peek('#')) || $this->peek('/^[^{]*\}/')) {
            return;
        }

		$this->save();

        if ($match = $this->match('/^([#.](?:[\w-]|\\\(?:[A-Fa-f0-9]{1,6} ?|[^A-Fa-f0-9]))+)\s*\(/')) {
            $name = $match[1];

			$argInfo = $this->parseMixinArgs( false );
			$params = $argInfo['args'];
			$variadic = $argInfo['variadic'];


			// .mixincall("@{a}");
			// looks a bit like a mixin definition.. so we have to be nice and restore
			if( !$this->MatchChar(')') ){
				//furthest = i;
				$this->restore();
			}

			$this->match('parseComment');

			if ($this->match('/^when/')) { // Guard
				$cond = $this->expect('parseConditions', 'Expected conditions');
			}

            $ruleset = $this->match('parseBlock');

            if( is_array($ruleset) ){
                return new Less_Tree_Mixin_Definition($name, $params, $ruleset, $cond, $variadic);
            } else {
				$this->restore();
				$this->sync();
			}
        }
    }

	//
	// Entities are the smallest recognized token,
	// and can be found inside a rule's value.
	//
	private function parseEntity(){

		return $this->match('parseEntitiesLiteral','parseEntitiesVariable','parseEntitiesUrl','parseEntitiesCall','parseEntitiesKeyword','parseEntitiesJavascript','parseComment');
	}

    //
    // A Rule terminator. Note that we use `peek()` to check for '}',
    // because the `block` rule will be expecting it, but we still need to make sure
    // it's there, if ';' was ommitted.
    //
    private function parseEnd()
    {
        return ($end = $this->MatchChar(';') ) ? $end : $this->peek('}');
    }

    //
    // IE's alpha function
    //
    //     alpha(opacity=88)
    //
    private function parseAlpha()
    {
        if ( ! $this->match('/^\(opacity=/i')) {
            return;
        }

        $value = $this->match('/^[0-9]+/');
        if ($value === null) {
            $value = $this->match('parseEntitiesVariable');
        }

        if ($value !== null) {
			$this->expect(')');
            return new Less_Tree_Alpha($value);
        }
    }


    //
    // A Selector Element
    //
    //     div
    //     + h1
    //     #socks
    //     input[type="text"]
    //
    // Elements are the building blocks for Selectors,
    // they are made out of a `Combinator` (see combinator rule),
    // and an element name, such as a tag a class, or `*`.
    //
    private function parseElement(){
        $c = $this->match('parseCombinator');

        $e = $this->match( '/^(?:\d+\.\d+|\d+)%/', '/^(?:[.#]?|:*)(?:[\w-]|[^\x00-\x9f]|\\\\(?:[A-Fa-f0-9]{1,6} ?|[^A-Fa-f0-9]))+/',
			'*', '&', 'parseAttribute', '/^\([^()@]+\)/', '/^[\.#](?=@)/', 'parseEntitiesVariableCurly');

		if( !$e ){
			if( $this->MatchChar('(') ){
				if( ($v = $this->match('parseSelector')) && $this->MatchChar(')') ){
					$e = new Less_Tree_Paren($v);
				}
			}
		}

        if ($e) {
            return new Less_Tree_Element($c, $e, $this->pos);
        }
    }

    //
    // Combinators combine elements together, in a Selector.
    //
    // Because our parser isn't white-space sensitive, special care
    // has to be taken, when parsing the descendant combinator, ` `,
    // as it's an empty space. We have to check the previous character
    // in the input, to see if it's a ` ` character.
    //
    private function parseCombinator()
    {
        $c = isset($this->input[$this->pos]) ? $this->input[$this->pos] : '';
        if ($c === '>' || $c === '+' || $c === '~' || $c === '|') {

            $this->pos++;
            while( $this->isWhitespace() ){
                $this->pos++;
            }
            return new Less_Tree_Combinator($c);
        } elseif ($this->pos > 0 && (preg_match('/\s/', $this->input[$this->pos - 1]))) {
            return new Less_Tree_Combinator(' ');
        } else {
            return new Less_Tree_Combinator();
        }
    }

    //
    // A CSS Selector
    //
    //     .class > div + h1
    //     li a:hover
    //
    // Selectors are made out of one or more Elements, see above.
    //
    private function parseSelector(){
		$elements = array();
		$extendList = array();

		while( ($extend = $this->match('parseExtend')) ? $extend : ($e = $this->match('parseElement')) ){
			if( $extend ){
				$extendList = array_merge($extendList,$extend);
			}else{
				if( count($extendList) ){
					//error("Extend can only be used at the end of selector");
				}
				$c = $this->input[ $this->pos ];
				$elements[] = $e;
				$e = null;
			}

			if( $c === '{' || $c === '}' || $c === ';' || $c === ',' || $c === ')') { break; }
		}

		if( count($elements) ) { return new Less_Tree_Selector( $elements, $extendList); }
		if( count($extendList) ) { throw new Less_ParserException('Extend must be used to extend a selector, it cannot be used on its own'); }
    }

	private function parseTag(){
		return ( $tag = $this->match('/^[A-Za-z][A-Za-z-]*[0-9]?/') ) ? $tag : $this->MatchChar('*');
	}

	private function parseAttribute(){

		$val = null;
		$op = null;

		if( !$this->MatchChar('[') ){
			return;
		}

		if( !($key = $this->match('parseEntitiesVariableCurly')) ){
			$key = $this->expect('/^(?:[_A-Za-z0-9-\*]*\|)?(?:[_A-Za-z0-9-]|\\\\.)+/');
		}

		if( ($op = $this->match('/^[|~*$^]?=/')) ){
			$val = $this->match('parseEntitiesQuoted','/^[\w-]+/','parseEntitiesVariableCurly');
		}

		$this->expect(']');

		return new Less_Tree_Attribute($key, $op, $val);


        if (! $this->MatchChar('[')) {
            return;
        }

        $attr = '';

		if( $key = $this->match('/^(?:[_A-Za-z0-9-]|\\\\.)+/','parseEntitiesQuoted') ){
			if( ($op = $this->match('/^[|~*$^]?=/')) &&
				($val = $this->match('parseEntitiesQuoted','/^[\w-]+/')) ){
				if( !is_string($val) ){
					$val = $val->toCss();
				}
				$attr = $key.$op.$val;
			}else{
				$attr = $key;
			}
		}

        if (! $this->MatchChar(']')) {
            return;
        }

        if ($attr) {
            return "[" . $attr . "]";
        }
    }

    //
    // The `block` rule is used by `ruleset` and `mixin.definition`.
    // It's a wrapper around the `primary` rule, with added `{}`.
    //
    private function parseBlock(){
        if ($this->MatchChar('{') && (is_array($content = $this->match('parsePrimary'))) && $this->MatchChar('}')) {
            return $content;
        }
    }

    //
    // div, .class, body > p {...}
    //
    private function parseRuleset()
    {
        $selectors = array();
        $start = $this->pos;

        while ($s = $this->match('parseSelector')) {
            $selectors[] = $s;
            $this->match('parseComment');
            if ( ! $this->MatchChar(',')) {
                break;
            }
            $this->match('parseComment');
        }

        if (count($selectors) > 0 && (is_array($rules = $this->match('parseBlock')))) {
            return new Less_Tree_Ruleset($selectors, $rules, $this->env->strictImports);
        } else {
            // Backtrack
            $this->pos = $start;
            $this->sync();
        }
    }


	private function parseRule( $tryAnonymous = null ){
		$start = $this->pos;
		$c = isset($this->input[$this->pos]) ? $this->input[$this->pos] : '';
		$this->save();

		if ($c === '.' || $c === '#' || $c === '&') {
			return;
		}

		if( $name = $this->match('parseVariable','parseProperty') ){


			// prefer to try to parse first if its a variable or we are compressing
			// but always fallback on the other one
			if( !$tryAnonymous && ($this->env->compress || ( $name[0] === '@')) ){
				$value = $this->match('parseValue','parseAnonymousValue');
			}else{
				$value = $this->match('parseAnonymousValue','parseValue');
			}

			$important = $this->match('parseImportant');

			if( $value && $this->match('parseEnd') ){
				return new Less_Tree_Rule($name, $value, $important, $start, $this->env->currentFileInfo);
			}else{
				//not the same as less.js
				$this->restore();
				/*
				if( $value && !$tryAnonymous ){
					//return new Less_Tree_Rule(true);
				}else{
					$this->restore();
				}
				*/
			}
		}
	}

	function parseAnonymousValue(){

		if( preg_match('/^([^@+\/\'"*`(;{}-]*);/',$this->current, $match) ){
			$this->pos += strlen($match[0]) - 1;
			return new Less_Tree_Anonymous($match[1]);
		}
	}

	//
	// An @import directive
	//
	//     @import "lib";
	//
	// Depending on our environment, importing is done differently:
	// In the browser, it's an XHR request, in Node, it would be a
	// file-system operation. The function used for importing is
	// stored in `import`, which we pass to the Import constructor.
	//
	private function parseImport(){
		$index = $this->pos;

		$this->save();

		$dir = $this->match('/^@import?\s+/');

		$options = array();
		if( $dir ){
			$options = $this->match('parseImportOptions');
		}

		if( $dir && ($path = $this->match('parseEntitiesQuoted','parseEntitiesUrl')) ){
			$features = $this->match('parseMediaFeatures');
			if( $this->MatchChar(';') ){
				if( $features ){
					$features = new Less_Tree_Value($features);
				}

				return new Less_Tree_Import($path, $features, $options, $this->pos, $this->env->currentFileInfo );
			}
		}

		$this->restore();
	}

	private function parseImportOptions(){

		$options = array();

		// list of options, surrounded by parens
		if( !$this->MatchChar('(') ){ return null; }
		do{
			if( $o = $this->match('parseImportOption') ){
				$optionName = $o;
				$value = true;
				switch( $optionName ){
					case "css":
						$optionName = "less";
						$value = false;
					break;
					case "once":
						$optionName = "multiple";
						$value = false;
					break;
				}
				$options[$optionName] = $value;
				if( !$this->MatchChar(',') ){ break; }
			}
		}while($o);
		$this->expect(')');
		return $options;
	}

	private function parseImportOption(){
		$opt = $this->match('/^(less|css|multiple|once)/');
		if( $opt ){
			return $opt[1];
		}
	}

	private function parseMediaFeature() {
		$nodes = array();

		do {
			if ($e = $this->match('parseEntitiesKeyword')) {
				$nodes[] = $e;
			} elseif ($this->MatchChar('(')) {
				$p = $this->match('parseProperty');
				$e = $this->match('parseValue');
				if ($this->MatchChar(')')) {
					if ($p && $e) {
						$nodes[] = new Less_Tree_Paren(new Less_Tree_Rule($p, $e, null, $this->pos, $this->env->currentFileInfo, true));
					} elseif ($e) {
						$nodes[] = new Less_Tree_Paren($e);
					} else {
						return null;
					}
				} else
					return null;
			}
		} while ($e);

		if ($nodes) {
			return new Less_Tree_Expression($nodes);
		}
	}

	private function parseMediaFeatures() {
		$features = array();

		do {
			if ($e = $this->match('parseMediaFeature')) {
				$features[] = $e;
				if (!$this->MatchChar(',')) break;
			} elseif ($e = $this->match('parseEntitiesVariable')) {
				$features[] = $e;
				if (!$this->MatchChar(',')) break;
			}
		} while ($e);

		return $features ? $features : null;
	}

	private function parseMedia() {
		if ($this->match('/^@media/')) {
			$features = $this->match('parseMediaFeatures');

			if ($rules = $this->match('parseBlock')) {
				return new Less_Tree_Media($rules, $features);
			}
		}
	}

    //
    // A CSS Directive
    //
    //     @charset "utf-8";
    //
    private function parseDirective()
    {
		$hasBlock = false;
		$hasIdentifier = false;
		$hasExpression = false;

        if (! $this->peek('@')) {
            return;
        }

		$value = $this->match('parseImport','parseMedia');
        if( $value ){
            return $value;
		}

		$this->save();

		$name = $this->match('/^@[a-z-]+/');

		if( !$name ) return;

		$nonVendorSpecificName = $name;
		$pos = strpos($name,'-', 2);
		if( $name[1] == '-' && $pos > 0 ){
			$nonVendorSpecificName = "@" . substr($name, $pos + 1);
		}

		switch($nonVendorSpecificName) {
			case "@font-face":
				$hasBlock = true;
				break;
			case "@viewport":
			case "@top-left":
			case "@top-left-corner":
			case "@top-center":
			case "@top-right":
			case "@top-right-corner":
			case "@bottom-left":
			case "@bottom-left-corner":
			case "@bottom-center":
			case "@bottom-right":
			case "@bottom-right-corner":
			case "@left-top":
			case "@left-middle":
			case "@left-bottom":
			case "@right-top":
			case "@right-middle":
			case "@right-bottom":
				$hasBlock = true;
				break;
			case "@page":
			case "@document":
			case "@supports":
			case "@keyframes":
				$hasBlock = true;
				$hasIdentifier = true;
				break;
			case "@namespace":
				$hasExpression = true;
				break;
		}

		if( $hasIdentifier ){
			$temp = $this->match('/^[^{]+/');
			if( !$temp ){
				$temp = '';
			}
			$name .= " " . trim($temp);
		}


		if( $hasBlock ){

			if ($rules = $this->match('parseBlock')) {
				return new Less_Tree_Directive($name, $rules);
			}
		} else {
			if (($value = $hasExpression ? $this->match('parseExpression') : $this->match('parseEntity')) && $this->MatchChar(';')) {
				return new Less_Tree_Directive($name, $value);
			}
		}

		$this->restore();
    }


    //
    // A Value is a comma-delimited list of Expressions
    //
    //     font-family: Baskerville, Georgia, serif;
    //
    // In a Rule, a Value represents everything after the `:`,
    // and before the `;`.
    //
    private function parseValue ()
    {
        $expressions = array();

        while ($e = $this->match('parseExpression')) {
            $expressions[] = $e;
            if (! $this->MatchChar(',')) {
                break;
            }
        }

        if (count($expressions) > 0) {
            return new Less_Tree_Value($expressions);
        }
    }

    private function parseImportant ()
    {
        if ($this->peek('!')) {
            return $this->match('/^! *important/');
        }
    }

	private function parseSub (){

		if( $this->MatchChar('(') ){
			if( $a = $this->match('parseAddition') ){
				$e = new Less_Tree_Expression( array($a) );
				$this->expect(')');
				$e->parens = true;
				return $e;
			}
		}
	}

	private function parseMultiplication() {
		$operation = false;
		$expression = array();

		if ($m = $this->match('parseOperand')) {
			$isSpaced = $this->isWhitespace( -1 );
			while( !$this->peek('/^\/[*\/]/') && ($op = $this->match('/','*')) ){

				if( $a = $this->match('parseOperand') ){
					$m->parensInOp = true;
					$a->parensInOp = true;
					$operation = new Less_Tree_Operation( $op, array( $operation ? $operation : $m, $a ), $isSpaced );
					$isSpaced = $this->isWhitespace( -1 );
				}else{
					break;
				}
			}
			return ($operation ? $operation : $m);
		}
	}

    private function parseAddition (){
        $operation = false;
        if ($m = $this->match('parseMultiplication')) {
			$isSpaced = $this->isWhitespace( -1 );

            while( ($op = ($op = $this->match('/^[-+]\s+/')) ? $op : ( !$isSpaced ? ($this->match('+','-')) : false )) && ($a = $this->match('parseMultiplication')) ){
				$m->parensInOp = true;
				$a->parensInOp = true;
                $operation = new Less_Tree_Operation($op, array($operation ? $operation : $m, $a), $isSpaced);
                $isSpaced = $this->isWhitespace( -1 );
            }
            return $operation ? $operation : $m;
        }
    }

	private function parseConditions() {
		$index = $this->pos;
		$condition = null;
		if ($a = $this->match('parseCondition')) {
			while ($this->MatchChar(',') && ($b = $this->match('parseCondition'))) {
				$condition = new Less_Tree_Condition('or', $condition ? $condition : $a, $b, $index);
			}
			return $condition ? $condition : $a;
		}
	}

	private function parseCondition() {
		$index = $this->pos;
		$negate = false;

		if ($this->match('/^not/')) $negate = true;
		$this->expect('(');
		if ($a = ($this->match('parseAddition','parseEntitiesKeyword','parseEntitiesQuoted')) ) {
			if ($op = $this->match('/^(?:>=|=<|[<=>])/')) {
				if ($b = ($this->match('parseAddition','parseEntitiesKeyword','parseEntitiesQuoted'))) {
					$c = new Less_Tree_Condition($op, $a, $b, $index, $negate);
				} else {
					throw new Less_ParserException('Unexpected expression');
				}
			} else {
				$c = new Less_Tree_Condition('=', $a, new Less_Tree_Keyword('true'), $index, $negate);
			}
			$this->expect(')');
			return $this->match('/^and/') ? new Less_Tree_Condition('and', $c, $this->match('parseCondition')) : $c;
		}
	}

	//
	// An operand is anything that can be part of an operation,
	// such as a Color, or a Variable
	//
	private function parseOperand (){
		$negate = false;
		$p = isset($this->input[$this->pos + 1]) ? $this->input[$this->pos + 1] : '';
		if ($this->peek('-') && ($p === '@' || $p === '(')) {
			$negate = $this->MatchChar('-');
		}
		$o = $this->match('parseSub','parseEntitiesDimension','parseEntitiesColor','parseEntitiesVariable','parseEntitiesCall');

		if( $negate ){
			$o->parensInOp = true;
			$o = new Less_Tree_Negative($o);
		}

		return $o;
	}

    //
    // Expressions either represent mathematical operations,
    // or white-space delimited Entities.
    //
    //     1px solid black
    //     @var * 2
    //
    private function parseExpression (){
        $entities = array();

        while ($e = $this->match('parseAddition','parseEntity')) {
            $entities[] = $e;
			// operations do not allow keyword "/" dimension (e.g. small/20px) so we support that here
			if( !$this->peek('/^\/[\/*]/') && ($delim = $this->MatchChar('/')) ){
				$entities[] = new Less_Tree_Anonymous($delim);
			}

        }
        if (count($entities) > 0) {
            return new Less_Tree_Expression($entities);
        }
    }

    private function parseProperty (){
        if ($name = $this->match('/^(\*?-?[_a-zA-Z0-9-]+)\s*:/')) {
            return $name[1];
        }
    }


}

	if( !function_exists('pre') ){
		function pre($arg){
			global $debug;

			if( !isset($debug) || !$debug ){
				//return;
			}
			ob_start();
			echo "\n\n<pre>";
			if( $arg === 0 ){
				echo '0';
			}elseif( !$arg ){
				var_dump($arg);
			}else{
				print_r($arg);
			}
			echo "</pre>\n";
			return ob_get_clean();
		}
	}

	if( !function_exists('msg') ){
		function msg($arg){
			echo Pre($arg);
		}
	}
