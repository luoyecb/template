<?php
/***************************************************
 * PHP 模板引擎
 ***************************************************/
defined('LEFT_DELIM') or define('LEFT_DELIM', '{');   //左分隔符
defined('RIGHT_DELIM') or define('RIGHT_DELIM', '}'); //右分隔符
// 包含标签库
require 'TagLib.php';

/**
 * @author guolinchao
 * @email luoyecb@163.com
 */
class Template
{
	// 模板目录
	public $templateDir = './templates/';
	// 编译文件目录
	public $compileDir = './templates_c/';
	// 配置文件目录
	public $configDir = './config/';
	// 缓存目录
	public $cacheDir = './cache/';
	// 缓存有效期, 单位秒
	public $cacheLifetime = 60;
	// 是否开启页面缓存
	public $isCache = false;
	// 模板变量容器
	private $_vars = array();
	// 模板文件
	private $tplFile = '';
	// 编译文件
	private $compFile = '';
	// 缓存文件
	private $cacheFile = '';
	
	public final function __construct() {
		$this->registerSysVariables();
	}
	
	/**
	 * 注册系统变量
	 * 支持的系统变量有: session get post cookie request server
	 * 支持常量获取: {$sysvar.const }
	 */
	protected final function registerSysVariables() {
		$this->_vars['sysvar'] = array(
			'get' 	 => $_GET,
			'post'	 => $_POST,
			'request' => $_REQUEST,
			'cookie' => $_COOKIE,
			'server' => array_change_key_case($_SERVER, CASE_LOWER),
			'session'=> isset($_SESSION) ? $_SESSION : array(),
			'const'  => array_change_key_case(get_defined_constants(true)['user'], CASE_LOWER)
		);
	}
	
	// 表单令牌验证
	public function checkToken(){
		if(isset($_SESSION['TOKEN_NAME'],$_REQUEST['TOKEN_NAME'])){
			if($_SESSION['TOKEN_NAME'] != $_REQUEST['TOKEN_NAME']){
				$referer = $_SERVER['HTTP_REFERER'];
				header('Refresh: 3;url=' . $referer);
				echo '表单重复提交，请<a href="' . $referer . '">返回</a>后刷新页面再试！';
				exit();
			}
			$_SESSION['TOKEN_NAME'] = md5(microtime()); //重新生成
		}
	}
	
	// 单实例
	public function instance($className){
		static $clsContainer = array();
		if(!isset($clsContainer[$className])){
			$clsContainer[$className] = new $className($this);
		}
		return $clsContainer[$className];
	}
	
	// 生成缓存文件名
	public function generateCacheFilename($file,$cacheId=NULL){
		$prefix = $cacheId ? $cacheId . '_' : '';
		return $prefix . $file . '.php';
	}

	// 替换目录分隔符, 处理多级目录
	public function replaceDirSeparator($dir){
		return str_replace('/', '_dir_', $dir);
	}
	
	// 编译
	protected function compile(){
		// 模板文件存在且未修改，不必重新编译
		if(file_exists($this->compFile) && 
				filemtime($this->tplFile) < filemtime($this->compFile)){
			if(!defined('DEBUG') || !DEBUG){ //DEBUG模式下每次都编译
				return ;
			}
		}else{
			// 检查编译目录
			if(!is_dir($this->compileDir)){
				mkdir($this->compileDir,0777,true);
			}else if(!is_writable($this->compileDir)){
				exit('编译目录不可写');
			}
		}
		$tplData = file_get_contents($this->tplFile);
		$tplData = $this->parse($tplData); //解析
		file_put_contents($this->compFile,$tplData); //写入编译文件
	}

	// 缓存
	protected function cache(){
		// 检查缓存文件是否过期
		if(file_exists($this->cacheFile) && 
				time()-filemtime($this->cacheFile)<$this->cacheLifetime){
			if(!defined('DEBUG') || !DEBUG){ //DEBUG模式下每次都编译
				return ;
			}
		}else{
			// 检查缓存目录
			if(!is_dir($this->cacheDir)){
				mkdir($this->cacheDir,0777,true);
			}else if(!is_writable($this->cacheDir)){
				exit('缓存目录不可写');
			}
		}
		file_put_contents($this->cacheFile,$this->includeTpl($this->compFile)); //写入缓存文件
	}

	// 包含一个模板文件
	protected function includeTpl($file){
		ob_start();
		extract($this->_vars,EXTR_OVERWRITE);
		$preErrLevel = error_reporting(); //记录之前的错误级别
		error_reporting($preErrLevel & ~E_NOTICE); //屏蔽 notice 级别的错误
		include $file;
		error_reporting($preErrLevel); //还原之前的错误级别
		return ob_get_clean();
	}
	
	// 清除指定缓存
	public function clearCache($file,$cacheId=NULL){
		$file = $this->replaceDirSeparator($file);
		if($cacheId){
			unlink($this->cacheDir . $this->generateCacheFilename($file,$cacheId));
		}else{
			$files = scandir($this->cacheDir);
			foreach ($files as $filename){
				if(stripos($filename,$file) !== false){
					unlink($this->cacheDir . $filename);
				}
			}
		}
	}
	
