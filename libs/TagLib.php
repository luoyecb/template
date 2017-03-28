<?php
/***************************************************
 * 解析标签属性
 ***************************************************/
abstract class AttributesParser
{
	// Template 类的实例
	protected $template = null;

	public function __construct($tpl=null){
		if($tpl instanceof Template){
			$this->template = $tpl;
		}
	}

	// 返回 Template 类的实例
	public function getTplIns(){
		return $this->template;
	}

	/**
	 * <pre>
	 * 属性解析方法
	 * 若属性是 key="valule" 格式, 则对应数组项为 key='value', 否则为 key=true(布尔值true)
	 * @param string $attrString 属性字符串形式
	 * @return array 属性关联数组
	 * </pre>
	 */
	public function parseAttrs($attrString){
		$attrs = array(); //属性关联数组
		if(preg_match_all('/([a-zA-Z_$]\w*)(?:\s*=\s*("|\')(.*?)\2)?/',$attrString,$matches,PREG_SET_ORDER)){
			foreach ($matches as $value){
				$attrs[$value[1]] = isset($value[3]) ? $value[3] : true;
			}
		}
		return $attrs;
	}
}

/***************************************************
 * 标签库解析基类
 * 支持解析闭合标签和非闭合标签, 对于闭合标签是将其分为开始标签<tag>和结束标签</tag>两部分来分别解析
 * 对于闭合标签不能获取标签之间的内容
 * Example:
 *  对于闭合标签<loop></loop>, _start_loop() 解析 <loop> 部分, _end_loop() 解析 </loop> 部分,
 *    同时会向 _start_loop() 函数传递标签的属性关联数组作为参数
 *  对于非闭合标签<tag/>, 仅需 _start_tag() 解析即可, 标签名后的斜线(/) 可以省略
 ***************************************************/
abstract class TagLibSupport extends AttributesParser
{
	// 标签数组定义
	protected $tags = array();
	// 存储当前标签属性
	protected $tagAttrs = array();
	// 当前解析的标签名
	protected $currTagName = '';

	public function parse(&$str){
		$l = preg_quote(LEFT_DELIM); //转义分隔符中的特殊字符
		$r = preg_quote(RIGHT_DELIM);
		$pattern = sprintf('!%s(/?)([a-zA-Z_]\w*)([^\r\n%s]*?)(/?)\s*%s!',$l,$r,$r);
		return preg_replace_callback($pattern,array($this,'pregCallback'), $str);
	}

	// 回调函数
	protected function pregCallback($matches){
		$this->currTagName = $tagName = $matches[2]; //标签名
		if(isset($this->tags[$tagName])){
			if($matches[1] == '/'){ //结束标签
				$endMethod = '_end_' . $tagName; //结束标签解析方法
				$str = $this->$endMethod();
				array_pop($this->tagAttrs[$tagName]); //释放当前标签的属性, 出栈
			}else{ //开始标签
				$attrArr = $this->parseAttrs($matches[3]); //标签属性
				$this->tagAttrs[$tagName][] = $attrArr; //保存当前标签属性, 入栈
				$startMethod = '_start_' . $tagName; //开始标签解析方法
				$str = $this->$startMethod($attrArr);
			}
			return $str;
		}
		return $matches[0];
	}

	// 获取当前标签属性数组
	public function getCurrTagAttrs(){
		return end($this->tagAttrs[$this->currTagName]); //最后一个
	}

	// 获取标签的某个属性
	public function getAttribute($attrName){
		$attrs = $this->getCurrTagAttrs();
		if($attrs){
			return $attrs[$attrName];
		}
		return false;
	}

	// 动态给当前标签添加属性
	public function setAttribute($attrName,$attrVal){
		$size = count($this->tagAttrs[$this->currTagName]);
		$this->tagAttrs[$this->currTagName][$size-1][$attrName] = $attrVal;
	}
}

/***************************************************
 * 标签库解析基类
 * 仅支持解析非闭合标签, 可以获取标签中间内容
 * Example:
 *  对于闭合标签<tag></tag>, 解析方法为 _tag(), 同时传递两个参数,
 *   参数一: 属性关联数组
 *   参数二: 标签中间内容
 ***************************************************/
