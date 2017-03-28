<?php
/**
 * 抽象解析类
 */
abstract class AbstractTagLib
{
	protected $template;

	public function __construct(Template $tpl=null) {
		if (!is_null($tpl)) {
		    $this->template = $tpl;
		}
	}

	public abstract function parse(& $str);
	
	public function getTemplate() {
		return $this->template;
	}

	/**
	 * 解析标签属性
	 * @param string $attrString 属性字符串
	 * @return array
	 */
	public function parseAttrs($attrString) {
		$attrs = array(); //属性关联数组
		if(preg_match_all('/([a-zA-Z_$]\w*)(?:\s*=\s*("|\')(.*?)\2)?/', 
		      $attrString, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $value) {
				$attrs[$value[1]] = isset($value[3]) ? $value[3] : true;
			}
		}
		return $attrs;
	}
}

/**
 * 标签解析, 无法获取标签中间内容
 */
abstract class TagLibSupport extends AbstractTagLib
{
	// 标签数组定义
	protected $tags = array();
	// 当前标签属性
	protected $tagAttrs = array();
	// 当前解析的标签名
	protected $currTagName = '';

	/**
	 * 解析入口
	 */
	public function parse(&$str) {
		$l = preg_quote(LEFT_DELIM); //转义分隔符中的特殊字符
		$r = preg_quote(RIGHT_DELIM);
		return preg_replace_callback(
		    sprintf('!%s(/?)([a-zA-Z_]\w*)([^\r\n%s]*?)(/?)\s*%s!', $l, $r, $r), 
		    array($this, '__pregCallback'), 
		    $str);
	}

	protected final function __pregCallback($matches) {
		$this->currTagName = $tagName = $matches[2]; // tag name
		if(isset($this->tags[$tagName])) {
			if($matches[1] == '/') { // end tag
				$endMethod = '_end_' . $tagName; // parse end tag method name
				$str = $this->$endMethod();
				array_pop($this->tagAttrs[$tagName]); // 释放当前标签的属性, stack push
			} else { // start tag
				$attrArr = $this->parseAttrs($matches[3]); // tag attributes
				$this->tagAttrs[$tagName][] = $attrArr; //保存当前标签属性, stack pop
				$startMethod = '_start_' . $tagName; // parse start tag method name
				$str = $this->$startMethod($attrArr);
			}
			return $str;
		}
		return $matches[0];
	}

	/**
	 * 获取当前正在被解析标签的所有属性
	 */
	public function getCurrTagAttrs() {
	    $size = count($this->tagAttrs[$this->currTagName]);
		return $this->tagAttrs[$this->currTagName][$size - 1];
	}

	/**
	 * 获取当前正在被解析标签的指定属性
	 */
	public function getAttribute($attrName) {
		$attrs = $this->getCurrTagAttrs();
		if(isset($attrs[$attrName])) {
			return $attrs[$attrName];
		}
		return null;
	}

	/**
	 * 给当前标签设置属性
	 */
	public function setAttribute($attrName, $attrVal) {
		$size = count($this->tagAttrs[$this->currTagName]);
		$this->tagAttrs[$this->currTagName][$size - 1][$attrName] = $attrVal;
	}
}

/**
 * 标签解析, 可获取标签中间内容
 */
abstract class BodyTagLibSupport extends AbstractTagLib
{
	// 标签数组定义
	protected $tags = array();
	// 当前解析的标签名
	protected $currTagName = '';

	/**
	 * 解析入口
	 */
	public function parse(&$str) {
		$l = preg_quote(LEFT_DELIM); //转义分隔符中的特殊字符
		$r = preg_quote(RIGHT_DELIM);
		foreach($this->tags as $tagName=>$val) {
			$this->currTagName = $tagName;
			$pattern = sprintf('!%s\s*%s([^\r\n%s]*?)(?:/\s*%s|%s(.*?)%s/%s\s*%s)!is', $l, $tagName, $r, $r, $r, $l, $tagName, $r);
			$str = preg_replace_callback($pattern, array($this, '__pregCallback'), $str);
		}
		return $str;
	}

	protected final function __pregCallback($matches) {
		$attrString = $matches[1];
		$content = isset($matches[2]) ? $matches[2] : '';
		$callback = '_' . $this->currTagName; //标签解析函数
		return $this->$callback($this->parseAttrs($attrString), $content);
	}
}

/***************************************************
 * 模板继承, 只能有一个父模板
 * Example:
 *   {extends parent=""/}
 *   {block name=""}{/block}
 ***************************************************/
