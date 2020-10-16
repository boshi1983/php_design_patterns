<?php

class OrangeBatisException extends Exception
{
}

class OrangeBatis
{
    //是否强制编译代理类文件
    static $forceCompile = false;
    static $dirCompile = 'ProxyTemp/';
    static $dirXml = 'DB/XmlModel/';

    /**
     * 反射内存池
     * @var array
     */
    private $reflectionList = [];

    private function __construct()
    {
    }


    /**
     * 下划线转驼峰
     * 思路:
     * step1.原字符串转小写,原字符串中的分隔符用空格替换,在字符串开头加上分隔符
     * step2.将字符串中每个单词的首字母转换为大写,再去空格,去字符串首部附加的分隔符.
     */
    public static function camelize($uncamelized_words,$separator='_', $firstup = false)
    {
        $uncamelized_words = $separator. str_replace($separator, " ", strtolower($uncamelized_words));
        $rt = ltrim(str_replace(" ", "", ucwords($uncamelized_words)), $separator );
        if ($firstup) {
            $rt = ucfirst($rt);
        }
        return $rt;
    }

    /**
     * 驼峰命名转下划线命名
     * 思路:
     * 小写和大写紧挨一起的地方,加上分隔符,然后全部转小写
     */
    public static function uncamelize($camelCaps, $separator='_')
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', "$1" . $separator . "$2", $camelCaps));
    }

    /**
     * @param string $className
     * @return string
     */
    private function getClassShortName(string $className)
    {
        return basename(str_replace('\\', '/', $className));
    }

    /**
     * 获取反射函数
     * @param string $className 类名
     * @return mixed
     * @throws OrangeBatisException
     */
    private function getReflectionByClassName(string $className)
    {
        if (!isset($this->reflectionList[$className]) || empty($this->reflectionList[$className])) {
            try {
                $this->reflectionList[$className] = new ReflectionClass($className);
            } catch (Exception $e) {
                throw new OrangeBatisException($e);
            }
        }
        return $this->reflectionList[$className];
    }

    /**
     * 生成ORM操作类
     * @param string $interFaceName 接口类名
     * @throws OrangeBatisException
     */
    private function generatedClass(string $interFaceName)
    {
        $xmlName = substr($interFaceName, 1, strlen($interFaceName) - 4);
        $xmlContent = $this->parseXml($xmlName);

        $reflection = $this->getReflectionByClassName($interFaceName);
        $baseReflextion = $this->getReflectionByClassName('BaseBatisProxy');

        $methodList = $reflection->getMethods();
        $functionList = [];
        foreach ($methodList as $method) {
            $methodName = $method->getName();
            $hasMethod = $baseReflextion->hasMethod($methodName);
            if (!$hasMethod) {
                $xmlNode = $xmlContent[$methodName] ?? null;
                if (!empty($xmlNode)) {
                    $functionList[] = $this->generateMethod($method, $xmlNode);
                } else {
                    throw new OrangeBatisException('not find decleared ' . $methodName . ' defined!');
                }
            }
        }

        $class = 'final class ' . $xmlName . 'Proxy extends BaseBatisProxy implements ' . $interFaceName . ' {' . PHP_EOL;
        $class .= 'const VERSION = ' . microtime(true) . ';' . PHP_EOL;
        $class .= join(PHP_EOL . PHP_EOL, $functionList) . PHP_EOL;

        $class .= '}' . PHP_EOL;

        $path = ROOT_PATH . self::$dirCompile . $xmlName . 'Proxy.php';
        file_put_contents($path, '<?php' . PHP_EOL . $class);

        if (function_exists('opcache_invalidate')
            && (!function_exists('ini_get') || strlen(ini_get("opcache.restrict_api")) < 1)
        ) {
            opcache_invalidate($path, true);
        } elseif (function_exists('apc_compile_file')) {
            apc_compile_file($path);
        }

        //加载动态代理类
        eval($class);
    }

    /**
     * 把查询出来的数据，赋值到对象
     * @param array $xmlNode xml节点
     * @param ReflectionNamedType $returnType
     * @return string
     * @throws OrangeBatisException
     */
    private function setValue(array $xmlNode, ReflectionNamedType $returnType)
    {
        $class = $xmlNode['resultType'];

        $value = '$row';
        $obj = '';
        switch ($returnType->getName()) {
            case 'array':
                $obj .= '$result = &$this->db->query($sql);' . PHP_EOL;
                $obj .= '$list = [];' . PHP_EOL;
                $obj .= 'if(is_array($result)) { foreach ($result as $row) {' . PHP_EOL;
                $obj .= $this->row2Object($class, $value);
                $obj .= '$list[] = $obj;' . PHP_EOL;
                $obj .= '}}' . PHP_EOL;
                $obj .= 'return $list;' . PHP_EOL;
                break;
            default:
                $obj .= $value . ' = &$this->db->get($sql);' . PHP_EOL;
                $obj .= $this->row2Object($class, $value);
                $obj .= 'return $obj;' . PHP_EOL;
                break;
        }

        return $obj;
    }

    /**
     * 生成行转对象的代码段
     * @param string $class
     * @param string $value
     * @return string
     * @throws OrangeBatisException
     */
    private function row2Object(string $class, string $value)
    {
        $obj = '$obj = new ' . $class . '();' . PHP_EOL;
        $obj .= 'if(!empty('.$value.')) {' . PHP_EOL;
        $reflection = $this->getReflectionByClassName($class);
        $methodList = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methodList as $method) {
            $name = $method->getName();
            if (strpos($name, 'set') !== 0)
                continue;

            $varname = self::uncamelize(substr($name, 3));
            $obj .= '$obj->' . $name . '(' . $value . '[\'' . $varname . '\']??\'\');' . PHP_EOL;
        }
        $obj .= '}' . PHP_EOL;

        return $obj;
    }

    /**
     * 准备sql bind参数
     * @param $sql
     * @param $methodName
     * @param $varname
     * @param $value
     * @return string
     */
    private function getValueBySql($sql, $methodName, $varname, $value)
    {
        $obj = '';
        if (strpos($sql, ':' . $varname) !== false) {
            $obj .= 'if (!is_null(' . $value . '->' . $methodName . '())) {' . PHP_EOL;
            $obj .= '$this->db->bindParam(\'' . $varname . '\', ' . $value . '->' . $methodName . '());' . PHP_EOL;
            $obj .= '}' . PHP_EOL;
        }

        return $obj;
    }

    /**
     * 准备sql bind参数
     * @param $value
     * @param $class
     * @param $sql
     * @return string
     * @throws OrangeBatisException
     */
    private function getValue($value, $class, $sql)
    {
        if (empty($class)) {
            $class = 'string';
        }

        $obj = '';
        $reflection = $this->getReflectionByClassName($class);
        $methodList = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methodList as $method) {
            $methodName = $method->getName();
            if (strpos($methodName, 'get') !== 0)
                continue;

            $varname = self::uncamelize(substr($methodName, 3));
            $obj .= $this->getValueBySql($sql, $methodName, $varname, $value);
        }

        return $obj;
    }

    /**
     * 解析xml
     * @param $xmlName
     * @return array
     * @throws OrangeBatisException
     */
    private function &parseXml($xmlName)
    {
        $path = ROOT_PATH . self::$dirXml . self::uncamelize($xmlName) . '.xml';
        $mapper = simplexml_load_file($path);
        if (empty($mapper)) {
            throw new OrangeBatisException(libxml_get_errors());
        }

        $xmlContent = [];
        foreach ($mapper->children() as $key => $value) {

            $id = trim($value->attributes()['id']);
            $xmlContent[$id] = [
                'id' => $id,
                'tag' => $key,
                'resultType' => trim($value->attributes()['resultType']),
                'sql' => trim($value)
            ];
        }

        return $xmlContent;
    }

    /**
     * 生成ORM操作方法
     * @param ReflectionMethod $method
     * @param array $xmlNode
     * @return string
     * @throws OrangeBatisException
     */
    private function generateMethod(ReflectionMethod $method, array $xmlNode)
    {
        $function = 'public function ' . $method->getName() . '(';

        $reflectionParameters = $method->getParameters();
        $paramList = [];
        $paramArr = [];
        foreach ($reflectionParameters as $parameter) {
            $paramStr = '';
            $param = ['name' => '', 'type' => ''];

            $type = $parameter->getType();
            if (!is_null($type)) {
                $className = $this->getClassShortName($type->getName());
                $paramStr .= $className . ' ';

                $param['type'] = $className;
            }
            $paramStr .= '$' . $parameter->getName();

            $paramArr[] = $paramStr;

            $param['name'] = trim($parameter->getName());
            $paramList[] = $param;
        }

        $function .= join(', ', $paramArr);

        $function .= ')';
        $return = $method->getReturnType();
        if (!is_null($return)) {
            $function .= ':' . $this->getClassShortName($return->getName());
        }

        $function .= '{' . PHP_EOL;
        $function .= $this->generateMethodContent($method, $paramList, $xmlNode);
        $function .= '}' . PHP_EOL;

        return $function;
    }

    /**
     * 生成操作方法体
     * @param $method
     * @param $paramList
     * @param $xmlNode
     * @return string
     * @throws OrangeBatisException
     */
    public function generateMethodContent($method, $paramList, $xmlNode)
    {
        $content = '$sql = "' . $xmlNode['sql'] . '";' . PHP_EOL;

        foreach ($paramList as $param) {
            if (in_array($param['type'], ['int', 'string', 'float', 'double', 'bool', 'boolean', ''])) {
                if (strpos($xmlNode['sql'], ':' . $param['name']) !== false) {
                    $content .= '$this->db->bindParam(\'' . $param['name'] . '\', $' . $param['name'] . ');' . PHP_EOL;
                }
            } elseif (in_array($param['type'], ['array', 'object'])) {
                $content .= 'foreach ($' . $param['name'] . ' as $key => $value) {' . PHP_EOL;
                $content .= 'if (strpos($sql, \':\' . $key) !== false) {' . PHP_EOL;
                $content .= '$this->db->bindParam($key, $value);' . PHP_EOL;
                $content .= '}' . PHP_EOL;
                $content .= '}' . PHP_EOL;
            } else {
                $content .= $this->getValue('$' . $param['name'], $param['type'], $xmlNode['sql']) . PHP_EOL;
            }
        }

        if ($xmlNode['tag'] == 'select') {
            if ($method->getReturnType()->getName() == 'int') {
                $content .= 'return $this->db->getOne($sql);' . PHP_EOL;
            } else {
                $content .= $this->setValue($xmlNode, $method->getReturnType());
            }
        } else {
            //update, insert, delete
            $content .= 'return $this->db->execute($sql);' . PHP_EOL;
        }

        return $content;
    }

    //--------------------------------------------------------------------------------------------------------------

    /**
     * @param $path
     * @param $xml
     * @return bool
     * @throws OrangeBatisException
     */
    private static function checkFileTime($path, $xml)
    {
        $thisTime = filemtime(__FILE__);
        $pathTime = file_exists($path) ? filemtime($path) : 0;
        $xmlTime = file_exists($xml) ? filemtime($xml) : 0;
        if (empty($xmlTime)) {
            throw new OrangeBatisException('xml "'.$xml.'" is not found!');
        }

        return ($pathTime >= max($thisTime, $xmlTime));
    }

    /**
     * @param $interFaceName
     * @return mixed
     * @throws OrangeBatisException
     */
    public static function getMapper($interFaceName)
    {
        //去掉前缀i和后缀Dao
        $xmlName = substr($interFaceName, 1, strlen($interFaceName) - 4);

        $className = $xmlName . 'Proxy';
        $path = ROOT_PATH . self::$dirCompile . $className . '.php';
        $xml = ROOT_PATH . self::$dirXml . self::uncamelize($xmlName) . '.xml';

        if (self::$forceCompile || !file_exists($path) || !self::checkFileTime($path, $xml)) {
            //强制编译 或者 编译文件不存在
            (new OrangeBatis())->generatedClass($interFaceName);
        } else {
            /** @noinspection PhpIncludeInspection */
            require_once $path;
        }

        return Container::instance()->get($className);
    }
}