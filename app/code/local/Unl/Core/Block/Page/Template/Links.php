<?php

class Unl_Core_Block_Page_Template_Links extends Mage_Page_Block_Template_Links
{
    /* Overrides
     * @see Mage_Page_Block_Template_Links::addLink()
     * removes the sorting logic
     */
    public function addLink($label, $url='', $title='', $prepare=false, $urlParams=array(),
        $position=null, $liParams=null, $aParams=null, $beforeText='', $afterText='')
    {
        if (is_null($label) || false===$label) {
            return $this;
        }
        $link = new Varien_Object(array(
            'label'         => $label,
            'url'           => ($prepare ? $this->getUrl($url, (is_array($urlParams) ? $urlParams : array())) : $url),
            'title'         => $title,
            'li_params'     => $this->_prepareParams($liParams),
            'a_params'      => $this->_prepareParams($aParams),
            'before_text'   => $beforeText,
            'after_text'    => $afterText,
        ));

        $this->_links[$this->_getNewPosition($position)] = $link;

        return $this;
    }

    /* Overrides
     * @see Mage_Page_Block_Template_Links::removeLinkByUrl()
     * does interact with block links
     */
    public function removeLinkByUrl($url)
    {
        foreach ($this->_links as $k => $v) {
            if (!$v instanceof Mage_Core_Block_Abstract && $v->getUrl() == $url) {
                unset($this->_links[$k]);
            }
        }

        return $this;
    }

    public function removeLinkBlock($name)
    {
        if ($this->getChild($name)) {
            foreach ($this->_links as $k => $v) {
                if ($v instanceof Mage_Core_Block_Abstract && $v->getNameInLayout() == $name) {
                    unset($this->_links[$k]);
                }
            }

            $this->unsetChild($name);
        }

        return $this;
    }

    /* Overrides
     * @see Mage_Page_Block_Template_Links::_beforeToHtml()
     * to add sorting
     */
    protected function _beforeToHtml()
    {
        if (!empty($this->_links)) {
            reset($this->_links);
            ksort($this->_links);
            $this->_links[key($this->_links)]->setIsFirst(true);
            end($this->_links);
            $this->_links[key($this->_links)]->setIsLast(true);
        }
        return parent::_beforeToHtml();
    }
}