class TemplateInheritTagLib extends BodyTagLibSupport
{
	protected $tags = array(
		'extends'	=> array('attrs'=>'parent'),
		'block'		=> array('attrs'=>'name'),
	);
	// 模板继承文件
	protected $extendsFile = NULL;
	// block数据
	protected $blockData = array();

	/**
	 * 需要配合 extends 标签一起使用
	 * {block name=""}{/block}
	 */
	public function _block($attrs,$content){
		if(!$this->extendsFile){ //未继承模板
			$content = addslashes($content);
			return "<?php echo \"{$content}\"; ?>";
		}else{
			if(isset($this->blockData[$attrs['name']])){
				$this->blockData[$attrs['name']] = $content; //覆盖 block 对应的值
				return '';
			}else{
				$this->blockData[$attrs['name']] = $content; //初次设置 block 的值, 标记其位置
				return 'BLOCK_' . $attrs['name'] . '_CONTENT';
			}
		}
	}

	// 还原 block 标签内容
	public function restoreBlock($str){
		foreach ($this->blockData as $name=>$content){
			$str = str_replace('BLOCK_' . $name . '_CONTENT',$content,$str);
		}
		return $str;
	}

	/**
	 * 继承模板文件
	 * {extends parent=""/}
	 */
	public function _extends($attrs,$content){
		$extendsFile = $this->getTemplate()->templateDir . $attrs['parent'];
		if(file_exists($extendsFile)){
			$this->extendsFile = $extendsFile; //设置继承的模板文件
			return file_get_contents($extendsFile);
		}
		return '';
	}
}

/***************************************************
 * 核心标签库
 ***************************************************/
class CoreTagLib extends TagLibSupport
{
	protected $tags = array(
		'loop'		=> array('attrs'=>'name,item,key,index'),
		'switch'	=> array('attrs'=>'name'),
		'if'		=> array('attrs'=>'test'),
		'elseif'	=> array('attrs'=>'test'),
		'elif'		=> array('attrs'=>'test'), // elseif 的别名
		'in'		=> array('attrs'=>'name,value'),
		'between'	=> array('attrs'=>'name,value'),
		'assign'	=> array('attrs'=>'name,value'),
		'php'		=> array(),
		'else'		=> array(),
		'token'		=> array(),
		'cfgload' 	=> array('attrs'=>'path,file'), //加载配置文件(.ini类型)
		'config'	=> array('attrs'=>'name'), //读取配置
		'foreach'	=> array(),
		'for'		=> array('attrs'=>'name,start,stop,step,comparison'),
	);
	
	/**
	 * 支持 for 循环语法
	 * 支持的运算符有: lt(<) gt(>) le(<=) ge(>=)
	 * {for name="" start="" stop="" step="1" comparison="lt"}
	 */
	public function _start_for($attrs){
		$var = $attrs['name'];
		$start = $attrs['start'];
		$stop = $attrs['stop'];
		$step = isset($attrs['step']) ? $attrs['step'] : 1; //默认值1
		$comparison = isset($attrs['comparison']) ? 
			$this->replaceOperator($attrs['comparison']) : '<'; //默认是lt
		return "<?php for(\$$var = $start; \$$var $comparison $stop; \$$var += $step){ ?>";
	}
	
	public function _end_for(){
		return '<?php } ?>';
	}
	
	/**
	 * 支持 foreach 语法
	 * 格式: {foreach $var as $val}{/foreach} 或者 {foreach $var as $key $val}{/foreach}
	 */
	public function _start_foreach($attrs){
		$attrs = array_keys($attrs); //获取所有的key
		$attrs[0] = preg_replace('/\$(\w+)\s*\.\s*(\w+)/','$$1[\'$2\']',$attrs[0]); //点语法解析
		$str = "$attrs[0] as $attrs[2]";
		if(isset($attrs[3])){
			$str .= " => $attrs[3]";
		}
		return "<?php foreach($str){ ?>";
	}
	
	public function _end_foreach(){
		return '<?php } ?>';
	}
	
	/**
	 * 加载配置文件, 默认目录为 configDir, 可以使用 path 属性重新指定, file 指明配置文件名(.ini类型)
	 * 需要配合标签 config 一起使用
	 * {cfgload path="" file=""/}
	 */
	public function _start_cfgload($attrs){
		// 加载配置文件
		if(isset($attrs['path'])){
			$cfg = rtrim($attrs['path'],'/') . '/' . $attrs['file'];
		}else {
			$cfg = $this->getTemplate()->configDir . $attrs['file'];
		}
		if(file_exists($cfg)){
			return "<?php if(!isset(\$this->_vars['__cfg'])){ \$this->_vars['__cfg']=array(); } " .
				"\$this->_vars['__cfg']=array_merge(\$this->_vars['__cfg'],parse_ini_file('{$cfg}')); ?>";
		}else{
			return '';
		}
	}
	
