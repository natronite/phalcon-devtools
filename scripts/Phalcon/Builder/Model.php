<?php

/*
  +------------------------------------------------------------------------+
  | Phalcon Developer Tools                                                |
  +------------------------------------------------------------------------+
  | Copyright (c) 2011-2014 Phalcon Team (http://www.phalconphp.com)       |
  +------------------------------------------------------------------------+
  | This source file is subject to the New BSD License that is bundled     |
  | with this package in the file docs/LICENSE.txt.                        |
  |                                                                        |
  | If you did not receive a copy of the license and are unable to         |
  | obtain it through the world-wide-web, please send an email             |
  | to license@phalconphp.com so we can send you a copy immediately.       |
  +------------------------------------------------------------------------+
  | Authors: Andres Gutierrez <andres@phalconphp.com>                      |
  |          Eduar Carvajal <eduar@phalconphp.com>                         |
  +------------------------------------------------------------------------+
*/

namespace Phalcon\Builder;

use Phalcon\Db\Column;
use Phalcon\Builder\Component;
use Phalcon\Builder\BuilderException;
use Phalcon\Script\Color;
use Phalcon\Text as Utils;

/**
 * ModelBuilderComponent
 *
 * Builder to generate models
 *
 * @category    Phalcon
 * @package    Builder
 * @subpackage  Model
 * @copyright   Copyright (c) 2011-2014 Phalcon Team (team@phalconphp.com)
 * @license    New BSD License
 */
class Model extends Component
{
    /**
     * Mapa de datos escalares a objetos
     *
     * @var array
     */
    private $_typeMap = array(//'Date' => 'Date',
        //'Decimal' => 'Decimal'
    );

    public function __construct($options)
    {
        if (!isset($options['name'])) {
            throw new BuilderException("Please, specify the model name");
        }
        if (!isset($options['force'])) {
            $options['force'] = false;
        }
        if (!isset($options['className'])) {
            $options['className'] = Utils::camelize($options['name']);
        }
        if (!isset($options['fileName'])) {
            $options['fileName'] = $options['name'];
        }
        parent::__construct($options);
    }

    /**
     * Returns the associated PHP type
     *
     * @param  string $type
     * @return string
     */
    public function getPHPType($type)
    {
        switch ($type) {
            case Column::TYPE_INTEGER:
                return 'integer';
                break;
            case Column::TYPE_DECIMAL:
            case Column::TYPE_FLOAT:
                return 'double';
                break;
            case Column::TYPE_DATE:
            case Column::TYPE_VARCHAR:
            case Column::TYPE_DATETIME:
            case Column::TYPE_CHAR:
            case Column::TYPE_TEXT:
                return 'string';
                break;
            default:
                return 'string';
                break;
        }
    }