abstract class BodyTagLibSupport extends AttributesParser
{
	// 标签数组定义
	protected $tags = array();
	// 当前解析的标签名
	protected $currTagName = '';

	public function parse(&$str){
		$l = preg_quote(LEFT_DELIM); //转义分隔符中的特殊字符
		$r = preg_quote(RIGHT_DELIM);
		foreach($this->tags as $tagName=>$val){
			$this->currTagName = $tagName;
			$pattern = sprintf('!%s\s*%s([^\r\n%s]*?)(?:/\s*%s|%s(.*?)%s/%s\s*%s)!is',$l,$tagName,$r,$r,$r,$l,$tagName,$r);
			$str = preg_replace_callback($pattern,array($this,'pregCallback'),$str);
		}
		return $str;
	}

	// 回调函数
	protected function pregCallback($matches){
		$attrString = $matches[1];
		$content = isset($matches[2]) ? $matches[2] : '';
		$callback = '_' . $this->currTagName; //标签解析函数
		return $this->$callback($this->parseAttrs($attrString),$content);
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
	 * <pre>
	 * 需要配合 extends 标签一起使用
	 * {block name=""}{/block}
	 * </pre>
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
	 * <pre>
	 * 继承模板文件
	 * {extends parent=""/}
	 * </pre>
	 */
	public function _extends($attrs,$content){
		$extendsFile = $this->getTplIns()->templateDir . $attrs['parent'];
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
	 * <pre>
	 * 支持 for 循环语法
	 * 支持的运算符有: lt(<) gt(>) le(<=) ge(>=)
	 * {for name="" start="" stop="" step="1" comparison="lt"}
	 * </pre>
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
	 * <pre>
	 * 支持 foreach 语法
	 * 格式: {foreach $var as $val}{/foreach} 或者 {foreach $var as $key $val}{/foreach}
	 * </pre>
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
	 * <pre>
	 * 加载配置文件, 默认目录为 configDir, 可以使用 path 属性重新指定, file 指明配置文件名(.ini类型)
	 * 需要配合标签 config 一起使用
	 * {cfgload path="" file=""/}
	 * </pre>
	 */
	public function _start_cfgload($attrs){
		// 加载配置文件
		if(isset($attrs['path'])){
			$cfg = rtrim($attrs['path'],'/') . '/' . $attrs['file'];
		}else {
			$cfg = $this->getTplIns()->configDir . $attrs['file'];
		}
		if(file_exists($cfg)){
			return "<?php if(!isset(\$this->_vars['__cfg'])){ \$this->_vars['__cfg']=array(); } " .
				"\$this->_vars['__cfg']=array_merge(\$this->_vars['__cfg'],parse_ini_file('{$cfg}')); ?>";
		}else{
			return '';
		}
	}
	
	/**
	 * <pre>
	 * 读取指定配置项, 需要和 cfgload 标签一起使用
	 * 格式: {config name="key"/} 或者 {config key/}
	 *  name 属性指定配置项key, 或者 key 直接以属性的形式表示
	 * </pre>
	 */
	public function _start_config($attrs){
		$cfgkey = isset($attrs['name']) ? $attrs['name'] : key($attrs); //获取键值
		return "<?php echo \$this->_vars['__cfg']['{$cfgkey}']; ?>";
	}

	/**
	 * <pre>
	 * 表单令牌, 防止表单重复提交
	 * {token/}
	 * </pre>
	 */
	public function _start_token($attrs){
		if(isset($_SESSION)){
			$key = md5(microtime());
			$_SESSION['TOKEN_NAME'] = $key;
			return '<input type="hidden" name="TOKEN_NAME" value="'.$key.'"/>';
		}
		return '';
	}

	/**
	 * <pre>
	 * 循环输出变量
	 * {loop name="" item="" key="k" index='index'} {$user.name} {/loop}
	 * 属性 name: 指明被循环的变量
	 * 属性 item: 循环体内的临时变量名
	 * 属性 key: 数组的键值, 默认是 k
	 * 属性 index: 迭代序号(从1开始), 默认是 index
	 * </pre>
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
	 * <pre>
	 * 支持 switch 语法
	 * {switch name=""}
	 * 	 {case value=""}{/case} {case value=""}{/case} {default}{/default}
	 * {/switch}
	 * </pre>
	 */
	public function _start_switch($attrs){
		return "<?php switch(\${$attrs['name']}){ ";
	}

	public function _end_switch(){
		return ' } ?>';
	}

	/**
	 * <pre>
	 * 支持 if 语法, 可以使用的内嵌标签有: elseif elif else
	 * 支持的运算符有: eq lt gt le ge and or neq not heq nheq
	 * test 属性支持二级点语法, 变量形式为: $var[.key]
	 * {if test=""}{/if}
	 * </pre>
	 */
	public function _start_if($attrs){
		$test = $this->parseAttrTest($attrs['test']);
		return "<?php if({$test }){ ?>";
	}

	public function _end_if(){
		return '<?php } ?>';
	}

	/**
	 * <pre>
	 * 支持 else if 语法, 其它特性同 if 标签
	 * {elseif test=""/}
	 * </pre>
	 */
	public function _start_elseif($attrs){
		$test = $this->parseAttrTest($attrs['test']);
		return "<?php }elseif({$test }){ ?>";
	}

	/**
	 * <pre>
	 * elseif 标签的别名
	 * {elif /}
	 * </pre>
	 */
	public function _start_elif($attrs){
		return $this->_start_elseif($attrs);
	}

	/**
	 * <pre>
	 * 可以使用 else 标签的标签有: if, between, in
	 * {else/}
	 * </pre>
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
	 * <pre>
	 * 变量值是某几个散列值中的一个, 适用于数字、字符串, else 标签可选
	 * {in name="" value=""} {else/} {/in}
	 * Example:
	 *   {in name="age" value="1,3,5,8"} ... {/in}
	 * </pre>
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
	 * <pre>
	 * 变量值在某个范围内, 包含端点值, 但仅适用于数字, else 标签可选
	 * {between name="" value=""} {else/} {/between}
	 * Example:
	 *   {between name="age" value="1,10"} ... {/between}
	 * </pre>
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
	 * <pre>
	 * 使用该标签, 可以在模板中直接书写 php 代码
	 * {php} PHP Code {/php}
	 * </pre>
	 */
	public function _start_php($attrs){
		return "<?php ";
	}

	public function _end_php(){
		return ' ?>';
	}

	/**
	 * <pre>
	 * 模板文件中给变量赋值, 支持数字、字符串、布尔值
	 * {assign name="" value=""/}
	 * </pre>
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
	 * <pre>
	 * switch 语法的 case 分支, 需要配合 switch 标签一起使用
	 * {case value=""}{/case}
	 * </pre>
	 */
	public function _case($attrs,$content){
		$value = $attrs['value'];
		$content = addslashes($content);
		return "case '{$value }': echo \"{$content }\"; break;";
	}

	/**
	 * <pre>
	 * switch 语法的 default 分支, 需要配合 switch 标签一起使用
	 * {default}{/default}
	 * </pre>
	 */
	public function _default($attrs,$content){
		$phpCode = 'default: echo "';
		$phpCode .= addslashes($content);
		$phpCode .= '";';
		return $phpCode;
	}

	/**
	 * <pre>
	 * 局部不缓存, 该标签中间的内容不会被缓存, 需要配合 noCacheCallback() 方法一起使用
	 * {nocache}{/nocache}
	 * </pre>
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
	 * <pre>
	 * 原样输出标签, 需要配合 restoreLiteral() 方法一起使用
	 * {literal}{/literal}
	 * </pre>
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
	 * <pre>
	 * 包含模板文件, 可多次调用, 被包含的文件支持解析
	 * {include file="模板文件名"/}
	 * </pre>
	 */
	public function _include($attrs,$content){
		$file = $this->getTplIns()->templateDir . trim($attrs['file']);
		return file_get_contents($file);
	}
}