	/**
	 * 读取指定配置项, 需要和 cfgload 标签一起使用
	 * 格式: {config name="key"/} 或者 {config key/}
	 *  name 属性指定配置项key, 或者 key 直接以属性的形式表示
	 */
	public function _start_config($attrs){
		$cfgkey = isset($attrs['name']) ? $attrs['name'] : key($attrs); //获取键值
		return "<?php echo \$this->_vars['__cfg']['{$cfgkey}']; ?>";
	}

	/**
	 * 表单令牌, 防止表单重复提交
	 * {token/}
	 */
	public function _start_token($attrs){
		if(isset($_SESSION)){
			$key = md5(microtime() . rand(0, 100000));
			$_SESSION['TOKEN_NAME'] = $key;
			return '<input type="hidden" name="TOKEN_NAME" value="'.$key.'"/>';
		}
		return '';
	}

	/**
	 * 循环输出变量
	 * {loop name="" item="" key="k" index='index'} {$user.name} {/loop}
	 * 属性 name: 指明被循环的变量
	 * 属性 item: 循环体内的临时变量名
	 * 属性 key: 数组的键值, 默认是 k
	 * 属性 index: 迭代序号(从1开始), 默认是 index
	 */
	public function _start_loop($attrs){
		$name = $attrs['name'];
		$item = $attrs['item'];
		$key = isset($attrs['key']) ? $attrs['key'] : 'k'; //默认值k
		$index = isset($attrs['index']) ? $attrs['index'] : 'index'; //默认值index,不可嵌套使用
		$this->setAttribute('index',$index); //设置index属性
		return "<?php \${$index}=1; foreach(\${$name} as \${$key}=>\${$item}){ ?>";
	}

	public function _end_loop(){
		$index = $this->getAttribute('index');
		return "<?php \${$index}++; } ?>";
	}

	/**
	 * 支持 switch 语法
	 * {switch name=""}
	 * 	 {case value=""}{/case} {case value=""}{/case} {default}{/default}
	 * {/switch}
	 */
	public function _start_switch($attrs){
		return "<?php switch(\${$attrs['name']}){ ";
	}

	public function _end_switch(){
		return ' } ?>';
	}

	/**
	 * 支持 if 语法, 可以使用的内嵌标签有: elseif elif else
	 * 支持的运算符有: eq lt gt le ge and or neq not heq nheq
	 * test 属性支持二级点语法, 变量形式为: $var[.key]
	 * {if test=""}{/if}
	 */
	public function _start_if($attrs){
		$test = $this->parseAttrTest($attrs['test']);
		return "<?php if({$test }){ ?>";
	}

	public function _end_if(){
		return '<?php } ?>';
	}

	/**
	 * 支持 else if 语法, 其它特性同 if 标签
	 * {elseif test=""/}
	 */
	public function _start_elseif($attrs){
		$test = $this->parseAttrTest($attrs['test']);
		return "<?php }elseif({$test }){ ?>";
	}

	/**
	 * elseif 标签的别名
	 * {elif /}
	 */
	public function _start_elif($attrs){
		return $this->_start_elseif($attrs);
	}

	/**
	 * 可以使用 else 标签的标签有: if, between, in
	 * {else/}
	 */
	public function _start_else($attrs){
		return '<?php }else{ ?>';
	}

	/**
	 * 解析 if、elseif 等标签的 test 属性
	 */
	protected function parseAttrTest($testAttr){
		$testAttr = $this->replaceOperator($testAttr); //运算符替换
		return preg_replace('/\$(\w+)\s*\.\s*(\w+)/','$$1[\'$2\']',$testAttr); //点语法解析
	}
	
	/**
	 * 运算符替换
	 */
	protected function replaceOperator($operStr){
		$pattern = array('/\beq\b/i','/\blt\b/i','/\bgt\b/i','/\ble\b/i','/\bge\b/i','/\band\b/i','/\bor\b/i','/\bneq\b/i','/\bnot\b/i','/\bheq\b/i','/\bnheq\b/i');
		$replace = array(' == ',' < ',' > ',' <= ',' >= ',' && ',' || ',' != ',' ! ',' === ',' !== ');
		return preg_replace($pattern,$replace,$operStr);
	}