    public function build()
    {
        $getSource = "
    public function getSource()
    {
        return '%s';
    }
";
        $templateThis = "        \$this->%s(%s);" . PHP_EOL;
        $templateRelation = "        \$this->%s('%s', '%s', '%s', %s);" . PHP_EOL;
        $templateSetter = "
    /**
     * Method to set the value of field %s
     *
     * @param %s \$%s
     * @return \$this
     */
    public function set%s(\$%s)
    {
        \$this->%s = \$%s;

        return \$this;
    }
";

        $templateValidateInclusion = "
        \$this->validate(
            new InclusionIn(
                array(
                    'field'    => '%s',
                    'domain'   => array(%s),
                    'required' => true,
                )
            )
        );";

        $templateValidateEmail = "
        \$this->validate(
            new Email(
                array(
                    'field'    => '%s',
                    'required' => true,
                )
            )
        );";

        $templateValidationFailed = "
        if (\$this->validationHasFailed() == true) {
            return false;
        }";

        $templateAttributes = "
    /**
     *
     * @var %s
     */
    %s \$%s;
";

        $templateGetterMap = "
    /**
     * Returns the value of field %s
     *
     * @return %s
     */
    public function get%s()
    {
        if (\$this->%s) {
            return new %s(\$this->%s);
        } else {
           return null;
        }
    }
";

        $templateGetter = "
    /**
     * Returns the value of field %s
     *
     * @return %s
     */
    public function get%s()
    {
        return \$this->%s;
    }
";

        $templateValidations = "
    /**
     * Validations and business logic
     */
    public function validation()
    {
%s
    }
";

        $templateInitialize = "
    /**
     * Initialize method for model.
     */
    public function initialize()
    {
%s
    }
";

        $templateFind = "
    /**
     * @return %s[]
     */
    public static function find(\$parameters = array())
    {
        return parent::find(\$parameters);
    }

    /**
     * @return %s
     */
    public static function findFirst(\$parameters = array())
    {
        return parent::findFirst(\$parameters);
    }
";

        $templateUse = 'use %s;';
        $templateUseAs = 'use %s as %s;';

        $templateCode = "<?php

%s%s%s%sclass %s extends %s
{
%s
}
";

        $propertyLineTemplate = "* @property %s %s";
        $propertiesTemplate = "/**
 * Class %s
 %s
 *%s
 */
";

        if (!$this->options->get('name')) {
            throw new BuilderException("You must specify the table name");
        }

        $path = '';
        if ($this->options->has('directory')) {
            if ($this->options->get('directory')) {
                $path = $this->options->get('directory') . '/';
            }
        } else {
            $path = '.';
        }

        $config = $this->getConfig($path);

        if (!$this->options->has('modelsDir')) {
            if (!isset($config->application->modelsDir)) {
                throw new BuilderException(
                    "Builder doesn't knows where is the models directory"
                );
            }
            $modelsDir = $config->application->modelsDir;
        } else {
            $modelsDir = $this->options->get('modelsDir');
        }

        $modelsDir = rtrim(rtrim($modelsDir, '/'), '\\') . DIRECTORY_SEPARATOR;

        if ($this->isAbsolutePath($modelsDir) == false) {
            $modelPath = $path . DIRECTORY_SEPARATOR . $modelsDir;
        } else {
            $modelPath = $modelsDir;
        }

        $methodRawCode = array();
        $className = $this->options->get('className');
        $modelPath .= $className . '.php';

        if (file_exists($modelPath)) {
            if (!$this->options->get('force')) {
                throw new BuilderException(
                    "The model file '" . $className .
                    ".php' already exists in models dir"
                );
            }
        }

        if (!isset($config->database)) {
            throw new BuilderException(
                "Database configuration cannot be loaded from your config file"
            );
        }

        if (!isset($config->database->adapter)) {
            throw new BuilderException(
                "Adapter was not found in the config. " .
                "Please specify a config variable [database][adapter]"
            );
        }

        if ($this->options->has('namespace')) {
            $package = '* @package ' . $this->options->get('namespace');
            $namespace = 'namespace ' . $this->options->get('namespace') . ';'
                . PHP_EOL . PHP_EOL;
            $methodRawCode[] = sprintf($getSource, $this->options->get('name'));
        } else {
            $package = '';
            $namespace = '';
        }

        $useSettersGetters = $this->options->get('genSettersGetters');
        if ($this->options->has('genDocMethods')) {
            $genDocMethods = $this->options->get('genDocMethods');
        } else {
            $genDocMethods = false;
        }

        $adapter = $config->database->adapter;
        $this->isSupportedAdapter($adapter);

        if (isset($config->database->adapter)) {
            $adapter = $config->database->adapter;
        } else {
            $adapter = 'Mysql';
        }

        if (is_object($config->database)) {
            $configArray = $config->database->toArray();
        } else {
            $configArray = $config->database;
        }

        // An array for use statements
        $uses = array();

        $adapterName = 'Phalcon\Db\Adapter\Pdo\\' . $adapter;
        unset($configArray['adapter']);
        $db = new $adapterName($configArray);

        $initialize = array();
        if ($this->options->has('schema')) {
            if ($this->options->get('schema') != $config->database->dbname) {
                $initialize[] = sprintf(
                    $templateThis, 'setSchema', '"' . $this->options->get('schema') . '"'
                );
            }
            $schema = $this->options->get('schema');
        } elseif ($adapter == 'Postgresql') {
            $schema = 'public';
            $initialize[] = sprintf(
                $templateThis, 'setSchema', '"' . $this->options->get('schema') . '"'
            );
        } else {
            $schema = $config->database->dbname;
        }

        if ($this->options->get('fileName') != $this->options->get('name')) {
        $initialize[] = sprintf(
            $templateThis, 'setSource',
            '\'' . $this->options->get('name') . '\''
        );
    }

        $table = $this->options->get('name');
        if ($db->tableExists($table, $schema)) {
            $fields = $db->describeColumns($table, $schema);
        } else {
            throw new BuilderException('Table "' . $table . '" does not exists');
        }


        $magicProperties = [];

        if ($this->options->has('hasMany')) {
            if (count($this->options->get('hasMany'))) {
                foreach ($this->options->get('hasMany') as $relation) {
                    $relation['options'] = [];
                    if (is_string($relation['fields'])) {
                        $entityName = $relation['camelizedName'];
                        if ($this->options->has('derivedNamespace')) {
                            $entityNamespace = "{$this->options->get('derivedNamespace')}\\";
                            $relation['options']['alias'] = $entityName;
                        } else if ($this->options->has('namespace')) {
                            $entityNamespace = "{$this->options->get('namespace')}\\";
                            $relation['options']['alias'] = $entityName;
                        } else {
                            $entityNamespace = '';
                        }
                        $initialize[] = sprintf(
                            $templateRelation,
                            'hasMany',
                            $relation['fields'],
                            $entityNamespace . $entityName,
                            $relation['relationFields'],
                            $this->_buildRelationOptions( isset($relation['options']) ? $relation["options"] : NULL)
                        );

                        $magicProperties[] = sprintf($propertyLineTemplate, '\\Phalcon\\Mvc\\Model\\Resultset\\Simple', $entityName);
                    }
                }
            }
        }

        if ($this->options->has('belongsTo')) {
            if (count($this->options->get('belongsTo'))) {
                foreach ($this->options->get('belongsTo') as $relation) {
                    $relation['options'] = [];
                    if (is_string($relation['fields'])) {
                        $entityName = $relation['referencedModel'];
                        if ($this->options->has('derivedNamespace')) {
                            $entityNamespace = "{$this->options->get('derivedNamespace')}\\";
                            $relation['options']['alias'] = $entityName;
                            $magicType = '\\' . $entityNamespace . $entityName;
                        } else if ($this->options->has('namespace')) {
                            $entityNamespace = "{$this->options->get('namespace')}\\";
                            $relation['options']['alias'] = $entityName;
                            $magicType = $entityName;
                        } else {
                            $entityNamespace = '';
                            $magicType = $entityName;
                        }
                        $initialize[] = sprintf(
                            $templateRelation,
                            'belongsTo',
                            $relation['fields'],
                            $entityNamespace . $entityName,
                            $relation['relationFields'],
                            $this->_buildRelationOptions(isset($relation['options']) ? $relation["options"] : NULL)
                        );
                        
                        $magicProperties[] = sprintf($propertyLineTemplate, $magicType, $entityName);
                    }
                }
            }
        }

        if(count($magicProperties)){
            $propertyLines = "\n " . rtrim(implode("\n ", $magicProperties));
            $properties = sprintf($propertiesTemplate, $className, $package, $propertyLines);
        } else {
            $properties = '';
        }

        $alreadyInitialized = false;
        $alreadyValidations = false;
        if (file_exists($modelPath)) {
            try {
                $possibleMethods = array();
                if ($useSettersGetters) {
                    foreach ($fields as $field) {
                        $methodName = Utils::camelize($field->getName());
                        $possibleMethods['set' . $methodName] = true;
                        $possibleMethods['get' . $methodName] = true;
                    }
                }

                $possibleMethods['getSource'] = true;
                $possibleMethods['initialize'] = true;

                require $modelPath;

                $linesCode = file($modelPath);
                $fullClassName = $this->options->get('className');
                if ($this->options->has('namespace')) {
                    $fullClassName = $this->options->get('namespace').'\\'.$fullClassName;
                }
                $reflection = new \ReflectionClass($fullClassName);
                foreach ($reflection->getMethods() as $method) {
                    if ($method->getDeclaringClass()->getName() == $fullClassName) {
                        $methodName = $method->getName();
                        if (!isset($possibleMethods[$methodName])) {
                            $methodRawCode[$methodName] = join(
                                '',
                                array_slice(
                                    $linesCode,
                                    $method->getStartLine() - 1,
                                    $method->getEndLine() - $method->getStartLine() + 1
                                )
                            );
                        } else {
                            continue;
                        }
                        if ($methodName == 'initialize') {
                            $alreadyInitialized = true;
                        } else {
                            if ($methodName == 'validation') {
                                $alreadyValidations = true;
                            }
                        }
                    }
                }
            } catch (\ReflectionException $e) {
            }
        }

        $validations = array();
        foreach ($fields as $field) {
            if ($field->getType() === Column::TYPE_CHAR) {
                $domain = array();
                if (preg_match('/\((.*)\)/', $field->getType(), $matches)) {
                    foreach (explode(',', $matches[1]) as $item) {
                        $domain[] = $item;
                    }
                }
                if (count($domain)) {
                    $varItems = join(', ', $domain);
                    $validations[] = sprintf(
                        $templateValidateInclusion, $field->getName(), $varItems
                    );
                }
            }
            if ($field->getName() == 'email') {
                $validations[] = sprintf(
                    $templateValidateEmail, $field->getName()
                );
                $uses[] = sprintf(
                    $templateUseAs,
                    'Phalcon\Mvc\Model\Validator\Email',
                    'Email'
                );
            }
        }
        if (count($validations)) {
            $validations[] = $templateValidationFailed;
        }

        /**
         * Check if there has been an extender class
         */
        $extends = '\\Phalcon\\Mvc\\Model';
        if ($this->options->has('extends')) {
            if (!empty($this->options->get('extends'))) {
                $extends = $this->options->get('extends');
            }
        }

        /**
         * Check if there have been any excluded fields
         */
        $exclude = array();
        if ($this->options->has('excludeFields')) {
            if (!empty($this->options->get('excludeFields'))) {
                $keys = explode(',', $this->options->get('excludeFields'));
                if (count($keys) > 0) {
                    foreach ($keys as $key) {
                        $exclude[trim($key)] = '';
                    }
                }
            }
        }

        $attributes = array();
        $setters = array();
        $getters = array();
        foreach ($fields as $field) {
            $type = $this->getPHPType($field->getType());
            if ($useSettersGetters) {

                if (!array_key_exists(strtolower($field->getName()), $exclude)) {
                    $attributes[] = sprintf(
                        $templateAttributes, $type, 'protected', $field->getName()
                    );
                    $setterName = Utils::camelize($field->getName());
                    $setters[] = sprintf(
                        $templateSetter,
                        $field->getName(),
                        $type,
                        $field->getName(),
                        $setterName,
                        $field->getName(),
                        $field->getName(),
                        $field->getName()
                    );

                    if (isset($this->_typeMap[$type])) {
                        $getters[] = sprintf(
                            $templateGetterMap,
                            $field->getName(),
                            $type,
                            $setterName,
                            $field->getName(),
                            $this->_typeMap[$type],
                            $field->getName()
                        );
                    } else {
                        $getters[] = sprintf(
                            $templateGetter,
                            $field->getName(),
                            $type,
                            $setterName,
                            $field->getName()
                        );
                    }
                }
            } else {
                $attributes[] = sprintf(
                    $templateAttributes, $type, 'public', $field->getName()
                );
            }
        }

        if ($alreadyValidations == false) {
            if (count($validations) > 0) {
                $validationsCode = sprintf(
                    $templateValidations, join('', $validations)
                );
            } else {
                $validationsCode = '';
            }
        } else {
            $validationsCode = '';
        }

        if ($alreadyInitialized == false) {
            if (count($initialize) > 0) {
                $initCode = sprintf(
                    $templateInitialize,
                    rtrim(join('', $initialize))
                );
            } else {
                $initCode = '';
            }
        } else {
            $initCode = '';
        }

        $license = '';
        if (file_exists('license.txt')) {
            $license = trim(file_get_contents('license.txt')) . PHP_EOL . PHP_EOL;
        }

        $content = join('', $attributes);

        if ($useSettersGetters) {
            $content .= join('', $setters)
                . join('', $getters);
        }

        $content .= $validationsCode . $initCode;
        foreach ($methodRawCode as $methodCode) {
            $content .= $methodCode;
        }

        if ($genDocMethods) {
            $content .= sprintf($templateFind, $className, $className);
        }

        if ($this->options->get('mapColumn', false)) {
            $content .= $this->_genColumnMapCode($fields);
        }

        $str_use = '';
        if (!empty($uses)) {
            $str_use = implode(PHP_EOL, $uses) . PHP_EOL . PHP_EOL;
        }

        echo "\n".$extends."\n";

        $code = sprintf(
            $templateCode,
            $license,
            $namespace,
            $str_use,
            $properties,
            $className,
            $extends,
            $content
        );

        if (!@file_put_contents($modelPath, $code)) {
            throw new BuilderException("Unable to write to '$modelPath'");
        }

        if ($this->isConsole()) {
            $this->_notifySuccess('Model "' . $this->options->get('name') .'" was successfully created.');
        }
    }

    /**
     * Builds a PHP syntax with all the options in the array
     * @param  array  $options
     * @return string PHP syntax
     */
    private function _buildRelationOptions($options)
    {
        if (empty($options)) {
            return 'NULL';
        }

        $values = array();
        foreach ($options as $name=>$val) {
            if (is_bool($val)) {
                $val = $val ? 'true':'false';
            } elseif (!is_numeric($val)) {
                $val = "'{$val}'";
            }

            $values[] = sprintf('\'%s\' => %s', $name, $val);
        }

        $syntax = 'array('. implode(',', $values). ')';

        return $syntax;
    }

    private function _genColumnMapCode($fields)
    {
        $template = '
    /**
     * Independent Column Mapping.
     */
    public function columnMap()
    {
        return array(
            %s
        );
    }
';
        $contents = array();
        foreach ($fields as $field) {
            $name = $field->getName();
            $contents[] = sprintf('\'%s\' => \'%s\'', $name, $name);
        }

        return sprintf($template, join(", \n            ", $contents));
    }

}
