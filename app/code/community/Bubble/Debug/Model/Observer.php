<?php
/**
 * @category    Bubble
 * @package     Bubble_Debug
 * @version     1.0.1
 * @copyright   Copyright (c) 2013 BubbleCode (http://shop.bubblecode.net)
 */
class Bubble_Debug_Model_Observer
{
    protected $_request;

    protected $_debugEnabled = false;

    protected $_debug = array(
        'start'         => array(), // rendered blocks start time
        'blocks'        => array(), // rendered blocks
        'current_block' => null,    // current rendered block
        'sql'           => array(), // sql queries
    );

    public function __construct()
    {
        $this->_request = Mage::app()->getRequest();
        if (($this->_request->getQuery('debug') || $this->_request->getCookie('debug'))
            && !$this->_request->isAjax() && !$this->_request->getPost())
        {
            $this->_debugEnabled = true;
        }
    }

    public function onSendResponseBefore(Varien_Event_Observer $observer)
    {
        // Reset permanent debugging if needed
        if ($this->_request->getQuery('debug') === '0') {
            Mage::getSingleton('core/cookie')->delete('debug');
            return;
        }

        if (!$this->isDebugEnabled()) {
            return;
        }

        // handling permanent debugging
        if ($this->_request->getQuery('debug') === 'perm') {
            Mage::getSingleton('core/cookie')->set('debug', 1);
        }

        $front = $observer->getEvent()->getFront();
        $html = $this->_getDebugHtml();
        $front->getResponse()->appendBody($html);
    }

