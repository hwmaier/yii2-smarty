<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\smarty;

use Yii;
use Smarty;
use yii\base\Widget;
use yii\base\ViewRenderer as BaseViewRenderer;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;

/**
 * SmartyViewRenderer allows you to use Smarty templates in views.
 *
 * @author Alexander Makarov <sam@rmcreative.ru>
 * @author Henrik Maier <hwmaier@gmail.com>
 * @since 2.0
 */
class ViewRenderer extends BaseViewRenderer
{
    /**
     * @var string the directory or path alias pointing to where Smarty cache will be stored.
     */
    public $cachePath = '@runtime/Smarty/cache';
    /**
     * @var string the directory or path alias pointing to where Smarty compiled templates will be stored.
     */
    public $compilePath = '@runtime/Smarty/compile';
    /**
     * @var boolean enables Smarty's caching
     */
    public $caching = false;
    /**
     * @var boolean enables Smarty's debugging
     */
    public $debugging = false;
    /**
     * @var integer sets Smarty's cache_lifetime configuration setting
     */
    public $cacheLifetime = 3600;
    /**
     * @var boolean sets Smarty's force_compile configuration setting
     */
    public $forceCompile = false;
    /**
     * @var boolean sets Smarty's merge_compiled_includes configuration setting
     */
    public $mergeCompiledIncludes = false;
    /**
     * @var boolean sets Smarty's escape_html configuration setting
     */
    public $escapeHtml = false;
    /**
     * @var array Add additional directories to Smarty's search path for template file.
     *            This is useful when using the {import} function.
     */
    public $templateDirs = [];
    /**
     * @var array Declare Yii widgets to be available as Smarty block tags.
     */
    public $blocks = [];
    /**
     * @var array Declare Yii widgets to be available as Smarty function tags.
     */
    public $functions = [];
    /**
     * @var Smarty The Smarty object used for rendering
     */
    protected $smarty;


    /**
     * Instantiates and configures the Smarty object.
     */
    public function init()
    {
        $this->smarty = new Smarty();

        // Configure Smarty using this view's configuration properties
        $this->smarty->setCompileDir(Yii::getAlias($this->compilePath));
        $this->smarty->setCacheDir(Yii::getAlias($this->cachePath));
        $this->smarty->caching = $this->caching;
        $this->smarty->cache_lifetime = $this->cacheLifetime;
        $this->smarty->force_compile = $this->forceCompile;
        $this->smarty->debugging = $this->debugging;
        $this->smarty->merge_compiled_includes = $this->mergeCompiledIncludes;
        $this->smarty->escape_html = $this->escapeHtml;

        // Set default template directories to current view's dir and application view dir
        $this->smarty->setTemplateDir([
            dirname(Yii::$app->getView()->getViewFile()),
            Yii::$app->getViewPath(),
        ]);

        // Add additional template dirs from configuration array, apply Yii's dir convention
        foreach ($this->templateDirs as &$dir) {
            $dir = $this->resolveTemplateDir($dir);
        }
        $this->smarty->addTemplateDir($this->templateDirs);

        // Register plugins
        $this->smarty->registerPlugin('function', 'path', [$this, 'smarty_function_path']);
        $this->smarty->registerPlugin('function', 'meta', [$this, 'smarty_function_meta']);
        $this->smarty->registerPlugin('function', 'registerJsFile', [$this, 'smarty_function_javascript_file']);
        $this->smarty->registerPlugin('block', 'title', [$this, 'smarty_block_title']);
        $this->smarty->registerPlugin('block', 'description', [$this, 'smarty_block_description']);
        $this->smarty->registerPlugin('block', 'registerJs', [$this, 'smarty_block_javascript']);

        // Register block widgets specified in configuration array
        if (!empty($this->blocks)) {
            foreach($this->blocks as $tag => $class) {
                $this->smarty->registerPlugin('block', $tag, [$this, '_widget_block__' . $tag]);
            }
        }

        // Register function widgets specified in configuration array
        if (!empty($this->functions)) {
            foreach($this->functions as $tag => $class) {
                $this->smarty->registerPlugin('function', $tag, [$this, '_widget_func__' . $tag]);
            }
        }
    }