	/**
	 * 变量值是某几个散列值中的一个, 适用于数字、字符串, else 标签可选
	 * {in name="" value=""} {else/} {/in}
	 * Example:
	 *   {in name="age" value="1,3,5,8"} ... {/in}
	 */
	public function _start_in($attrs){
		$name = $attrs['name'];
		$values = array_map('trim',explode(',',$attrs['value']));
		$arrStr = var_export($values,true);
		return "<?php if(in_array(\${$name},{$arrStr})){ ?>";
	}

	public function _end_in(){
		return '<?php } ?>';
	}

	/**
	 * 变量值在某个范围内, 包含端点值, 但仅适用于数字, else 标签可选
	 * {between name="" value=""} {else/} {/between}
	 * Example:
	 *   {between name="age" value="1,10"} ... {/between}
	 */
	public function _start_between($attrs){
		$name = $attrs['name'];
		$value = explode(',',$attrs['value']);
		return "<?php if(\${$name}>={$value[0]} && \${$name}<={$value[1]}){ ?>";
	}

	public function _end_between(){
		return '<?php } ?>';
	}

	/**
	 * 使用该标签, 可以在模板中直接书写 php 代码
	 * {php} PHP Code {/php}
	 */
	public function _start_php($attrs){
		return "<?php ";
	}

	public function _end_php(){
		return ' ?>';
	}

	/**
	 * 模板文件中给变量赋值, 支持数字、字符串、布尔值
	 * {assign name="" value=""/}
	 */
	public function _start_assign($attrs){
		$val = $attrs['value'];
		if(is_numeric($val) || $val == 'true' || $val == 'false'){ //布尔值、数字
			return "<?php \${$attrs['name']}={$val}; ?>";
		}else if(is_string($val)){ //字符串
			$val = addslashes($val); //转义
			return "<?php \${$attrs['name']}='{$val}'; ?>";
		}
		return '';
	}
}

/***************************************************
 * 其它的标签
 ***************************************************/
class CoreBodyTagLib extends BodyTagLibSupport
{
	protected $tags = array(
		'literal'	=> array(), //需要第一个解析
		'include'	=> array('attrs'=>'file'), //需要放在 literal 后面解析
		'case'		=> array('attrs'=>'value'), //需要配合 switch 标签一起使用
		'default'	=> array(), //需要配合 switch 标签一起使用
		'nocache'	=> array(),
	);

	/**
	 * switch 语法的 case 分支, 需要配合 switch 标签一起使用
	 * {case value=""}{/case}
	 */
	public function _case($attrs,$content){
		$value = $attrs['value'];
		$content = addslashes($content);
		return "case '{$value }': echo \"{$content }\"; break;";
	}

	/**
	 * switch 语法的 default 分支, 需要配合 switch 标签一起使用
	 * {default}{/default}
	 */
	public function _default($attrs,$content){
		$phpCode = 'default: echo "';
		$phpCode .= addslashes($content);
		$phpCode .= '";';
		return $phpCode;
	}

	/**
	 * 局部不缓存, 该标签中间的内容不会被缓存, 需要配合 noCacheCallback() 方法一起使用
	 * {nocache}{/nocache}
	 */
	public function _nocache($attrs,$content){
		$crlf = PHP_EOL;
		$salt = md5(rand(100000,999999)); //生成随机值
		$class = __CLASS__;
		$phpCode = '<?php if($this->isCache){ ';
		$phpCode .= "\$__str=<<<'_CACHE_DATA_{$salt}'{$crlf}{$content}{$crlf}_CACHE_DATA_{$salt};{$crlf} echo {$class}::noCacheCallback(\$__str); ?>";
		$phpCode .= '<?php }else{ ?> ';
		$phpCode .= $content;
		$phpCode .= ' <?php } ?>';
		return $phpCode;
	}

	/**
	 * nocache 标签解析方法的延迟回调函数
	 */
	public static function noCacheCallback($str){
		return $str;
	}

	/**
	 * 原样输出标签, 需要配合 restoreLiteral() 方法一起使用
	 * {literal}{/literal}
	 */
	public function _literal($attrs,$content){
		$content = htmlentities($content);
		$content = str_replace(array('{','}','$'),array('_LITERAL_1','_LITERAL_2','_LITERAL_3'),$content);
		return "<pre>{$content}</pre>";
	}

	/**
	 * 还原 literal 标签解析方法替换过的字符
	 */
	public static function restoreLiteral(&$str){
		return str_replace(array('_LITERAL_1','_LITERAL_2','_LITERAL_3'),array('{','}','$'),$str);
	}

	/**
	 * 包含模板文件, 可多次调用, 被包含的文件支持解析
	 * {include file="模板文件名"/}
	 */
	public function _include($attrs,$content){
		$file = $this->getTemplate()->templateDir . trim($attrs['file']);
		return file_get_contents($file);
	}
}