    public function onBlockToHtmlBefore(Varien_Event_Observer $observer)
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        /** @var $block Mage_Core_Block_Abstract */
        $block = $observer->getEvent()->getBlock();
        // Saving block rendering time, used in onBlockToHtmlAfter()
        $this->_debug['start'][$block->getNameInLayout()] = microtime(true);
        $this->_debug['current_block'] = $block->setDebugId(uniqid(mt_rand()));
    }

    public function onBlockToHtmlAfter(Varien_Event_Observer $observer)
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        $block = $observer->getEvent()->getBlock();
        $this->_debug['current_block'] = null;

        // Block rendering duration
        $start = $this->_debug['start'][$block->getNameInLayout()];
        if ($start) {
            $blocks =& $this->_debug['blocks'];
            $parents = array();
            $parentBlock = $block->getParentBlock();
            while ($parentBlock) {
                $parents[] = $parentBlock->getNameInLayout();
                $parentBlock = $parentBlock->getParentBlock();
            }
            foreach (array_reverse($parents) as $parent) {
                $blocks =& $blocks[$parent]['children'];
            }
            $tpl = false;
            if ($block->getTemplateFile() && pathinfo($block->getTemplateFile(), PATHINFO_EXTENSION) == 'phtml') {
                $tpl = 'app' . DS . 'design' . DS . $block->getTemplateFile();
            }
            $blockInfo = array(
                'debug_id'  => $block->getDebugId(),
                'name'      => $block->getNameInLayout(),
                'class'     => get_class($block),
                'tpl'       => $tpl,
                'took'      => microtime(true) - $start,
                'cached'    => !is_null($block->getCacheLifetime()),
            );
            if (isset($blocks[$blockInfo['name']])) {
                $blocks[$blockInfo['name']] = array_merge($blocks[$blockInfo['name']], $blockInfo);
            } else {
                $blocks[] = $blockInfo;
            }
        }
    }

    public function onSqlQueryBefore(Varien_Event_Observer $observer)
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        /** @var $adapter Zend_Db_Adapter_Pdo_Abstract */
        $adapter = $observer->getEvent()->getAdapter();
        $sql = $observer->getEvent()->getQuery();
        $bind = $observer->getEvent()->getBind();
        if ($adapter && $sql) {
            $debug = array(
                'query' => $sql,
                'stack' => array(),
            );
            $debug['query'] = $sql;
            if (is_string(key($bind))) {
                foreach ($bind as $field => $value) {
                    $debug['query'] = str_replace($field, $adapter->quote($value), $debug['query']);
                }
            } else if (is_numeric(key($bind))) {
                $offset = 0;
                foreach ($bind as $value) {
                    $pos = strpos($debug['query'], '?', $offset);
                    if (null === $value) {
                        $value = 'NULL';
                    } else if (is_string($value)) {
                        $value = $adapter->quote($value);
                    }
                    $debug['query'] = substr_replace($debug['query'], $value, $pos, 1);
                    $offset = $pos + strlen($value);
                }
            }
            $debug['query'] .= ';';
            $backtrace = array_slice(debug_backtrace(false), 4);
            foreach ($backtrace as $data) {
                $file = false;
                if (isset($data['file'])) {
                    $file = ltrim(str_replace(dirname($_SERVER['SCRIPT_FILENAME']), '', $data['file']), DS);
                }
                $function = $data['function'] . '()';
                if (isset($data['class'])) {
                    $function = $data['class'] . $data['type'] . $function;
                }
                $debug['stack'][] = array(
                    'function'  => $function,
                    'file'      => $file,
                    'line'      => isset($data['line']) ? $data['line'] : false,
                );
            }
            if (isset($this->_debug['current_block'])) {
                $this->_debug['sql']['blocks'][$this->_debug['current_block']->getDebugId()][] = $debug;
            }
            $this->_debug['sql']['queries'][] = $debug;
        }
    }

    public function getDebug()
    {
        return $this->_debug;
    }

    public function isDebugEnabled()
    {
        return $this->_debugEnabled;
    }

    protected function _getBlockInfoHtml(&$html, $block, $level = 0)
    {
        $indent = $level * 4;
        if (!empty($block['name'])) {
            $blockId = $block['debug_id'];
            $html .= '<div>';
            $html .= sprintf(
                '%s %s <strong>%s</strong> <span style="color:%s;">(%s)</span>',
                str_repeat('&nbsp;', $indent),
                str_pad(round($block['took'], 4), 6, STR_PAD_LEFT),
                $block['name'],
                $block['cached'] ? 'limegreen' : 'red',
                $block['cached'] ? 'cached' : 'not cached'
            );
            $html .= '<br />';
            $html .= str_repeat('&nbsp;', $indent + 7) . '&nbsp;' . str_pad($block['class'], 6, STR_PAD_LEFT);
            if (!empty($block['tpl'])) {
                $html .= '<br />';
                $html .= str_repeat('&nbsp;', $indent + 7) . '&nbsp;' . str_pad($block['tpl'], 6, STR_PAD_LEFT);
            }
            if (isset($this->_debug['sql']['blocks'][$blockId])) {
                $id = uniqid(mt_rand());
                $html .= '<br />';
                $onclick = "var el = document.getElementById('$id');
                    el.style.display = el.style.display == 'none' ? 'block' : 'none';return false;";
                $html .= sprintf(
                    '%s <a href="#" onclick="%s" style="color:#1e7ec8;"><strong>SQL Queries (%d)</strong></a>',
                    str_repeat('&nbsp;', $indent + 7),
                    $onclick,
                    count($this->_debug['sql']['blocks'][$blockId])
                );
                $html .= '<br />';
                $html .= '<ol id="'. $id .'" style="display:none;white-space:normal;">';
                foreach ($this->_debug['sql']['blocks'][$blockId] as $i => $data) {
                    $color = ($i % 2) ? '#f4f4f4' : '#dddddd';
                    $html .= '<li style="background-color:'. $color .';">';
                    $html .= str_repeat('&nbsp;', $indent + 8) . $data['query'];
                    $html .= '</li>';
                }
                $html .= '</ol>';
            }
            $html .= '</div>';
        }
        if (isset($block['children'])) {
            foreach ($block['children'] as $child) {
                $this->_getBlockInfoHtml($html, $child, $level + 1);
            }
        }

        return $this;
    }

    protected function _getSqlDebugHtml()
    {
        $html = '';
        if (isset($this->_debug['sql']['queries'])) {
            $count = count($this->_debug['sql']['queries']);
            $html = '<p style="font-size: 16px;margin:10px 0 5px;border-bottom:1px dashed black;">';
            $html .= '<strong>All SQL Queries ('. $count .')</strong>';
            $html .= '</p>';
            $html .= '<ol style="white-space:normal;">';
            foreach ($this->_debug['sql']['queries'] as $sql) {
                $html .= '<li style="margin-bottom:10px;">';
                $html .= $sql['query'] . '<br/>';
                $id = uniqid(mt_rand());
                $onclick = "var el = document.getElementById('$id');
                    el.style.display = el.style.display == 'none' ? 'block' : 'none';return false;";
                $html .= '<a href="#" onclick="'. $onclick .'" style="color:#1e7ec8;"><strong>Stack Trace</strong></a>';
                $html .= '<ol id="'. $id .'" style="display:none;white-space:pre;">';
                foreach ($sql['stack'] as $i => $info) {
                    $color = ($i % 2) ? '#f4f4f4' : '#dddddd';
                    foreach ($info as $key => $value) {
                        $key = str_pad($key, 8, ' ', STR_PAD_RIGHT);
                        $html .= '<li style="background-color:'. $color .';">' . $key . ' => ' . $value . '</li>';
                    }
                }
                $html .= '</ol>';
                $html .= '</li>';
            }
            $html .= '</ol>';
        }

        return $html;
    }

    protected function _getDebugHtml()
    {
        $html = '<pre style="text-align:left;background:white;padding: 10px 10px 20px;">';
        $html .= '<p style="font-size: 16px;margin:0 0 5px;border-bottom:1px dashed black;">';
        $html .= '<strong>Rendered Blocks</strong>';
        $html .= '</p>';
        foreach ($this->_debug['blocks'] as $block) {
            $this->_getBlockInfoHtml($html, $block);
        }
        $html .= $this->_getSqlDebugHtml();
        $html .= '</pre>';

        return $html;
    }
}