    /**
     * The directory can be specified in Yii's standard convention
     * using @, // and / prefixes or no prefix for view relative directories.
     *
     * @param string $dir directory name to be resolved
     * @return string the resolved directory name
     */
    protected function resolveTemplateDir($dir)
    {
        if (strncmp($dir, '@', 1) === 0) {
            // e.g. "@app/views/dir"
            $dir = Yii::getAlias($dir);
        } elseif (strncmp($dir, '//', 2) === 0) {
            // e.g. "//layouts/dir"
            $dir = Yii::$app->getViewPath() . DIRECTORY_SEPARATOR . ltrim($dir, '/');
        } elseif (strncmp($dir, '/', 1) === 0) {
            // e.g. "/site/dir"
            if (Yii::$app->controller !== null) {
                $dir = Yii::$app->controller->module->getViewPath() . DIRECTORY_SEPARATOR . ltrim($dir, '/');
            }
            else {
                // No controller, what to do?
            }
        }
        else
            // relative to view file
            $dir = dirname(Yii::$app->getView()->getViewFile()) . DIRECTORY_SEPARATOR . $dir;

        return $dir;
    }

    /**
     * Mechanism to pass a widget's tag name to the callback function.
     *
     * Using a magic function call would not be necessary if Smarty would
     * support closures. Smarty closure support is announced for 3.2,
     * until its release magic function calls are used to pass the
     * tag name to the callback.
     *
     * @param string $method
     * @param array $args
     * @throws InvalidConfigException
     * @throws \BadMethodCallException
     * @return string
     */
    public function __call($method, $args)
    {
        $methodInfo = explode('__', $method);
        if (count($methodInfo) === 2) {
            $tag = $methodInfo[1];
            if (isset($this->functions[$tag])) {
                if (($methodInfo[0] === '_widget_func') && (count($args) === 2))
                    return $this->widgetFunction($this->functions[$tag], $args[0], $args[1]);
            } elseif (isset($this->blocks[$tag])) {
                if (($methodInfo[0] === '_widget_block') && (count($args) === 4))
                    return $this->widgetBlock($this->blocks[$tag], $args[0], $args[1], $args[2], $args[3]);
            } else {
                throw new InvalidConfigException('Widget "' . $tag . '" not defined in view\'s configuration array.');
            }
        }

        throw new \BadMethodCallException('Method does not exist: ' . $method);
    }

    /**
     * Smarty plugin callback function to support widget as Smarty blocks.
     * This function is not called directly by Smarty but through a
     * magic __call wrapper.
     *
     * Example usage is the following:
     *
     *    {ActiveForm assign='form' id='login-form'}
     *        {$form->field($model, 'username')}
     *        {$form->field($model, 'password')->passwordInput()}
     *        <div class="form-group">
     *            <input type="submit" value="Login" class="btn btn-primary" />
     *        </div>
     *    {/ActiveForm}
     */
    private function widgetBlock($class, $params, $content, \Smarty_Internal_Template $template, &$repeat)
    {
        // Check if this is the opening ($content is null) or closing tag.
        if ($content === null) {
            $params['class'] = $class;
            // Figure out where to put the result of the widget call if any
            if (isset($params['assign'])) {
                $assign = $params['assign'];
                unset($params['assign']);
            }
            else
                $assign = false;
            ob_start();
            ob_implicit_flush(false);
            $widget = Yii::createObject($params);
            Widget::$stack[] = $widget;
            if ($assign)
                $template->assign($assign, $widget);
        } else {
            $widget = array_pop(Widget::$stack);
            echo $content;
            $out = $widget->run();
            return ob_get_clean() . $out;
        }
    }

    /**
     * Smarty plugin callback function to support widgets as Smarty functions.
     * This function is not called directly by Smarty but through a
     * magic __call wrapper.
     *
     * Example usage is the following:
     *
     * {Extractor file='@assets/html/features.html divClass='feature'}
     *
     */
    private function widgetFunction($class, $params, \Smarty_Internal_Template $template)
    {
        $params['class'] = $class;
        ob_start();
        ob_implicit_flush(false);
        $widget = Yii::createObject($params);
        $out = $widget->run();
        return ob_get_clean() . $out;
    }

    /**
     * Smarty template function to get a path for using in links
     *
     * Usage is the following:
     *
     * {path route='blog/view' alias=$post.alias user=$user.id}
     *
     * where route is Yii route and the rest of parameters are passed as is.
     *
     * @param $params
     * @param \Smarty_Internal_Template $template
     * @return string
     * @note Even though this method is public it should not be called directly.
     */
    public function smarty_function_path($params, \Smarty_Internal_Template $template)
    {
        if (!isset($params['route'])) {
            trigger_error("path: missing 'route' parameter");
        }

        array_unshift($params, $params['route']) ;
        unset($params['route']);

        return Url::to($params);
    }

