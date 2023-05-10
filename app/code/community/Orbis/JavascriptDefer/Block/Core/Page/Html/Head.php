<?php

/** @author: Orbis Technology <me@gurdeepbangar.com>
 *  @date: 16/03/2014
 *  @description: Catch selected JS files as listed in admin config & place in defer section.
 * @copyright Copyright (c) Orbis Technology (http://www.orbis.technology)
 */


/**
 * Html page block
 *
 * @category   Mage
 * @package    Mage_Page
 */
class Orbis_JavascriptDefer_Block_Core_Page_Html_Head extends Mage_Page_Block_Html_Head
{
    // List of all the javascript black listed items as defined in the backend.
    protected $_javascriptBlacklist = array();
    protected $_minifiedJsFiles = array(); //Currently unused
    protected $_deferEnabled;
    protected $_i = 0;
    protected $_controllerName;
    /**
     * Initialize template
     *
     */
    public function __construct()
    {
        parent::__construct();
        $this->_controllerName = $this->getRequest()->getControllerName();
        $this->_deferEnabled = Mage::getStoreConfig('deferjs/config/active', Mage::app()->getStore());
        // to do: feed this process to a model, sanitize & store file names.
        if ((int) $this->_deferEnabled === 1) {


            $_tempBlackList = array();

            // Orbis Technology
            // depending on request, we want a different list of JS files to defer (category is used default across the website)
            switch ($this->_controllerName) {
                case 'product':
                    $_tempBlackList = explode(',', Mage::getStoreConfig('deferjs/config/productfiles', Mage::app()->getStore()));
                    break;
                default:
                    $_tempBlackList = explode(',', Mage::getStoreConfig('deferjs/config/categoryfiles', Mage::app()->getStore()));
                    break;
            }

            foreach ($_tempBlackList as $jsBlacklist) {
                $jsBlacklist = trim($jsBlacklist);
                if ($jsBlacklist) {
                    $this->_javascriptBlacklist[] = $jsBlacklist;
                }
            }

            $_tempBlackList = null;
        }
    }
    // Orbis Technology
    // Override Core method 
    public function getCssJsHtml()
    {
        // separate items by types
        $lines = array();
        $deferLines = array();
        foreach ($this->_data['items'] as $item) {
            if (!is_null($item['cond']) && !$this->getData($item['cond']) || !isset($item['name'])) {
                continue;
            }
            $if = !empty($item['if']) ? $item['if'] : '';
            $params = !empty($item['params']) ? $item['params'] : '';
            switch ($item['type']) {
                case 'js': // js/*.js
                case 'skin_js': // skin/*/*.js
                case 'js_css': // js/*.css
                case 'skin_css': // skin/*/*.css

                    /*****************************
                     * JS Defer - override existing functionality if defer is enabled.
                     * This will create a separate "deferred" file array processed later
                     ******************************/
                    if ((int) $this->_deferEnabled === 1) {

                        if (!$this->defer($item['name'])) {
                            $lines[$if][$item['type']][$params][$item['name']] = $item['name'];
                        } elseif ($this->defer($item['name'])) {
                            $deferLines[$if][$item['type']][$params][$item['name']] = $item['name'];
                        }
                    } else {
                        $lines[$if][$item['type']][$params][$item['name']] = $item['name'];
                    }

                    break;
                default:
                    $this->_separateOtherHtmlHeadElements($lines, $if, $item['type'], $params, $item['name'], $item);
                    break;
            }
        }

        // prepare HTML
        $shouldMergeJs = Mage::getStoreConfigFlag('dev/js/merge_files');
        $shouldMergeCss = Mage::getStoreConfigFlag('dev/css/merge_css_files');
        $html = '';
        foreach ($lines as $if => $items) {
            if (empty($items)) {
                continue;
            }
            if (!empty($if)) {
                // open !IE conditional using raw value
                if (strpos($if, "><!-->") !== false) {
                    $html .= $if . "\n";
                } else {
                    $html .= '<!--[if ' . $if . ']>' . "\n";
                }
            }

            // static and skin css
            $html .= $this->_prepareStaticAndSkinElements(
                '<link rel="stylesheet" type="text/css" href="%s"%s />' . "\n",
                empty($items['js_css']) ? array() : $items['js_css'],
                empty($items['skin_css']) ? array() : $items['skin_css'],
                $shouldMergeCss ? array(Mage::getDesign(), 'getMergedCssUrl') : null
            );

            // static and skin javascripts
            $html .= $this->_prepareStaticAndSkinElements(
                '<script type="text/javascript" src="%s"%s></script>' . "\n",
                empty($items['js']) ? array() : $items['js'],
                empty($items['skin_js']) ? array() : $items['skin_js'],
                $shouldMergeJs ? array(Mage::getDesign(), 'getMergedJsUrl') : null
            );

            // other stuff
            if (!empty($items['other'])) {
                $html .= $this->_prepareOtherHtmlHeadElements($items['other']) . "\n";
            }

            if (!empty($if)) {
                $html .= '<![endif]-->' . "\n";
            }

        }

        /*****************************
         * JS Defer
         * Load our deffered Js files after page contents are loaded. javascript trigger: window.addEventListener
         ******************************/
        if ((int) $this->_deferEnabled === 1) {
            $html .= '<script type="text/javascript">function downloadJSAtOnload() {';
            foreach ($deferLines as $if => $items) {
                if (empty($items)) {
                    continue;
                }

                // defer static js lines	
                $html .= $this->_prepareDeferStaticAndSkinElements(
                    null,
                    empty($items['js']) ? array() : $items['js'],
                    empty($items['skin_js']) ? array() : $items['skin_js'],
                    $shouldMergeJs ? array(Mage::getDesign(), 'getMergedJsUrl') : null
                );
            }

            $html .= '}if (window.addEventListener)
window.addEventListener("load", downloadJSAtOnload, false);
else if (window.attachEvent)
window.attachEvent("onload", downloadJSAtOnload);
else window.onload = downloadJSAtOnload;
</script>';
        }
        return $html;
    }
    // Orbis Technology
    /*****************************
     * Custom method : defer()
     * Helper method to check if file is defered. see: getCssJsHtml() 
     ******************************/
    private function defer($file)
    {
        if (in_array(basename($file), $this->_javascriptBlacklist)) {
            return true;
        } else {
            return false;
        }

    }
    /*****************************
     * Custom method: _prepareDeferStaticAndSkinElements
     * uses sprintf to form js structure
     ******************************/
    protected function &_prepareDeferStaticAndSkinElements($format, array $staticItems, array $skinItems, $mergeCallback = null)
    {
        $designPackage = Mage::getDesign();
        $baseJsUrl = Mage::getBaseUrl('js');
        $items = array();
        if ($mergeCallback && !is_callable($mergeCallback)) {
            $mergeCallback = null;
        }

        // get static files from the js folder, no need in lookups
        foreach ($staticItems as $params => $rows) {
            foreach ($rows as $name) {
                $items[$params][] = $mergeCallback ? Mage::getBaseDir() . DS . 'js' . DS . $name : $baseJsUrl . $name;
            }
        }

        // lookup each file basing on current theme configuration
        foreach ($skinItems as $params => $rows) {
            foreach ($rows as $name) {
                $items[$params][] = $mergeCallback ? $designPackage->getFilename($name, array('_type' => 'skin'))
                    : $designPackage->getSkinUrl($name, array());
            }
        }

        $html = '';
        foreach ($items as $params => $rows) {

            // attempt to merge
            $mergedUrl = false;
            if ($mergeCallback) {
                $mergedUrl = call_user_func($mergeCallback, $rows);
            }
            // render elements
            $params = trim($params);
            $params = $params ? ' ' . $params : '';
            if ($mergedUrl) {
                $html .= sprintf('var element' . $this->_i . ' = document.createElement("script");' . "\n" . 'element' . $this->_i . '.src = "%s";' . "\n" . 'document.body.appendChild(element' . $this->_i . ');', $mergedUrl) . "\n";
            } else {
                foreach ($rows as $src) {
                    $html .= sprintf('var element' . $this->_i . ' = document.createElement("script");' . "\n" . 'element' . $this->_i . '.src = "%s";' . "\n" . 'document.body.appendChild(element' . $this->_i . ');', $src) . "\n";
                    $this->_i++;
                }
            }

        }
        return $html;
    }
}