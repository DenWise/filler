<?php

    require_once "mapper/ShopVdomeMapper.php"; // New mapper requires here <<<-------------------------|||

    class shopFillerPlugin extends shopPlugin
    {
        const
            MAP_FOLDER = 'maps/',

            PRODUCT_DOCS_FOLDER = 'product_docs/',
            DOCS_IMG_FOLDER = 'img/',
            DOCS_FILE_FOLDER = 'file/',

            TABLE_CATEGORIES = 'shop_filler_category',
            TABLE_PRODUCTS = 'shop_filler_product',
            TABLE_DOCS = 'shop_filler_docs',

            LOG_FILE = 'shopFiller.log',
            LOG_DOCS_FILE = 'shopFillerDocs.log',

            DEBUG_MODE = 'debug';

        private $_mapper;
        private $_existingCategories, $_existingProducts, $_existingDocuments;

        /**
         * @var array
         *     available values:  url, parse_url, sku, img, in_stock, docs, images, description, price, name, features
         */
        private $_clearFields = array('url','parse_url','sku','img','in_stock','images');

        /** ----------------------- BASICS ----------------------- **/
        /** ----------------------- ------ ----------------------- **/

        protected $cyr = [
            'а','б','в','г','д','е','ё','ж','з','и','й','к','л','м','н','о','п',
            'р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ы','ь','э','ю','я',
            'А','Б','В','Г','Д','Е','Ё','Ж','З','И','Й','К','Л','М','Н','О','П',
            'Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ы','Ь','Э','Ю','Я'
        ];
        protected $lat = [
            'a','b','v','g','d','e','io','zh','z','i','y','k','l','m','n','o','p',
            'r','s','t','u','f','h','ts','ch','sh','sht','a','i','y','e','yu','ya',
            'A','B','V','G','D','E','Io','Zh','Z','I','Y','K','L','M','N','O','P',
            'R','S','T','U','F','H','Ts','Ch','Sh','Sht','A','I','Y','e','Yu','Ya'
        ];

        /**
         * @param $text
         * @return mixed
         */
        protected function translit($text)
        {
            return str_replace($this->cyr, $this->lat, $text);
        }

        /**
         * @param string $setting_name
         * @return mixed
         * @throws waException
         */
        public function getSettingPlugin($setting_name = '')
        {
            if (!empty($setting_name)) {
                return wa('shop')->getPlugin('filler')->getSettings($setting_name);
            } else {
                return wa('shop')->getPlugin('filler')->getSettings();
            }
        }

        /**
         * @return string
         */
        public function getPluginDataPath() {
            return wa()->getDataPath('plugins', true, 'shop') . "/filler/";
        }

        protected function log($message)
        {
            waLog::log($message, self::LOG_FILE);
            echo $message . "\n";
        }

        protected function logDocs($message)
        {
            waLog::log($message, self::LOG_DOCS_FILE);
            echo $message . "\n";
        }

        /** ----------------------- COMMON METHODS ----------------------- **/
        /** ----------------------- ------ ------- ----------------------- **/

        /**
         * @param $url
         * @return array|false|string
         */
        protected function get($url)
        {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 50);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                $content = curl_exec($ch);

                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                return array(
                    'content' => $content,
                    'status' => $status
                );
            }

            return file_get_contents($url);
        }

        protected function getDataFromUrl($url)
        {
            for ($i = 0; $i <= 5; $i++){
                $response = $this->get($url,false);
                if ($response['status'] != 200) {
                    $response = $this->get($url,false);
                } else {
                    break;
                }
            }

            if ($response['status'] != 200) {
                $this->log("url: ".$url." (is not reachable)\n");
            }

            $content = preg_replace('#<head>(.*?)</head>#is', '', $response['content']);
            $content = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $content);

            return [
                'content' => $content,
                'status' => $response['status']
            ];
        }

        protected function getHtml($url)
        {
            $data = $this->getDataFromUrl($url);

            if ($data['status'] != 200) {
                $this->log("{$url}:Cannot receive data.");
            }

            $html = str_get_html($data['content']);

            if (empty($html)) {
                $this->log( "Получение данных: шибка при получении DOM дерева. ({$url})");
                return [];
            }

            return $html;
        }

        protected function getBody($content)
        {
            if (is_null($content) || !$content) return false;

            $html = str_get_html($content);
            $body = $html->find('body',0)->innertext ?: '';

            return $body;
        }

        /** ----------------------- PARSING METHODS ----------------------- **/
        /** ----------------------- ------- ------- ----------------------- *
         /**
         *
         * @param IMapper $mapper
         * @param string $mode
         */

        public function run($mode = '')
        {
            $this->_mapper = new ShopVdomeMapper(); // New mapper instantiates here <<<-------------------------|||

            if (!$this->_mapper) {
                $this->log("The mapper is not defined.. Parsing is impossible :(");
                die();
            }

            $this->_init($mode);
            $this->log("We've started now! Just wait..");

            if ($this->_mapper->createMaps($this->_getMapFilePath())) { // TODO вернуть пред боем
                $maps = $this->_mapper->getMaps();
                $this->log("The Parsing Map is drawn. (".count($maps)." maps) Congratulations! Receiving data will start soon..");
                $this->_existingCategories = $this->_getFillerCategories();

                foreach ($maps as $mapPath) {

                    $map = json_decode(file_get_contents($mapPath),true);

                    foreach ($map as $key => $category) {
                        $this->_handleCategory($category);
                    }
                }

                $this->log("That's all.. Bye bye :)");
            }
        }

        protected function _init($mode)
        {
            if (!file_exists($this->getPluginDataPath().self::MAP_FOLDER) && !is_dir($this->getPluginDataPath().self::MAP_FOLDER)) {
                waFiles::create($this->getPluginDataPath().self::MAP_FOLDER, true);
            }
            if (!file_exists($this->getPluginDataPath().self::MAP_FOLDER.(new \ReflectionClass($this->_mapper))->getShortName()) && !is_dir($this->getPluginDataPath().self::MAP_FOLDER.(new \ReflectionClass($this->_mapper))->getShortName())) {
                waFiles::create($this->getPluginDataPath().self::MAP_FOLDER.(new \ReflectionClass($this->_mapper))->getShortName(), true);
            }
            if (!file_exists($this->getPluginDataPath().self::PRODUCT_DOCS_FOLDER) && !is_dir($this->getPluginDataPath().self::PRODUCT_DOCS_FOLDER)) {
                waFiles::create($this->getPluginDataPath().self::PRODUCT_DOCS_FOLDER, true);
            }

            waLog::delete(self::LOG_FILE);
            waLog::delete(self::LOG_DOCS_FILE);

            if ($mode === self::DEBUG_MODE) {
                error_reporting(E_ALL);
                ini_set("display_startup_errors",1);
                ini_set("display_errors",1);
            }

        }

        public function updateProductsByStorage()
        {
            $model = new waModel();
            $limit = 1000;
            $offset = 0;

            $select = "SELECT shop_product_id, product_string FROM ".self::TABLE_PRODUCTS." LIMIT i:limit OFFSET i:offset";

            while ($model->query($select, array('limit' => $limit, 'offset' => $offset))->count() > 0) {
                $rows = $model->query($select, array('limit' => $limit, 'offset' => $offset))->fetchAll();

                foreach ($rows as $row) {
                    $product = json_decode($row['product_string'], 1);
                    if ($product) {
                        $productId = $row['shop_product_id'];
                        $this->_updateProduct($product, $productId);
                    }
                }

                $offset += $limit;
            }

        }

        /** ----------------------- PARSING HELPERS ----------------------- **/
        /** ----------------------- ------- ------- ----------------------- **/

        protected function _getMapFilePath()
        {
            return $this->getPluginDataPath().self::MAP_FOLDER.(new \ReflectionClass($this->_mapper))->getShortName()."/";
        }

        protected function _getFileName($url)
        {
            $explode = explode('/',$url);
            $file = $explode[count($explode) - 1];
            $explode = explode(".",$file);
            $ext = $explode[count($explode) - 1];
            unset($explode[count($explode) - 1]);
            $name = implode("",$explode);
            return preg_replace("#[_\.]#","",$name) . "." .$ext;
        }

        protected function _prepareName($product)
        {
            $name = $product['name'];

            if (isset($product['features']['Производитель'])) {
                $name = preg_replace("#".$product['features']['Производитель']."#", "",$name);
            }

            $name = preg_replace(sprintf("#\[%s\]#", $product['sku']),"",$name);
            if (!preg_match("#[\*\+\.]#",$product['sku'])) {
                $name = preg_replace(sprintf("#%s#", $product['sku']),"",$name);
            }

            return $name;
        }

        /** ----------------------- CREATE CATALOG ----------------------- **/
        /** ----------------------- ------ ------- ----------------------- **/

        /** ------ CATEGORY ------ **/

        /**
         * @param $category
         * @param int $parent
         * @return bool
         */
        protected function _handleCategory($category, $parent = 0)
        {
            $unique = $parent . '-' . $category['url'];
            if (!array_key_exists($unique, $this->_existingCategories)) {
                $categoryId = $this->_createCategory($category,$parent);
                if ($categoryId) {
                    $this->_writeCategory($category['url'],$categoryId,$parent);
                }
            } else {
                $categoryId = $this->_existingCategories[$unique];
                unset($this->_existingCategories[$unique]);
            }

            if ($categoryId) {
                if (isset($category['childs']) && !empty($category['childs'])) {
                    foreach ($category['childs'] as $childCategory) {
                        $this->_handleCategory($childCategory, $categoryId);
                    }
                }
                if (isset($category['products']) && !empty($category['products'])) {
                    $this->_handleProducts($category['products'],$categoryId); // create or update products
                }
                return true;
            } else {
                $this->log("{$category['url']}: Failed to create category.");
            }
        }

        protected function _createCategory($category,$parent)
        {
            $categoryModel = new shopCategoryModel();

            $newCategoryData = array(
                'url' => $category['url'],
                'name' => $category['name'],
                'create_datetime' => date('Y-m-d H:i:s'),
                'status' => 0,
                'parent_id' => $parent,
            );

            try {
                $newCategoryId = $categoryModel->insert($newCategoryData);
                $categoryModel->repair();
                $this->log("Category has been created: ".$category['url']."; id: ".$newCategoryId);
            } catch (Exception $e) {
                $this->log($e->getMessage());
            }

            return $newCategoryId ?: false;
        }

        protected function _writeCategory($url, $categoryId, $parent)
        {
            $model = new waModel();
            $insert = "INSERT INTO ".self::TABLE_CATEGORIES." (url, parent_id, shop_category_id) VALUES (s:url, i:parent, i:category)";

            try {
                $model->query($insert,array('url' => $url, 'category' => $categoryId, 'parent' => $parent));
            } catch (Exception $e) {
                $this->log("Error while writing the category {$url} - ". $e->getMessage());
            }

        }

        /** ------ PRODUCT ------ **/

        /**
         * @param $products
         * @param $categoryId
         */
        protected function _handleProducts($products,$categoryId)
        {
            $this->_existingProducts = $this->_getProductsByCategoryId($categoryId);
            $toRemove = $this->_existingProducts;

            foreach ($products as $product) {
                if (!array_key_exists($product['sku'],$this->_existingProducts) || empty($this->_existingProducts)) {
                    $productData = $this->_mapper->getProductData($product['parse_url']);
                    if (!$productData) continue;
                    $product = array_merge($product,$productData);
                    $productId = $this->_createProduct($product,$categoryId);
                    if ($productId) {
                        $productString = json_encode($this->_clearProductArray($product),1);
                        $this->_writeProduct($product['sku'],$categoryId,$productId,$productString);
                    }
                } else {
                    $this->log("Product {$product['sku']} exists");
                    $productData = $this->_mapper->getProductData($product['parse_url']);
                    if (!$productData) continue;
                    $product = array_merge($product,$productData);
                    $productString = $this->_getProductString($product['sku']);
                    $incomingProductString = json_encode($this->_clearProductArray($product),1);
                    if ($productString) {
                        if ($productString !== $incomingProductString) {
                            if ($this->_updateProduct($product,$this->_existingProducts[$product['sku']])) {
                                $this->_updateProductString($product['sku'],$incomingProductString);
                            }
                        }
                    }
                    unset($toRemove[$product['sku']]);
                }
            }

            if (!empty($toRemove)) {
                $this->_clearProducts($toRemove);
            }

            $this->log("All products for category {$categoryId} performed.");
        }

        protected function _createProduct($product, $categoryId)
        {
            $categoryModel = new shopCategoryModel();
            $typeModel = new shopTypeModel();


            $categoryName = $categoryModel->getById($categoryId)['name'];

            if ($type = $typeModel->getByName($categoryName)) {
                $typeId = $type['id'];
            } else {
                $typeId = $typeModel->insert(array('name' => $categoryName));
            }

            if (!$typeId) $typeId = 0;

            $newProduct = new shopProduct('new');

            $data = array(
                'type_id' => $typeId,
                'name' => $this->_prepareName($product),
                'description' => $product['description'],
                'price' => $product['price'],
                'skus' => array(
                    '-1' => array (
                        'available' => 1,
                        'price' => $product['price'],
                        'name' => $product['name'],
                        'sku' => $product['sku'],
                    )
                ),
                'status' => 0,
                'categories' => array(
                    0 => $categoryId
                )
            );

            try {
                if ($newProduct->save($data, true)) {
                    $productId = $newProduct->getId();
                }
            } catch (Exception $e) {
                $this->log("Error while creating product with {$product['url']} - " . $e->getMessage());
            }

            if (isset($productId)) {
                if ($product['features']) {
                    $this->_loopFeatures($productId, $product['features'],$typeId);
                }
                if ($this->_mapper->getConfigField('stopImg')) {
                    if (isset($product['images']) && !preg_match("#".$this->_config['stopImg']."#", $product['img'])) {
                        $this->_loopImages($productId, $product['images']);
                    }
                } else {
                    if (isset($product['images'])) {
                        $this->_loopImages($productId, $product['images']);
                    }
                }

                $docsData = $this->_saveDocs($productId,$product['docs']);
                if ($docsData) {
                    $this->_handleDocs($newProduct,$docsData);
                }
                $this->log("Product has been created: {$productId}");
                return $productId;
            }
        }

        protected function _updateProduct($product, $shopProductId)
        {
            $updateProduct = new shopProduct($shopProductId);
            $skus = $updateProduct->getSkus();
            foreach ($skus as &$sku) {
                $s['available'] = 1;
                $s['price'] = $product['price'];
            }
            $data['skus'] = $skus;
            $data['name'] = $product['name'];
            $data['price'] = $product['price'];
            $data['description'] = $product['description'];

            try {
                if ($updateProduct->save($data, true)) {
                    if ($product['features']) {
                        $this->_loopFeatures($shopProductId, $product['features'],$updateProduct->type_id,true);
                    }
                    $this->log("Product with id: {$shopProductId} - updated successfully.");
                    return true;
                }
            } catch (Exception $e) {
                $this->log("Error while updating product with id: {$shopProductId} - " . $e->getMessage());
            }
        }

        protected function _clearProducts($products)
        {
            foreach ($products as $sku => $id) {
                $product = new shopProduct($id);
                $product->status = 0;
                try {
                    $product->save();
                    $this->log("Product with sku: {$sku} has been hidden.");
                } catch (Exception $e){
                    $this->log("Error while hiding product with sku: {$sku} -".$e->getMessage());
                }
            }
        }

        protected function _writeProduct($sku, $categoryId, $productId, $productString)
        {
            $model = new waModel();

            $selectId = "SELECT id FROM ".self::TABLE_CATEGORIES." WHERE shop_category_id = i:id";
            $selectSku = "SELECT id FROM ".self::TABLE_PRODUCTS." WHERE sku = s:sku";
            $insert = "INSERT INTO ".self::TABLE_PRODUCTS." (sku, filler_category_id, shop_product_id, product_string) VALUES (s:sku,i:category,i:product, s:string)";

            $category = $model->query($selectId,array('id' => $categoryId))->fetch()['id'];
            $result = $model->query($selectSku,array('sku' => $sku))->fetch();
            $exists = ($result && isset($result['id']));

            if ($category && !$exists) {
                $model->exec($insert,array('sku' => $sku, 'category' => $category, 'product' => $productId, 'string' => $productString));
            }
        }

        protected function _updateProductString($sku, $productString)
        {
            $model = new waModel();
            $update = "UPDATE ".self::TABLE_PRODUCTS." SET product_string = s:string WHERE sku = s:sku";
            $params = array(
                'string' => $productString,
                'sku' => $sku
            );

            try {
                $model->exec($update,$params);
            } catch (Exception $e) {
                $this->log("Error while updating product string {$sku} - ". $e->getMessage());
            }
        }

        /** ------ PRODUCT DATA CREATING ------ **/
        /** ------ ------- ---- -------- ------ **/

        /**
         * @param $features
         * @param $type
         * @return array|bool
         * @throws waException
         */
        protected function _loopFeatures($productId, $features, $typeId, $isUpdate = false) {
            $featureModel = new shopFeatureModel;
            $varcharModel = new shopFeatureValuesVarcharModel;
            $productFeaturesModel = new shopProductFeaturesModel;

            foreach ($features as $name => $value) {

                $gen_code = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', trim(waLocale::transliterate($name))));

                //проверка на существование характеристики
                if (!$featureModel->getByCode($gen_code)) {
                    $new_data = array(
                        'name'       => $name,
                        'type'       => 'varchar',
                        'selectable' => 1,
                        'multiple'   => 0,
                        'status'     => 'public',
                        'code'       => $gen_code,
                    );

                    $id_f = $featureModel->insert($new_data);
                    if ($id_f) {
                        $featureModel->query("INSERT INTO shop_type_features (type_id, feature_id, sort) VALUES ('0','" . $id_f . "','0')");
                        $featureModel->query("INSERT INTO shop_type_features (type_id, feature_id, sort) VALUES ('" . $typeId . "','" . $id_f . "','0')");
                    }
                }

                if ($data_feat = $featureModel->getByField('code', $gen_code)) {
                    $find_varchar = $varcharModel->getByField(array('feature_id' => $data_feat['id'], 'value' => $value));

                    if ($find_varchar) {
                        $id_varchar = $find_varchar['id'];
                    } else {
                        $varchar_data = array(
                            'feature_id' => $data_feat['id'],
                            'value'      => $value,
                        );
                        $id_varchar = $varcharModel->insert($varchar_data);
                    }

                    $p_feat_data = array(
                        'product_id'       => $productId,
                        'feature_id'       => $data_feat['id'],
                        'feature_value_id' => $id_varchar,
                    );

                    if ($isUpdate) {
                        $params = array('product' => $productId, 'feature' => $data_feat['id']);
                        $prodFeatureId = $productFeaturesModel->query("SELECT id FROM shop_product_features WHERE product_id = i:product AND feature_id = i:feature",$params)->fetchAssoc();
                        if (isset($prodFeatureId['id']) && $prodFeatureId['id']) {
                            $productFeaturesModel->updateByField(array('product_id' => $productId, 'feature_id' => $data_feat['id']), array('feature_value_id' => $id_varchar));
                        } else {
                            $productFeaturesModel->insert($p_feat_data);
                        }
                    } else {
                        $productFeaturesModel->insert($p_feat_data);
                    }
                }
            }

        }

        protected function _loopImages($productId, $images) {
            if (!is_array($images)) {
                return;
            }
            foreach ($images as $image) {
                $this->_uploadImage($productId, $image);
            }
        }

        protected function _uploadImage($id, $url) {

            $array = explode('/', $url);
            $f = explode('.', $array[count($array)-1]);
            preg_match('/^.*\.(jpeg|JPEG|jpg|JPG|gif|GIF|png|PNG)$/', $url, $matches);
            $filename = $f[0];
            $file_extension = $matches[1];
            $size = getimagesize($url);

            $data = array(
                'product_id'        => $id,
                'upload_datetime'   => date('Y-m-d H:i:s'),
                'width'             => $size[1],
                'height'            => $size[0],
                'filename'          => $filename,
                'original_filename' => basename($filename),
                'ext'               => $file_extension,
            );

            $product_images_model = new shopProductImagesModel();
            $image_id = $data['id'] = $product_images_model->add($data);

            $config = wa('shop')->getConfig();
            $image_path = shopImage::getPath($data);

            if ((file_exists($image_path) && !is_writable($image_path)) || (!file_exists($image_path) && !waFiles::create($image_path))) {
                $product_images_model->deleteById($image_id);
                throw new waException(
                    sprintf("The insufficient file write permissions for the %s folder.",
                        substr($image_path, strlen($config->getRootPath()))
                    ));
            }

            waFiles::upload($url, $image_path);
        }

        protected function _saveDocs($productId, $docs)
        {
            $productDocuments = array();
            $this->_existingDocuments = $this->_getExistingDocuments();

            if (!empty($docs['data'])) {
                if ($docs['withHeaders']) {
                    foreach ($docs['data'] as $title => $documents) {
                        foreach ($documents as $document) {
                            if (!in_array($document['file'],$this->_existingDocuments)) {
                                if ($data = $this->_saveDoc($productId, $document, $title)) {
                                    $productDocuments[$this->translit($title)][] = $data;
                                }
                            }
                        }
                    }
                } else {
                    foreach ($docs['data'] as $title => $document) {
                        if (!in_array($document['file'],$this->_existingDocuments)) {
                            if ($data = $this->_saveDoc($productId, $document, $title)) {
                                $productDocuments[$this->translit($title)][] = $data;
                            }
                        }
                    }
                }
            }

            return $productDocuments;
        }

        protected function _saveDoc($productId, $document, $title)
        {
            $title = $this->translit($title);
            $fileName = $this->_getFileName($document['file']);
            $imgName = $this->_getFileName($document['image']);

            $documentFolderName = substr(preg_replace("#[^a-zA-Z0-9]#","",$fileName),0,35);

            $titlePath = $this->getPluginDataPath().self::PRODUCT_DOCS_FOLDER . $title . "/"; // wa-data/shop/plugins/filler/product_docs/licenzii/
            if (!file_exists($titlePath) && !is_dir($titlePath)) waFiles::create($titlePath,true);

            $documentPath = $titlePath . $documentFolderName . "/"; // wa-data/shop/plugins/filler/product_docs/licenzii/anionzaklyuchenie/
            if (!file_exists($documentPath) && !is_dir($documentPath)) waFiles::create($documentPath,true);

            $filePath = $documentPath . self::DOCS_FILE_FOLDER; // wa-data/shop/plugins/filler/product_docs/licenzii/anionzaklyuchenie/file/
            if (!file_exists($filePath) && !is_dir($filePath)) waFiles::create($filePath, true);

            $imgPath = $documentPath . self::DOCS_IMG_FOLDER; // wa-data/shop/plugins/filler/product_docs/licenzii/anionzaklyuchenie/img/
            if (!file_exists($imgPath) && !is_dir($imgPath)) waFiles::create($imgPath, true);

            $documentUrl = (mb_detect_encoding($document['file']) == 'cp1251') ? @iconv('windows-1251', 'utf-8//ignore', $document['file']) : $document['file'];

            $headers = get_headers($documentUrl);

            if (preg_match("#200#", $headers[0])) {

                try {
                    waFiles::upload($documentUrl, $filePath . $fileName);
                    waFiles::upload($document['image'], $imgPath . $imgName);
                    if (file_exists($filePath . $fileName) && file_exists($imgPath . $imgName)) {
                        $this->_writeDoc($document['file']);
                        $this->logDocs("Document [{$document['file']}] loaded ({$productId})");
                        return array(
                            'document' => $documentFolderName, // anionzaklyuchenie
                            'image'    => $imgName, // anion-zaklyuchenie.jpg
                            'file'     => $fileName // gigiena_emkosti.pdf
                        );
                    } else {
                        return false;
                    }
                } catch (Exception $e) {
                    $this->logDocs("Document [{$document['file']}] not loaded ({$productId}) with error - " .$e->getMessage());
                }
            }

            return false;
        }

        protected function _writeDoc($url)
        {
            $model = new waModel();
            $insert = "INSERT INTO ".self::TABLE_DOCS." (url) VALUES (s:url)";
            $model->exec($insert, array('url' => $url));
        }

        protected function _handleDocs($product, $docsData)
        {
            $params = array();
            $documentsString = '';

            foreach ($docsData as $title => $documents) {
                foreach ($documents as $key => $data) {
                    $documentsString .= ($documentsString == '') ? implode('/',$data): '+'.implode('/',$data);
                }
                $params[$title] = $documentsString;
            }

            $product->save(array(
                'params' => $params
            ));
        }

        protected function _clearProductArray($productArray)
        {
            foreach ($this->_clearFields as $field) {
                if (isset($productArray[$field])) {
                    unset($productArray[$field]);
                }
            }
            return $productArray;
        }

        /** ----------------------- FETCHING DATA ----------------------- **/
        /** ----------------------- -------- ---- ----------------------- **/

        /**
         * @return array
         */
        protected function _getFillerCategories()
        {
            $model = new waModel();
            $categories = array();

            $select = "SELECT t.id, t.url, t.parent_id, t.shop_category_id FROM ".self::TABLE_CATEGORIES ." as t";

            foreach ($model->query($select)->fetchAll() as $key => $data) {
                $unique = $data['parent_id'].'-'.$data['url'];
                $categories[$unique] = $data['shop_category_id'];
            }

            return $categories;
        }

        protected function _getProductsByCategoryId($id)
        {
            $model = new waModel();
            $products = array();

            $select = "SELECT p.sku, p.shop_product_id FROM ".self::TABLE_PRODUCTS." as p INNER JOIN ".self::TABLE_CATEGORIES." as c ON p.filler_category_id = c.id WHERE c.shop_category_id = i:id";
            $params = array('id' => $id);

            foreach ($model->query($select,$params)->fetchAll() as $key => $data) {
                $products[$data['sku']] = $data['shop_product_id'];
            }

            return $products;
        }

        protected function _getProductString($sku)
        {
            $model = new waModel();

            $select = "SELECT product_string FROM ".self::TABLE_PRODUCTS." WHERE sku = s:sku";

            try {
                return $model->query($select,array('sku' => $sku))->fetch()['product_string'];
            } catch (Exception $e) {
                $this->log("Error while getting product string {$sku} - ". $e->getMessage());
            }
        }

        protected function _getExistingDocuments()
        {
            $docs = array();
            $model = new waModel();

            $select = "SELECT url FROM ".self::TABLE_DOCS;

            try {
                foreach ($model->query($select)->fetchAll() as $key => $data) {
                    $docs[] = $data['url'];
                }
            } catch (Exception $e) {
                $this->log("Error while getting exists documents - ". $e->getMessage());
            }

            return $docs;
        }

        /** ----------------------- WEBASYST HOOKS ----------------------- **/
        /** ----------------------- -------- ----- ----------------------- **/

        public function productDelete(&$params)
        {
            foreach ($params['ids'] as $productId) {
                $this->_removeFillerProduct($productId);
            }
        }

        public function categoryDelete(&$params)
        {
            $this->_removeFillerCategory($params['id']);
        }

        /** ------ HOOK HANDLERS ------ **/

        /**
         * @param $productId
         */
        protected function _removeFillerProduct($productId)
        {
            $model = new waModel();
            $delete = "DELETE FROM ".self::TABLE_PRODUCTS." WHERE shop_product_id = i:id";

            try {
                $model->exec($delete,array('id' => $productId));
            } catch (Exception $e) {
                $this->log("Error while deleting filler product {$productId} - ". $e->getMessage());
            }
        }

        /**
         * @param $categoryId
         */
        protected function _removeFillerCategory($categoryId)
        {
            $model = new waModel();
            $delete = "DELETE FROM ".self::TABLE_CATEGORIES." WHERE shop_category_id = i:id";

            try {
                $this->log("Category delete {$categoryId}");
                $model->exec($delete,array('id' => $categoryId));
            } catch (Exception $e) {
                $this->log("Error while deleting filler category {$categoryId} - ". $e->getMessage());
            }
        }
    }