    /**
     * Smarty template function
     * Usage is the following:
     *
     * {meta keywords="Yii,PHP,Smarty,framework"}
     *
     * @param $params
     * @param \Smarty_Internal_Template $template
     * @return string
     * @note Even though this method is public it should not be called directly.
     */
    public function smarty_function_meta($params, $template)
    {
        $key = isset($params['name']) ? $params['name'] : null;

        Yii::$app->getView()->registerMetaTag($params, $key);
    }

    /**
     * Smarty template function
     * Usage is the following:
     *
     * {title} Web Site Login {/title}
     *
     * @param $params
     * @param $content
     * @param \Smarty_Internal_Template $template
     * @param $repeat
     * @return string
     * @note Even though this method is public it should not be called directly.
     */
    public function smarty_block_title($params, $content, $template, &$repeat)
    {
        if ($content !== null) {
            Yii::$app->getView()->title = $content;
        }
    }

    /**
     * Smarty template function
     * Usage is the following:
     *
     * {description}
     *     The text between the opening and closing tags is added as
     *     meta description tag to the page output.
     * {/description}
     *
     * @param $params
     * @param $content
     * @param \Smarty_Internal_Template $template
     * @param $repeat
     * @return string
     * @note Even though this method is public it should not be called directly.
     */
    public function smarty_block_description($params, $content, $template, &$repeat)
    {
        if ($content !== null) {
            // Clean-up whitespace and newlines
            $content = preg_replace('/\s+/', ' ', trim($content));

            Yii::$app->getView()->registerMetaTag(['name' => 'description',
                                                   'content' => $content],
                                                   'description');
        }
    }

    /**
     * Helper function to convert a textual constant identifier to a View class
     * integer constant value.
     *
     * @param string $string Constant identifier name
     * @param integer $default Default value
     *
     * @return mixed
     */
    protected function getViewConstVal($string, $default)
    {
       $val = @constant('yii\web\View::' . $string);
       return isset($val) ? $val : $default;
    }

    /**
     * Smarty template function
     * Usage is the following:
     *
     * {registerJsFile url='http://maps.google.com/maps/api/js?sensor=false'}
     *
     * @param $params
     * @param \Smarty_Internal_Template $template
     * @return string
     * @note Even though this method is public it should not be called directly.
     */
    public function smarty_function_javascript_file($params, $template)
    {
        if (!isset($params['url'])) {
            trigger_error("registerJsFile: missing 'url' parameter");
        }

        $url = ArrayHelper::remove($params, 'url');
        $key = ArrayHelper::remove($params, 'key', null);
        $depends = ArrayHelper::remove($params, 'depends', null);
        if (isset($params['position']))
            $params['position'] = $this->getViewConstVal($params['position'], View::POS_END);

        Yii::$app->getView()->registerJsFile($url, $depends, $params, $key);
    }

    /**
     * Smarty template function
     * Usage is the following:
     *
     * {registerJs key='map'}
     *     $("#map").before('<h4>How to find us</h4>');
     * {/registerJs}
     *
     * @param $params
     * @param $content
     * @param \Smarty_Internal_Template $template
     * @param $repeat
     * @return string
     * @note Even though this method is public it should not be called directly.
     */
    public function smarty_block_javascript($params, $content, $template, &$repeat)
    {
        if ($content !== null) {
            $key = isset($params['key']) ? $params['key'] : null;
            $position = isset($params['position']) ? $params['position'] : null;

            Yii::$app->getView()->registerJs($content,
                                             $this->getViewConstVal($position, View::POS_READY),
                                             $key);
        }
    }

    /**
     * Renders a view file.
     *
     * This method is invoked by [[View]] whenever it tries to render a view.
     * Child classes must implement this method to render the given view file.
     *
     * @param View $view the view object used for rendering the file.
     * @param string $file the view file.
     * @param array $params the parameters to be passed to the view file.
     * @return string the rendering result
     */
    public function render($view, $file, $params)
    {
        /* @var $template \Smarty_Internal_Template */
        $template = $this->smarty->createTemplate($file, null, null, empty($params) ? null : $params, false);

        $template->assign('app', \Yii::$app);
        $template->assign('this', $view);

        return $template->fetch();
    }
}
