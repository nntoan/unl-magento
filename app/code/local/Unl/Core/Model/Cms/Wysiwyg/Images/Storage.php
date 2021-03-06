<?php

class Unl_Core_Model_Cms_Wysiwyg_Images_Storage extends Mage_Cms_Model_Wysiwyg_Images_Storage
{
    const THUMBS_EXTERNAL_DIR = '.external';

    /* Overrides
     * @see Mage_Cms_Model_Wysiwyg_Images_Storage::getDirsCollection()
     * to match MAGE_PATCH SUPEE-2677
     */
    public function getDirsCollection($path)
    {
        if (Mage::helper('core/file_storage_database')->checkDbUsage()) {
            $subDirectories = Mage::getModel('core/file_storage_directory_database')->getSubdirectories($path);
            foreach ($subDirectories as $directory) {
                $fullPath = rtrim($path, DS) . DS . $directory['name'];
                if (!file_exists($fullPath)) {
                    mkdir($fullPath, 0777, true);
                }
            }
        }

        $conditions = array('reg_exp' => array(), 'plain' => array());

        foreach ($this->getConfig()->dirs->exclude->children() as $dir) {
            $conditions[$dir->getAttribute('regexp') ? 'reg_exp' : 'plain'][(string) $dir] = true;
        }
        // "include" section takes precedence and can revoke directory exclusion
        foreach ($this->getConfig()->dirs->include->children() as $dir) {
            unset($conditions['regexp'][(string) $dir], $conditions['plain'][(string) $dir]);
        }

        $regExp = $conditions['reg_exp'] ? ('~' . implode('|', array_keys($conditions['reg_exp'])) . '~i') : null;
        $collection = $this->getCollection($path)
            ->setCollectDirs(true)
            ->setCollectFiles(false)
            ->setCollectRecursively(false);
        $storageRootLength = strlen($this->getHelper()->getStorageRoot());

        foreach ($collection as $key => $value) {
            $rootChildParts = explode(DIRECTORY_SEPARATOR, substr($value->getFilename(), $storageRootLength));

            if (array_key_exists(end($rootChildParts), $conditions['plain'])
                || ($regExp && preg_match($regExp, $value->getFilename()))) {
                $collection->removeItemByKey($key);
            }
        }

        return $collection;
    }

    /* Overrides
     * @see Mage_Cms_Model_Wysiwyg_Images_Storage::getFilesCollection()
     * by using the design path for the default thumbnail
     */
    public function getFilesCollection($path, $type = null)
    {
        if (Mage::helper('core/file_storage_database')->checkDbUsage()) {
            $files = Mage::getModel('core/file_storage_database')->getDirectoryFiles($path);

            $fileStorageModel = Mage::getModel('core/file_storage_file');
            foreach ($files as $file) {
                $fileStorageModel->saveFile($file);
            }
        }

        $collection = $this->getCollection($path)
            ->setCollectDirs(false)
            ->setCollectFiles(true)
            ->setCollectRecursively(false)
            ->setOrder('mtime', Varien_Data_Collection::SORT_ORDER_ASC);

        // Add files extension filter
        if ($allowed = $this->getAllowedExtensions($type)) {
            $collection->setFilesFilter('/\.(' . implode('|', $allowed). ')$/i');
        }

        $helper = $this->getHelper();

        // prepare items
        foreach ($collection as $item) {
            $item->setId($helper->idEncode($item->getBasename()));
            $item->setName($item->getBasename());
            $item->setShortName($helper->getShortFilename($item->getBasename()));
            $item->setUrl($helper->getCurrentUrl() . $item->getBasename());

            if ($this->isImage($item->getBasename())) {
                $thumbUrl = $this->getThumbnailUrl($item->getFilename(), true);
                // generate thumbnail "on the fly" if it does not exists
                if(! $thumbUrl) {
                    $thumbUrl = Mage::getSingleton('adminhtml/url')->getUrl('*/*/thumbnail', array('file' => $item->getId()));
                }

                $size = @getimagesize($item->getFilename());

                if (is_array($size)) {
                    $item->setWidth($size[0]);
                    $item->setHeight($size[1]);
                }
            } else {
                $thumbUrl = Mage::getDesign()->getSkinUrl(self::THUMB_PLACEHOLDER_PATH_SUFFIX);
            }

            $item->setThumbUrl($thumbUrl);
        }

        return $collection;
    }

    public function resizeFile($source, $keepRation = true, $keepTransparency = true)
    {
        if (!is_file($source) || !is_readable($source)) {
            return false;
        }

        $targetDir = $this->getThumbsPath($source);
        $io = new Varien_Io_File();
        if (!$io->isWriteable($targetDir)) {
            $io->mkdir($targetDir);
        }
        if (!$io->isWriteable($targetDir)) {
            return false;
        }
        $image = Varien_Image_Adapter::factory('GD2');
        $image->open($source);
        $width = $this->getConfigData('resize_width');
        $height = $this->getConfigData('resize_height');
        $image->keepAspectRatio($keepRation);
        $image->keepTransparency($keepTransparency);
        $image->resize($width, $height);
        $dest = $targetDir . DS . pathinfo($source, PATHINFO_BASENAME);
        $image->save($dest);
        if (is_file($dest)) {
            return $dest;
        }
        return false;
    }

    public function getThumbsPath($filePath = false)
    {
        if ($filePath && $path = $this->getThumbnailPath($filePath)) {
            return dirname($path);
        }

        return $this->getThumbnailRoot() . DS . self::THUMBS_EXTERNAL_DIR;
    }
}