	// 清除所有缓存
	public function clearAllCache(){
		$files = scandir($this->cacheDir);
		foreach ($files as $filename){
			if ($filename != '.' && $filename != '..'){
				unlink($this->cacheDir . $filename);
			}
		}
	}
	
	// 单一赋值、批量赋值
	public function assign($name,$value=NULL){
		if(is_array($name)){
			$this->_vars = array_merge($this->_vars,$name);
		}else{
			$this->_vars[$name] = $value;
		}
	}
	
	// 返回解析之后的模板内容
	public function fetch($file, $cacheId=NULL){
		$this->tplFile = $this->templateDir . $file;
		$file = $this->replaceDirSeparator($file);
		$this->compFile = $this->compileDir . md5($file) . $file . '.php';
		$this->compile();
		if($this->isCache){
			$this->cacheFile = $this->cacheDir . $this->generateCacheFilename($file,$cacheId);
			$this->cache();
		}
		return $this->includeTpl($this->isCache ? $this->cacheFile : $this->compFile);
	}
	
	// 渲染模板
	public function display($file,$cacheId=NULL){
		echo $this->fetch($file,$cacheId);
	}

	// {$var}格式的变量解析, 不支持算术运算
	protected function parseVariables(&$data){
		return preg_replace_callback('/\{\$(.*?)\}/', function($matchs){
			$parts = array_map('trim', explode('|',$matchs[1])); //去除空格
			$parts = array_filter($parts); //过滤掉空元素
			$phpcode = $this->parseObjArrVarPart($parts[0]); //解析第一部分
			unset($parts[0]);
			if(!isset($parts[1])){ //不需要解析函数或默认值
				return "<?php echo ($phpcode); ?>";
			}else{
				// {$var|default='默认值' }
				if(preg_match('/default\s*=\s*(\'|\")(.*?)\1/i', $parts[1], $matchs)){
					$defaultValue = addslashes($matchs[2]);
					return "<?php if(isset($phpcode)){ echo ($phpcode); }else{ echo \"$defaultValue\"; } ?>";
				}else{
					// 函数调用
					foreach($parts as $part){
						$func_code = $this->parseFuncPart($part);
						$phpcode = str_replace('###', $phpcode, $func_code);
					}
					return "<?php echo {$phpcode}; ?>";
				}
			}
		},$data);
	}
	
	/**
	 * <pre>
	 * 解析数组和对象格式调用
	 * 数组: {$arr.name.length }, {$arr.key } 支持数组级联操作,不支持数组对象混合级联操作
	 * 对象: {$user->name }, 支持对象的属性级联操作,不支持数组对象混合级联操作
	 * @param string $args 	{$user.age|md5|substr=###,0,10 }的第一个竖线(|)之前的字符串传递给该参数
	 * </pre>
	 */
	protected function parseObjArrVarPart($args){
		if(strpos($args,'.') !== false){ //数组
			$parts = array_map('trim', explode('.',$args)); //去除每一部分的空格
			$code = '$' . $parts[0];
			unset($parts[0]);
			foreach ($parts as $part){
				$code .= "['$part']";
			}
			return $code;
		}else{ //对象
			return '$' . $args; //{$user->name } 或者 {$var }
		}
	}
	
	/**
	 * <pre>
	 * 函数调用: {$var|substr=###,0,10|md5 }, 支持一个或多个函数,不能和 default 一起使用,###代表该变量,是一个占位符
	 * 	函数的参数必须是和 php 代码中的写法一致,如若是字符串则需要加引号
	 * @param string $args	{$user.age|md5|substr=###,0,10 }的第一个竖线(|)之后的每一部分都会传递给该参数,eg: md5或者substr=###,0,10
	 * </pre>
	 */
	protected function parseFuncPart($args){
		$arra = array_map('trim', explode('=',$args)); //分隔函数名和参数列表
		if(!isset($arra[1])){
			$arra[1] = '###';
		}
		return $arra[0] . '('.$arra[1].')';
	}

	// 解析模板
	protected function parse(&$data){
		// 模板继承解析
		$ex = $this->instance('TemplateInheritTagLib');
		$data = $ex->restoreBlock($ex->parse($data));
		// 标签库
		$data = $this->instance('CoreBodyTagLib')->parse($data);
		$data = $this->instance('CoreTagLib')->parse($data);
		$data = $this->parseVariables($data);
		// 解析注释
		// {/* 多行注释 */} 或者  {// 单行注释 }
		$data = preg_replace(array('!\{\s*//.*?\}!','!\{\s*/\*.*?\*/\s*\}!s'),'',$data);
		// 解析方法调用
		// {:funcName(arg1,arg2,...) }
		$data = preg_replace('/\{:\s*([a-zA-Z_]\w*)\s*\((.*?)\)\s*\}/', '<?php echo $1($2); ?>', $data);
		$data = preg_replace('/\?>\s*<\?php/', '', $data);
		$data = preg_replace('/^\s*\r?\n/m', '', $data); //去除空行
		return CoreBodyTagLib::restoreLiteral($data);
	}
	
	// 解析字符串
	public function parseTemplate($tplData){
		$tmpFile = $this->compileDir . md5($tplData) . '.php';
		if(!file_exists($tmpFile)){			
			file_put_contents($tmpFile,$this->parse($tplData));
		}
		return $this->includeTpl($tmpFile);
	}
}
